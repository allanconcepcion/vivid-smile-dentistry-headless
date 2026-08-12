/**
 * Warm the CDN cache for every WordPress media file, before Astro fetches them.
 *
 *   node scripts/warm-media-cache.mjs
 *
 * Runs as a prebuild step. Without it the build fails on the hosted CMS.
 *
 * WHY THIS IS NEEDED
 *
 * Astro downloads every remote image at build time to optimize it, and it does
 * so with high concurrency. The CMS sits behind Cloudflare with a 31-day TTL,
 * so a cached file is served from the edge — but a MISS goes to the origin, and
 * the managed WordPress host rate-limits sustained origin traffic. A cold build
 * fires hundreds of simultaneous misses and gets a 429, which fails the whole
 * build on a single image.
 *
 * This pass requests each file ONCE, at low concurrency, retrying on 429 with
 * backoff. It is slower per request and far more reliable in aggregate: by the
 * time Astro runs, every URL is a cache HIT and the origin is never touched.
 *
 * It must run inside the build, not from a developer's machine: Cloudflare
 * caches per datacenter, so warming from one location does nothing for the
 * colo a CI build runs in.
 *
 * Failures here are NOT fatal. A file this cannot warm is one Astro will fetch
 * itself; the point is to remove the thundering herd, not to gate the build on
 * a media request.
 */

import { loadEnv } from "vite";

const env = loadEnv(process.env.NODE_ENV ?? "production", process.cwd(), "");
const endpoint = env.WP_GRAPHQL_ENDPOINT ?? process.env.WP_GRAPHQL_ENDPOINT;

if (!endpoint) {
  console.log("[warm-media] WP_GRAPHQL_ENDPOINT unset; skipping");
  process.exit(0);
}

/**
 * Requests in flight. Low on purpose.
 *
 * The constraint is not origin capacity — it is Cloudflare's bot protection,
 * which challenges a burst from one IP with 429 and an HTML interstitial. That
 * challenge then applies to the GraphQL requests the build makes AFTER this
 * step, so warming too fast fails the build somewhere unrelated.
 */
const CONCURRENCY = 3;
const MAX_ATTEMPTS = 5;

/** Pause after warming, to let any rate-limit window roll off before Astro starts. */
const SETTLE_MS = 3000;

const MEDIA_QUERY = /* GraphQL */ `
  query Media($first: Int!, $after: String) {
    mediaItems(first: $first, after: $after) {
      pageInfo {
        hasNextPage
        endCursor
      }
      nodes {
        sourceUrl
      }
    }
  }
`;

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function collectUrls() {
  const urls = [];
  let after = null;

  // WPGraphQL caps `first` at 100 regardless of what is asked for, so this
  // pages rather than requesting everything at once.
  for (let page = 0; page < 50; page++) {
    const res = await fetch(endpoint, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ query: MEDIA_QUERY, variables: { first: 100, after } }),
    });

    if (!res.ok) throw new Error(`GraphQL responded ${res.status}`);

    const json = await res.json();
    if (json.errors?.length) throw new Error(json.errors.map((e) => e.message).join("; "));

    const conn = json.data?.mediaItems;
    if (!conn) break;

    urls.push(...conn.nodes.map((n) => n.sourceUrl).filter(Boolean));

    if (!conn.pageInfo?.hasNextPage || !conn.pageInfo.endCursor) break;
    after = conn.pageInfo.endCursor;
  }

  return [...new Set(urls)];
}

async function warm(url) {
  for (let attempt = 1; attempt <= MAX_ATTEMPTS; attempt++) {
    try {
      // HEAD is not enough: Cloudflare stores the body on a GET, and a HEAD can
      // leave the object uncached, which defeats the whole exercise.
      const res = await fetch(url, { headers: { "User-Agent": "vivid-smiles-build/1.0" } });

      if (res.ok) {
        await res.arrayBuffer();
        return res.headers.get("cf-cache-status") ?? "OK";
      }

      if (res.status === 429 || res.status >= 500) {
        // Exponential backoff — the whole point is to stop hammering.
        await sleep(attempt * attempt * 1500);
        continue;
      }

      return `HTTP ${res.status}`;
    } catch {
      await sleep(attempt * 1500);
    }
  }

  return "FAILED";
}

let urls;
try {
  urls = await collectUrls();
} catch (error) {
  console.log(`[warm-media] could not list media (${error.message}); skipping`);
  process.exit(0);
}

if (!urls.length) {
  console.log("[warm-media] no media items; skipping");
  process.exit(0);
}

const started = Date.now();
const tally = new Map();
let index = 0;

async function worker() {
  while (index < urls.length) {
    const url = urls[index++];
    const status = await warm(url);
    tally.set(status, (tally.get(status) ?? 0) + 1);
  }
}

await Promise.all(Array.from({ length: CONCURRENCY }, worker));

const summary = [...tally.entries()].map(([k, v]) => `${k}=${v}`).join(" ");
const seconds = ((Date.now() - started) / 1000).toFixed(1);

console.log(`[warm-media] ${urls.length} files in ${seconds}s — ${summary}`);

await sleep(SETTLE_MS);

const failed = tally.get("FAILED") ?? 0;
if (failed) {
  // Not fatal: Astro will try these itself, and one unfetchable file should not
  // stop a deploy at the warming stage.
  console.log(`[warm-media] ${failed} file(s) could not be warmed; Astro will retry them`);
}
