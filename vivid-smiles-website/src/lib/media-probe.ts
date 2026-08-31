/**
 * Ask whether a WordPress media file is still really there.
 *
 * WHY THIS EXISTS
 *
 * Astro fetches every remote image it is asked to optimize. When the file has
 * been deleted from the Media Library — or the post's HTML still points at a
 * path an offload plugin has moved — that fetch 404s and the BUILD DIES. One
 * deleted picture in one old post takes down all 48 pages.
 *
 * The trap is that the failure is deferred. `getImage()` with an explicit width
 * and height does not fetch anything; it registers the asset and returns a
 * descriptor, and the fetch happens later in the build pipeline, outside any
 * try/catch at the call site. So catching around `getImage()` looks like it
 * handles this and does not.
 *
 * A cheap HEAD request before the asset is ever registered is what closes it.
 *
 * WHAT COUNTS AS GONE
 *
 * Only 404, 410 and 3xx. Everything else — 429 (the CMS sits behind Cloudflare,
 * which answers a burst with 429 and an HTML interstitial; see
 * scripts/warm-media-cache.mjs), 5xx, a refused HEAD, a timeout, a DNS blip —
 * returns "unknown" and the caller keeps the image. Demoting a real picture on
 * a transient network fault would be a silent visual regression on a live
 * client site, which is worse than the loud build failure this exists to
 * prevent.
 */

/** Generous: the CMS is rate-limited and a slow HEAD is not a missing file. */
export const MEDIA_PROBE_TIMEOUT_MS = 8_000;

export async function mediaFetchable(url: string): Promise<"ok" | "gone" | "unknown"> {
  try {
    const res = await fetch(url, {
      method: "HEAD",
      redirect: "manual",
      signal: AbortSignal.timeout(MEDIA_PROBE_TIMEOUT_MS),
    });

    if (res.ok) return "ok";
    if (res.status === 404 || res.status === 410) return "gone";
    // "manual" surfaces a redirect as an opaqueredirect response (status 0) in
    // some runtimes and as the 3xx itself in others. Treat both as fatal.
    if (res.type === "opaqueredirect" || (res.status >= 300 && res.status < 400)) return "gone";
    return "unknown";
  } catch {
    return "unknown";
  }
}
