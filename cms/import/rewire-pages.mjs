/**
 * Stage 3 of the page migration: point the Astro templates at WordPress.
 *
 *   node cms/import/rewire-pages.mjs [--dry]
 *
 * For each route in pages-payload.json, removes the frontmatter arrays that are
 * now stored in WordPress and replaces them with a single getPageContent()
 * lookup. The markup below the frontmatter is untouched — the arrays keep their
 * exact shapes, so every `.map()` over them still works.
 *
 * Only removes a const when the payload actually contains that field for that
 * route, so a page whose array was computed rather than authored (and therefore
 * never imported) keeps its local definition.
 *
 * Idempotent: a page already containing getPageContent is skipped.
 */

import { readFile, writeFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";
import path from "node:path";

const HERE = path.dirname(fileURLToPath(import.meta.url));
const SITE = path.resolve(HERE, "../../vivid-smiles-website");
const PAGES_DIR = path.join(SITE, "src/pages");
const DRY = process.argv.includes("--dry");

/** payload field name -> the const identifier in the Astro frontmatter */
const FIELD_TO_CONST = {
  toc_links: "tocLinks",
  process_steps: "processSteps",
  faqs: "faqs",
};

/** Route -> the .astro file that serves it. */
function fileForRoute(route) {
  const segments = route.split("/").filter(Boolean);
  if (!segments.length) return path.join(PAGES_DIR, "index.astro");

  const nested = path.join(PAGES_DIR, ...segments) + ".astro";
  const indexed = path.join(PAGES_DIR, ...segments, "index.astro");
  return { nested, indexed };
}

async function resolveFile(route) {
  const candidates = fileForRoute(route);
  const list = typeof candidates === "string" ? [candidates] : [candidates.nested, candidates.indexed];

  for (const c of list) {
    try {
      await readFile(c, "utf8");
      return c;
    } catch {
      /* try next */
    }
  }
  return null;
}

/**
 * Remove `const <name> = [ ... ];` from `src`, respecting nested brackets and
 * strings so an apostrophe or a bracket inside copy cannot truncate the match.
 */
function stripConst(src, name) {
  const decl = new RegExp(`(^|\\n)const ${name}\\s*(?::[^=]+)?=\\s*\\[`);
  const m = decl.exec(src);
  if (!m) return null;

  const start = m.index + (m[1] === "" ? 0 : 1);
  let i = src.indexOf("[", m.index);
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
    else if (ch === "]" && --depth === 0) break;
  }

  if (depth !== 0) return null;

  let end = src.indexOf(";", i);
  if (end === -1) return null;
  end++;
  while (src[end] === "\n") end++;

  return src.slice(0, start) + src.slice(end);
}

const { pages } = JSON.parse(
  await readFile(path.join(HERE, "pages-payload.json"), "utf8"),
);

let rewired = 0;
let skipped = 0;
const problems = [];

for (const page of pages) {
  const fields = Object.keys(page.fields ?? {});
  if (!fields.length) continue;

  const file = await resolveFile(page.route);
  if (!file) {
    problems.push(`${page.route}: no .astro file found`);
    continue;
  }

  let src = await readFile(file, "utf8");

  if (src.includes("getPageContent")) {
    skipped++;
    continue;
  }

  const names = fields.map((f) => FIELD_TO_CONST[f]).filter(Boolean);
  const removed = [];

  for (const name of names) {
    const next = stripConst(src, name);
    if (next) {
      src = next;
      removed.push(name);
    }
  }

  if (!removed.length) {
    problems.push(`${page.route}: none of [${names.join(", ")}] found as a const`);
    continue;
  }

  // Insert the lookup as the last statement of the frontmatter, so it sits
  // after any imports it might otherwise be hoisted above.
  const fmEnd = src.indexOf("\n---", 3);
  if (fmEnd === -1) {
    problems.push(`${page.route}: no frontmatter fence`);
    continue;
  }

  const depth = path.relative(path.dirname(file), PAGES_DIR) || ".";
  const importPath = path.join(depth, "../lib/page-content").split(path.sep).join("/");
  const importLine = `import { getPageContent } from '${importPath.startsWith(".") ? importPath : "./" + importPath}';`;

  const lookup =
    `\n// ${removed.join(", ")} ${removed.length > 1 ? "are" : "is"} edited in WordPress ` +
    `(Pages -> ${page.title}).\n` +
    `// The shapes are unchanged from the arrays that used to live here, so the\n` +
    `// markup below is untouched.\n` +
    `const { ${removed.join(", ")} } = await getPageContent(Astro.url.pathname);\n`;

  src = src.slice(0, fmEnd) + lookup + src.slice(fmEnd);

  // Put the import after the last existing import line.
  const importMatches = [...src.matchAll(/^import .*;$/gm)];
  if (importMatches.length) {
    const last = importMatches[importMatches.length - 1];
    const at = last.index + last[0].length;
    src = src.slice(0, at) + "\n" + importLine + src.slice(at);
  } else {
    src = src.replace("---", `---\n${importLine}`);
  }

  if (!DRY) await writeFile(file, src);
  rewired++;
  console.log(`  ${DRY ? "would rewire" : "rewired"}  ${page.route}  [${removed.join(", ")}]`);
}

console.log();
console.log(`${DRY ? "Would rewire" : "Rewired"}: ${rewired}`);
console.log(`Already done (skipped): ${skipped}`);
if (problems.length) {
  console.log(`\nNeeds manual attention (${problems.length}):`);
  for (const p of problems) console.log(`  ${p}`);
}
