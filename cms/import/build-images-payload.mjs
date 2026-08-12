/**
 * Map every page image import to the file it points at.
 *
 *   VS_PAGES_DIR=/tmp/vs-orig/vivid-smiles-website/src/pages \
 *     node cms/import/build-images-payload.mjs
 *
 * Each page's frontmatter imports its images as ES modules:
 *
 *   import heroImg from '../../assets/images/people/real/woman.webp';
 *
 * The import NAME becomes the slot an editor swaps in WordPress, and the path
 * identifies the file to upload to the media library. The name is already
 * meaningful (heroImg, whatImg, naturalImg), so it doubles as a usable label
 * without inventing a second naming scheme.
 *
 * Alt text is read from the <Image alt="…"> tag using the variable, so the
 * media library entry starts with the words the site already ships rather than
 * an empty alt field somebody has to backfill.
 */

import { readFile, writeFile, readdir, access } from "node:fs/promises";
import { fileURLToPath } from "node:url";
import path from "node:path";

const HERE = path.dirname(fileURLToPath(import.meta.url));
const SITE = path.resolve(HERE, "../../vivid-smiles-website");
const PAGES_DIR = process.env.VS_PAGES_DIR || path.join(SITE, "src/pages");
const ASSETS_DIR = path.join(SITE, "src/assets");
const OUT = path.join(HERE, "images-payload.json");

const SKIP_ROUTES = new Set(["/design-system/", "/404/", "/blog/"]);

async function* walk(dir) {
  for (const entry of await readdir(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) yield* walk(full);
    else if (entry.name.endsWith(".astro")) yield full;
  }
}

function routeFor(file) {
  const rel = path.relative(PAGES_DIR, file);
  if (rel.includes("[")) return null;
  return "/" + rel.replace(/index\.astro$/, "").replace(/\.astro$/, "/");
}

const pages = [];
const allFiles = new Set();
const missing = [];

for await (const file of walk(PAGES_DIR)) {
  const route = routeFor(file);
  if (!route || SKIP_ROUTES.has(route)) continue;

  const src = await readFile(file, "utf8");
  const images = [];

  const importRe = /^import\s+([A-Za-z_$][\w$]*)\s+from\s+['"]([^'"]*assets\/images\/[^'"]+)['"];?/gm;

  for (const [, slot, ref] of src.matchAll(importRe)) {
    // Import paths are relative to the template. When reading from a pristine
    // checkout (VS_PAGES_DIR), that copy has no assets alongside it, so
    // re-anchor onto the real tree before resolving.
    const realTemplate = path.join(SITE, "src/pages", path.relative(PAGES_DIR, file));
    const abs = path.resolve(path.dirname(realTemplate), ref);

    try {
      await access(abs);
    } catch {
      missing.push(`${route}: ${ref}`);
      continue;
    }

    const rel = path.relative(ASSETS_DIR, abs);
    if (rel.startsWith("..")) {
      missing.push(`${route}: ${ref} is outside src/assets`);
      continue;
    }

    // Alt text from the first <Image> (or <img>) that uses this variable.
    const altRe = new RegExp(
      `<(?:Image|img)\\b[^>]*?\\bsrc=\\{${slot}\\}[^>]*?\\balt=["']([^"']*)["']`,
      "s",
    );
    const altReverse = new RegExp(
      `<(?:Image|img)\\b[^>]*?\\balt=["']([^"']*)["'][^>]*?\\bsrc=\\{${slot}\\}`,
      "s",
    );
    const alt = (altRe.exec(src)?.[1] ?? altReverse.exec(src)?.[1] ?? "").trim();

    images.push({ slot, rel, alt });
    allFiles.add(rel);
  }

  if (images.length) pages.push({ route, images });
}

pages.sort((a, b) => a.route.localeCompare(b.route));

await writeFile(
  OUT,
  JSON.stringify({ pages, files: [...allFiles].sort() }, null, 2),
);

const total = pages.reduce((n, p) => n + p.images.length, 0);
const withAlt = pages.reduce((n, p) => n + p.images.filter((i) => i.alt).length, 0);

console.log(`Wrote ${path.relative(process.cwd(), OUT)}`);
console.log(`  pages with images: ${pages.length}`);
console.log(`  image slots:       ${total}`);
console.log(`  unique files:      ${allFiles.size}`);
console.log(`  slots with alt:    ${withAlt}/${total}`);

if (missing.length) {
  console.log(`\n  unresolved (${missing.length}):`);
  for (const m of missing.slice(0, 15)) console.log(`    ${m}`);
}
