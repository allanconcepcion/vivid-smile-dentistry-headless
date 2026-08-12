# Deployment

The site is a static build. There is no backend, no database, and no server-rendered routes, so it can be hosted anywhere that serves static files.

## Build

| Item | Value |
|---|---|
| Node version | 22.12 or newer (pinned in `.nvmrc`) |
| Install | `npm ci` |
| Build | `npm run build` |
| Output directory | `dist/` |
| Environment variables | None |

`npm run check` type-checks and then builds. It is the command to run before shipping.

Hosting, CI, and deploy configuration are not included in this repository. There are no GitHub Actions, no workflow files, no deploy hooks, and no host-specific config to carry over.

## Redirects and headers

`public/_redirects` and `public/_headers` use the Netlify and Cloudflare format. Astro copies both into `dist/` at build time. If the site is hosted somewhere that does not read these files, the rules need to be reimplemented on that platform.

`_redirects` holds about 65 rules covering legacy URLs from the previous WordPress site. They carry real search traffic, so they should be preserved in some form. `_headers` sets HSTS, `X-Frame-Options`, `X-Content-Type-Options`, a referrer policy, and a one-year immutable cache on hashed assets under `/_assets/*`.

The build also produces a `/404/` page. Point the host's not-found handling at it.

## DNS

The domain is registered at GoDaddy. DNS is currently hosted at Cloudflare, on nameservers `beth.ns.cloudflare.com` and `dan.ns.cloudflare.com`.

**Email runs on Google Workspace through this same DNS zone.** The MX record (`smtp.google.com`), the SPF record (`v=spf1 include:_spf.google.com -all`), and the `_dmarc` TXT record must be carried over unchanged by any DNS migration. There are also two verification TXT records, one for Google Search Console and one for Apple. Website changes never require touching any of these.

## Third-party tags

These load in the browser and need no server-side keys. Account access is handled separately from this repository.

| Service | Identifier | Where |
|---|---|---|
| Google Tag Manager | `GTM-W5FBTHCQ` | `src/components/Analytics.astro`, mounted by both layouts |
| Microsoft Clarity | project `vkjzesavnp` | Same component |
| Ahrefs Web Analytics | Loaded by the GTM container | Do not also add it to the page, or analytics loads twice |
| Google Ads conversions | Configured inside GTM | Fires on form submission |
| Typeform | Embedded forms and popups | `Analytics.astro` listens for Typeform's submit message and pushes a `typeform_submit` event into the GTM data layer. Conversion triggers depend on that event |
| Bing Webmaster Tools | `public/BingSiteAuth.xml` | Static verification file |

## Notes

- `astro.config.mjs` sets the canonical URL and forces trailing slashes on every route. The sitemap filter there keeps the design system route, the paid landing pages, the thank-you page, and the 404 page out of the sitemap. Those pages are also noindex in markup.
- Asset filenames are rewritten in the Vite config so Astro's default `@_@` separator becomes `_`. The raw `@` causes an extra redirect round-trip on some static hosts.
- `/cosmetic-dentistry-lp/`, `/veneers-lp/`, and `/general-lp/` are advertising landing pages. They use their own layout, have no navigation away from the page, and are noindex so they do not compete with the organic service pages.
