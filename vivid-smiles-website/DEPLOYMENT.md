# Deployment

**The output is static. The build is not.** Every page is pre-rendered HTML with no
backend, no database, and no server-rendered routes, so the *deployed artifact* can
be served from anywhere that serves static files. But the build itself reads all
content from WordPress over WPGraphQL, so **the build machine must be able to reach
a live WordPress install and must have `WP_GRAPHQL_ENDPOINT` set.** Visitors never
touch WordPress; the build machine always does.

That distinction matters when picking a host or a CI runner: "static site" does not
mean "builds anywhere". A runner with no network route to the CMS fails the build
outright rather than publishing a thinned-out site.

**Hosting, the Vercel project, and the deploy runbook live in
[../docs/DEPLOYING.md](../docs/DEPLOYING.md).** That is the authoritative reference
for the CMS hostname, the Vercel project settings, the environment variable, the CDN
warm-up step, and build-time failure modes. This file covers what is specific to
this directory: the build inputs, the redirect and header rules, DNS, and the
third-party tags.

## Build

| Item | Value |
|---|---|
| Node version | `engines` requires `>=22.12.0` |
| Install (local) | `npm ci` |
| Install (deployed) | `cd vivid-smiles-website && npm install` — set in [../vercel.json](../vercel.json) |
| Build | `npm run build` |
| Output directory | `dist/` (declared to Vercel as `vivid-smiles-website/dist`) |
| Environment variables | `WP_GRAPHQL_ENDPOINT` — required |

`npm run build` is not a bare `astro build`. npm runs the `prebuild` script first,
so the real chain is `scripts/warm-media-cache.mjs` then `astro build`. The warm-up
pre-fetches every media file at concurrency 3 so Astro's own high-concurrency image
phase sees cache hits instead of Cloudflare bot challenges. It is deliberately
non-fatal. [../docs/DEPLOYING.md](../docs/DEPLOYING.md) explains why it exists and
what breaks without it.

`npm run check` type-checks and then builds. It is the command to run before
shipping. Because it ends in a real build, it also needs `WP_GRAPHQL_ENDPOINT` and a
reachable CMS.

**Node is not effectively pinned for the deployed build.** `.nvmrc` in this
directory says `22`, but Vercel reads `.nvmrc` from the *repository root* only, and
the root has none. `vercel.json` sets no `nodeVersion` either, so the deployed build
takes whatever the Vercel project dashboard is set to. Locally, `.nvmrc` works as
expected. If a build starts failing on syntax or an engine warning, check the
dashboard's Node setting against the `>=22.12.0` requirement first.

**Without `WP_GRAPHQL_ENDPOINT` the build fails immediately**, at the first content
loader, with a message naming the fix. That is intentional: a quiet failure would
publish a blog hub with zero posts and a sitemap missing every URL while reporting a
successful deploy. Copy `.env.example` to `.env` for local work; set it in the
Vercel project for deployed builds.

## Redirects and headers

`public/_redirects` and `public/_headers` use the Netlify and Cloudflare format.
Astro copies both into `dist/` at build time. **Vercel does not read either file.**

`../vercel.json` is generated from them and is what actually applies on the deployed
site. It is committed, because Vercel reads `vercel.json` from the repository before
any build step could produce it. Regenerate after editing either source file, or the
deployed behaviour will not change:

```bash
npm run vercel:config
```

The generator writes to the repository root, not this directory — see
[../docs/DEPLOYING.md](../docs/DEPLOYING.md) for why the config has to live there.

`_redirects` holds **65 rules** covering legacy URLs from the previous WordPress
site — the same 65 now present in `vercel.json`. They carry real search traffic, so
they must be preserved in some form on any host. `_headers` sets HSTS,
`X-Frame-Options`, `X-Content-Type-Options`, and a referrer policy sitewide, plus a
one-year immutable cache and `Accept-Ranges` on hashed assets under `/_assets/*`.
CSP is deliberately omitted: GTM injects further tags at runtime, so an enforcing
policy would break analytics and need upkeep on every new marketing tag.

Both source files are still copied into `dist/` and are therefore publicly readable
at `/_redirects` and `/_headers` on the deployed site. Harmless but untidy, and not
yet addressed.

Moving to a host that reads `_redirects`/`_headers` natively would work without
`vercel.json`; moving to any other host means reimplementing the rules there.

The build also produces `dist/404.html` from `src/pages/404.astro`. Point the host's
not-found handling at it.

## DNS

The domain is registered at GoDaddy. DNS is currently hosted at Cloudflare, on
nameservers `beth.ns.cloudflare.com` and `dan.ns.cloudflare.com`.

**Email runs on Google Workspace through this same DNS zone.** The MX record
(`smtp.google.com`), the SPF record (`v=spf1 include:_spf.google.com -all`), and the
`_dmarc` TXT record must be carried over unchanged by any DNS migration. There are
also two verification TXT records, one for Google Search Console and one for Apple.
Website changes never require touching any of these.

A cutover to Vercel changes only the apex and `www` records. Because nothing else
moves, email is unaffected in both directions, which is also what makes a rollback
safe — revert those two records to the previous host's values. The full cutover
runbook, including the Cloudflare proxy setting and the pre-flight TTL reduction, is
in [VERCEL-DEPLOYMENT-NOTES.md](VERCEL-DEPLOYMENT-NOTES.md); the shorter
post-migration checklist is in [../docs/DEPLOYING.md](../docs/DEPLOYING.md).

## Third-party tags

These load in the browser and need no server-side keys. Account access is handled
separately from this repository.

| Service | Identifier | Where |
|---|---|---|
| Google Tag Manager | `GTM-W5FBTHCQ` | `src/components/Analytics.astro`, mounted by both layouts |
| Microsoft Clarity | project `vkjzesavnp` | Same component |
| Ahrefs Web Analytics | key `8FEXSNF1PEBHArYCsPXwZQ`, loaded by the GTM container | Do not also add it to the page. It used to be hardcoded in `Analytics.astro` as well, which double-loaded `analytics.js`; GTM is now the single owner |
| Google Ads conversions | Configured inside GTM | Fires on form submission |
| Typeform | Embedded forms and popups | `Analytics.astro` listens for Typeform's submit message and pushes a `typeform_submit` event into the GTM data layer. Conversion triggers depend on that event |
| WhatConverts call tracking | profile `162233`, served from `s.ksrndkehqnwntyxlhgto.com` | `src/layouts/BaseLayout.astro` and `src/layouts/LandingLayout.astro` — **not** in `Analytics.astro`, and not managed through GTM. **Account ownership unconfirmed — see below** |
| Bing Webmaster Tools | `public/BingSiteAuth.xml` | Static verification file |

**The WhatConverts tag rewrites the phone number a visitor sees.** It is hardcoded
into both layouts, so it is sitewide, and it swaps the displayed number at runtime
based on visitor source in order to attribute calls to a marketing channel. The
vendor is identified in the layout comments and disclosed by name in
`src/pages/privacy-policy/index.astro`.

What is **not** confirmed is whether the practice owns or authorised the WhatConverts
account behind profile `162233`. It was inherited with the site rather than set up
during this build, and no confirmation from the practice is on record. Treat that as
an open item, not a settled fact. It matters more than a normal analytics tag would:
a tracking profile nobody controls can stop rewriting the number, or rewrite it to
one that no longer rings the practice, and the failure is invisible in the markup.
Confirm the account before the domain cutover, and remove the tag from both layouts
if it turns out to be unclaimed.

## Notes

- `astro.config.mjs` sets the canonical URL (`https://vividsmilesdentistry.com`) and
  forces trailing slashes on every route. `vercel.json` sets `trailingSlash: true`
  to match. If those two ever disagree, every URL gets a redirect chain and a
  duplicate-content signal.
- **The shipped sitemap comes from Yoast, not from `@astrojs/sitemap`.** The
  `src/integrations/yoast-sitemap.ts` integration fetches the CMS sitemap index and
  its children at build time, rewrites the CMS origin to this site's origin, and
  overwrites the generated output. `@astrojs/sitemap` stays in the chain only as the
  fallback for when the CMS is unreachable. Its `filter` in `astro.config.mjs`
  excludes six exact paths — `/design-system/`, the three paid landing pages,
  `/thank-you/`, and `/404/` — and the integration keeps its own matching allowlist
  in `NOT_EXPECTED_IN_SITEMAP`. The two lists are identical and maintained
  separately; changing one without the other produces spurious build warnings. All
  six pages are also noindex in markup.
- Every sitemap URL is verified against a page the build actually produced. A URL
  with no built page fails the build rather than shipping a sitemap full of 404s.
- Asset filenames are rewritten in the Vite config so Astro's default `@_@`
  separator becomes `_`. The raw `@` causes an extra redirect round-trip on some
  static hosts, on a render-blocking stylesheet.
- `/cosmetic-dentistry-lp/`, `/veneers-lp/`, and `/general-lp/` are advertising
  landing pages. They use `LandingLayout`, which mounts no global nav or footer and
  **defaults `noindex` to true**, so they cannot compete with the organic service
  pages they mirror. That default is load-bearing: passing an explicit
  `noindex={false}` from page data would quietly make all three indexable.
