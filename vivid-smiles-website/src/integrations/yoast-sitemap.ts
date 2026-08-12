/**
 * Serve Yoast's sitemap from the front end, with front-end URLs.
 *
 * The sitemap is authored in WordPress — editors control what is included via
 * Yoast, per post — but it cannot be SERVED from there. The CMS host is
 * noindexed and Disallow: /, and a sitemap has to sit on the same domain as the
 * URLs it lists. So the build fetches Yoast's index and its child sitemaps,
 * rewrites the CMS host to this site's origin, and writes them into dist.
 *
 * The rewrite happens here rather than in WordPress because Yoast validates
 * that every entry belongs to its own host and silently drops anything foreign
 * — a wpseo_sitemap_entry filter produces an empty sitemap. See
 * cms/mu-plugins/vs-sitemap.php.
 *
 * EVERY URL IS VERIFIED against a page that actually got built. WordPress can
 * publish things the front end has no route for, and a sitemap full of 404s
 * spends crawl budget and signals a broken site. A mismatch fails the build
 * rather than shipping quietly.
 */

import type { AstroIntegration } from "astro";
import { writeFile, readdir, stat } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { loadEnv } from "vite";

/** Paths that exist in the build but are legitimately absent from the sitemap. */
const NOT_EXPECTED_IN_SITEMAP = new Set([
  "/404/",
  "/thank-you/",
  "/design-system/",
  "/cosmetic-dentistry-lp/",
  "/veneers-lp/",
  "/general-lp/",
]);

function locs(xml: string): string[] {
  return [...xml.matchAll(/<loc>([^<]+)<\/loc>/g)].map((m) => m[1].trim());
}

/** Every route the build actually produced, as "/path/". */
async function builtRoutes(outDir: string): Promise<Set<string>> {
  const routes = new Set<string>();

  async function walk(dir: string): Promise<void> {
    for (const entry of await readdir(dir, { withFileTypes: true })) {
      const full = path.join(dir, entry.name);
      if (entry.isDirectory()) {
        await walk(full);
      } else if (entry.name === "index.html") {
        const rel = path.relative(outDir, path.dirname(full));
        routes.add(rel === "" ? "/" : `/${rel.split(path.sep).join("/")}/`);
      }
    }
  }

  await walk(outDir);
  return routes;
}

export default function yoastSitemap(options: { endpoint?: string } = {}): AstroIntegration {
  // `site` comes from the resolved config rather than import.meta.env: an
  // integration runs in Astro's own Node context, outside the Vite transform
  // that populates import.meta.env for application code. Reading it there
  // yields undefined and silently skips this whole step.
  let site: string | undefined;

  return {
    name: "vs:yoast-sitemap",
    hooks: {
      "astro:config:done": ({ config }) => {
        site = config.site;
      },
      "astro:build:done": async ({ dir, logger }) => {
        // Same reason: .env is not loaded into import.meta.env here, so read it
        // the way Vite would.
        const env = loadEnv(process.env.NODE_ENV ?? "production", process.cwd(), "");
        const graphql =
          options.endpoint ?? env.WP_GRAPHQL_ENDPOINT ?? process.env.WP_GRAPHQL_ENDPOINT;

        if (!graphql) {
          logger.warn("WP_GRAPHQL_ENDPOINT is unset; keeping the generated sitemap");
          return;
        }

        const cms = new URL(graphql).origin;

        if (!site) {
          logger.warn("`site` is not configured in astro.config.mjs; keeping the generated sitemap");
          return;
        }

        const outDir = fileURLToPath(dir);
        const origin = new URL(site).origin;

        const fetchXml = async (url: string): Promise<string> => {
          const res = await fetch(url);
          if (!res.ok) throw new Error(`${url} responded ${res.status} ${res.statusText}`);
          return res.text();
        };

        const rewrite = (xml: string): string =>
          xml
            .split(cms)
            .join(origin)
            // Yoast points the stylesheet at a plugin file that does not exist
            // on this origin; without it browsers render the raw XML, which is
            // what a crawler sees anyway.
            .replace(/<\?xml-stylesheet[^>]*\?>/g, "");

        let index: string;
        try {
          index = await fetchXml(`${cms}/sitemap_index.xml`);
        } catch (error) {
          // Keep the sitemap @astrojs/sitemap already wrote rather than leaving
          // the site with none at all.
          logger.warn(
            `Could not fetch Yoast's sitemap (${(error as Error).message}); keeping the generated one`,
          );
          return;
        }

        const children = locs(index);
        const written: string[] = [];
        const allUrls: string[] = [];

        for (const childUrl of children) {
          const name = childUrl.split("/").pop();
          if (!name) continue;

          const xml = await fetchXml(childUrl);
          allUrls.push(...locs(xml).map((u) => u.replace(cms, origin)));

          await writeFile(path.join(outDir, name), rewrite(xml), "utf8");
          written.push(name);
        }

        // Verify before replacing the index — if this throws, the previously
        // written sitemap-index.xml is still in place.
        const routes = await builtRoutes(outDir);
        const missing = allUrls.filter((u) => {
          const pathname = new URL(u).pathname;
          return !routes.has(pathname.endsWith("/") ? pathname : `${pathname}/`);
        });

        if (missing.length) {
          throw new Error(
            `Yoast's sitemap lists ${missing.length} URL(s) with no page in this build:\n` +
              missing.slice(0, 10).map((u) => `  ${u}`).join("\n") +
              (missing.length > 10 ? `\n  …and ${missing.length - 10} more` : "") +
              `\n\nEither the page is missing from the Astro site, or it should be excluded ` +
              `from the sitemap in WordPress (mark it noindex under Yoast's Advanced tab).`,
          );
        }

        await writeFile(path.join(outDir, "sitemap_index.xml"), rewrite(index), "utf8");
        // Keep the hyphenated name working too — it is what robots.txt and any
        // previous submission already reference.
        await writeFile(path.join(outDir, "sitemap-index.xml"), rewrite(index), "utf8");

        // @astrojs/sitemap's own child file is now orphaned; leaving it would
        // serve a second, stale URL list nobody maintains.
        try {
          const stale = path.join(outDir, "sitemap-0.xml");
          await stat(stale);
          await writeFile(stale, rewrite(index), "utf8");
        } catch {
          /* nothing to replace */
        }

        const unlisted = [...routes].filter(
          (r) => !NOT_EXPECTED_IN_SITEMAP.has(r) && !allUrls.some((u) => new URL(u).pathname === r),
        );

        logger.info(
          `Yoast sitemap: ${allUrls.length} URLs across ${written.length} child sitemap(s), all verified`,
        );

        if (unlisted.length) {
          logger.warn(
            `${unlisted.length} built page(s) are NOT in the sitemap: ${unlisted.join(", ")}`,
          );
        }
      },
    },
  };
}
