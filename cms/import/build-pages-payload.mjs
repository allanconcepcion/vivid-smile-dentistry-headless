/**
 * Stage 1 of the page migration: Astro page frontmatter → an import payload.
 *
 *   node cms/import/build-pages-payload.mjs
 *
 * The 35 pages under src/pages already keep their editable content as
 * structured arrays in frontmatter — `tocLinks`, `processSteps`, `faqs` — plus
 * the title/description passed to BaseLayout. This lifts those out so
 * import-pages.php can create matching WordPress pages.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO
 *
 * It does not attempt to extract the prose sitting inline in each page's
 * markup. That copy is woven through bespoke per-page layouts; pulling it into
 * generic fields would either flatten the design or produce a field set nobody
 * can edit safely. The arrays below are the parts that are genuinely
 * data-shaped, and they are what an editor actually changes.
 *
 * Frontmatter is parsed by evaluating the object literals in a locked-down
 * context rather than by regex — these are real JS arrays with nested quotes,
 * apostrophes and template strings, and a regex would mangle them.
 */

import { readFile, writeFile, readdir, writeFile as _w } from "node:fs/promises";
import { fileURLToPath } from "node:url";
import path from "node:path";
import vm from "node:vm";

const HERE = path.dirname(fileURLToPath(import.meta.url));
const SITE = path.resolve(HERE, "../../vivid-smiles-website");
// Overridable so the payload can be regenerated from a pristine checkout.
// Once rewire-pages.mjs has run, the arrays are gone from the working tree and
// a plain re-run would produce an EMPTY payload — which then re-imports over
// good WordPress content with nothing. Point this at a git worktree of the
// pre-migration commit to rebuild the payload:
//   VS_PAGES_DIR=/tmp/orig/src/pages node cms/import/build-pages-payload.mjs
const PAGES_DIR = process.env.VS_PAGES_DIR || path.join(SITE, "src/pages");
const OUT = path.join(HERE, "pages-payload.json");

/** Arrays worth lifting into WordPress, mapped to their ACF field names. */
const ARRAY_FIELDS = {
  tocLinks: "toc_links",
  processSteps: "process_steps",
  faqs: "faqs",
};

/** Routes that are not editable marketing pages. */
const SKIP = new Set([
  "/design-system/",
  "/404/",
  "/blog/", // hub, rendered from the blog collection
]);

async function* walk(dir) {
  for (const entry of await readdir(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) yield* walk(full);
    else if (entry.name.endsWith(".astro")) yield full;
  }
}

function routeFor(file) {
  const rel = path.relative(PAGES_DIR, file);
  if (rel.includes("[")) return null; // dynamic route, not a page
  return "/" + rel.replace(/index\.astro$/, "").replace(/\.astro$/, "/");
}

function frontmatter(source) {
  if (!source.startsWith("---")) return "";
  const end = source.indexOf("\n---", 3);
  return end === -1 ? "" : source.slice(3, end);
}

/**
 * Evaluate a single `const <name> = [...]` declaration and return its value.
 *
 * Runs in a fresh VM context with no globals, and only ever receives an array
 * literal extracted from the repo's own source. Returns null if the expression
 * references anything outside itself (a helper call, an imported image), which
 * is the signal that the array is computed rather than authored content.
 */
function evalArray(fm, name) {
  const start = fm.search(new RegExp(`(^|\\n)\\s*const\\s+${name}\\s*(:[^=]+)?=\\s*\\[`));
  if (start === -1) return null;

  const open = fm.indexOf("[", start);
  let depth = 0;
  let inStr = null;
  let end = -1;

  for (let i = open; i < fm.length; i++) {
    const ch = fm[i];
    const prev = fm[i - 1];

    if (inStr) {
      if (ch === inStr && prev !== "\\") inStr = null;
      continue;
    }
    if (ch === '"' || ch === "'" || ch === "`") { inStr = ch; continue; }
    if (ch === "[") depth++;
    else if (ch === "]") {
      depth--;
      if (depth === 0) { end = i; break; }
    }
  }

  if (end === -1) return null;

  try {
    const value = vm.runInNewContext(`(${fm.slice(open, end + 1)})`, Object.create(null), {
      timeout: 1000,
    });
    return Array.isArray(value) ? value : null;
  } catch {
    // References an import or helper — computed, not authored content.
    return null;
  }
}

/** Pull the title/description passed to <BaseLayout> or <LandingLayout>. */
function layoutProp(source, prop) {
  const re = new RegExp(`${prop}=(?:"([^"]*)"|\\{\`([^\`]*)\`\\}|\\{"([^"]*)"\\})`);
  const m = re.exec(source);
  return m ? (m[1] ?? m[2] ?? m[3] ?? "").trim() : "";
}

const pages = [];
const skipped = [];

for await (const file of walk(PAGES_DIR)) {
  const route = routeFor(file);
  if (!route || SKIP.has(route)) {
    if (route) skipped.push(route);
    continue;
  }

  const source = await readFile(file, "utf8");
  const fm = frontmatter(source);

  const fields = {};
  for (const [constName, fieldName] of Object.entries(ARRAY_FIELDS)) {
    const value = evalArray(fm, constName);
    if (value?.length) fields[fieldName] = value;
  }

  // Slug: last non-empty path segment; the homepage becomes "home".
  const segments = route.split("/").filter(Boolean);
  const slug = segments.length ? segments[segments.length - 1] : "home";

  pages.push({
    route,
    slug,
    // Title shown in wp-admin. The SEO title lives in the seoTitle field.
    title: titleFor(route, source),
    seoTitle: layoutProp(source, "title"),
    seoDescription: layoutProp(source, "description"),
    fields,
    counts: Object.fromEntries(Object.entries(fields).map(([k, v]) => [k, v.length])),
  });
}

function titleFor(route, source) {
  const seo = layoutProp(source, "title");
  if (seo) {
    // "Porcelain Veneers in Parker, CO | Vivid Smiles Dentistry" -> the first part
    return seo.split("|")[0].split("—")[0].trim();
  }
  const segments = route.split("/").filter(Boolean);
  const last = segments[segments.length - 1] ?? "home";
  return last.replace(/-/g, " ").replace(/\b\w/g, (c) => c.toUpperCase());
}

pages.sort((a, b) => a.route.localeCompare(b.route));

// Guard against the failure above: refuse to replace a payload that has real
// content with one that has none. Losing the extracted arrays is silent and
// only shows up later as blank sections on the live site.
try {
  const prev = JSON.parse(await readFile(OUT, "utf8"));
  const prevRows = (prev.pages ?? []).reduce(
    (n, p) => n + Object.values(p.fields ?? {}).reduce((m, v) => m + v.length, 0), 0);
  const nextRows = pages.reduce(
    (n, p) => n + Object.values(p.fields ?? {}).reduce((m, v) => m + v.length, 0), 0);

  if (prevRows > 0 && nextRows < prevRows) {
    console.error(
      `\nRefusing to overwrite ${path.basename(OUT)}: it currently holds ${prevRows} rows ` +
      `and this run extracted only ${nextRows}.\n` +
      `The page templates have probably already been rewired, so the arrays are no longer ` +
      `in the working tree. Regenerate from a pre-migration checkout with VS_PAGES_DIR.`);
    process.exit(1);
  }
} catch (error) {
  if (error.code !== "ENOENT") throw error;
}

await writeFile(OUT, JSON.stringify({ pages }, null, 2));

const withFields = pages.filter((p) => Object.keys(p.fields).length);
const totals = pages.reduce(
  (acc, p) => {
    for (const [k, v] of Object.entries(p.counts)) acc[k] = (acc[k] ?? 0) + v;
    return acc;
  },
  {},
);

console.log(`Wrote ${path.relative(process.cwd(), OUT)}`);
console.log(`  pages:              ${pages.length}`);
console.log(`  with structured content: ${withFields.length}`);
console.log(`  skipped routes:     ${skipped.join(", ") || "none"}`);
console.log(`  totals:             ${JSON.stringify(totals)}`);
console.log();
console.log("  pages WITHOUT structured content (copy stays in Astro):");
for (const p of pages.filter((p) => !Object.keys(p.fields).length)) {
  console.log(`    ${p.route}`);
}
