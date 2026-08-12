# Deploying

The site is a static Astro build that reads content from WordPress **at build
time**. Nothing is fetched from WordPress by a visitor's browser.

That single fact drives everything below: **the build machine must be able to
reach WordPress over the public internet.** A Vercel build cannot reach
`localhost:8888`, which is why hosting WordPress was a prerequisite for
deploying at all rather than a follow-up task.

```
WordPress (1230613.us28.myftpupload.com)
      │  WPGraphQL, at build time only
      ▼
Vercel build ──► static HTML ──► visitors
```

---

## What is deployed today

Verified live on 2026-08-13.

| Setting | Value |
| --- | --- |
| GitHub repository | `allanconcepcion/vivid-smile-dentistry-headless` — **still public; see [Outstanding before launch](#outstanding-before-launch)** |
| Branch | `main`, the only branch |
| Vercel team | `allans-projects-cc55d7b7` ("Allan's projects", Hobby plan) |
| Vercel project | `vivid-smiles-headless` |
| Production URL | `https://vivid-smiles-headless.vercel.app` |
| **Root Directory** | `.` — the repository root, **not** `vivid-smiles-website` |
| Install command | `cd vivid-smiles-website && npm install` (from `vercel.json`) |
| Build command | `cd vivid-smiles-website && npm run build` (from `vercel.json`) |
| Output directory | `vivid-smiles-website/dist` (from `vercel.json`) |
| Environment variable | `WP_GRAPHQL_ENDPOINT` — Production, Preview and Development |
| WordPress | `https://1230613.us28.myftpupload.com` — GoDaddy Managed WordPress, temporary hostname |

The local CLI link lives in `vivid-smiles-website/.vercel/project.json`
(git-ignored) and records the same project and team ids.

### Root Directory is the repository root, and that is load-bearing

Vercel reads `vercel.json` from the project's Root Directory. `vercel.json` is
at the **repository root**, so the Root Directory must stay `.` for the file to
be read at all. Point it at `vivid-smiles-website` and the file becomes
invisible: no install command, no build command, no output directory, and the 65
legacy redirects, the security headers and `trailingSlash` all stop applying —
without any error saying so.

The repository root has no `package.json`. That is why `vercel.json` carries
explicit `installCommand`, `buildCommand` and `outputDirectory` values that `cd`
into `vivid-smiles-website/`, instead of relying on a framework preset. The
generator that writes the file says the same thing in a comment, so the two
cannot drift apart unnoticed.

### The environment variable

| Name | Value | Environments |
| --- | --- | --- |
| `WP_GRAPHQL_ENDPOINT` | `https://1230613.us28.myftpupload.com/graphql` | Production, Preview, Development |

This is the only variable the build needs. Without it the build fails
immediately with a message naming the fix, rather than publishing an empty site.

It is set for Preview as well as Production, so a branch or pull-request preview
builds against the same live WordPress content.

A third copy exists for Development. Production and Preview are marked Sensitive,
so the dashboard will not display their values; only Development can be read
back. Verified in the dashboard on 2026-08-13.

Change it to `https://cms.vividsmilesdentistry.com/graphql` when the CMS moves to
its permanent hostname, and add that hostname to `image.remotePatterns` in
`astro.config.mjs` in the same change. `astro.config.mjs` already authorizes
`cms.vividsmilesdentistry.com`, `1230613.us28.myftpupload.com` and
`http://localhost:8888`, all scoped to `/wp-content/uploads/**`. A host that is
not on that list fails every image in the build.

### Node version is not pinned where Vercel looks

`vivid-smiles-website/package.json` declares `engines.node: ">=22.12.0"` and
`vivid-smiles-website/.nvmrc` says `22`. **Neither of those reaches Vercel.**
Vercel reads `.nvmrc` from the repository root, and there is none there;
`vercel.json` sets no `nodeVersion`. The build uses whatever the project's
dashboard Node setting is. Read back on 2026-08-13: **24.x**, which satisfies
`engines` — but nothing in the repository enforces that, a change made in the
dashboard would not appear in a diff, and this is the first thing to check if a
build ever fails on a Node API rather than on content.

### How a deploy happens

Push to `main`. The Vercel Git integration is connected and builds every push to
`main` as a Production deployment. There is no manual step and no CLI command in
the normal path.

```bash
git push origin main
```

Verified on 2026-08-13: a push to `main` produced a Ready production deployment
in 37 seconds.

---

## Where the front-end URL is configured

**`cms/mu-plugins/vs-config.php`** — not `wp-config.php`. The managed host
rewrites `wp-config.php` during platform updates, silently dropping anything
added by hand. That failure is quiet: the constant disappears, the redirect
stops, and the raw WordPress theme starts answering on the CMS hostname
alongside the real site. `wp-content/mu-plugins/` survives those updates.

Locally the constant comes from `cms/.wp-env.json` instead, which loads first;
`vs-config.php` guards with `defined()` so the local value wins.

Verified live: `https://1230613.us28.myftpupload.com/` and
`https://1230613.us28.myftpupload.com/about-us/` both 302 to the matching path
on `https://vivid-smiles-headless.vercel.app`, and the CMS `robots.txt` is
`Disallow: /`.

**The redirect is deliberately skipped for signed-in users.** `redirect_frontend()`
in `vs-headless.php` returns early on `is_user_logged_in()` so an editor can
preview a draft. A browser logged into wp-admin therefore gets the WordPress theme
rather than a 302 — test anonymously, or in a private window, before concluding
the redirect has broken.

**`/sitemap_index.xml`, `/page-sitemap.xml` and `/post-sitemap.xml` are not
redirected** and return 200 on the CMS hostname. That is required — the Astro
build fetches them (see [Build-time failure modes](#build-time-failure-modes)).
They are not in the passthrough list in `vs-headless.php`; they survive because
Yoast emits and exits before the redirect hook runs. If a Yoast upgrade ever
breaks the sitemap step of a build, check this first.

---

## What is already handled

**`vercel.json` is generated, not hand-written.** `public/_headers` and
`public/_redirects` are Netlify/Cloudflare syntax and Vercel ignores both. Left
as they were, the site would deploy with no security headers, no immutable
caching on `/_assets/*`, and **65 dead legacy redirects** — every old WordPress
URL 404ing.

Regenerate after editing either file:

```bash
cd vivid-smiles-website && npm run vercel:config
```

It is committed because Vercel reads `vercel.json` from the repository, before
any build step could produce it.

**`trailingSlash: true`** is set there to match Astro's `trailingSlash: 'always'`.
Without it the two disagree about the canonical form of every URL, producing
redirect chains and duplicate-content signals.

Confirmed on the production URL: the four sitewide security headers are present,
`/about-us` 308s to `/about-us/`, and `/before-and-afters` resolves through to
`/smile-gallery/`.

**Known gap:** `_redirects` and `_headers` are still copied into `dist/` by
Astro and are readable by anyone at `/_redirects/` and `/_headers/`. They leak
nothing secret — every rule in them is observable by requesting the URLs — but
they are build inputs, not site content, and they do not belong on the public
origin. This was raised in
[../vivid-smiles-website/VERCEL-DEPLOYMENT-NOTES.md](../vivid-smiles-website/VERCEL-DEPLOYMENT-NOTES.md)
and only half-fixed: the rules were migrated into `vercel.json`, the exposure
was not addressed.

---

## The CDN warm-up step, and why it exists

`npm run build` runs `scripts/warm-media-cache.mjs` first, via `prebuild`.

Astro downloads every remote image at build time, at high concurrency. The CMS
sits behind Cloudflare, and a few hundred rapid requests come back **429 with
`cf-mitigated: challenge`** — bot protection, not rate limiting. It fires even on
files already cached at the edge, so caching alone does not avoid it, and a
cold build failed on a different image every run.

The warm-up requests each of the **131 media files** once, **three at a time**
(`CONCURRENCY = 3`), retrying on 429 over five attempts with backoff, then
pauses three seconds before handing over so the build does not start rendering
inside a rate-limit window it opened moments earlier. By the time Astro's
high-concurrency phase runs, every URL is a cache HIT and the origin is never
touched.

It must run **inside** the build: Cloudflare caches per datacenter, so warming
from a laptop does nothing for the colo a CI build runs in. This is measured,
not assumed — the warmer reports `MISS=131` on Vercel where the identical run
reports HIT locally. Failures there are non-fatal; Astro retries anything
unwarmed.

If image builds start failing after a host change, check this first.

---

## Cloudflare bot protection — outstanding

The warm-up and the retry logic are workarounds, not the fix.

GoDaddy fronts the managed WordPress origin with Cloudflare bot protection that
answers sustained traffic from one IP with **429 and an HTML interstitial**. It
is triggered by cumulative request volume from the build IP and then applies to
whatever the build does next — which is why the failure moves around. Two
production deploys errored on it: once on an image, once on the footer-menu
GraphQL query while rendering the first page, after every earlier query had
already succeeded.

Two mitigations are in place and both are load-bearing:

- `scripts/warm-media-cache.mjs` pulls media at low concurrency before Astro's
  parallel phase.
- `src/lib/wp.ts` treats 429 as **retryable** — unlike other 4xx, which are
  permanent — honouring `Retry-After` when present and otherwise backing off
  quadratically over five attempts.

**The durable fix is still outstanding: relax the Cloudflare rules on the CMS
hostname for `/wp-content/uploads/` and `/graphql`.** Those two paths are the
build's entire surface area, they are read-only, and they are hit by a known
caller. Until that happens the build's reliability depends on staying under a
threshold nobody controls, and a larger media library or a slower colo can push
it back over.

---

## Build-time failure modes

The loaders fail loudly rather than publishing something that looks fine:

| Situation | What happens |
| --- | --- |
| `WP_GRAPHQL_ENDPOINT` unset | Build fails with setup instructions |
| WordPress unreachable, 5xx, or 429 | 5 attempts with backoff, then the build fails |
| Zero posts/reviews/pages returned | Build fails — an empty result is far more likely to be a broken query than deleted content |
| A GraphQL query returns errors | Build fails immediately; query errors are deterministic and retrying only wastes time |
| A post is missing its hero image or alt text | That post is skipped with a warning naming it |
| Yoast's sitemap lists a URL with no page | Build fails, naming the URLs |

The last one is deliberate: a sitemap full of 404s spends crawl budget and
signals a broken site. Verification runs before the index file is replaced, so a
failure leaves the previously written sitemap in place.

If Yoast's sitemap index cannot be fetched at all, that step warns and keeps the
Astro-generated sitemap rather than shipping none.

---

## Outstanding before launch

| Item | Status |
| --- | --- |
| Make the GitHub repository private | **Not done.** See below |
| Move the CMS to `cms.vividsmilesdentistry.com` | Not done — see [Moving the CMS to its permanent hostname](#moving-the-cms-to-its-permanent-hostname) |
| Relax Cloudflare bot rules for `/wp-content/uploads/` and `/graphql` | Not done — see [Cloudflare bot protection](#cloudflare-bot-protection--outstanding) |
| Wire a Vercel deploy hook and fire it from WordPress | Not done — no hook code exists in `cms/mu-plugins/` |
| Cut `vividsmilesdentistry.com` over to Vercel | Not done — DNS still points at the old host |
| Stop shipping `_redirects` and `_headers` into `dist/` | Not done |
| Confirm the Facebook URL | `facebook.com/VivdSmiles/` appears to be missing an `i`. It is stored in two places: `src/components/Footer.astro` and `cms/import/import-wp-settings.php`, plus the live WordPress option |

### Make the repository private

The repository is public and it should not be. It carries 661 files under
`cms/uploads/`, including **74 identifiable patient photographs**, and a
2.3 MB database dump of the client's entire site.

`cms/backup/database.sql` no longer contains `wp_users` or `wp_usermeta` —
`backup.sh` now excludes both at the table level — but **the password hash
remains in this repository's git history**, so making the repository private
does not retire it. Any install restored from an older dump needs its password
rotated.

### Wire the deploy hook

Create a Deploy Hook in the Vercel project, then fire it from WordPress on
publish so editors do not need to know Vercel exists. Debounce it — ten quick
edits should not queue ten builds.

---

## Moving the CMS to its permanent hostname

The current hostname is GoDaddy's temporary one. Moving to
`cms.vividsmilesdentistry.com`:

```bash
# On the host: restore the content
bash cms/bin/restore.sh https://cms.vividsmilesdentistry.com
```

Pass the target URL. The dump was taken against `http://localhost:8888`
(`cms/backup/SITEURL` records this), and `restore.sh` runs
`wp search-replace` rather than editing the SQL, because WordPress stores
serialized PHP in `wp_options` with string lengths encoded alongside the values.
A plain find/replace corrupts every serialized option whose URL changes length.
See [../cms/README.md](../cms/README.md).

- Copy `cms/uploads/` to the host's `wp-content/uploads/`.
- Deploy `cms/mu-plugins/` to `wp-content/mu-plugins/`.
- Run the equivalent of `cms/bin/setup.sh` to install the pinned plugins.
- Change `VS_FRONTEND_URL` in `cms/mu-plugins/vs-config.php` to
  `https://vividsmilesdentistry.com` and re-upload that one file.
- Update `WP_GRAPHQL_ENDPOINT` in the Vercel project and add the new hostname to
  `image.remotePatterns` in `astro.config.mjs`.

Confirm before moving on:

```bash
curl -sS -X POST https://cms.vividsmilesdentistry.com/graphql \
  -H 'Content-Type: application/json' \
  -d '{"query":"{ posts(first:1){ nodes { slug } } }"}'
```

---

## Cutting over the domain

Do this only after a production deployment renders correctly on the Vercel URL.

### DNS, and the records that must not be touched

`vividsmilesdentistry.com` is registered at GoDaddy. DNS is hosted at Cloudflare
on nameservers `beth.ns.cloudflare.com` and `dan.ns.cloudflare.com`. The apex
and `www` currently resolve to Cloudflare proxy addresses in front of the old
host.

**Email runs on Google Workspace through the same Cloudflare zone.** A DNS
migration or a tidy-up that drops any of the following will break the practice's
email. Verified present on 2026-08-13:

| Record | Value |
| --- | --- |
| `MX` | `1 smtp.google.com` |
| `TXT` (SPF) | `v=spf1 include:_spf.google.com -all` |
| `TXT` at `_dmarc` | `v=DMARC1; p=quarantine; adkim=r; aspf=r; rua=mailto:dmarc_rua@onsecureserver.net;` |
| `TXT` (Google Search Console) | `google-site-verification=BHnZIy4HsTfvGohSkKYzlNe0zSlg852c_ocprBdyTWY` |
| `TXT` (Apple) | `apple-domain-verification=zCeLhNWshJWildt7` |

**Attaching the site to Vercel never requires modifying any of them.** Only the
apex `A` record and the `www` record change. If a step in any runbook — this one
or a registrar's — asks you to delete records or replace the whole zone, stop.

### Pre-flight

1. `vercel.json` is committed with all redirects, headers and `trailingSlash`,
   and a production deploy has been made with it. Done.
2. Spot-check several legacy redirects on the `.vercel.app` URL.
3. Confirm the call-tracking vendor with the practice. The script at
   `s.ksrndkehqnwntyxlhgto.com/162233.js` is **WhatConverts dynamic number
   insertion, profile 162233** — identified in `BaseLayout.astro` and
   `LandingLayout.astro` and disclosed by name in the privacy policy. It rewrites
   the displayed phone number and POSTs visitor data offsite, so confirm the
   account is the practice's own before launch.
4. Note the current host's configuration so a rollback is possible.
5. Lower the TTL on the apex and `www` records about 24 hours in advance, so a
   rollback takes minutes rather than a day.

### Cutover

1. In Vercel: project → Settings → Domains → Add Existing. Add
   `vividsmilesdentistry.com` and `www.vividsmilesdentistry.com`, connected to
   Production.
2. Vercel displays the exact DNS target values. **Use the values Vercel shows at
   that moment**, not any value written here — they change.
3. In Cloudflare, add or update **only** the apex and `www` records. Leave every
   record in the table above alone.
4. Set the Vercel records to **DNS only** (grey cloud) unless Cloudflare
   proxying has been deliberately configured to work with Vercel. Proxying on
   both sides causes redirect loops and certificate-issuance failures.
5. Decide whether the apex or `www` is canonical and set the other to redirect to
   it in Vercel's domain settings.
6. Wait for Vercel to show Valid Configuration and for the certificate to issue.
7. Update `VS_FRONTEND_URL` in `cms/mu-plugins/vs-config.php` to the real domain
   and re-upload it. Until you do, anyone landing on the CMS hostname is sent to
   the `vercel.app` address instead of the live site.
8. Verify: the homepage loads over HTTPS, several legacy redirect URLs land on
   the right pages, `/robots.txt` and `/sitemap_index.xml` resolve, and the four
   security headers are present.
9. Confirm `site` in `astro.config.mjs` and the emitted sitemap both say
   `https://vividsmilesdentistry.com/`.
10. Submit `https://vividsmilesdentistry.com/sitemap_index.xml` in Search Console
    and Bing Webmaster Tools. That is the same path the old WordPress site used,
    so an existing submission keeps working; the build also writes
    `sitemap-index.xml` for anything referencing the hyphenated form.
11. Re-check the Microsoft Clarity and Google Ads tags. Both
    `clarity.ms/tag/vkjzesavnp` and the GTM-fired POST to
    `process.iconnode.com/google-ads/` returned 503 during the 2026-08-11 audit;
    both returned 200 when re-checked on 2026-08-13, so that looks transient
    rather than a domain rejection. Confirm again once the real domain is live.
12. Confirm `https://cms.vividsmilesdentistry.com/robots.txt` is `Disallow: /` —
    the CMS must never be indexed alongside the real site.

### Rollback

Revert the apex and `www` records in Cloudflare to the previous host's values.
Because only those two records change, email is unaffected either way.
