# Vivid Smiles — Headless WordPress CMS

The content backend for the Astro site in `../vivid-smiles-website`.

WordPress here is **content storage and an editing UI only**. It renders no
public pages: every front-end request is redirected to the Astro site, and the
Astro build reads content from WPGraphQL at build time. Visitors never touch
WordPress.

```
wp-admin (editors)                      Astro build (CI)
      │                                       │
      ▼                                       ▼
┌──────────────┐   WPGraphQL /graphql   ┌───────────┐   static HTML   ┌────────┐
│  WordPress   │ ─────────────────────► │  loaders  │ ──────────────► │ Vercel │
└──────────────┘                        └───────────┘                 └────────┘
```

## There are two installs

This directory is the source for both, but they are different machines and it
is worth knowing which one you are looking at.

| | Local | Hosted |
| --- | --- | --- |
| What | wp-env + Docker, your development copy | GoDaddy Managed WordPress, the CMS the production build reads |
| Address | `http://localhost:8888` | `https://1230613.us28.myftpupload.com` (temporary hostname) |
| Content | whatever you have imported | the real content: 14 posts, 33 pages, 20 testimonials |
| `VS_FRONTEND_URL` | `http://localhost:4321`, from `.wp-env.json` | `https://vivid-smiles-headless.vercel.app`, from `mu-plugins/vs-config.php` |

Everything below describes the local install. For the hosted one — how it was
stood up, moving it to `cms.vividsmilesdentistry.com`, the deploy hook, and the
domain cutover — see [../docs/DEPLOYING.md](../docs/DEPLOYING.md).

`mu-plugins/` is the same code in both places and is deployed by uploading the
directory. Nothing in it is environment-specific except `vs-config.php`.

## Requirements

- Docker Desktop, running (`docker info` must succeed)
- Node 22+

## Getting started

```bash
npm install
npm start
```

`npm start` runs `wp-env start` and then `bin/setup.sh`.

| URL | What |
| --- | --- |
| http://localhost:8888/wp-admin | Editor UI |
| http://localhost:8888/graphql | GraphQL endpoint |
| http://localhost:8888/graphql (browser) | GraphiQL IDE, when logged in |

Sign in with the throwaway account `wp-env` creates on first start; its defaults
are documented in [@wordpress/env](https://www.npmjs.com/package/@wordpress/env).
Deliberately not repeated here: this repository is public, and a credential
written into a README is a credential published. It is local-only in any case
and is not valid against the hosted CMS.

Then point the Astro site at it — from `../vivid-smiles-website`:

```bash
cp .env.example .env
npm run build
```

`.env.example` defaults to `http://localhost:8888/graphql` and carries the
hosted and production endpoints commented out. `WP_GRAPHQL_ENDPOINT` is the only
variable the build needs; without it the build fails on the first loader rather
than shipping an empty site.

## Why `bin/setup.sh` runs on every start

`.wp-env.json` sources WordPress core from `https://wordpress.org/latest.zip`,
and wp-env re-extracts core on each `wp-env start` — which resets
`wp-content/plugins`. Plugins installed by WP-CLI do not survive a restart.

The database is a separate Docker volume and **is** preserved, so your content
is safe across restarts; only the plugin set is rebuilt. `bin/setup.sh` is
idempotent, so re-running it is always safe.

Plugins are installed **by slug**, not by zip URL, and this matters: wp-env
derives a plugin's directory name from the zip filename, so a URL source lands
in `wp-content/plugins/wp-graphql.latest-stable/`. WordPress 6.5+ resolves the
`Requires Plugins:` header against the directory slug, so WPGraphQL for ACF
refuses to activate against that folder name. Slug installs produce
`wp-content/plugins/wp-graphql/` and resolve correctly.

## Plugins

Installed in this order — `wp-graphql` must be active before `wpgraphql-acf`,
and `wordpress-seo` before `add-wpgraphql-seo`.

| Plugin | Pin | Why |
| --- | --- | --- |
| WPGraphQL | 2.19.0 | The API the Astro build reads from |
| Secure Custom Fields | 6.9.5 | Structured fields beyond title/body |
| WPGraphQL for ACF | 2.7.0 | Exposes those fields to GraphQL |
| Yoast SEO | 28.2 | Editor-facing SEO fields |
| WPGraphQL Yoast SEO Addon | 5.1.0 | Exposes Yoast fields to GraphQL |

**The versions are pinned, and `bin/setup.sh` disables auto-updates.** WPGraphQL
for ACF does not officially support SCF. It works because its only dependency
check is `class_exists('ACF')` and SCF declares `class ACF` — the compatibility
is incidental, not contractual (wpgraphql-acf issue #264, open since Feb 2026,
no maintainer response). A release that tightens that check or branches on
`ACF_VERSION` would break the Astro build with no warning. Upgrade deliberately,
after testing a build, rather than letting an unattended update decide when the
blog stops rendering.

### Secure Custom Fields, not ACF

The content model needs **repeaters**. There are seven — `toc_links`,
`process_steps`, `sections`, `images`, `cards` and `faqs` on the page group, and
`office_hours` on Practice Settings — plus a `gallery` field for the smile
gallery and an options page to hang the practice settings on. Repeater, gallery
and options pages are all ACF **Pro** features ($49/yr for one site, $149/yr for
ten); ACF free has none of them.

Secure Custom Fields is WordPress.org's fork of ACF, and it ships the paid field
types — `repeater`, `flexible_content`, `gallery`, `clone` — plus options pages,
free and GPL from the official plugin repo. Verified on this install: SCF 6.9.5
registers 37 field types against ACF free's 32.

It is a drop-in rather than a migration:

- defines `ACF_VERSION` and the same `acf_*` function surface, so
  `acf_add_local_field_group()` and `update_field()` work unchanged,
- stores values in the same post-meta format, so existing content needs no
  conversion — the testimonial and post fields kept resolving through GraphQL
  after the swap with no edits,
- leaves **WPGraphQL for ACF** working as-is.

Both must never be active at once — they are the same plugin and would fight
over the same hooks. `bin/setup.sh` deactivates `advanced-custom-fields` if it
finds it active.

## The content model

Declared in code under `mu-plugins/`, not through wp-admin, so it is
version-controlled. Editing these field groups in the ACF UI will not persist.

Must-use plugins load in **filename order**, and the `vs-` prefix keeps that
order meaningful:

- **`vs-config.php`** — per-environment constants, currently just
  `VS_FRONTEND_URL`. Sorts first so it is defined before `vs-content-model.php`
  and `vs-headless.php` read it.
- **`vs-content-model.php`** — the `vs_testimonial` post type, the
  `vs_testimonial_tag` taxonomy, the three ACF field groups, the five canonical
  blog categories, and two custom GraphQL fields (`Page.vsRoute`,
  `Post.contentUpdatedAt`).
- **`vs-headless.php`** — front-end redirect, GraphQL CORS, `robots.txt`,
  `noindex` headers, and assorted trimming.
- **`vs-menus.php`** — the `primary` and `footer` menu locations plus the
  mega-panel fields (eyebrow, icon, image, focal point, layout).
- **`vs-seo.php`** — the hand-rolled `vsSeo` GraphQL field on `Page` and `Post`.
- **`vs-settings.php`** — the Practice Settings options page.
- **`vs-sitemap.php`** — narrows Yoast's sitemap to content that has a route.

Field names here are a contract with `../vivid-smiles-website/src/loaders/` and
the Zod schemas in `src/content.config.ts`. Renaming one is a breaking change on
both sides. WPGraphQL for ACF camelCases them on the way out, so `toc_links`
arrives in the loader as `tocLinks` and `section_id` as `sectionId`.

### `VS_FRONTEND_URL` lives in a mu-plugin, not `wp-config.php`

On the managed host `wp-config.php` is the wrong file: the platform rewrites it
during its own updates and migrations, and anything hand-added there disappears
without a warning. The failure is quiet — the constant vanishes, the redirect
stops, and the raw WordPress theme starts answering on the CMS domain.
`wp-content/` survives those updates, so the constant lives in
`mu-plugins/vs-config.php` instead.

It is guarded with `defined()` rather than a bare `define()`. Locally wp-env
writes the constant into the container's `wp-config.php` from `.wp-env.json`,
and `wp-config.php` loads first — so the local value (`http://localhost:4321`)
wins and `vs-config.php` acts as the production fallback it is. Without the
guard every local request would raise "Constant VS_FRONTEND_URL already
defined".

`frontend_url()` in `vs-headless.php` returns `null` when the constant is
missing, and the redirect is skipped. That is deliberate: it used to fall back
to `http://localhost:4321`, which meant a deploy without the constant set 302'd
real visitors to a dead address on their own machine. Serving WordPress — still
noindexed, still `Disallow: /` — is the harmless outcome.

Change the constant at domain cutover. It is the only place the front-end URL is
configured on the CMS side.

### Blog categories are a public contract

The five category names are a closed enum in the Astro schema, the runtime keys
for the client-side blog filter, and are embedded verbatim in shared
`/blog/?category=…` URLs. Renaming one breaks previously-shared links and drops
posts out of the hub filter. Add new ones in **both** places.

### The sitemap is generated here and served there

Yoast generates the XML sitemap on this host. The Astro build fetches it,
rewrites the CMS origin to the public origin, verifies every URL against a page
the build actually produced, and writes the result into `dist` — see
`../vivid-smiles-website/src/integrations/yoast-sitemap.ts`. It is never crawled
here (`robots.txt` on this host is `Disallow: /`) but it has to exist for the
build to read it, which is why `bin/setup.sh` sets `wpseo.enable_xml_sitemap`
to `true`.

`vs-sitemap.php` narrows it to `post` and `page`. Left alone Yoast would list
20 `vs_testimonial` URLs — reviews are data, they render inside other pages and
have no route of their own — plus category, author and attachment archives the
Astro site does not generate. Every one of those is a 404 for a crawler.

**URLs are not rewritten here, deliberately.** Yoast validates that each entry
belongs to the site's own host and silently drops anything foreign, so a
`wpseo_sitemap_entry` filter swapping the hostname produces a valid-looking
sitemap file with zero URLs in it. The rewrite has to happen in the Astro build.

Two loose ends worth knowing before you touch any of this:

- `vs-headless.php` still carries
  `add_filter( 'wpseo_enable_xml_sitemap', '__return_false' )` and
  `import/import-wp-settings.php` still writes the same option `false`. Both
  predate the build consuming the sitemap and neither currently wins — verified
  2026-08-13: `/sitemap_index.xml`, `/page-sitemap.xml` and `/post-sitemap.xml`
  all return 200 on the hosted CMS. Confirm a build still produces a sitemap
  before "fixing" any of the three in either direction.
- Sitemap paths are **not** in `redirect_frontend()`'s passthrough list in
  `vs-headless.php`, yet they are served rather than redirected. The likely
  reason is hook ordering — Yoast emits the sitemap and exits before
  `template_redirect`, where `redirect_frontend()` runs at priority 0. That has
  not been confirmed against Yoast's source and nothing here enforces it, so
  re-check the build's sitemap fetch after any Yoast upgrade.

## What an editor can change

Nothing hides or restricts the standard admin menus. On top of them:

| Screen | What is editable |
| --- | --- |
| **Posts** | Title, body, excerpt, featured image, date, category (the five, default Dental Tips). Sidebar panel *Post — Astro fields*: hero alt (required — a post without it is skipped at build), author override. |
| **Pages** | Title, slug, parent, order. The content editor is removed and the classic editor forced, because nothing renders a page's `post_content` and an empty canvas reads as "the page lost its content". All copy lives in the *Page content* metabox: On this page, Process, Section copy, Images, Cards & lists, FAQ. |
| **Testimonials** (menu position 21) | Title (the admin label), body (the review text), order. Panel *Testimonial*: reviewer name, rating 1–5, source. Review Tags is a flat taxonomy alongside it. |
| **Practice Settings** (position 22, `edit_posts`) | Contact, Address, Opening hours, Smile gallery, Logos, Forms. Save reads "Save settings" and confirms "They go live on the next site build." |
| **Appearance → Menus** | *Primary navigation (header + mobile)* and *Footer links*, plus *Menu item — appearance*: eyebrow, icon, image, focal point, panel layout. |
| **Yoast SEO metabox** | SEO title, meta description, canonical, per-post noindex, OG image. The per-post noindex checkbox is honoured; the site-wide setting is deliberately ignored, because this host sets `blog_public = 0` and reading that globally would deindex the public site. |

Three fields are marked readonly and "Set by the migration. Do not change." —
`sections.section_id`, `images.slot` and `cards.group`. They are the keys that
tie a row to its place in a bespoke layout; changing one detaches the content.

There are deliberately **no hero fields** on pages. An earlier version had them,
nothing populated or rendered them, and an editor could type a new headline,
save, and see no change. A field that silently does nothing is worse than an
absent one.

## Importing the original markdown

A one-time migration of the repo's markdown and Astro templates into WordPress.
It has already been run — the hosted CMS holds the result — so these commands
exist to make the migration reproducible on a fresh install, not as a routine
step.

Every importer is idempotent: posts and testimonials record their source
filename in `_vs_import_slug`, pages match on `_vs_route`, attachments on
`_vs_import_src`. Re-running updates rather than duplicating.

```bash
npm run import:all
```

That chains nine importers in dependency order:

| # | Command | What it imports |
| --- | --- | --- |
| 1 | `import:wp-settings` | WordPress general options, Yoast site representation and social profiles |
| 2 | `import:settings` | Practice Settings — contact, address, hours, Typeform id |
| 3 | `import:gallery` | The smile gallery photos and the two brand logos |
| 4 | `import:reviews` | `src/content/reviews/*.md` → `vs_testimonial` posts |
| 5 | `import:blog` | `src/content/blog/*.md` → posts, with media sideloaded |
| 6 | `import:pages` | 31 Astro routes → nested pages, plus TOC/process/FAQ rows and Yoast titles |
| 7 | `import:sections` | Section prose and card lists onto those pages |
| 8 | `import:images` | 82 unique files filling 200 image slots across 29 pages |
| 9 | `import:menus` | Primary and Footer menus with their mega-panel fields |

The order is not cosmetic. Steps 7 and 8 match pages on `_vs_route`, so both
need step 6 to have run. Step 9 matches media on `_vs_import_src`, so it wants
step 8 first. One wrinkle: step 1 looks up the Yoast company logo among the
attachments step 3 creates, so on a first run it reports the logo as not set and
a second run fills it in.

**`import:pages` regenerates its payload before importing, and that is a trap.**
`build-pages-payload.mjs` reads the structured arrays out of the Astro page
templates — and the rewiring step already removed them from the working tree, so
a plain re-run extracts nothing. It refuses to overwrite a payload holding more
rows than the current run produced and tells you to point `VS_PAGES_DIR` at a
pre-migration checkout. The committed `import/pages-payload.json` is the artifact
that matters; losing it is silent and only shows up later as blank sections on
the live site.

`import/rewire-*.mjs` are the other half of the migration: one-way edits to the
Astro templates so they read from WordPress. They are not wired to npm scripts,
they all accept `--dry`, and they have already been run.

## Content durability

WordPress content lives in a Docker volume, which `wp-env destroy` deletes.
Two things keep it safe and portable:

- **`uploads/`** — `wp-content/uploads` is mapped to the host, so media sits in
  this directory as ordinary files rather than inside Docker.
- **`backup/database.sql`** — written by `npm run backup`.

Together those are a complete, portable copy of the site. Back up before
destroying anything, and after any editing session worth keeping:

```bash
npm run backup     # -> backup/database.sql
npm run restore    # <- backup/database.sql
```

`restore` also accepts a target URL, which is what makes the same dump usable
against a real hostname:

```bash
bash bin/restore.sh https://cms.vividsmilesdentistry.com
```

`backup/SITEURL` records the URL the dump was taken from and is currently
`http://localhost:8888`, so a restore onto any hosted install needs that
argument or every internal link, attachment GUID and serialized option keeps
pointing at localhost.

**The dump carries content, not accounts.** `backup.sh` excludes `wp_users` and
`wp_usermeta`, so a restore leaves the target install's own logins alone and no
password hash is ever written into this repository. Posts stay attributed to
user 1, which exists on any WordPress install.

The exclusion happens at the table level rather than by stripping rows, and that
distinction is load-bearing: the export uses `--add-drop-table`, so a dump that
merely had its user rows removed would still drop and recreate `wp_users` on
import — wiping the target's accounts and locking everyone out.

The URL rewrite runs through `wp search-replace`, not SQL. WordPress stores
serialized PHP in the options table, where string lengths are encoded alongside
the values — a plain find/replace on the dump corrupts every serialized option
whose URL changes length.

This has been verified end to end: back up, delete records, restore, rebuild,
confirm rendering.

## Commands

| Command | What |
| --- | --- |
| `npm start` | Start WordPress and provision it |
| `npm run start:only` | Start the containers without re-provisioning |
| `npm run stop` | Stop the containers |
| `npm run setup` | Re-provision plugins and options |
| `npm run backup` | Export the database to `backup/database.sql` |
| `npm run restore` | Restore from `backup/database.sql` |
| `npm run cli -- <args>` | Run WP-CLI, e.g. `npm run cli -- plugin list` |
| `npm run logs` | Tail container logs |
| `npm run clean` | `wp-env clean all` — **wipes both databases**, containers kept |
| `npm run destroy` | Delete the containers **and the database** |
| `npm run import:all` | Run the nine importers in order |
| `npm run import:wp-settings` | WordPress and Yoast site-wide options |
| `npm run import:settings` | Practice Settings |
| `npm run import:gallery` | Smile gallery and brand logos |
| `npm run import:reviews` | `src/content/reviews/*.md` |
| `npm run import:blog` | `src/content/blog/*.md` |
| `npm run import:pages` | Astro routes → pages (read the trap above first) |
| `npm run import:sections` | Section prose and card lists |
| `npm run import:images` | Page images |
| `npm run import:menus` | Primary and Footer menus |

## Hosted CMS

Provisioned and live at `https://1230613.us28.myftpupload.com` — GoDaddy Managed
WordPress, on a temporary hostname. All content is migrated and
`mu-plugins/` on the host matches this repo. The full runbook is in
[../docs/DEPLOYING.md](../docs/DEPLOYING.md); the standing constraints are:

- Keep the CMS on its own hostname (`cms.vividsmilesdentistry.com` once moved)
  so it is never confused with the public site. Moving it means updating
  `WP_GRAPHQL_ENDPOINT` in the Vercel project and `image.remotePatterns` in
  `../vivid-smiles-website/astro.config.mjs` in the same change.
- Set `VS_FRONTEND_URL` in `mu-plugins/vs-config.php`, never in `wp-config.php`.
- Keep `blog_public` off and leave the `robots.txt` disallow in place.
- Put a caching CDN in front of `/wp-content/uploads/` — the build machine pulls
  every image through it, and a slow or failing origin hard-fails the build.
  This host sits behind Cloudflare bot protection that answers a cold burst with
  429s, which is why `../vivid-smiles-website/scripts/warm-media-cache.mjs` runs
  before every build and `src/lib/wp.ts` treats 429 as retryable. Relaxing the
  host's Cloudflare rules for `/wp-content/uploads/` and `/graphql` is the
  durable fix and has not been done.
- Add the Vercel deploy hook so publishing triggers a rebuild. No hook code
  exists in `mu-plugins/` yet.
