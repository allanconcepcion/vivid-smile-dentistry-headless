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
| http://localhost:8888/wp-admin | Editor UI — `admin` / `password` |
| http://localhost:8888/graphql | GraphQL endpoint |
| http://localhost:8888/graphql (browser) | GraphiQL IDE, when logged in |

Then point the Astro site at it — from `../vivid-smiles-website`:

```bash
cp .env.example .env
npm run build
```

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

| Plugin | Why |
| --- | --- |
| WPGraphQL | The API the Astro build reads from |
| Advanced Custom Fields | Structured fields beyond title/body |
| WPGraphQL for ACF | Exposes ACF fields to GraphQL |
| Yoast SEO | Editor-facing SEO fields |
| WPGraphQL Yoast SEO Addon | Exposes Yoast fields to GraphQL |

> **ACF Pro is required before page content can be modelled.** Repeater,
> Flexible Content, Options Pages and Gallery are all Pro-only. The testimonial
> and post field groups here use only free field types; the page-level model
> (hero / process steps / FAQ blocks) needs Repeater and Flexible Content.
> $49/yr for one site, $149/yr for ten. Dev and staging installs do not count
> against the activation limit.

## The content model

Declared in code under `mu-plugins/`, not through wp-admin, so it is
version-controlled. Editing these field groups in the ACF UI will not persist.

- **`vs-content-model.php`** — the `vs_testimonial` post type, the
  `vs_testimonial_tag` taxonomy, ACF field groups, and the five canonical blog
  categories.
- **`vs-headless.php`** — front-end redirect, GraphQL CORS, `robots.txt`,
  `noindex` headers, and assorted trimming.

Field names here are a contract with `../vivid-smiles-website/src/loaders/` and
the Zod schemas in `src/content.config.ts`. Renaming one is a breaking change on
both sides.

### Blog categories are a public contract

The five category names are a closed enum in the Astro schema, the runtime keys
for the client-side blog filter, and are embedded verbatim in shared
`/blog/?category=…` URLs. Renaming one breaks previously-shared links and drops
posts out of the hub filter. Add new ones in **both** places.

## Importing the original markdown

One-time migration of the repo's markdown into WordPress. Idempotent — each post
records its source filename, so re-running updates rather than duplicating.

```bash
npm run import:reviews
```

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
against a real hostname when the site goes online:

```bash
bash bin/restore.sh https://cms.vividsmilesdentistry.com
```

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
| `npm run stop` | Stop the containers |
| `npm run setup` | Re-provision plugins and options |
| `npm run import:reviews` | Import `src/content/reviews/*.md` |
| `npm run backup` | Export the database to `backup/database.sql` |
| `npm run restore` | Restore from `backup/database.sql` |
| `npm run cli -- <args>` | Run WP-CLI, e.g. `npm run cli -- plugin list` |
| `npm run logs` | Tail container logs |
| `npm run destroy` | Delete the containers **and the database** |

## Production notes

Not yet provisioned. When it is:

- Host the CMS on its own hostname (`cms.vividsmilesdentistry.com`) so it is
  never confused with the public site.
- Set `VS_FRONTEND_URL` in `wp-config.php` to the live site URL.
- Keep `blog_public` off and leave the `robots.txt` disallow in place.
- Put a caching CDN in front of `/wp-content/uploads/` — the build machine pulls
  every image through it, and a slow or failing origin hard-fails the build.
- Add the Vercel deploy hook so publishing triggers a rebuild.
