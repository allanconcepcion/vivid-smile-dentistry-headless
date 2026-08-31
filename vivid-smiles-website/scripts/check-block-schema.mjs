/**
 * Pre-flight the block selection sets against the CMS's live schema.
 *
 *   node scripts/check-block-schema.mjs
 *   npm run check:blocks
 *
 * WHY THIS EXISTS
 *
 * src/blocks/manifest.ts states, per layout, the fields the build will ask
 * WordPress for. cms/mu-plugins/vs-content-model.php states the fields the
 * layout actually has. They are two halves of one contract with nothing holding
 * them together, and the halves ship on different timelines: the manifest goes
 * out with the next Astro build, the PHP only when somebody hand-runs
 * cms/bin/deploy-mu-plugins.sh.
 *
 * A mismatch does not fail politely. GraphQL validates the WHOLE document
 * before executing any of it, so one wrong sub-field name in one fragment fails
 * every page in the build — 48 routes down because of one word. That happened
 * twice in Phase 2; `band` on code_section is still commented as a headstone in
 * manifest.ts. This turns that into a ten-second check.
 *
 * HOW, GIVEN INTROSPECTION IS OFF
 *
 * Re-verified against the host rather than assumed: both `{ __schema … }` and
 * `{ __type(name: …) … }` come back with "GraphQL introspection is not allowed
 * for public requests by default", and src/lib/wp.ts sends no auth header. So
 * there is no schema to diff against.
 *
 * There does not need to be. Validation runs on every request whether or not
 * introspection is published, and it runs BEFORE execution — which is the same
 * property that makes a mismatch so destructive at build time and so cheap to
 * probe here. Each fragment is POSTed inside a query whose `where` clause
 * matches no page, so the server validates the full document and then resolves
 * zero rows. A pass costs one round trip and no page render.
 *
 * WHAT THIS DELIBERATELY DOES NOT CHECK
 *
 * A field that exists on the CMS but that the manifest does not ask for. That
 * is not a fault — `card_grid.cards.href` and `media_split.checklist` are both
 * registered in PHP and both deliberately unselected, because a field that is
 * queried and then dropped reads as supported. There are dozens of these. A
 * check that listed them would be noise, and a noisy pre-flight gets skipped,
 * which costs more than it ever saved. This script never enumerates the CMS's
 * fields at all, so it cannot drift into reporting them.
 *
 * Nor does it check SHAPES. Failure mode 3 from Phase 2 — an ACF select
 * arriving as a LIST of one string rather than a string — is invisible to name
 * validation, because `band` is a valid leaf either way. That one belongs to
 * the components, which must tolerate both.
 *
 * It does catch failure mode 2 without being asked to. Two layouts sharing a
 * repeater or group sub-field NAME make WPGraphQL merge their types and
 * silently drop one side's fields; the dropped side then stops validating, and
 * shows up here as a plain "Cannot query field" on that layout. The PHP has its
 * own guard (assert_unique_block_container_names) but only for what is
 * registered locally — this one reads the schema that is actually deployed.
 *
 * EXIT CODES, because this is meant to gate a build or a deploy
 *
 *   0  every registered fragment validates
 *   1  at least one fragment does not — the build would die; fix the manifest
 *   2  the check could not be run (no endpoint, CMS unreachable, `blocks` not
 *      deployed yet). Distinct from 1 on purpose: "your field names are wrong"
 *      and "the CMS is down" want different responses from whoever is paged.
 *
 * The advisory section at the end never changes the exit code. See it there.
 */

import { readFileSync } from "node:fs";
import { dirname, join, relative } from "node:path";
import { fileURLToPath } from "node:url";

const HERE = dirname(fileURLToPath(import.meta.url));
const APP_ROOT = join(HERE, "..");
const REPO_ROOT = join(APP_ROOT, "..");

const MANIFEST_PATH = join(APP_ROOT, "src", "blocks", "manifest.ts");
const CONTENT_MODEL_PATH = join(REPO_ROOT, "cms", "mu-plugins", "vs-content-model.php");

/**
 * Paths are resolved from import.meta.url, not process.cwd().
 *
 * scripts/warm-media-cache.mjs uses cwd because npm always runs it from the
 * package directory. This one is meant to be runnable from a git hook, from CI,
 * and from the repo root by hand, and a pre-flight that silently reads the
 * wrong manifest is worse than no pre-flight.
 */

const EXIT_OK = 0;
const EXIT_MISMATCH = 1;
const EXIT_CANNOT_RUN = 2;

const argv = process.argv.slice(2);

function argValue(flag) {
  const i = argv.indexOf(flag);
  return i !== -1 && argv[i + 1] ? argv[i + 1] : undefined;
}

// ---------------------------------------------------------------------------
// Endpoint
// ---------------------------------------------------------------------------

/**
 * Read the endpoint the same way the build reads it.
 *
 * vite's loadEnv is what Astro itself uses to populate import.meta.env, so
 * pointing this script at .env / .env.local by hand would be a second source of
 * truth that can disagree with the build — a pre-flight that passes against
 * staging while the build talks to production is exactly the class of bug this
 * file is supposed to remove. Same call as scripts/warm-media-cache.mjs makes.
 *
 * vite is present as a transitive dependency of astro, never a direct one, so
 * the import is guarded: a checkout with no node_modules should say so plainly
 * rather than throw a module-resolution stack at somebody.
 */
async function resolveEndpoint() {
  const fromFlag = argValue("--endpoint");
  if (fromFlag) return { endpoint: fromFlag, source: "--endpoint" };

  if (process.env.WP_GRAPHQL_ENDPOINT) {
    return { endpoint: process.env.WP_GRAPHQL_ENDPOINT, source: "environment" };
  }

  try {
    const { loadEnv } = await import("vite");
    const env = loadEnv(process.env.NODE_ENV ?? "production", APP_ROOT, "");
    if (env.WP_GRAPHQL_ENDPOINT) {
      return { endpoint: env.WP_GRAPHQL_ENDPOINT, source: ".env" };
    }
  } catch {
    // Fall through to the same advice as an unset variable. Whether the cause
    // was a missing vite or a missing value, the fix is to supply the endpoint.
  }

  return { endpoint: undefined, source: undefined };
}

// ---------------------------------------------------------------------------
// The manifest
// ---------------------------------------------------------------------------

/**
 * Import manifest.ts and use the real exports.
 *
 * NOT parsed out of the file with a regex, even though that would drop the Node
 * version question below. The fields are assembled from template literals
 * (BLOCK_PREAMBLE_FIELDS, BLOCK_IMAGE_FIELDS), so a parser would be
 * reimplementing the interpolation and could disagree with it — and a
 * pre-flight that checks a slightly different string than the build sends is
 * the one outcome worse than no pre-flight. blockSelectionSet() is called here
 * for the same reason: it is the exact function src/loaders/pages.ts calls.
 *
 * manifest.ts is importable from plain Node because it is type-only TypeScript:
 * interfaces and `Record<…>` annotations, all erasable, no enums or decorators.
 * Node strips those itself from 22.6 with --experimental-strip-types and from
 * 22.18 without a flag. process.features.typescript reports which, so an older
 * 22.x — the floor package.json's `engines` allows — gets one clear line rather
 * than ERR_UNKNOWN_FILE_EXTENSION.
 */
async function loadManifest() {
  if (!process.features.typescript) {
    fail(
      `Node ${process.version} cannot import TypeScript without a flag.`,
      "Re-run as: node --experimental-strip-types scripts/check-block-schema.mjs",
      "(Node 22.18 and newer need no flag; .nvmrc asks for 22.)",
    );
  }

  const mod = await import(`file://${MANIFEST_PATH}`);
  return {
    manifest: mod.BLOCK_MANIFEST,
    selectionSet: mod.blockSelectionSet(""),
  };
}

// ---------------------------------------------------------------------------
// GraphQL
// ---------------------------------------------------------------------------

const TIMEOUT_MS = 20_000;
const MAX_ATTEMPTS = 4;

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/**
 * POST a query and hand back `errors` rather than throwing on them.
 *
 * Inverted from src/lib/wp.ts on purpose. There, a query-level error is a
 * build-stopping fault; here a query-level error is the measurement. Only
 * transport failures throw.
 *
 * The 429 retry is not optional politeness. The CMS sits behind Cloudflare,
 * which answers a burst from one IP with 429 and an HTML interstitial, and that
 * challenge then applies to whatever the build does next — so a pre-flight that
 * hammered the endpoint could fail the very build it was clearing.
 */
async function graphql(endpoint, query) {
  let lastError;

  for (let attempt = 1; attempt <= MAX_ATTEMPTS; attempt++) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), TIMEOUT_MS);

    try {
      const res = await fetch(endpoint, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ query }),
        signal: controller.signal,
      });

      if (res.status === 429 || res.status >= 500) {
        const after = Number(res.headers.get("retry-after"));
        lastError = new Error(`HTTP ${res.status} ${res.statusText}`);
        if (attempt === MAX_ATTEMPTS) break;
        await sleep(Number.isFinite(after) && after > 0 ? after * 1000 : 1000 * 2 ** attempt);
        continue;
      }

      if (!res.ok) {
        // Other 4xx is a misconfiguration — wrong URL, a disabled plugin, auth
        // in front of the endpoint. It will not fix itself on a retry.
        const body = await res.text().catch(() => "");
        throw new Error(`HTTP ${res.status} ${res.statusText}. ${body.slice(0, 200)}`);
      }

      const json = await res.json();
      return { errors: json.errors ?? [], data: json.data };
    } catch (error) {
      lastError = error;
      const retryable =
        (error instanceof Error && error.name === "AbortError") ||
        // fetch() rejects with TypeError for DNS and connection-level failures.
        error instanceof TypeError;
      if (!retryable || attempt === MAX_ATTEMPTS) break;
      await sleep(1000 * 2 ** attempt);
    } finally {
      clearTimeout(timer);
    }
  }

  throw lastError instanceof Error ? lastError : new Error(String(lastError));
}

/**
 * Wrap a `blocks` selection in a query that validates fully and resolves
 * nothing.
 *
 * The path — pages → nodes → pageFields → blocks — is the one
 * src/loaders/pages.ts uses. It has to be: a fragment is only validated against
 * the type the field it sits under actually returns, so checking it somewhere
 * else would be checking a different thing.
 *
 * `where: { name: … }` names a slug no page has, which is what keeps this
 * cheap. Validation covers the whole document regardless of what the arguments
 * will match, so the server rejects a bad field name and then returns an empty
 * node list without rendering a page. `first: 1` alone would validate just as
 * well but would resolve a real page and all of its blocks on every probe.
 */
function probeQuery(selection) {
  return /* GraphQL */ `
    query VsBlockSchemaPreflight {
      pages(first: 1, where: { name: "vs-block-schema-preflight-no-such-page" }) {
        nodes {
          pageFields {
${selection}
          }
        }
      }
    }
  `;
}

const indent = (text, pad) =>
  text
    .split("\n")
    .map((line) => (line ? pad + line : line))
    .join("\n");

// ---------------------------------------------------------------------------
// The PHP side, for the advisory pass
// ---------------------------------------------------------------------------

/**
 * The layout names registered in vs-content-model.php.
 *
 * Keyed off the `layout_vs_blk_` prefix rather than off `'name' =>` alone,
 * because every sub-field in the file also has a `'name' =>` and only the
 * layouts carry that key prefix. The name is read from the following `'name'`
 * rather than from the key's own suffix so a layout whose key and name have
 * drifted apart is reported as the name — which is what `acf_fc_layout` stores
 * and therefore what the __typename is built from.
 *
 * Returns an empty list rather than throwing when the file cannot be read. This
 * feeds the advisory pass only, and a missing cms/ checkout must not be able to
 * fail a check about Astro's fragments.
 */
function phpLayoutNames() {
  let source;
  try {
    source = readFileSync(CONTENT_MODEL_PATH, "utf8");
  } catch {
    return null;
  }

  const names = [];
  const re = /'key'\s*=>\s*'layout_vs_blk_[a-z0-9_]+'[\s\S]{0,200}?'name'\s*=>\s*'([a-z0-9_]+)'/g;
  for (const match of source.matchAll(re)) names.push(match[1]);
  return names;
}

/**
 * layout name → the __typename wpgraphql-acf registers for it.
 *
 * The convention manifest.ts documents and the pilot confirmed live:
 * `PageFieldsBlocks` + the layout name in PascalCase + `Layout`. Only used to
 * generate CANDIDATES for the advisory probe below — nothing fatal is decided
 * from a name derived here, because the derivation is a convention rather than
 * something this script can read off the server.
 */
function typeNameFor(layoutName) {
  const pascal = layoutName
    .split("_")
    .filter(Boolean)
    .map((part) => part[0].toUpperCase() + part.slice(1))
    .join("");
  return `PageFieldsBlocks${pascal}Layout`;
}

/**
 * Does the live schema carry this type?
 *
 * Introspection is off, so this asks indirectly: request a field no layout
 * could have. A type that exists answers 'Cannot query field "…" on type "X"';
 * a type that does not answers 'Unknown type "X"'. Two different validation
 * rules, two distinguishable messages, no introspection involved.
 */
async function typeExists(endpoint, typeName) {
  const { errors } = await graphql(
    endpoint,
    probeQuery(
      `            blocks { __typename ... on ${typeName} { vsBlockSchemaPreflightNoSuchField } }`,
    ),
  );
  return !errors.some((e) => /Unknown type/i.test(e.message ?? ""));
}

// ---------------------------------------------------------------------------
// Reporting
// ---------------------------------------------------------------------------

const out = (line = "") => console.log(line);

function fail(...lines) {
  for (const line of lines) console.error(line);
  process.exit(EXIT_CANNOT_RUN);
}

/** The field name out of 'Cannot query field "band" on type "…"', for a one-line summary. */
function offendingField(message) {
  return /Cannot query field "([^"]+)"/.exec(message ?? "")?.[1];
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

const { endpoint, source } = await resolveEndpoint();

if (!endpoint) {
  fail(
    "WP_GRAPHQL_ENDPOINT is not set, so there is no schema to check against.",
    "",
    "  Local WordPress:  cd ../cms && npm install && npm start",
    "                    then copy .env.example to .env",
    "  Hosted CMS:       WP_GRAPHQL_ENDPOINT=https://<host>/graphql \\",
    "                      node scripts/check-block-schema.mjs",
    "  One-off:          node scripts/check-block-schema.mjs --endpoint https://<host>/graphql",
    "",
    "Set in the Vercel project's environment variables for a deployed build.",
  );
}

const { manifest, selectionSet } = await loadManifest();
const entries = Object.entries(manifest);

out("Block schema pre-flight");
out(`  endpoint  ${endpoint}  (from ${source})`);
out(`  manifest  ${relative(REPO_ROOT, MANIFEST_PATH)}  (${entries.length} layouts)`);
out();

if (entries.length === 0) {
  out("  Nothing registered. Nothing to check.");
  process.exit(EXIT_OK);
}

/**
 * Is `blocks` on the schema at all?
 *
 * Asked once, before the fragments, because when the mu-plugin has not been
 * deployed the answer is 'Cannot query field "blocks" on type "PageFields"' —
 * and without this step that single fact would print as eight identical
 * per-layout failures and read as eight wrong field names. It is also not a
 * manifest fault, so it exits 2, not 1: nothing in Astro needs changing.
 */
let baseline;
try {
  baseline = await graphql(endpoint, probeQuery("            blocks { __typename }"));
} catch (error) {
  fail(`  Cannot reach the CMS: ${error.message}`, "", "  Nothing was checked.");
}

if (baseline.errors.length) {
  const detail = baseline.errors.map((e) => e.message).join("; ");
  fail(
    `  The CMS does not answer for pageFields.blocks: ${detail}`,
    "",
    "  The manifest is not the problem. vs-content-model.php registers the field;",
    "  this host has not been given it yet. Deploy the mu-plugins:",
    "    cms/bin/deploy-mu-plugins.sh",
  );
}

// One request per layout, sequentially.
//
// A single combined document would be one round trip, but graphql-php stops
// collecting once a fragment's type condition is unknown, so a renamed layout
// would mask every field fault inside it and the second run would find a fault
// the first run had already been looking at. Isolating each fragment means one
// broken layout reports exactly one broken layout. The combined run below
// covers what isolation cannot see.
const failures = [];

for (const [key, def] of entries) {
  const { errors } = await graphql(
    endpoint,
    probeQuery(`            blocks { __typename ... on ${def.typeName} { ${def.fields} } }`),
  );

  const fieldCount = def.fields.split(/\s+/).filter((t) => /^[a-zA-Z_]\w*$/.test(t)).length;

  if (errors.length === 0) {
    out(`  ok    ${def.typeName.padEnd(38)} ${fieldCount} fields`);
    continue;
  }

  failures.push({ key, def, errors });
  const first = offendingField(errors[0].message);
  out(`  FAIL  ${def.typeName.padEnd(38)} ${first ? `field "${first}"` : "see below"}`);

  // The manifest key and typeName are stated twice in that file and a mismatch
  // between them is the one drift manifest.ts cannot catch for itself.
  if (key !== def.typeName) {
    out(`        manifest key "${key}" does not match typeName "${def.typeName}"`);
  }
}

out();

/**
 * Finally, the exact document the build sends.
 *
 * Not redundant with the per-layout pass. Validation rules that compare
 * fragments to each other — chiefly OverlappingFieldsCanBeMerged, where two
 * fragments select one field name with two different shapes — cannot fire when
 * each fragment is sent on its own. This is also the literal string
 * blockSelectionSet() hands src/loaders/pages.ts, so a pass here is a statement
 * about the build rather than about eight queries that resemble it.
 */
let combined = { errors: [] };
if (failures.length === 0) {
  combined = await graphql(endpoint, probeQuery(indent(selectionSet, "            ")));
  if (combined.errors.length === 0) {
    out(`  ok    the assembled blocks { … } selection, as the build sends it`);
  } else {
    out("  FAIL  the assembled selection, though every fragment passes alone");
    out("        (a cross-fragment conflict; each layout below is individually valid)");
    for (const e of combined.errors) out(`        ${e.message}`);
  }
  out();
}

if (failures.length) {
  out(`${failures.length} of ${entries.length} layouts do not match the deployed schema.`);
  out();
  for (const { key, def, errors } of failures) {
    out(`  ${def.typeName}`);
    out(`    manifest  ${relative(REPO_ROOT, MANIFEST_PATH)} -> BLOCK_MANIFEST.${key}`);
    out(`    selects   ${def.fields}`);
    for (const e of errors) out(`    error     ${e.message}`);
    out();
  }
  out("Every one of these fails the WHOLE build, not the page that uses the layout:");
  out("GraphQL validates the document before executing any of it. Fix the field name in");
  out(`${relative(REPO_ROOT, MANIFEST_PATH)}, or add the sub-field to the layout in`);
  out(`${relative(REPO_ROOT, CONTENT_MODEL_PATH)} and deploy the mu-plugins.`);
  out();
}

/**
 * ADVISORY: a layout the CMS offers that the manifest has no entry for.
 *
 * Reported, and deliberately NOT fatal. The reasoning, since the two halves
 * pull opposite ways:
 *
 * It is worth reporting because the symptom is the quietest one in the system.
 * An editor adds the section, saves, sees it in wp-admin, and the deploy
 * succeeds — the row routes to UnknownBlock, which renders a placeholder in dev
 * and nothing in production. No error, no failed build, no log line anyone
 * reads. A section that is simply absent from the page is far more likely to
 * reach a client than a build that refused to finish, and it can sit there for
 * weeks. That is exactly the sort of thing a pre-flight should be watching for.
 *
 * It must not be fatal because "PHP ahead of Astro" is a SUPPORTED state, not a
 * fault. manifest.ts and registry.ts both document it: the mu-plugins are
 * hand-deployed and the Astro side follows on the next build, and UnknownBlock
 * exists precisely to make that window survivable. Exiting non-zero would gate
 * deploys on a condition the architecture calls normal, and a gate that fires
 * on normal states is a gate people learn to pass with --force.
 *
 * So: exit code 1 keeps its single meaning — the build would die. This prints
 * underneath it and changes nothing.
 *
 * Candidates come from the checked-in PHP, but membership is confirmed against
 * the LIVE schema, because those two disagree exactly when this matters. A
 * layout in the file but not on the host is the safe half of the window and is
 * reported separately as such.
 */
const phpNames = phpLayoutNames();

if (phpNames === null) {
  out(`  (cms/mu-plugins/vs-content-model.php not readable; skipped the reverse check)`);
} else {
  const known = new Set(entries.map(([, def]) => def.typeName));
  const unregistered = [];
  const notDeployed = [];

  for (const name of phpNames) {
    const typeName = typeNameFor(name);
    if (known.has(typeName)) continue;
    if (await typeExists(endpoint, typeName)) unregistered.push({ name, typeName });
    else notDeployed.push({ name, typeName });
  }

  if (unregistered.length) {
    out("Advisory — not a build failure, and not counted in the exit code:");
    for (const { name, typeName } of unregistered) {
      out(`  ${name} (${typeName}) is live on the CMS with no BLOCK_MANIFEST entry.`);
    }
    out("  A row using one renders as UnknownBlock: no error, no output, an invisible");
    out("  section on a published page. Add it to manifest.ts and registry.ts together.");
    out();
  }

  if (notDeployed.length) {
    out("Advisory — the checked-in PHP is ahead of this host:");
    for (const { name } of notDeployed) {
      out(`  ${name} is registered in vs-content-model.php but not on the CMS.`);
    }
    out("  Harmless: nothing queries it and no editor can author a row for it.");
    out("  It becomes live when somebody runs cms/bin/deploy-mu-plugins.sh.");
    out();
  }

  // The other direction of the same disagreement. Only reachable when the
  // fragment validated, so the layout is definitely on the host — meaning this
  // checkout's cms/ is behind, not that anything is broken.
  const phpTypes = new Set(phpNames.map(typeNameFor));
  const missingFromPhp = entries
    .filter(([, def]) => !phpTypes.has(def.typeName))
    .filter(([key]) => !failures.some((f) => f.key === key));

  if (missingFromPhp.length) {
    out("Advisory — the CMS is ahead of the checked-in PHP:");
    for (const [, def] of missingFromPhp) {
      out(`  ${def.typeName} validates live but vs-content-model.php does not register it.`);
    }
    out("  Usually a stale cms/ checkout rather than a fault. Worth reconciling before");
    out("  the next mu-plugin deploy overwrites the host with the older file.");
    out();
  }
}

if (failures.length || combined.errors.length) process.exit(EXIT_MISMATCH);

out(`All ${entries.length} layouts match the deployed schema.`);
process.exit(EXIT_OK);
