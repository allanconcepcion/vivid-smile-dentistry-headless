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

const APP = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
/** Repository root — vercel.json must live here, not in the app directory. */
const REPO = path.resolve(APP, "..");
/** The app's path relative to the repository root. */
const APP_DIR = path.basename(APP);

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
  readFile(path.join(APP, "public/_headers"), "utf8"),
  readFile(path.join(APP, "public/_redirects"), "utf8"),
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
  // This is a monorepo: the Astro app lives in a subdirectory, and the
  // repository root has no package.json. Vercel's Git integration builds from
  // the project's Root Directory, which is "." and cannot be changed from the
  // CLI — so the build is pointed into the app from here instead. Without this
  // every push-triggered deploy fails before it starts.
  installCommand: `cd ${APP_DIR} && npm install`,
  buildCommand: `cd ${APP_DIR} && npm run build`,
  outputDirectory: `${APP_DIR}/dist`,
  // Matches Astro's `trailingSlash: 'always'`. Without it Vercel and Astro
  // disagree about the canonical form of every URL, which produces redirect
  // chains and duplicate-content signals.
  trailingSlash: true,
  headers,
  redirects,
};

await writeFile(path.join(REPO, "vercel.json"), JSON.stringify(config, null, 2) + "\n", "utf8");

console.log("Wrote vercel.json (repository root)");
console.log(`  header groups: ${headers.length}`);
console.log(`  redirects:     ${redirects.length}`);
