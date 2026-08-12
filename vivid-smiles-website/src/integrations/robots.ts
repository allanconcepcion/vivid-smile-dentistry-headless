/**
 * Emit robots.txt from the same origin the rest of the build uses.
 *
 * robots.txt is per-host, and this project is served from more than one host:
 * the .vercel.app deployment today, the practice's domain after cutover. A
 * static file in public/ cannot tell them apart, so the preview origin was
 * shipping Allow: / together with Sitemap: lines pointing at a domain that is
 * not live yet — an invitation to index a second, identical copy of the site
 * and split its ranking signals.
 *
 * So the file is generated at build time from `site`, the same value
 * yoast-sitemap.ts rewrites <loc> entries onto. Only a real custom domain is
 * allowed into the index; every deployment origin is Disallow: /. The Sitemap:
 * lines are rebuilt on that origin, because a sitemap has to be served from the
 * host it lists.
 *
 * public/robots.txt stays as the fallback for when `site` is unset: Astro
 * copies it into dist first, and this overwrites it there.
 */

import type { AstroIntegration } from "astro";
import { writeFile } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";

/** Origins that must never be indexed, whatever else they serve. */
function isDeploymentHost(hostname: string): boolean {
  return (
    hostname.endsWith(".vercel.app") || hostname === "localhost" || hostname === "127.0.0.1"
  );
}

function allowAll(origin: string): string {
  return [
    "User-agent: *",
    "Allow: /",
    "",
    `Sitemap: ${origin}/sitemap-index.xml`,
    "",
    "# Same sitemap, also served at the URL WordPress used, so an older",
    "# submission or a stale reference keeps resolving.",
    `Sitemap: ${origin}/sitemap_index.xml`,
    "",
  ].join("\n");
}

function disallowAll(origin: string): string {
  return [
    `# ${origin} is a deployment origin, not the public site.`,
    "# Kept out of the index so it cannot compete with the live domain for the",
    "# same content. No Sitemap: line — nothing here is meant to be crawled.",
    "User-agent: *",
    "Disallow: /",
    "",
  ].join("\n");
}

export default function robotsTxt(): AstroIntegration {
  // Read from the resolved config rather than import.meta.env: an integration
  // runs in Astro's own Node context, outside the Vite transform that populates
  // import.meta.env for application code. Same reason as yoast-sitemap.ts.
  let site: string | undefined;

  return {
    name: "vs:robots-txt",
    hooks: {
      "astro:config:done": ({ config }) => {
        site = config.site;
      },
      "astro:build:done": async ({ dir, logger }) => {
        if (!site) {
          logger.warn("`site` is not configured in astro.config.mjs; keeping public/robots.txt");
          return;
        }

        const { origin, hostname } = new URL(site);
        const deployment = isDeploymentHost(hostname);
        const body = deployment ? disallowAll(origin) : allowAll(origin);

        await writeFile(path.join(fileURLToPath(dir), "robots.txt"), body, "utf8");
        logger.info(
          `robots.txt written for ${origin} — ${deployment ? "Disallow: /" : "Allow: /"}`
        );
      },
    },
  };
}
