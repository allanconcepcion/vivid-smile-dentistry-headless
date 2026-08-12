# Vivid Smiles Dentistry

Marketing site for Vivid Smiles Dentistry, a cosmetic, implant, general, and emergency dental practice in Parker, Colorado. Built with [Astro](https://astro.build).

This is the front end of a headless pair. The WordPress CMS that supplies its content lives alongside it at [../cms/](../cms/README.md).

- [DEPLOYMENT.md](./DEPLOYMENT.md) — what the site itself needs: redirects, headers, DNS, and third-party tags.
- [../docs/DEPLOYING.md](../docs/DEPLOYING.md) — the deploy runbook: hosting WordPress, the Vercel project, environment variables, and the domain cutover.

## Stack

- **Astro 6**, static output, file-based routing
- **Vanilla CSS** with custom properties. No Tailwind. Design tokens live in `src/styles/tokens.css`
- **GSAP + ScrollTrigger** for scroll reveals and parallax, **Lenis** for smooth scrolling
- **WordPress via WPGraphQL**, queried at build time only. Blog posts, testimonials, page copy, images, navigation menus, and practice details are all edited in wp-admin and baked into static HTML. Nothing here ships a WordPress dependency to the browser — a visitor never contacts the CMS

## Getting started

Requires Node 22.12 or newer.

**The site cannot be built or served without a reachable WordPress.** All three content collections are fetched over WPGraphQL, and every page pulls the practice's phone number and hours from the same source, so `npm run dev` and `npm run build` both fail immediately without one. `src/lib/wp.ts` throws on every failure path rather than degrading: a network blip that yielded an empty `posts` array would publish a blog hub with zero posts and a sitemap missing every URL, and report a successful deploy.

Start the bundled WordPress (Docker required), then the site:

```bash
cd ../cms && npm install && npm start
```

```bash
npm install
cp .env.example .env
npm run dev        # http://localhost:4321
```

`WP_GRAPHQL_ENDPOINT` is the only environment variable this codebase reads. Without it the build stops with a named error before any page renders.

| Environment | Value |
|---|---|
| Local (`.env`) | `http://localhost:8888/graphql` |
| Hosted CMS, temporary hostname | `https://1230613.us28.myftpupload.com/graphql` |
| Production target, after the CMS moves | `https://cms.vividsmilesdentistry.com/graphql` |

On Vercel it is set in the project's environment variables, not in a file. Any host serving as an image source must also be listed under `image.remotePatterns` in `astro.config.mjs`, or every image fails the build.

| Script | What it does |
|---|---|
| `npm run dev` | Dev server at `http://localhost:4321` |
| `npm run build` | Build to `dist/`. Runs `prebuild` first |
| `npm run preview` | Serve the production build locally |
| `npm run check` | Type-check, then build |
| `npm run warm:media` | Request every WordPress media file once at low concurrency, warming the CDN. Wired as `prebuild`, so `npm run build` runs it automatically. The CMS sits behind Cloudflare in front of a rate-limited origin; Astro's high-concurrency image fetching triggers a 429 on a cold cache and fails the whole build on a single image. Failures here are never fatal |
| `npm run vercel:config` | Regenerate `vercel.json` at the repo root from `public/_headers` and `public/_redirects`. **Not** part of the build — run it by hand after editing either file, or the deployed headers and redirects will not change |

## Layout

- `src/pages/` — 35 route files. Trailing slashes are always on
- `src/layouts/BaseLayout.astro` — head, fonts, global scripts, shared nav and footer
- `src/layouts/LandingLayout.astro` — layout for the paid landing pages. Defaults `noindex` to true so they cannot compete with the organic pages they mirror
- `src/components/` — shared components, with `cards/` and `nav/` subfolders
- `src/styles/tokens.css` — the `--vs-*` custom properties
- `src/styles/pages/` — one stylesheet per page, namespaced under a page-level class
- `src/scripts/animations.js` — the GSAP animation library
- `src/loaders/` — the WPGraphQL content-layer loaders behind the three collections: `blog.ts`, `pages.ts`, `reviews.ts`
- `src/lib/` — the rest of the WordPress integration. `wp.ts` is the build-time GraphQL client (retries, backoff, 429 handling); `settings.ts`, `menus.ts`, `smiles.ts` and `page-content.ts` wrap the settings page, navigation menus, smile gallery and per-page content; `blog.ts`, `open-now.ts` and `wp-body-images.ts` are the helpers on top
- `src/integrations/yoast-sitemap.ts` — replaces the generated sitemap with Yoast's, rewritten to this origin. Every URL is checked against a page the build actually produced; a mismatch fails the build
- `src/content.config.ts` — declares three collections, `reviews`, `blog` and `pages`, all loaded from WordPress. No collection reads local files
- `src/content/` — the markdown that used to back those collections, 14 posts and 20 reviews. **Nothing in the Astro build reads it.** It remains as the input to the one-time CMS importers (`cms/import/build-blog-payload.mjs`, `cms/import/import-reviews.php`) and as the rollback copy. Editing a file here changes nothing on the site; edit the post in wp-admin instead
- `src/data/` — `contact.ts` and `hours.ts`. Still the import surface for the footer, contact page and structured data, but they are now thin adapters over WordPress → Practice Settings rather than the source. WordPress stores only the facts (which days, open and close times); every display string is derived here, so printed hours cannot contradict the `openingHoursSpecification` Google reads
- `scripts/` — build-adjacent Node scripts: `warm-media-cache.mjs` and `build-vercel-config.mjs`
- `public/` — served as-is: redirects, headers, robots.txt, favicons

`/design-system` renders every component, token, and type style on one page. It is noindex and excluded from the sitemap.

## What is editable where

| Change | Where |
|---|---|
| Blog posts, testimonials, page copy, FAQs, images, navigation, phone, hours, smile gallery | wp-admin. See [../cms/README.md](../cms/README.md) |
| Layout, components, styling, routes, animations | this repo |

Content edits go live on the next build, not on save.
