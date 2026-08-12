/**
 * Point page images at the WordPress media library.
 *
 *   node cms/import/rewire-images.mjs [--dry]
 *
 * For every image slot in images-payload.json:
 *   <Image src={heroImg} …/>   ->   <Image src={image("heroImg").url}
 *                                          width={image("heroImg").width}
 *                                          height={image("heroImg").height} …/>
 * and removes the now-unused `import heroImg from '…'` line.
 *
 * WHY THE TAG HAS TO CHANGE
 *
 * Swapping only the import — pointing the same variable at an object shaped
 * like ImageMetadata whose src is a remote URL — looks equivalent and is not.
 * Astro treats such an object as a LOCAL asset and prefixes the base path,
 * emitting src="/http://host/…". Verified against a real build before writing
 * this. A remote source needs a URL string plus explicit width and height.
 *
 * width/height are only added when the tag does not already set them, so a tag
 * with deliberate dimensions keeps them.
 *
 * An import is removed only when its variable no longer appears anywhere else
 * in the file — some are passed to components rather than rendered directly.
 */

import { readFile, writeFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";
import path from "node:path";

const HERE = path.dirname(fileURLToPath(import.meta.url));
const SITE = path.resolve(HERE, "../../vivid-smiles-website");
const PAGES_DIR = path.join(SITE, "src/pages");
const DRY = process.argv.includes("--dry");

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

/** Find the full source span of the tag containing `index`. */
function tagSpan(src, index) {
  const open = src.lastIndexOf("<", index);
  if (open === -1) return null;

  let i = open;
  let inStr = null;
  let braces = 0;

  for (; i < src.length; i++) {
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

const { pages } = JSON.parse(await readFile(path.join(HERE, "images-payload.json"), "utf8"));

let rewired = 0;
let importsRemoved = 0;
let filesTouched = 0;
const skipped = [];

for (const page of pages) {
  const file = await resolveFile(page.route);
  if (!file) {
    skipped.push(`${page.route}: no template`);
    continue;
  }

  let src = await readFile(file, "utf8");
  const original = src;

  for (const { slot } of page.images) {
    const ref = `src={${slot}}`;
    let guard = 0;

    while (src.includes(ref) && guard++ < 50) {
      const at = src.indexOf(ref);
      const span = tagSpan(src, at);

      if (!span) {
        skipped.push(`${page.route} ${slot}: could not delimit the tag`);
        break;
      }

      const tag = src.slice(span.start, span.end);
      const hasW = /\bwidth=/.test(tag);
      const hasH = /\bheight=/.test(tag);

      const parts = [`src={image(${JSON.stringify(slot)}).url}`];
      if (!hasW) parts.push(`width={image(${JSON.stringify(slot)}).width}`);
      if (!hasH) parts.push(`height={image(${JSON.stringify(slot)}).height}`);

      const newTag = tag.replace(ref, parts.join(" "));
      src = src.slice(0, span.start) + newTag + src.slice(span.end);
      rewired++;
    }
  }

  // Drop imports whose variable is now unreferenced.
  for (const { slot } of page.images) {
    const importLine = new RegExp(
      `^import\\s+${slot}\\s+from\\s+['"][^'"]*assets/images/[^'"]+['"];?\\r?\\n`,
      "m",
    );
    if (!importLine.test(src)) continue;

    const withoutImport = src.replace(importLine, "");
    const stillUsed = new RegExp(`\\b${slot}\\b`).test(withoutImport);

    if (!stillUsed) {
      src = withoutImport;
      importsRemoved++;
    } else {
      skipped.push(`${page.route} ${slot}: import kept — still referenced elsewhere`);
    }
  }

  if (src === original) continue;

  // Ensure `image` is destructured from getPageContent.
  if (/const \{([^}]*)\} = await getPageContent\(/.test(src)) {
    src = src.replace(/const \{([^}]*)\} = await getPageContent\(/, (m0, names) =>
      /\bimage\b/.test(names) ? m0 : `const {${names.replace(/\s*$/, "")}, image } = await getPageContent(`,
    );
  } else {
    const fmEnd = src.indexOf("\n---", 3);
    const depth = path.relative(path.dirname(file), PAGES_DIR) || ".";
    const importPath = path.join(depth, "../lib/page-content").split(path.sep).join("/");
    const rel = importPath.startsWith(".") ? importPath : "./" + importPath;

    src =
      src.slice(0, fmEnd) +
      `\n// Images are managed in WordPress (Pages -> this page -> Images).\n` +
      `const { image } = await getPageContent(Astro.url.pathname);\n` +
      src.slice(fmEnd);

    const imports = [...src.matchAll(/^import .*;$/gm)];
    const line = `import { getPageContent } from '${rel}';`;
    if (imports.length) {
      const last = imports[imports.length - 1];
      const at = last.index + last[0].length;
      src = src.slice(0, at) + "\n" + line + src.slice(at);
    }
  }

  if (!DRY) await writeFile(file, src);
  filesTouched++;
  console.log(`  ${DRY ? "would rewire" : "rewired"}  ${page.route}`);
}

console.log();
console.log(`${DRY ? "Would rewire" : "Rewired"}: ${rewired} tags, ${importsRemoved} imports removed, ${filesTouched} files`);

if (skipped.length) {
  console.log(`\nNotes (${skipped.length}):`);
  for (const s of skipped.slice(0, 25)) console.log(`  ${s}`);
  if (skipped.length > 25) console.log(`  … and ${skipped.length - 25} more`);
}
