/**
 * Point each page's <title> and meta description at WordPress.
 *
 *   node cms/import/rewire-seo.mjs [--dry]
 *
 * Rewrites the layout props:
 *
 *   title="Porcelain Veneers in Parker, CO | Vivid Smiles Dentistry"
 *   ->
 *   title={seo.title || "Porcelain Veneers in Parker, CO | Vivid Smiles Dentistry"}
 *
 * The existing value is KEPT as the fallback rather than deleted. Three of the
 * 31 pages have no Yoast title stored, and an editor can clear a field at any
 * time — without the fallback that publishes a page with an empty <title>,
 * which is both an SEO regression and completely invisible in a page that
 * otherwise renders fine.
 *
 * noindex is passed through as well, so the per-page checkbox in WordPress
 * actually does something.
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

const { pages } = JSON.parse(await readFile(path.join(HERE, "pages-payload.json"), "utf8"));

let rewritten = 0;
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

  // Only the layout element's own props — not any title="…" deeper in the
  // markup (buttons, links, svg <title>).
  const layoutRe = /<(BaseLayout|LandingLayout)\b([\s\S]*?)>/;
  const layout = layoutRe.exec(src);

  if (!layout) {
    skipped.push(`${page.route}: no BaseLayout/LandingLayout element`);
    continue;
  }

  let props = layout[2];
  const before = props;

  props = props.replace(/(\s)title="([^"]*)"/, (_m, ws, value) => {
    rewritten++;
    return `${ws}title={seo.title || ${JSON.stringify(value)}}`;
  });

  props = props.replace(/(\s)description="([^"]*)"/, (_m, ws, value) => {
    rewritten++;
    return `${ws}description={seo.description || ${JSON.stringify(value)}}`;
  });

  if (props === before) {
    // Some templates pass a variable — title={pageTitle} — so the override has
    // to go on the variable's definition instead of the prop.
    let viaVar = false;

    for (const prop of ["title", "description"]) {
      const usage = new RegExp(`\\b${prop}=\\{(\\w+)\\}`).exec(before);
      if (!usage) continue;

      const varName = usage[1];
      const decl = new RegExp(`(const ${varName}\\s*=\\s*)("(?:[^"\\\\]|\\\\.)*"|\`(?:[^\`\\\\]|\\\\.)*\`)`);
      if (!decl.test(src)) continue;

      src = src.replace(decl, (_m, lhs, literal) => {
        rewritten++;
        viaVar = true;
        return `${lhs}seo.${prop} || ${literal}`;
      });
    }

    if (!viaVar) {
      skipped.push(`${page.route}: no literal title/description props to rewrite`);
      continue;
    }

    props = before;
  }

  // Carry the per-page noindex checkbox through — but ONLY for BaseLayout.
  //
  // LandingLayout DEFAULTS noindex to true: the paid landing pages are kept out
  // of search on purpose so they cannot compete with the organic hub they
  // mirror. Passing noindex={seo.noindex} there sends `false` (no per-post
  // checkbox is set) and overrides that default, quietly making all three
  // indexable. Leave the prop off and the safe default stands.
  if (layout[1] === "BaseLayout" && !/\bnoindex/.test(props)) {
    props = `${props} noindex={seo.noindex}`;
  }

  // Re-find the element before splicing. The variable-declaration branch above
  // edits `src` earlier in the file, which shifts every offset after it — using
  // the original match index here spliced at the wrong position and destroyed
  // the surrounding markup.
  const current = layoutRe.exec(src);
  if (!current) {
    skipped.push(`${page.route}: layout element vanished after edits — skipped`);
    continue;
  }

  src =
    src.slice(0, current.index) +
    `<${current[1]}${props === before ? current[2] : props}>` +
    src.slice(current.index + current[0].length);

  // Make `seo` available.
  if (/const \{([^}]*)\} = await getPageContent\(/.test(src)) {
    src = src.replace(/const \{([^}]*)\} = await getPageContent\(/, (m0, names) =>
      /\bseo\b/.test(names) ? m0 : `const {${names.replace(/\s*$/, "")}, seo } = await getPageContent(`,
    );
  } else {
    const fmEnd = src.indexOf("\n---", 3);
    const depth = path.relative(path.dirname(file), PAGES_DIR) || ".";
    const importPath = path.join(depth, "../lib/page-content").split(path.sep).join("/");
    const rel = importPath.startsWith(".") ? importPath : "./" + importPath;

    src =
      src.slice(0, fmEnd) +
      `\n// Meta title and description are edited in WordPress (Pages -> this page -> Yoast SEO).\n` +
      `const { seo } = await getPageContent(Astro.url.pathname);\n` +
      src.slice(fmEnd);

    const imports = [...src.matchAll(/^import .*;$/gm)];
    const line = `import { getPageContent } from '${rel}';`;
    if (imports.length) {
      const last = imports[imports.length - 1];
      const at = last.index + last[0].length;
      src = src.slice(0, at) + "\n" + line + src.slice(at);
    }
  }

  if (src === original) continue;

  // Hoist the lookup above everything that uses it. On the landing pages the
  // rewritten declaration (`const pageDescription = seo.description || …`) sits
  // ABOVE the getPageContent call, so `seo` was referenced before it existed —
  // a TDZ error at build time, not a silent one, but still a broken build.
  const callRe =
    /\n?(?:\/\/[^\n]*\n)*const \{[^}]*\} = await getPageContent\(Astro\.url\.pathname\);\n/;
  const call = callRe.exec(src);

  if (call) {
    const withoutCall = src.replace(callRe, "\n");
    const imports = [...withoutCall.matchAll(/^import .*;$/gm)];

    if (imports.length) {
      const last = imports[imports.length - 1];
      const at = last.index + last[0].length;
      src = withoutCall.slice(0, at) + "\n" + call[0].trim() + "\n" + withoutCall.slice(at);
    }
  }

  if (!DRY) await writeFile(file, src);
  filesTouched++;
  console.log(`  ${DRY ? "would rewire" : "rewired"}  ${page.route}`);
}

console.log();
console.log(`${DRY ? "Would rewrite" : "Rewrote"}: ${rewritten} props across ${filesTouched} files`);

if (skipped.length) {
  console.log(`\nSkipped (${skipped.length}):`);
  for (const s of skipped) console.log(`  ${s}`);
}
