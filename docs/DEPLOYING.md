# Deploying

The site is a static Astro build that reads content from WordPress **at build
time**. Nothing is fetched from WordPress by a visitor's browser.

That single fact drives everything below: **the build machine must be able to
reach WordPress over the public internet.** A Vercel build cannot reach
`localhost:8888`, so hosting WordPress is a prerequisite for deploying, not a
follow-up task.

```
WordPress (cms.vividsmilesdentistry.com)
      │  WPGraphQL, at build time only
      ▼
Vercel build ──► static HTML ──► visitors
```

---

## Order of operations

### 1. Host WordPress first — DONE

Live at `https://1230613.us28.myftpupload.com` (GoDaddy Managed WordPress,
temporary hostname). All content migrated: 14 posts, 33 pages, 20 testimonials,
practice settings, menus, and the custom `vsRoute` / `vsSeo` fields.

Two things still to do on that host:

1. **Set `VS_FRONTEND_URL` in `wp-config.php`** once the site has a public URL.
   Until then the CMS front end serves WordPress rather than redirecting — the
   safe default. Deploy the current `cms/mu-plugins/` first: an older copy is on
   the host that redirects visitors to `http://localhost:4321`.
2. **Move to a permanent hostname** (`cms.vividsmilesdentistry.com`) before
   launch, and update `WP_GRAPHQL_ENDPOINT` plus `image.remotePatterns`.

Then:

```bash
# On the host: restore the content
bash cms/bin/restore.sh https://cms.vividsmilesdentistry.com
```

- Copy `cms/uploads/` to the host's `wp-content/uploads/`.
- Deploy `cms/mu-plugins/` to `wp-content/mu-plugins/`.
- Run the equivalent of `cms/bin/setup.sh` to install the pinned plugins.
- Set `VS_FRONTEND_URL` in `wp-config.php` to `https://vividsmilesdentistry.com`.

Confirm before moving on:

```bash
curl -sS -X POST https://cms.vividsmilesdentistry.com/graphql \
  -H 'Content-Type: application/json' \
  -d '{"query":"{ posts(first:1){ nodes { slug } } }"}'
```

### 2. Create the GitHub repository

```bash
gh repo create allanconcepcion/vivid-smile-dentistry-headless --private --source . --push
```

Without the `gh` CLI, create an empty repo of that name in the GitHub UI, then:

```bash
git push -u origin main
```

**Make it private.** It contains the client's full site content and a database
dump, including the local WordPress user table.

### 3. Create the Vercel project

| Setting | Value |
| --- | --- |
| Framework preset | Astro |
| **Root Directory** | `vivid-smiles-website` |
| Build command | `npm run build` (default) |
| Output directory | `dist` (default) |
| Node version | 22 (from `.nvmrc`) |

The root directory matters: this is a monorepo, and the Astro app is not at the
repository root.

### 4. Set the environment variable

| Name | Value | Environments |
| --- | --- | --- |
| `WP_GRAPHQL_ENDPOINT` | `https://1230613.us28.myftpupload.com/graphql` | Production, Preview |

Change this to `https://cms.vividsmilesdentistry.com/graphql` when the CMS moves
to its permanent hostname, and add that hostname to `image.remotePatterns` in
`astro.config.mjs` at the same time.

This is the only variable the build needs. Without it the build fails
immediately with a message saying so, rather than publishing an empty site.

### 5. Add the image host

`astro.config.mjs` already authorizes `cms.vividsmilesdentistry.com` under
`image.remotePatterns`. If WordPress ends up on a different hostname, add it
there or every image will fail to build.

### 6. Wire the deploy hook

Create a Deploy Hook in the Vercel project, then fire it from WordPress on
publish so editors do not need to know Vercel exists. Debounce it — ten quick
edits should not queue ten builds.

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

---

## The CDN warm-up step, and why it exists

`npm run build` runs `scripts/warm-media-cache.mjs` first, via `prebuild`.

Astro downloads every remote image at build time, at high concurrency. The CMS
sits behind Cloudflare, and a few hundred rapid requests come back **429 with
`cf-mitigated: challenge`** — bot protection, not rate limiting. It fires even on
files already cached at the edge, so caching alone does not avoid it, and a
cold build failed on a different image every run.

The warm-up requests each of the 131 media files once at concurrency 4 with
backoff. By the time Astro's high-concurrency phase runs, every URL is a cache
HIT and the origin is never touched. A cold build then completes in ~20s.

It must run **inside** the build: Cloudflare caches per datacenter, so warming
from a laptop does nothing for the colo a CI build runs in. Failures there are
non-fatal — Astro retries anything unwarmed.

If image builds start failing after a host change, check this first.

## Build-time failure modes

The loaders fail loudly rather than publishing something that looks fine:

| Situation | What happens |
| --- | --- |
| `WP_GRAPHQL_ENDPOINT` unset | Build fails with setup instructions |
| WordPress unreachable | 3 retries, then the build fails |
| Zero posts/reviews/pages returned | Build fails — an empty result is far more likely to be a broken query than deleted content |
| A post is missing its hero image or alt text | That post is skipped with a warning naming it |
| Yoast's sitemap lists a URL with no page | Build fails, naming the URLs |

The last one is deliberate: a sitemap full of 404s spends crawl budget and
signals a broken site.

If WordPress is unreachable when the sitemap step runs, it warns and keeps the
Astro-generated sitemap rather than shipping none at all.

---

## Cutting over the domain

The site is currently unbuilt against a public CMS, so do this only after a
production deployment renders correctly on the Vercel URL.

1. Point `vividsmilesdentistry.com` at Vercel.
2. Submit `https://vividsmilesdentistry.com/sitemap_index.xml` in Search
   Console. That is the same path the old WordPress site used, so an existing
   submission keeps working.
3. Spot-check several redirects from `public/_redirects` against the live site.
4. Confirm `https://cms.vividsmilesdentistry.com/robots.txt` is
   `Disallow: /` — the CMS must never be indexed alongside the real site.
