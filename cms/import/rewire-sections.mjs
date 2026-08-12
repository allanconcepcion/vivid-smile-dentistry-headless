/**
 * Point section prose in the Astro templates at WordPress.
 *
 *   node cms/import/rewire-sections.mjs [--dry]
 *
 * For each section captured in sections-payload.json, replaces the eyebrow,
 * heading and lead paragraph in the template with a lookup against the
 * WordPress-backed content.
 *
 * SAFETY: replacement is by EXACT SOURCE SUBSTRING, recorded at extraction
 * time, scoped to the matching `<section id="…">` block, and only applied when
 * that substring occurs exactly once inside the block. Anything ambiguous is
 * skipped and reported. Rewriting marketing copy with a near-miss regex is the
 * failure mode worth engineering against here — a gap is recoverable, a wrong
 * replacement is a silent content change nobody notices until a client does.
 *
 * Headings keep <em> accents, so they render through <Fragment set:html>.
 * Eyebrow and body are plain text and render as normal expressions.
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

/** Locate the source range of `<section … id="ID" …> … </section>`. */
function sectionRange(src, id) {
  const open = new RegExp(`<section\\b[^>]*\\bid="${id.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")}"[^>]*>`);
  const m = open.exec(src);
  if (!m) return null;

  const start = m.index + m[0].length;
  const end = src.indexOf("</section>", start);
  return end === -1 ? null : { start, end };
}

const { pages } = JSON.parse(await readFile(path.join(HERE, "sections-payload.json"), "utf8"));

let replaced = 0;
let filesTouched = 0;
const skipped = [];

for (const page of pages) {
  if (!page.sections?.length) continue;

  const file = await resolveFile(page.route);
  if (!file) {
    skipped.push(`${page.route}: no template found`);
    continue;
  }

  let src = await readFile(file, "utf8");
  const original = src;
  let pageReplacements = 0;

  for (const section of page.sections) {
    const raw = section.raw ?? {};
    const id = section.section_id;

    const swaps = [
      { field: "eyebrow", source: raw.eyebrow, expr: `{section(${JSON.stringify(id)}).eyebrow}` },
      {
        field: "heading",
        source: raw.heading,
        expr: `<Fragment set:html={section(${JSON.stringify(id)}).heading} />`,
      },
      { field: "body", source: raw.body, expr: `{section(${JSON.stringify(id)}).body}` },
    ];

    for (const { field, source, expr } of swaps) {
      if (!source) continue;

      const range = sectionRange(src, id);
      if (!range) {
        skipped.push(`${page.route} #${id}: section block not found`);
        break;
      }

      const block = src.slice(range.start, range.end);
      const first = block.indexOf(source);

      if (first === -1) {
        skipped.push(`${page.route} #${id} ${field}: source text not found`);
        continue;
      }
      if (block.indexOf(source, first + source.length) !== -1) {
        skipped.push(`${page.route} #${id} ${field}: text appears more than once — skipped`);
        continue;
      }

      src =
        src.slice(0, range.start + first) +
        expr +
        src.slice(range.start + first + source.length);

      pageReplacements++;
      replaced++;
    }
  }

  if (src === original) continue;

  // Make `section` available. Pages rewired earlier already call
  // getPageContent; extend that destructure rather than adding a second call.
  if (/const \{([^}]*)\} = await getPageContent\(/.test(src)) {
    src = src.replace(/const \{([^}]*)\} = await getPageContent\(/, (m0, names) =>
      names.includes("section")
        ? m0
        : `const {${names.replace(/\s*$/, "")}, section } = await getPageContent(`,
    );
  } else {
    const fmEnd = src.indexOf("\n---", 3);
    const depth = path.relative(path.dirname(file), PAGES_DIR) || ".";
    const importPath = path.join(depth, "../lib/page-content").split(path.sep).join("/");
    const rel = importPath.startsWith(".") ? importPath : "./" + importPath;

    src =
      src.slice(0, fmEnd) +
      `\n// Section copy is edited in WordPress (Pages -> this page -> Section copy).\n` +
      `const { section } = await getPageContent(Astro.url.pathname);\n` +
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
  console.log(`  ${DRY ? "would rewire" : "rewired"}  ${page.route}  (${pageReplacements} swaps)`);
}

console.log();
console.log(`${DRY ? "Would replace" : "Replaced"}: ${replaced} strings across ${filesTouched} files`);

if (skipped.length) {
  console.log(`\nSkipped (${skipped.length}) — these stay as literal copy in the template:`);
  for (const s of skipped.slice(0, 30)) console.log(`  ${s}`);
  if (skipped.length > 30) console.log(`  … and ${skipped.length - 30} more`);
}
