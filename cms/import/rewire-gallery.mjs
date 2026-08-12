/**
 * Add explicit dimensions to the smile-gallery <Image> tags.
 *
 *   node cms/import/rewire-gallery.mjs [--dry]
 *
 * getSmiles() now returns a WordPress media URL instead of an ImageMetadata
 * object, and <Image> requires width and height for a remote source. The tiles
 * keep `src={t.src}` — only the dimensions are added.
 *
 * Scoped by resolving the map parameter back to the gallery arrays, the same
 * way rewire-array-images.mjs does. Matching `src={x.src}` on key name alone
 * would also hit unrelated arrays that happen to use `src` as a key, which is
 * exactly the bug that broke the earlier attempt at the array images.
 */

import { readFile, writeFile, readdir } from "node:fs/promises";
import { fileURLToPath } from "node:url";
import path from "node:path";

const HERE = path.dirname(fileURLToPath(import.meta.url));
const SITE = path.resolve(HERE, "../../vivid-smiles-website");
const PAGES_DIR = path.join(SITE, "src/pages");
const DRY = process.argv.includes("--dry");

const esc = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");

async function* walk(dir) {
  for (const entry of await readdir(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) yield* walk(full);
    else if (entry.name.endsWith(".astro")) yield full;
  }
}

/** Array names that hold gallery tiles, following one level of spreading. */
function galleryArrays(src) {
  const names = new Set();

  for (const m of src.matchAll(/const\s+(\w+)\s*=\s*getSmiles\(\)/g)) names.add(m[1]);

  let grew = true;
  while (grew) {
    grew = false;
    for (const name of [...names]) {
      const re = new RegExp(`const\\s+(\\w+)\\s*=\\s*\\[[^\\]]*\\.\\.\\.${esc(name)}\\b[^\\]]*\\]`, "g");
      for (const m of src.matchAll(re)) {
        if (!names.has(m[1])) {
          names.add(m[1]);
          grew = true;
        }
      }
    }
  }

  return names;
}

function mapParams(src, arrayName) {
  const params = new Set();
  const re = new RegExp(`\\b${esc(arrayName)}\\s*\\.map\\s*\\(\\s*\\(?\\s*(\\w+)`, "g");
  for (const m of src.matchAll(re)) params.add(m[1]);
  return params;
}

/** Source span of the tag containing `index`, brace-aware. */
function tagSpan(src, index) {
  const open = src.lastIndexOf("<", index);
  if (open === -1) return null;

  let inStr = null;
  let braces = 0;

  for (let i = open; i < src.length; i++) {
    const ch = src[i];
    if (inStr) {
      if (ch === inStr) inStr = null;
      continue;
    }
    if (ch === '"' || ch === "'") { inStr = ch; continue; }
    if (ch === "{") braces++;
    else if (ch === "}") braces--;
    else if (ch === ">" && braces === 0) return { start: open, end: i + 1 };
  }
  return null;
}

let added = 0;
let filesTouched = 0;
const notes = [];

for await (const file of walk(PAGES_DIR)) {
  let src = await readFile(file, "utf8");
  if (!src.includes("getSmiles")) continue;

  const original = src;
  const params = new Set();

  for (const arrayName of galleryArrays(src)) {
    for (const p of mapParams(src, arrayName)) params.add(p);
  }

  if (!params.size) {
    notes.push(`${path.relative(SITE, file)}: getSmiles() present but no map parameter resolved`);
    continue;
  }

  for (const param of params) {
    const ref = `src={${param}.src}`;
    let guard = 0;

    while (src.includes(ref) && guard++ < 50) {
      const at = src.indexOf(ref);
      const span = tagSpan(src, at);
      if (!span) break;

      const tag = src.slice(span.start, span.end);
      const parts = [ref];
      if (!/\bwidth=/.test(tag)) parts.push(`width={${param}.width}`);
      if (!/\bheight=/.test(tag)) parts.push(`height={${param}.height}`);

      if (parts.length === 1) break; // already dimensioned

      const newTag = tag.replace(ref, parts.join(" "));
      src = src.slice(0, span.start) + newTag + src.slice(span.end);
      added++;
    }
  }

  if (src === original) continue;
  if (!DRY) await writeFile(file, src);
  filesTouched++;
  console.log(`  ${DRY ? "would fix" : "fixed"}  ${path.relative(SITE, file)}`);
}

console.log();
console.log(`${DRY ? "Would add" : "Added"} dimensions to ${added} gallery tags across ${filesTouched} files`);

if (notes.length) {
  console.log(`\nNotes (${notes.length}):`);
  for (const n of notes) console.log(`  ${n}`);
}
