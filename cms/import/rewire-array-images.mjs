/**
 * Point images held inside frontmatter arrays at WordPress.
 *
 *   node cms/import/rewire-array-images.mjs [--dry]
 *
 * rewire-images.mjs handled images rendered directly as `<Image src={heroImg}>`.
 * The rest live in arrays that get mapped over:
 *
 *   const trustBar = [{ logo: logoYomi, alt: "Yomi Robotics", … }];
 *   …
 *   {trustBar.map((b) => <Image src={b.logo} alt={b.alt} />)}
 *
 * Two coordinated edits, and both must land or the page breaks:
 *
 *   frontmatter:  logo: logoYomi   ->  logo: image("logoYomi")
 *   markup:       src={b.logo}     ->  src={b.logo.url}
 *                                      width={b.logo.width} height={b.logo.height}
 *
 * WHY THIS RESOLVES THE MAP PARAMETER INSTEAD OF MATCHING THE KEY
 *
 * A first attempt rewrote every `src={x.<key>}` for a converted key. The keys
 * are generic — one array uses `src` — so it also matched `src={tile.src}` in
 * the smile gallery, which comes from getSmiles() and has nothing to do with
 * WordPress. That shipped `tile.src.url` on an ImageMetadata object: undefined,
 * and a failed build.
 *
 * So each render site is now tied back to the array it actually iterates:
 * find `<array>.map((<param>`, and only rewrite `src={<param>.<key>}` for that
 * specific parameter name. Arrays derived by spreading (`[...a, ...a]`) are
 * followed one level, which is how the marquee tracks are built.
 */

import { readFile, writeFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";
import path from "node:path";

const HERE = path.dirname(fileURLToPath(import.meta.url));
const SITE = path.resolve(HERE, "../../vivid-smiles-website");
const PAGES_DIR = path.join(SITE, "src/pages");
const DRY = process.argv.includes("--dry");

const esc = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");

async function resolveFile(route) {
  const segments = route.split("/").filter(Boolean);
  const candidates = segments.length
    ? [path.join(PAGES_DIR, ...segments) + ".astro", path.join(PAGES_DIR, ...segments, "index.astro")]
    : [path.join(PAGES_DIR, "index.astro")];

  for (const c of candidates) {
    try {
      await readFile(c, "utf8");
      return c;
    } catch {
      /* next */
    }
  }
  return null;
}

/** Source span of `const <name> = [ … ];`, bracket-aware and string-aware. */
function arraySpan(src, name) {
  const decl = new RegExp(`(^|\\n)\\s*const\\s+${esc(name)}\\s*(?::[^=]+)?=\\s*\\[`);
  const m = decl.exec(src);
  if (!m) return null;

  let i = src.indexOf("[", m.index);
  const start = i;
  let depth = 0;
  let inStr = null;

  for (; i < src.length; i++) {
    const ch = src[i];
    const prev = src[i - 1];
    if (inStr) {
      if (ch === inStr && prev !== "\\") inStr = null;
      continue;
    }
    if (ch === '"' || ch === "'" || ch === "`") { inStr = ch; continue; }
    if (ch === "[") depth++;
    else if (ch === "]" && --depth === 0) return { start, end: i + 1 };
  }
  return null;
}

/** Array names that are `[...other]` spreads of `name`, one level deep. */
function derivedFrom(src, name) {
  const out = [];
  const re = new RegExp(`const\\s+(\\w+)\\s*=\\s*\\[[^\\]]*\\.\\.\\.${esc(name)}\\b[^\\]]*\\]`, "g");
  for (const m of src.matchAll(re)) out.push(m[1]);
  return out;
}

/** Map-callback parameter names used to iterate `arrayName`. */
function mapParams(src, arrayName) {
  const params = new Set();
  const re = new RegExp(`\\b${esc(arrayName)}\\s*\\.map\\s*\\(\\s*\\(?\\s*(\\w+)`, "g");
  for (const m of src.matchAll(re)) params.add(m[1]);
  return params;
}

const { pages } = JSON.parse(await readFile(path.join(HERE, "images-payload.json"), "utf8"));

let bindings = 0;
let renders = 0;
let importsRemoved = 0;
let filesTouched = 0;
const skipped = [];

for (const page of pages) {
  const file = await resolveFile(page.route);
  if (!file) continue;

  let src = await readFile(file, "utf8");
  const original = src;

  const slots = page.images.map((i) => i.slot);

  // Which array holds each slot, and under which key.
  const groups = new Map(); // arrayName -> { key, slots[] }

  for (const slot of slots) {
    const bind = new RegExp(`(\\w+):\\s*${esc(slot)}\\b`).exec(src);
    if (!bind) continue;

    const key = bind[1];

    const owner = [...src.matchAll(/(^|\n)\s*const\s+(\w+)\s*(?::[^=]+)?=\s*\[/g)]
      .map((m) => m[2])
      .find((name) => {
        const span = arraySpan(src, name);
        return span && bind.index > span.start && bind.index < span.end;
      });

    if (!owner) {
      skipped.push(`${page.route} ${slot}: not inside a recognisable array literal`);
      continue;
    }

    const entry = groups.get(owner) ?? { key, slots: [] };
    if (entry.key !== key) {
      skipped.push(`${page.route} ${slot}: array ${owner} mixes keys (${entry.key}, ${key})`);
      continue;
    }
    entry.slots.push(slot);
    groups.set(owner, entry);
  }

  // Several arrays can feed ONE render site: /services/ merges three arrays
  // into `allServices` and maps that once. Converting them array-by-array made
  // the first one rewrite the shared site, after which the others found no site
  // to match and were skipped — leaving mixed shapes in one list and an
  // undefined image at build. So group by RENDER SITE and convert every array
  // that feeds it together.
  const sites = new Map(); // "param.key" -> { param, key, slots:Set }

  for (const [arrayName, { key, slots: groupSlots }] of groups) {
    const params = new Set(mapParams(src, arrayName));
    for (const derived of derivedFrom(src, arrayName)) {
      for (const p of mapParams(src, derived)) params.add(p);
    }

    const matching = [...params].filter((p) =>
      new RegExp(`src=\\{${esc(p)}\\.${esc(key)}\\}`).test(src),
    );

    if (!matching.length) {
      for (const slot of groupSlots) {
        skipped.push(`${page.route} ${slot}: no src={<param>.${key}} for ${arrayName} — left as a local import`);
      }
      continue;
    }

    for (const param of matching) {
      const id = `${param}.${key}`;
      const entry = sites.get(id) ?? { param, key, slots: new Set() };
      for (const slot of groupSlots) entry.slots.add(slot);
      sites.set(id, entry);
    }
  }

  for (const { param, key, slots: siteSlots } of sites.values()) {
    for (const slot of siteSlots) {
      src = src.replace(
        new RegExp(`(\\w+):\\s*${esc(slot)}\\b`, "g"),
        `$1: image(${JSON.stringify(slot)})`,
      );
      bindings++;
    }

    src = src.replace(new RegExp(`src=\\{${esc(param)}\\.${esc(key)}\\}`, "g"), () => {
      renders++;
      return (
        `src={${param}.${key}.url} ` +
        `width={${param}.${key}.width} ` +
        `height={${param}.${key}.height}`
      );
    });
  }

  for (const slot of slots) {
    const importLine = new RegExp(
      `^import\\s+${esc(slot)}\\s+from\\s+['"][^'"]*assets/images/[^'"]+['"];?\\r?\\n`,
      "m",
    );
    if (!importLine.test(src)) continue;

    const without = src.replace(importLine, "");
    // Ignore occurrences inside image("slot") — the slot name lives on as a
    // string argument, which made every import look "still referenced" and left
    // 204 dead imports behind, each still pulling its local file through the
    // asset pipeline.
    const withoutLookups = without.replace(/image\(\s*"[^"]*"\s*\)/g, "image()");
    if (!new RegExp(`\\b${esc(slot)}\\b`).test(withoutLookups)) {
      src = without;
      importsRemoved++;
    }
  }

  if (src === original) continue;

  if (!/const \{([^}]*)\} = await getPageContent\(/.test(src)) {
    skipped.push(`${page.route}: no getPageContent call to extend — FILE SKIPPED`);
    continue;
  }

  src = src.replace(/const \{([^}]*)\} = await getPageContent\(/, (m0, names) =>
    /\bimage\b/.test(names) ? m0 : `const {${names.replace(/\s*$/, "")}, image } = await getPageContent(`,
  );

  // The array literals now call image(), so the lookup has to exist above them.
  const callRe = /\n?(?:\/\/[^\n]*\n)*const \{[^}]*\} = await getPageContent\(Astro\.url\.pathname\);\n/;
  const call = callRe.exec(src);

  if (call) {
    const without = src.replace(callRe, "\n");
    const imports = [...without.matchAll(/^import .*;$/gm)];
    if (imports.length) {
      const last = imports[imports.length - 1];
      const at = last.index + last[0].length;
      src = without.slice(0, at) + "\n" + call[0].trim() + "\n" + without.slice(at);
    }
  }

  if (!DRY) await writeFile(file, src);
  filesTouched++;
  console.log(`  ${DRY ? "would rewire" : "rewired"}  ${page.route}`);
}

console.log();
console.log(
  `${DRY ? "Would rewire" : "Rewired"}: ${bindings} bindings, ${renders} render sites, ` +
    `${importsRemoved} imports removed, ${filesTouched} files`,
);

if (skipped.length) {
  console.log(`\nLeft as local imports (${skipped.length}):`);
  for (const s of skipped.slice(0, 20)) console.log(`  ${s}`);
  if (skipped.length > 20) console.log(`  … and ${skipped.length - 20} more`);
}
