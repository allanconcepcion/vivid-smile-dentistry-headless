// @ts-check
import { defineConfig } from 'astro/config';
import sitemap from '@astrojs/sitemap';
import yoastSitemap from './src/integrations/yoast-sitemap';

// https://astro.build/config
export default defineConfig({
  site: 'https://vividsmilesdentistry.com',
  trailingSlash: 'always',
  integrations: [
    sitemap({
      // Keep crawler focus on real public pages. The design-system route is
      // noindex'd in markup; placeholder service stubs and legal pages don't
      // need to show up in search.
      filter(page) {
        // Stubs and
        // legal pages stay out of the sitemap until they're built out.
        const excludeExact = [
          '/design-system/',
          // Paid-traffic landing pages — noindex'd in markup and kept out
          // of the sitemap so they don't compete with their organic hub.
          '/cosmetic-dentistry-lp/',
          '/veneers-lp/',
          '/general-lp/',
          // Typeform-redirect destination — noindex'd in markup, not an
          // entry point. Conversion tracking is wired upstream on the ad
          // platforms; the URL doesn't need to be discoverable.
          '/thank-you/',
          // Custom 404 — noindex'd in markup, served by the host.
          // Astro likely excludes it by default; explicit > implicit and
          // matches the sibling destination-page exclusions above.
          '/404/',
        ];
        // Exact pathname match (not endsWith) so a future nested route like
        // /<service>/thank-you/ can't be silently excluded by a parent slug.
        const path = new URL(page).pathname;
        return !excludeExact.includes(path);
      },
    }),
    // Replaces the generated sitemap with Yoast's, rewritten to this origin and
    // verified against the pages the build produced. @astrojs/sitemap stays in
    // the chain as the fallback for when the CMS is unreachable.
    yoastSitemap(),
  ],
  build: {
    assets: '_assets',
  },
  image: {
    // Blog hero images live in the WordPress media library and reach the build
    // as absolute URLs. Authorizing the CMS host here is what lets Astro
    // download each one at build time, run it through sharp, and emit a hashed
    // file into _assets/ — so the deployed HTML references this site's own
    // origin, never WordPress. Without this, <Image> passes the CMS URL through
    // untouched: no webp, no srcset, and a public dependency on the CMS host.
    //
    // Scoped to the uploads path rather than the whole host, so a stray URL in
    // post content cannot turn into a build-time fetch.
    remotePatterns: [
      { protocol: 'http', hostname: 'localhost', port: '8888', pathname: '/wp-content/uploads/**' },
      { protocol: 'https', hostname: 'cms.vividsmilesdentistry.com', pathname: '/wp-content/uploads/**' },
    ],
  },
  vite: {
    build: {
      rollupOptions: {
        output: {
          // Astro emits page-scoped CSS as `<page>@_@astro.<hash>.css`. The raw
          // `@` in the filename triggers a 307 → `%40` redirect on some static
          // asset hosts (one extra round-trip per cold load on a
          // render-blocking stylesheet). Replace `@_@` with `_` so the URL is
          // safe end-to-end.
          assetFileNames(info) {
            const name = info.name ?? '';
            const stem = name.replace(/\.[^.]+$/, '').replace(/@_@/g, '_');
            return `_assets/${stem}.[hash][extname]`;
          },
        },
      },
    },
  },
});
