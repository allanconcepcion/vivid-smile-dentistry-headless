# Vivid Smiles — Headless WordPress + Astro

Conversion of the Vivid Smiles Dentistry site to a headless setup: WordPress as
the CMS, Astro as a statically-built front end.

This is a **new repository**. It does not push to
`allanconcepcion/vivid-smiles-website` — that repo is untouched, and the
pre-conversion history of the site is preserved locally in
`.original-git-backup/` (git-ignored).

> **This repository is public and holds client material.** `cms/uploads/`
> contains identifiable patient photographs, and `cms/backup/database.sql` is a
> full content export of the practice's site. Neither belongs in a public repo.
> Make it private. Account tables are excluded from the database dump, but the
> photographs are not something an access-control change can undo after the fact.
>
> The dump only stopped carrying `wp_users` in commit `9f41107`, so the local
> install's password hash is still in git history. Making the repo private does
> not remove it. Any WordPress install restored from a dump older than that
> commit should have its password rotated.

```
                        edit                     build-time fetch
   editors ──────────► WordPress ──── WPGraphQL ────────────────┐
                       (cms/)                                    │
                                                                 ▼
   visitors ◄────── Vercel (static HTML) ◄──── Astro build ──── loaders
                                               (vivid-smiles-website/)
```

Visitors never reach WordPress. Content is pulled at build time and baked into
static HTML, so the site keeps its current performance profile.

## Where things are

| | |
| --- | --- |
| Production site | https://vivid-smiles-headless.vercel.app |
| WordPress admin | https://cms.vividsmilesdentistry.com/wp-admin |
| GraphQL endpoint | https://cms.vividsmilesdentistry.com/graphql |
| GitHub repository | https://github.com/allanconcepcion/vivid-smile-dentistry-headless |
| Vercel project | `vivid-smiles-headless` (team `allans-projects-cc55d7b7`) |

The CMS hostname is GoDaddy's temporary one. Any anonymous visitor who lands on it
is redirected to the matching path on the front end — signed-in users are exempt so
an editor can preview a draft, which means testing the redirect while logged into
wp-admin shows the WordPress theme rather than a 302; only wp-admin, GraphQL, the
WordPress JSON and cron endpoints, `robots.txt` and Yoast's sitemap files answer
there. `robots.txt` is `Disallow: /`. The sitemaps are exempt from the redirect
because the Astro build reads them and rewrites their URLs onto the public
origin.

`vividsmilesdentistry.com` has **not** been cut over yet. See
[docs/DEPLOYING.md](docs/DEPLOYING.md).

| Directory | What |
| --- | --- |
| `vivid-smiles-website/` | The Astro front end — 35 route files, 48 built pages |
| `cms/` | WordPress: content model, import scripts, and the local wp-env stack |
| `docs/` | Deployment and the migration record |
| `.claude/` | Agent instructions for this repository |

## Documentation

| Read this | When |
| --- | --- |
| [cms/README.md](cms/README.md) | Running WordPress locally, the content model, the import scripts |
| [docs/DEPLOYING.md](docs/DEPLOYING.md) | Hosting, the Vercel project, build behaviour, the domain cutover |
| [vivid-smiles-website/README.md](vivid-smiles-website/README.md) | The Astro app itself |
| [vivid-smiles-website/DEPLOYMENT.md](vivid-smiles-website/DEPLOYMENT.md) | Touching DNS — the Google Workspace MX and `_dmarc` records that must survive any zone move — plus the redirect, header and third-party tag inventory |
| [vivid-smiles-website/VERCEL-DEPLOYMENT-NOTES.md](vivid-smiles-website/VERCEL-DEPLOYMENT-NOTES.md) | Vercel-specific working notes, including the stale deployments below |
| [docs/MIGRATION-PLAN.md](docs/MIGRATION-PLAN.md) | How the migration was carried out — a historical record |

## Quick start

Requires Docker Desktop (running) and Node 22+.

```bash
cd cms && npm install && npm start
```

That boots WordPress and provisions it. Then, in a second shell:

```bash
cd vivid-smiles-website && npm install && cp .env.example .env && npm run dev
```

| URL | What |
| --- | --- |
| http://localhost:4321 | The Astro site |
| http://localhost:8888/wp-admin | WordPress editor |
| http://localhost:8888/graphql | GraphQL endpoint |

The local WordPress uses the throwaway account `wp-env` creates on first start —
see the [@wordpress/env docs](https://www.npmjs.com/package/@wordpress/env) for
its defaults. No credentials are stored in this repository, and none of them are
valid against the hosted CMS.

Local development points at the local CMS; `.env.example` sets
`WP_GRAPHQL_ENDPOINT` to `http://localhost:8888/graphql`. To develop against the
hosted content instead, point that variable at the hosted endpoint.

## How a change reaches the live site

**Content edits.** An editor saves in wp-admin. Nothing happens until a build
runs — the site is static, so a publish is not a deploy. A deploy hook that
fires on publish is still outstanding; until then a build has to be triggered.

**Code changes.** Push to `main`. Vercel's Git integration builds and promotes
to production automatically.

The build fetches from WordPress, so **the CMS must be reachable for any build
to succeed**. That is the single fact that shapes deployment here, and it is why
WordPress is hosted rather than local-only.

## Migration status

| Content | Where it's edited |
| --- | --- |
| Reviews / testimonials (20) | wp-admin → Testimonials |
| Blog posts (14) | wp-admin → Posts |
| Page copy — 938 rows across 32 pages | wp-admin → Pages |
| Media — 131 items | wp-admin → Media |
| Phone, email, address, hours, booking URL | wp-admin → Practice Settings |
| Navigation and footer menus | wp-admin → Appearance → Menus |

**938 editable rows** moved into WordPress: 213 section headings and intros, 200
image rows, 187 cards and list items, 166 table-of-contents links, 122 FAQ
entries and 50 process steps — plus every practice detail that appears in the
nav, footer, CTAs and structured data.

Verified by comparing the rendered visible text of every route against a
pre-migration snapshot: **47/47 identical**, 0 references to the CMS host, and
an edit made in wp-admin confirmed appearing on the built page.

### What is still in code, and why

- **Layout and structure.** WordPress changes the words, not the design. There
  is no section ordering and no free-form block list — each page's CSS assumes a
  fixed structure, and a page builder would let an editor produce pages the
  stylesheet was never written for.
- **26 image-bearing arrays** (team photos, service icons, trust-bar logos,
  before/after cases). Their rows reference imported image assets, so the copy
  and the asset are one unit; splitting them would leave an editor able to
  change a caption but not the picture it describes.
- **16 duplicated eyebrow labels.** The same short label appears twice inside
  one section, so the migration could not tell which to replace and skipped both
  rather than guess. Listed in the output of `rewire-sections.mjs`.
- **Fine-grained inline copy** — button labels, captions and short spans woven
  into bespoke markup.

Everything above is reported by the import scripts rather than silently
omitted.

## Content is portable, not trapped

The local WordPress content lives in a Docker volume that `wp-env destroy`
deletes. Everything needed to recreate the install sits outside it, in the repo:

| What | Where | Survives `wp-env destroy` |
| --- | --- | --- |
| Posts, reviews, settings | `cms/backup/database.sql` (`npm run backup`) | Yes |
| Media uploads | `cms/uploads/` — mapped to the host, not Docker | Yes |
| Content model, plugins | `cms/mu-plugins/`, `cms/bin/setup.sh` | Yes — declared in code |

This is what made moving to a real host a copy rather than a rebuild, and it is
what would make the next move — to `cms.vividsmilesdentistry.com` — the same.
The dump deliberately excludes user accounts, so restoring it onto a host leaves
that host's logins alone.

## Known constraints

**No paid plugins.** The page model needs repeater fields, which ACF charges
for — so this uses Secure Custom Fields, WordPress.org's fork of ACF, which
ships repeater, flexible content, gallery, clone and options pages free and GPL.
It is a drop-in for ACF: same function surface, same storage format, and
WPGraphQL for ACF resolves against it unchanged. See
[cms/README.md](cms/README.md#secure-custom-fields-not-acf).

**Publishing is not deploying.** Because content is fetched at build time, an
edit in wp-admin does not appear until the next build. This is the trade for
serving static HTML, and it is why the deploy hook matters.

**Structured data stays in Astro.** 29 pages carry hand-written JSON-LD
(`Dentist`, `MedicalProcedure`, `FAQPage`, `BreadcrumbList`) that is more
specific than Yoast's generated output. Schema is generated in Astro from
WordPress field data rather than delegated to Yoast — so it remains a developer
concern, not an editor-facing one.

## Outstanding

- Make this repository private.
- Move the CMS to `cms.vividsmilesdentistry.com`, then update
  `WP_GRAPHQL_ENDPOINT` and `image.remotePatterns` in `astro.config.mjs`.
- Cut `vividsmilesdentistry.com` over to Vercel — runbook in
  [docs/DEPLOYING.md](docs/DEPLOYING.md).
- Add a Vercel deploy hook and fire it from WordPress on publish, debounced.
- Relax GoDaddy's Cloudflare rules for `/wp-content/uploads/` and `/graphql`.
  Bot protection returns 429 under the burst a cold build produces; the build
  works around it, but the workaround is not the fix.
- Retire the previous Vercel project. `vivid-smiles.vercel.app` and
  `vivid-smiles-website.vercel.app` both still return 200 and serve a
  pre-migration copy of the site — no legacy redirects, no trailing-slash
  enforcement, no Yoast sitemap, and `robots.txt` set to `Allow: /`. Three
  crawlable copies of the same marketing site is a duplicate-content problem
  that gets worse the moment the real domain is attached. Handle it before
  cutover.
- Confirm the Facebook URL `facebook.com/VivdSmiles/` — it appears to be missing
  an "i". Fixing it means editing `src/components/Footer.astro`,
  `cms/import/import-wp-settings.php` and the stored option in WordPress; the
  importer will not overwrite a value already corrected in wp-admin.
- Confirm the call-tracking account is the practice's own. The script that
  rewrites the displayed phone number is WhatConverts profile `162233` —
  identified in `BaseLayout.astro` and `LandingLayout.astro` and disclosed by
  name in the privacy policy — but the account itself has not been confirmed
  against the client's vendor list.
