/**
 * Extract section prose and the remaining frontmatter arrays from the ORIGINAL
 * page templates, for the "everything editable" pass.
 *
 *   VS_PAGES_DIR=/tmp/vs-orig/vivid-smiles-website/src/pages \
 *     node cms/import/build-sections-payload.mjs
 *
 * Two kinds of content are pulled out:
 *
 * 1. SECTIONS — for each `<section id="…">` in the markup, the eyebrow,
 *    heading and lead paragraph. Keyed by the section's own id, which is
 *    already stable (the table-of-contents anchors depend on it).
 *
 * 2. CARDS — the frontmatter arrays that build-pages-payload.mjs did not take:
 *    whyCards, services, keyStats, offerItems, alternatives, technology and
 *    friends. Their shapes differ per page, so each row is flattened onto a
 *    common { group, title, body, meta, href } and tagged with its source array
 *    name, which is what reassembles them on the Astro side.
 *
 * Extraction is conservative by design. A pattern it cannot read unambiguously
 * is REPORTED rather than guessed at — a wrong guess here silently rewrites
 * marketing copy, which is far worse than a gap the report tells you about.
 */

import { readFile, writeFile, readdir } from "node:fs/promises";
import { fileURLToPath } from "node:url";
import path from "node:path";
import vm from "node:vm";

const HERE = path.dirname(fileURLToPath(import.meta.url));
const SITE = path.resolve(HERE, "../../vivid-smiles-website");
const PAGES_DIR = process.env.VS_PAGES_DIR || path.join(SITE, "src/pages");
const OUT = path.join(HERE, "sections-payload.json");

/** Arrays already handled by build-pages-payload.mjs. */
const ALREADY_MIGRATED = new Set(["tocLinks", "processSteps", "faqs"]);

/** Arrays that are computed at build time, not authored copy. */
const COMPUTED = new Set(["galleryTrack", "galleryTiles", "reviewTrack", "trustTrack", "pickedReviews"]);

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

function splitFrontmatter(src) {
  if (!src.startsWith("---")) return { fm: "", markup: src };
  const end = src.indexOf("\n---", 3);
  if (end === -1) return { fm: "", markup: src };
  return { fm: src.slice(3, end), markup: src.slice(end + 4) };
}

/** Collapse markup to readable text, or return null if it holds an expression. */
function textOf(html) {
  if (html == null) return null;
  // A JSX expression means the copy is computed elsewhere; leave it alone.
  if (/\{[^}]*\}/.test(html.replace(/\{"\s*"\}/g, ""))) return null;

  const text = html
    .replace(/<br\s*\/?>/gi, " ")
    .replace(/<\/?(em|strong|b|i|span)\b[^>]*>/gi, "")
    .replace(/<[^>]+>/g, "")
    .replace(/&amp;/g, "&")
    .replace(/&nbsp;/g, " ")
    .replace(/&#8217;|&rsquo;/g, "’")
    .replace(/\s+/g, " ")
    .trim();

  return text || null;
}

/** Same, but keeps <em> so the accent styling survives a round-trip. */
function richTextOf(html) {
  if (html == null) return null;
  if (/\{[^}]*\}/.test(html.replace(/\{"\s*"\}/g, ""))) return null;

  const text = html
    .replace(/<br\s*\/?>/gi, " ")
    .replace(/<\/?(strong|b|i|span)\b[^>]*>/gi, "")
    .replace(/<(?!\/?em\b)[^>]+>/g, "")
    .replace(/&amp;/g, "&")
    .replace(/&nbsp;/g, " ")
    .replace(/\s+/g, " ")
    .trim();

  return text || null;
}

/** Evaluate a frontmatter array literal; null if it references anything external. */
function evalArray(fm, name) {
  const decl = new RegExp(`(^|\\n)\\s*const\\s+${name}\\s*(?::[^=]+)?=\\s*\\[`);
  const m = decl.exec(fm);
  if (!m) return null;

  let i = fm.indexOf("[", m.index);
  let depth = 0;
  let inStr = null;
  let end = -1;

  for (; i < fm.length; i++) {
    const ch = fm[i];
    const prev = fm[i - 1];
    if (inStr) {
      if (ch === inStr && prev !== "\\") inStr = null;
      continue;
    }
    if (ch === '"' || ch === "'" || ch === "`") { inStr = ch; continue; }
    if (ch === "[") depth++;
    else if (ch === "]" && --depth === 0) { end = i; break; }
  }
  if (end === -1) return null;

  try {
    return vm.runInNewContext(`(${fm.slice(fm.indexOf("[", m.index), end + 1)})`, Object.create(null), {
      timeout: 1000,
    });
  } catch {
    return null;
  }
}

/**
 * Flatten one row of an arbitrary card array onto the common shape.
 * Picks the most title-ish, body-ish and meta-ish key available.
 */
const TITLE_KEYS = ["title", "t", "name", "label", "heading", "q", "k", "term"];
const BODY_KEYS = ["body", "p", "copy", "text", "desc", "description", "a", "blurb", "d"];
const META_KEYS = ["meta", "value", "v", "stat", "num", "n", "price", "tag", "lbl", "eyebrow"];
const HREF_KEYS = ["href", "url", "link", "to"];

function flattenCard(row, group) {
  if (typeof row === "string") return { group, title: row, body: "", meta: "", href: "" };
  if (typeof row !== "object" || row === null) return null;

  const pick = (keys) => {
    for (const k of keys) {
      const v = row[k];
      if (typeof v === "string" && v.trim()) return v.trim();
      if (typeof v === "number") return String(v);
    }
    return "";
  };

  const card = {
    group,
    title: pick(TITLE_KEYS),
    body: pick(BODY_KEYS),
    meta: pick(META_KEYS),
    href: pick(HREF_KEYS),
  };

  return card.title || card.body ? card : null;
}

const pages = [];
const unreadable = [];

for await (const file of walk(PAGES_DIR)) {
  const route = routeFor(file);
  if (!route || SKIP_ROUTES.has(route)) continue;

  const src = await readFile(file, "utf8");
  const { fm, markup } = splitFrontmatter(src);

  /* ---- sections ------------------------------------------------------- */
  const sections = [];
  const sectionRe = /<section\b[^>]*\bid="([^"]+)"[^>]*>([\s\S]*?)<\/section>/g;

  for (const [, id, inner] of markup.matchAll(sectionRe)) {
    const rawEyebrow = /<span class="eyebrow"[^>]*>([\s\S]*?)<\/span>/.exec(inner)?.[1];
    const rawHeading = /<h2\b[^>]*>([\s\S]*?)<\/h2>/.exec(inner)?.[1];
    const rawBody = /<p\b[^>]*>([\s\S]*?)<\/p>/.exec(inner)?.[1];

    const eyebrow = textOf(rawEyebrow);
    const heading = richTextOf(rawHeading);
    const body = textOf(rawBody);

    if (eyebrow || heading || body) {
      sections.push({
        section_id: id,
        eyebrow: eyebrow ?? "",
        heading: heading ?? "",
        body: body ?? "",
        cta_label: "",
        cta_href: "",
        // Exact source substrings, so the rewiring step can do a literal
        // replacement instead of re-deriving a pattern and risking a wrong hit.
        raw: {
          eyebrow: eyebrow ? rawEyebrow : null,
          heading: heading ? rawHeading : null,
          body: body ? rawBody : null,
        },
      });
    }
  }

  /* ---- remaining frontmatter arrays ----------------------------------- */
  const cards = [];
  const arrayNames = [...fm.matchAll(/^\s*const\s+([A-Za-z_][A-Za-z0-9_]*)\s*(?::[^=]+)?=\s*\[/gm)].map(
    (m) => m[1],
  );

  for (const name of arrayNames) {
    if (ALREADY_MIGRATED.has(name) || COMPUTED.has(name)) continue;

    const rows = evalArray(fm, name);
    if (!Array.isArray(rows) || !rows.length) {
      unreadable.push(`${route}: ${name} (computed or unparseable)`);
      continue;
    }

    for (const row of rows) {
      const card = flattenCard(row, name);
      if (card) cards.push(card);
      else unreadable.push(`${route}: ${name} row shape not recognised`);
    }
  }

  if (sections.length || cards.length) {
    pages.push({ route, sections, cards });
  }
}

pages.sort((a, b) => a.route.localeCompare(b.route));
await writeFile(OUT, JSON.stringify({ pages }, null, 2));

const totalSections = pages.reduce((n, p) => n + p.sections.length, 0);
const totalCards = pages.reduce((n, p) => n + p.cards.length, 0);
const groups = new Set(pages.flatMap((p) => p.cards.map((c) => c.group)));

console.log(`Wrote ${path.relative(process.cwd(), OUT)}`);
console.log(`  pages:      ${pages.length}`);
console.log(`  sections:   ${totalSections}`);
console.log(`  cards:      ${totalCards}  across ${groups.size} groups`);
console.log(`  groups:     ${[...groups].sort().join(", ")}`);

if (unreadable.length) {
  console.log(`\n  not extracted (${unreadable.length}) — these stay in the templates:`);
  for (const u of unreadable.slice(0, 25)) console.log(`    ${u}`);
  if (unreadable.length > 25) console.log(`    … and ${unreadable.length - 25} more`);
}
