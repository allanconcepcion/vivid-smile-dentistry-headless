# Vercel Deployment Notes

Working notes for the Vivid Smiles Dentistry site on Vercel.
Last updated: 2026-08-13. Status: deployed and auto-deploying from `main` to a temporary
`.vercel.app` URL, building against the hosted WordPress CMS. Redirects, security headers and
trailing-slash enforcement are live and verified. The real domain is NOT cut over.

**Which document is authoritative.** [../docs/DEPLOYING.md](../docs/DEPLOYING.md) is the runbook
for the deployment as a whole — hosting WordPress, creating the project, environment variables,
the deploy hook, and the domain cutover. This file is Vercel-specific working notes: current
project state, the open third-party issues, the Cloudflare DNS records that must not be touched,
and the build-failure history. Where the two overlap on the cutover procedure, DEPLOYING.md wins.
The DNS detail in section 6 exists only here.

---

## 1. What is deployed

| Item | Value |
| --- | --- |
| Repo | `allanconcepcion/vivid-smile-dentistry-headless` |
| Branch | `main`, the only branch |
| Vercel team | `allans-projects-cc55d7b7` — "Allan's projects", Hobby plan |
| Vercel project | `vivid-smiles-headless` |
| Root directory | `.` (the repository root) |
| Install command | `cd vivid-smiles-website && npm install` (from `vercel.json`) |
| Build command | `cd vivid-smiles-website && npm run build` (from `vercel.json`) |
| Output directory | `vivid-smiles-website/dist` (from `vercel.json`) |
| Environment variables | `WP_GRAPHQL_ENDPOINT` — Production, Preview and Development |
| Git integration | Connected. Pushes to `main` auto-deploy to Production. |

**The Root Directory is `.`, and that is load-bearing.** This is a monorepo: the Astro app lives in
`vivid-smiles-website/` and the repository root has no `package.json`. Root Directory cannot be
changed from the Vercel CLI, so the install, build and output paths are carried in the repo-root
[../vercel.json](../vercel.json) instead, each one pointing into the app directory. Anything that
resets those three commands to Vercel's framework defaults will fail the build immediately, because
Vercel will look for a `package.json` that is not there.

**A `vercel.json` inside `vivid-smiles-website/` would be ignored.** With Root Directory `.`, Vercel
reads only the repo-root file. This was found the hard way — see section 8.

**The only environment variable the build needs is `WP_GRAPHQL_ENDPOINT`.** It currently points at
`https://cms.vividsmilesdentistry.com/graphql`, the GoDaddy Managed WordPress hostname.
Without it the build fails at the first content loader rather than degrading quietly: `src/lib/wp.ts`
throws, so a missing variable can never publish a site with an empty blog and a gutted sitemap.
See [.env.example](.env.example) for the three known values. It is defined in **three**
environments, not two: Production, Preview and Development. Production and Preview are marked
Sensitive, so the dashboard will not display their values; only Development can be read back.
Verified in the dashboard on 2026-08-13.

**Node version is not pinned for Vercel.** `package.json` sets `engines.node` to `>=22.12.0` and
`.nvmrc` says `22`, but `.nvmrc` lives in `vivid-smiles-website/`, and Vercel reads `.nvmrc` from the
repository root only. There is no root `.nvmrc` and `vercel.json` sets no `nodeVersion`, so the build
takes whatever the project dashboard's Node setting is. Read back from the dashboard on 2026-08-13:
**24.x**, which satisfies `engines`. Nothing in the repository enforces that, and a change to the
dashboard setting would not appear in a diff. To close the gap, either add a root `.nvmrc` or set
`nodeVersion` in `vercel.json`.

## 2. Live URLs

- Production: **https://vivid-smiles-headless.vercel.app**
- Vercel dashboard: https://vercel.com/allans-projects-cc55d7b7/vivid-smiles-headless

### The old project is still live, and it is a stale copy of the site

Verified by HTTP on 2026-08-13: **https://vivid-smiles.vercel.app** and
**https://vivid-smiles-website.vercel.app** both still return HTTP 200 and serve the site. They
belong to the previous Vercel project, built from the previous repo (`allanconcepcion/vivid-smiles-website`),
and they are not fed by anything in this repository. What distinguishes them:

| Check | `vivid-smiles-headless` (current) | `vivid-smiles` / `vivid-smiles-website` (old) |
| --- | --- | --- |
| `/before-and-afters/` | 308 → `/smile-gallery/` | **404** |
| `/about-us` (no slash) | 308 → `/about-us/` | **200** — served at two URLs |
| `/sitemap_index.xml` | 200 | **404** |
| `/sitemap-0.xml` | 200 (overwritten by the build) | 200 (the orphaned Astro-generated file) |
| `robots.txt` | `Allow: /`, two `Sitemap:` lines | `Allow: /`, one `Sitemap:` line |

The old deployment predates the WordPress migration and the generated `vercel.json`: no legacy
redirects, no trailing-slash enforcement, no Yoast sitemap. Its `robots.txt` says `Allow: /`, so it
is crawlable, and both its `Sitemap:` directives point at `vividsmilesdentistry.com` — a domain it
does not serve.

**Treat this as live work, not trivia.** Three publicly reachable copies of the same marketing site,
one of them stale and crawlable, is a duplicate-content and stale-content risk that gets worse the
moment the real domain is attached and the site starts being crawled seriously. Retire the old
project — or at minimum its domains — before cutover. See section 7.

## 3. Page audit

### The full 48-route audit is historical

The audit below was performed on **2026-08-11 against the OLD deployment**
(`vivid-smiles-website.vercel.app`), before the WordPress migration, before `vercel.json` existed,
and before the CMS became the content source. It is kept because the coverage it establishes is
still a useful checklist, **not because it describes the current deployment.** Do not cite it as
current verification.

Enumerated 48 routes = 42 from the deployed sitemap + 6 noindex pages excluded from it
(`/design-system/`, `/cosmetic-dentistry-lp/`, `/veneers-lp/`, `/general-lp/`, `/thank-you/`, `/404/`).

- All 47 real pages returned HTTP 200 with a populated title tag and full HTML payloads (37 KB to 151 KB).
- `/404/` correctly returned a 404 status.
- Coverage: homepage, 15 top-level sections, 6 cosmetic-dentistry sub-pages, 5 implant-dentistry
  sub-pages, blog index + 14 posts, plus the 6 noindex pages.
- Rendering verified on a service page, a blog post, the smile gallery, contact, and a landing page.
- All `/_assets/*` files, fonts and stylesheets returned 200. Zero broken images (44 checked on one landing page).
- GSAP scroll reveals and Lenis smooth scrolling work. Typeform embeds initialize on contact and the landing pages.
- No JavaScript console errors on any page checked.

### What has been re-verified against the current deployment

Checked by HTTP on 2026-08-13 against `vivid-smiles-headless.vercel.app`:

| Check | Result |
| --- | --- |
| Homepage | 200, correct `<title>` |
| Trailing slash — `/about-us` | 308 → `/about-us/` |
| Legacy redirect — `/before-and-afters/` | 308 → `/smile-gallery/` |
| Security headers on `/(.*)` | All 4 present (HSTS, X-Frame-Options, X-Content-Type-Options, Referrer-Policy) |
| Cache header on `/_assets/*` | `public, max-age=31536000, immutable`, plus `Accept-Ranges: bytes` |
| `/404/` and an unknown path | Both 404 |
| Sitemaps | `sitemap_index.xml`, `sitemap-index.xml`, `page-sitemap.xml`, `post-sitemap.xml`, `robots.txt` all 200 |
| Sitemap contents | 42 URLs — 27 pages + 15 posts (14 posts plus the `/blog/` hub) — every one on `https://vividsmilesdentistry.com`, zero CMS-host references |

The local `dist/` holds 47 `index.html` files, consistent with the route count.

**Not re-verified since the migration:** per-page rendering across all 48 routes, in-browser
JavaScript behaviour, GSAP/Lenis, Typeform initialization, and image integrity. Redo the full sweep
against the production URL before the domain cutover.

## 4. Open issues

### 4a. Redirects and headers — RESOLVED

Previously: `public/_redirects` and `public/_headers` use Netlify/Cloudflare syntax, which Vercel
does not read, so the legacy redirects and the security headers were not applying.

**Fixed by generating [../vercel.json](../vercel.json) from those two files.** It now carries
**65 redirects** and **2 header groups** — 4 sitewide security headers on `/(.*)`, and 2 cache
headers on `/_assets/(.*)`. All confirmed live on 2026-08-13; see the table in section 3. The
generator is [scripts/build-vercel-config.mjs](scripts/build-vercel-config.mjs); see section 5 for
how to maintain it.

**One part of 4a is still open.** `_redirects` and `_headers` are in `public/`, so Astro copies them
into `dist/` and Vercel serves them. `https://vivid-smiles-headless.vercel.app/_redirects/` returns
200 and readable text today. It leaks nothing sensitive — it is a list of URLs the site already
publishes redirects for — but it is a stray artifact on the public site. Fix by moving both files out
of `public/` and pointing the generator at the new location, or by adding a header/redirect rule that
blocks them. Do not delete them: they are the source the generator reads.

### 4b. Trailing slashes — RESOLVED

`vercel.json` sets `trailingSlash: true`, matching `trailingSlash: 'always'` in
[astro.config.mjs](astro.config.mjs). Verified: `/about-us` returns 308 to `/about-us/`, so pages are
no longer reachable at two URLs.

**Note the status code.** Vercel emits **308** for both trailing-slash enforcement and for
`permanent: true` redirects, where `public/_redirects` writes `301`. Both are permanent and both pass
link equity; 308 additionally preserves the request method. All 65 rules in `_redirects` are `301`,
so all 65 land in `vercel.json` as `permanent: true` and go out as 308. This is expected, not a
defect — do not "fix" it.

### 4c. Two third-party requests returned 503 — still open

Observed in-browser on 2026-08-11 against the old deployment:

- `https://www.clarity.ms/tag/vkjzesavnp` (Microsoft Clarity) returned 503.
- `https://process.iconnode.com/google-ads/` (POST, fired from the GTM container) returned 503.

**Re-checked 2026-08-13: both endpoints return 200 to a direct request.** That is not the same test.
The original 503s were seen in a browser loading the deployed site, where Origin and Referer are
present and the vendor may be rejecting an unrecognised domain; a bare `curl` from a different IP
does not reproduce those conditions. Treat this as unresolved until the tags are re-checked
in-browser — first on the production `.vercel.app` URL, then again after the real domain is attached,
which is the condition most likely to have caused it.

Both tags are owned by the GTM container, not by page markup: `src/components/Analytics.astro`
inlines GTM `GTM-W5FBTHCQ` and Clarity project `vkjzesavnp`.

### 4d. Call-tracking script — vendor identified, authorisation still unconfirmed

A script from `https://s.ksrndkehqnwntyxlhgto.com/162233.js` loads on every page and POSTs to
`p.ksrndkehqnwntyxlhgto.com/keyword/`. The 2026-08-11 audit observed it swapping the displayed phone
number from **(303) 841-5313** — the practice's real number, stored in WordPress Practice Settings —
to **(720) 617-0331**.

**The vendor is no longer unattributed.** It is WhatConverts dynamic number insertion, profile
`162233`, and the profile ID matches the script filename. It is named in three places in this repo:

| File | Line | What it says |
| --- | --- | --- |
| `src/layouts/BaseLayout.astro` | 90, 106 | "WhatConverts dynamic number insertion (profile 162233). Sitewide call tracking" |
| `src/layouts/LandingLayout.astro` | 128 | "WhatConverts dynamic number insertion (matches BaseLayout). Paid-traffic landing pages depend on this for ad-source call attribution." |
| `src/pages/privacy-policy/index.astro` | 320 | Discloses WhatConverts by name as call tracking that may display a dynamic phone number |

**What is still open is the question for the practice, and it is worth asking plainly:** nobody has
confirmed that WhatConverts profile 162233 is an account the practice controls, or that
(720) 617-0331 routes to their phones. If the account belongs to a former marketing vendor, then
every call originating from the website is being measured by a third party the practice may no longer
work with, and the number shown to patients is under that third party's control. Confirm account
ownership and the destination of the swapped number before the domain goes live.

[DEPLOYMENT.md](DEPLOYMENT.md) now carries WhatConverts in its third-party tag table, including the
profile ID, the two layouts that load it, and the same unconfirmed-ownership caveat. The
documentation gap is closed; the question for the practice is not.

## 5. How vercel.json is generated and maintained

`vercel.json` exists, lives at the **repository root**, and is **generated, not hand-written**.

**Do not edit `vercel.json` directly.** Edit [public/_redirects](public/_redirects) or
[public/_headers](public/_headers), then regenerate:

```bash
cd vivid-smiles-website && npm run vercel:config
```

That runs [scripts/build-vercel-config.mjs](scripts/build-vercel-config.mjs), which reads both
`public/` files and writes `../vercel.json`. The script resolves the repo root from its own location
rather than hardcoding a path, so moving the app directory does not silently break the deploy.

**It is committed on purpose.** Vercel reads `vercel.json` from the repository before any build step
could produce it, so a file generated during the build would arrive too late to affect anything.
This also means the generator is **not** part of the build chain — `npm run build` will not refresh
it. Editing `_redirects` without regenerating leaves the deployed rules unchanged, which is exactly
why both source files carry a banner saying so.

What the generator has to translate, and why:

- **`/*` becomes `/(.*)`.** Netlify glob syntax matches nothing under Vercel's path-to-regexp. Left
  untranslated, the sitewide security header group would silently apply to zero requests.
- **A missing status becomes `permanent: true`**, matching Netlify's default of 301.
- **Install, build and output paths** are written in by the generator, because Root Directory is `.`
  and nothing else points the build into the app directory. See section 1.

Current output: 2 header groups, 65 redirects, `trailingSlash: true`.

Vercel reference: https://vercel.com/docs/project-configuration

## 6. Runbook: cutting over to the real domain

[../docs/DEPLOYING.md](../docs/DEPLOYING.md) is authoritative for the cutover procedure and covers
the WordPress side as well. This section keeps the DNS specifics, which exist nowhere else. If the
two ever disagree on a step, follow DEPLOYING.md and correct this file.

Domain: `vividsmilesdentistry.com`. Registered at GoDaddy. DNS hosted at Cloudflare on nameservers
`beth.ns.cloudflare.com` and `dan.ns.cloudflare.com`.

### DO NOT TOUCH these DNS records

Email runs on Google Workspace through the same Cloudflare zone. A DNS migration or cleanup that
drops any of these will break email:

- MX record pointing at `smtp.google.com`
- SPF TXT record: `v=spf1 include:_spf.google.com -all`
- the `_dmarc` TXT record
- two verification TXT records, one for Google Search Console and one for Apple

Website changes never require modifying any of these.

### Pre-flight checklist

1. `vercel.json` is committed and current — regenerate it if `_redirects` or `_headers` changed
   since the last run. See section 5.
2. Redirects, headers and trailing slashes have been spot-checked on the production `.vercel.app`
   URL. The 2026-08-13 checks in section 3 cover this; repeat them if anything has been deployed since.
3. Re-run the full page sweep from section 3 against the current deployment — the 48-route audit on
   record predates the WordPress migration.
4. Confirm WhatConverts account ownership and the swapped number from section 4d.
5. Retire or unpublish the old `vivid-smiles` / `vivid-smiles-website` deployment, so a stale
   crawlable copy is not competing with the real domain. See section 2.
6. Note the current host's configuration so a rollback is possible.
7. Lower the TTL on the existing DNS records about 24 hours in advance to make rollback fast.

### Cutover steps

1. In Vercel: project, then Settings, then Domains, then Add Existing. Enter
   `vividsmilesdentistry.com` and `www.vividsmilesdentistry.com`, connected to Production.
2. Vercel will display the exact DNS target values to use. Use the values Vercel shows at that
   moment rather than any value written here.
3. In Cloudflare, add or update ONLY the apex and www records to point at Vercel's targets. Leave
   every record in the DO NOT TOUCH list alone.
4. Cloudflare proxy status: set the Vercel records to DNS only (grey cloud) unless you have
   deliberately configured Cloudflare proxying to work with Vercel. Proxying on both sides can cause
   redirect loops and certificate issues.
5. Decide whether the apex or www is canonical, and set the other to redirect to it in Vercel's
   domain settings.
6. Wait for Vercel to show Valid Configuration and for the SSL certificate to issue.
7. Verify: homepage loads over HTTPS, several legacy redirect URLs land on the right pages,
   `/robots.txt` and `/sitemap_index.xml` resolve, and the four security headers are present.
8. Confirm `astro.config.mjs` `site` and the emitted sitemap still use
   `https://vividsmilesdentistry.com/`. The sitemap URLs are rewritten at build time by
   `src/integrations/yoast-sitemap.ts` from `site`, not from the request host, so this should already
   hold — verify rather than assume.
9. Re-submit the sitemap in Google Search Console and Bing Webmaster Tools, and re-check the Clarity
   and Google Ads tags from section 4c in-browser.

### Rollback

Revert the apex and www DNS records in Cloudflare to the previous host's values. Because only those
two records change, email is unaffected either way.

## 7. Remaining work

Vercel-side, roughly in the order it should be done:

- **Retire the old Vercel project.** `vivid-smiles.vercel.app` and `vivid-smiles-website.vercel.app`
  still serve a stale, crawlable copy of the site. Section 2.
- **Confirm the WhatConverts account and the swapped phone number** with the practice. Section 4d.
- **Re-check the Clarity and Google Ads tags in-browser** on the production URL. Section 4c.
- **Stop shipping `_redirects` and `_headers` into `dist/`.** Section 4a.
- **Close the Node version gap** — add a root `.nvmrc` or set `nodeVersion` in `vercel.json`.
  Section 1.
- **Re-run the full page sweep** against the current deployment; the audit on record is pre-migration.
  Section 3.
- **Attach the real domain.** Section 6. Not started; DNS untouched.

Tracked in [../docs/DEPLOYING.md](../docs/DEPLOYING.md) rather than here, because they are not
Vercel-project settings:

- Making the GitHub repository private. It is public and carries 74 identifiable patient photos.
  (Credentials were purged from `cms/backup/database.sql` in commit `9f41107`; the photos remain.)
- Moving the CMS to `cms.vividsmilesdentistry.com`, then updating `WP_GRAPHQL_ENDPOINT` in the Vercel
  project and `image.remotePatterns` in `astro.config.mjs`.
- Wiring a Vercel deploy hook and firing it from WordPress on publish. Until this exists, editors'
  changes do not reach the site without a push or a manual redeploy.
- Relaxing the host's Cloudflare bot rules for `/wp-content/uploads/` and `/graphql` — the durable
  fix for section 8.

## 8. Build failure history: Cloudflare bot protection

**If a deploy fails, read this first.** Two production builds failed here, both for the same
underlying reason, and both fixes are in the repo.

The CMS sits behind Cloudflare in front of a rate-limited managed-WordPress origin. Under burst load
it answers with **429 and `cf-mitigated: challenge`** — bot protection, not ordinary rate limiting.
It fires even on files already cached at the edge, so caching alone does not avoid it.

**Failure 1 — images.** Astro downloads every remote image at high concurrency. A few hundred rapid
requests tripped the challenge and the build died. Fix:
[scripts/warm-media-cache.mjs](scripts/warm-media-cache.mjs), a `prebuild` step that requests each of
the 131 media files once at low concurrency with backoff, so Astro's high-concurrency phase sees only
cache hits. It must run **inside** the build — Cloudflare caches per datacenter, so warming from a
developer's machine does nothing for the colo a Vercel build runs in. Confirmed doing real work: the
warmer reported 131 MISS on Vercel where the same run locally reported HIT. Failures there are
non-fatal by design; one media request should not gate a deploy.

**Failure 2 — GraphQL.** The next deployment failed on the footer-menu GraphQL query while rendering
the first page. Every earlier query had succeeded, so the challenge was triggered by cumulative
request volume from the build IP and then applied to whatever came next. `wpQuery` treated any status
under 500 as permanent and gave up — correct for a 404 or an auth failure, wrong for a challenge,
which clears on a slower retry. Fix: `src/lib/wp.ts` now treats 429 as retryable, honouring
`Retry-After` when present and otherwise backing off quadratically over `MAX_ATTEMPTS = 5`. The media
warmer dropped to `CONCURRENCY = 3` with the same backoff and pauses `SETTLE_MS = 3000` before
handing over, so the build does not start rendering inside a rate-limit window it just opened.

Deploys have succeeded since. The most recent push produced a Ready production deploy in 37 seconds.

**The durable fix is still outstanding**: relaxing the host's Cloudflare rules for
`/wp-content/uploads/` and `/graphql`. Until that happens, the warm-up and the retry logic are what
stand between a content edit and a failed deploy — treat both as load-bearing, not as optimisations.

**Related build-time failure modes** are documented in
[../docs/DEPLOYING.md](../docs/DEPLOYING.md). The short version: the build is designed to fail loudly
rather than publish a degraded site. Zero posts, zero pages, zero testimonials, an empty nav menu, a
missing Practice Settings field, or a sitemap URL with no corresponding built page will all stop the
deploy and leave the previous one serving.
