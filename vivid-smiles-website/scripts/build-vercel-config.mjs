/**
 * Convert public/_headers and public/_redirects into vercel.json.
 *
 *   node scripts/build-vercel-config.mjs
 *
 * Those two files are Netlify/Cloudflare syntax. Vercel does not read either —
 * it ignores them silently, which is the worst way to be wrong: the files look
 * present and maintained while the site ships with no security headers, no
 * immutable asset caching, and 65 dead legacy redirects.
 *
 * Rather than hand-maintain the same rules twice, this regenerates vercel.json
 * from them. Run it whenever _headers or _redirects changes; the output is
 * committed because Vercel reads vercel.json from the repository, before any
 * build step could produce it.
 */

import { readFile, writeFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";
import path from "node:path";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");

/** Parse Netlify `_headers`: a path line, then indented "Name: value" lines. */
function parseHeaders(text) {
  const groups = [];
  let current = null;

  for (const raw of text.split("\n")) {
    const line = raw.replace(/\s+$/, "");
    if (!line.trim() || line.trim().startsWith("#")) continue;

    if (!/^\s/.test(line)) {
      current = { source: line.trim(), headers: [] };
      groups.push(current);
      continue;
    }

    const match = /^\s+([A-Za-z0-9-]+):\s*(.+)$/.exec(line);
    if (match && current) {
      current.headers.push({ key: match[1], value: match[2].trim() });
    }
  }

  return groups.filter((g) => g.headers.length);
}

/** Parse Netlify `_redirects`: "<from> <to> <status>", whitespace separated. */
function parseRedirects(text) {
  const rules = [];

  for (const raw of text.split("\n")) {
    const line = raw.trim();
    if (!line || line.startsWith("#")) continue;

    const parts = line.split(/\s+/);
    if (parts.length < 2) continue;

    const [source, destination, status] = parts;
    rules.push({
      source,
      destination,
      // Netlify defaults to 301 when the status is omitted.
      permanent: (status ?? "301") === "301",
    });
  }

  return rules;
}

/**
 * Netlify's `/*` means "every path". Vercel matches with path-to-regexp, where
 * the equivalent is `/(.*)`. A literal `/*` matches nothing there, which would
 * silently drop the site-wide security headers.
 */
function toVercelSource(source) {
  if (source === "/*") return "/(.*)";
  return source.replace(/\/\*$/, "/(.*)");
}

const [headersText, redirectsText] = await Promise.all([
  readFile(path.join(ROOT, "public/_headers"), "utf8"),
  readFile(path.join(ROOT, "public/_redirects"), "utf8"),
]);

const headers = parseHeaders(headersText).map((g) => ({
  source: toVercelSource(g.source),
  headers: g.headers,
}));

const redirects = parseRedirects(redirectsText).map((r) => ({
  ...r,
  source: toVercelSource(r.source),
}));

const config = {
  $schema: "https://openapi.vercel.sh/vercel.json",
  // Matches Astro's `trailingSlash: 'always'`. Without it Vercel and Astro
  // disagree about the canonical form of every URL, which produces redirect
  // chains and duplicate-content signals.
  trailingSlash: true,
  headers,
  redirects,
};

await writeFile(path.join(ROOT, "vercel.json"), JSON.stringify(config, null, 2) + "\n", "utf8");

console.log("Wrote vercel.json");
console.log(`  header groups: ${headers.length}`);
console.log(`  redirects:     ${redirects.length}`);
