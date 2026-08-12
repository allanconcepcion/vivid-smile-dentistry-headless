/**
 * Serve the sitemap at WordPress's URL as well as Astro's.
 *
 * @astrojs/sitemap writes `sitemap-index.xml` (hyphen). WordPress and Yoast use
 * `sitemap_index.xml` (underscore), and that is the URL this practice's site has
 * been submitting to Search Console and Bing for years — it is in their crawl
 * history, in Search Console's sitemap list, and in any backlink or directory
 * entry that ever pointed at it.
 *
 * A redirect would work, but it depends on host-level config (_redirects on
 * Netlify/Cloudflare, vercel.json on Vercel) that is easy to lose in a platform
 * move. Emitting a real file makes the URL work on any static host with no
 * configuration at all.
 *
 * The alias is a sitemap INDEX pointing at the same child sitemaps, not a copy
 * of the URL list — so there is one place the URLs actually live, and the two
 * entry points can never drift.
 */

import type { AstroIntegration } from "astro";
import { readFile, writeFile, readdir } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";

export default function wordpressSitemapAlias(): AstroIntegration {
  return {
    name: "vs:wordpress-sitemap-alias",
    hooks: {
      "astro:build:done": async ({ dir, logger }) => {
        const outDir = fileURLToPath(dir);

        let source: string;
        try {
          source = await readFile(path.join(outDir, "sitemap-index.xml"), "utf8");
        } catch {
          // @astrojs/sitemap emits nothing when every page is filtered out.
          // Not an error worth failing a build over, but worth saying aloud —
          // silently shipping no sitemap is how a site stops getting crawled.
          logger.warn("sitemap-index.xml not found; skipping the sitemap_index.xml alias");
          return;
        }

        await writeFile(path.join(outDir, "sitemap_index.xml"), source, "utf8");

        const children = (await readdir(outDir)).filter((f) => /^sitemap-\d+\.xml$/.test(f));
        logger.info(
          `sitemap_index.xml written as an alias of sitemap-index.xml (${children.length} child sitemap(s))`,
        );
      },
    },
  };
}
