# Vivid Smiles headless — session handoff

_Last verified: 13 August 2026._ Written to hand this work to a fresh session (human or AI)
without re-deriving any of it. Where this file and any other README disagree, this file was
checked against the live systems more recently — but re-verify before trusting it.

## What this project is

Headless WordPress (content) → Astro (build) → Vercel (static hosting). WordPress renders no
public pages; every front-end request to the CMS is redirected, and the Astro build reads
content from WPGraphQL at build time.

| Piece | Where |
| --- | --- |
| Repo | github.com/allanconcepcion/vivid-smile-dentistry-headless |
| Front end (production) | https://vivid-smiles-headless.vercel.app |
| Hosted CMS | https://1230613.us28.myftpupload.com (GoDaddy Managed WP, temporary hostname) |
| Vercel project | vercel.com/allans-projects-cc55d7b7/vivid-smiles-headless |
| Future CMS domain | cms.vividsmilesdentistry.com (not moved yet) |

## Current state

Both open PRs are merged to `main` and live in production.

- **PR #1 — `sitemap-frontend-origin`**, merged as `409764d`.
  `astro.config.mjs` now derives `site` from `SITE_URL` → `VERCEL_PROJECT_PRODUCTION_URL` →
  `FRONT_END_URL`. New `src/integrations/robots.ts` writes `dist/robots.txt` per host:
  a `.vercel.app` host or localhost gets `Disallow: /` with no `Sitemap:` line; a real custom
  domain gets `Allow: /` plus two `Sitemap:` lines.
- **PR #2 — `wp-pages-catch-all`**, merged as `e572bf1`.
  `src/pages/[...slug].astro` builds any published WordPress page that has no hand-built Astro
  route, assembling it from the page's structured fields (there is no `post_content` — see
  Gotchas). Ships with `src/styles/pages/wp-page.css`.

Most recent production deployment: `2rHPzmNTJ`, Ready in 1m52s, triggered by a manual redeploy.
Its log reported `Loaded 34 pages`, `Yoast sitemap: 43 URLs across 2 child sitemap(s), every one
built`, and `49 page(s) built`.

## The verification checklist

Run this against whichever host is being verified. Report pass/fail for each item **with the
actual value observed**, not just a verdict.

1. `/test/` renders a real page, not "Page not found".
2. `<link rel="canonical">` on `/test/` — the host in it must equal the host being browsed.
3. `/robots.txt` — on a `.vercel.app` host it must say `Disallow: /`; on a custom domain,
   `Allow: /` plus two `Sitemap:` lines.
4. `/sitemap_index.xml` and `/page-sitemap.xml` — the `<loc>` host must equal the host being
   browsed. A mismatch is the original bug this work fixed.
5. `/test/` appears in `page-sitemap.xml`.
6. Spot-check `/`, `/cosmetic-dentistry/` and `/blog/` canonicals to confirm nothing regressed.

### Result, 13 August 2026 — 6 of 6 PASS

| # | Result | Observed |
| --- | --- | --- |
| 1 | PASS | `/test/` returns 200 and renders: breadcrumb "HOME / TEST", `<h1>Test</h1>`, one process step, one FAQ. Title `Test \| Vivid Smiles Dentistry` |
| 2 | PASS | `https://vivid-smiles-headless.vercel.app/test/` |
| 3 | PASS | Three comment lines, then `User-agent: *` and `Disallow: /`. No `Sitemap:` line |
| 4 | PASS | Index lists 2 children; page-sitemap 28 `<loc>`, post-sitemap 15 `<loc>`, all on `vivid-smiles-headless.vercel.app` |
| 5 | PASS | `https://vivid-smiles-headless.vercel.app/test/` present |
| 6 | PASS | `/` → `.../` · `/cosmetic-dentistry/` → `.../cosmetic-dentistry/` · `/blog/` → `.../blog/` |

Fastest way to re-run: open the site, then run a same-origin `fetch()` loop over the routes and
regex out `<link rel="canonical" href="...">`, `<title>` and `<h1>`. Cross-origin fetch is blocked,
so this has to run from the site's own origin.

## Plugins — verified live in wp-admin, not copied from a README

The field engine is **Secure Custom Fields 6.9.5 (Active)**. **Advanced Custom Fields 6.8.7 is
installed but INACTIVE and must stay that way** — they are the same plugin and would fight over
the same hooks. SCF is used because the content model needs repeaters, gallery and options pages,
which are paid ACF Pro features but free in SCF. The admin menu reads "SCF", and field inputs
still carry the `acf[...]` name prefix — that drop-in compatibility is the whole reason
WPGraphQL for ACF resolves against SCF at all. Its dependency check is only
`class_exists('ACF')`, and SCF declares `class ACF`. That is incidental, not contractual, so
**auto-updates must stay off**.

Active (7): Add WPGraphQL SEO 5.1.0 · All-in-One WP Migration 7.109 · AIOWPM Unlimited Extension
2.87 · Secure Custom Fields 6.9.5 · WPGraphQL 2.19.0 · WPGraphQL for ACF 2.7.0 · Yoast SEO 28.2

Inactive (3): Advanced Custom Fields 6.8.7 · Akismet 5.7 · Hello Dolly 1.7.2

**README drift:** `cms/README.md` says eleven plugins. There are now **ten — WP File Manager has
been removed.** That is a good change; the README should be updated to match. Auto-updates are
off for the five setup plugins and on for the other five, which is correct.

**Must-use plugins on the host = 9:** Object Cache Pro (MU), GoDaddy's System Plugin, and seven
`vs-*` plugins. **`vs-deploy.php` is not among them.** It exists in the repo but was never
uploaded, which is why publishing content does not trigger a rebuild.

## Open issues

1. **`vs-deploy.php` is missing on the host.** Until it is uploaded and `VS_DEPLOY_HOOK_URL` is
   set in `vs-config.php`, every content change needs a manual Vercel redeploy. Highest-value fix.
2. **Section ID is both `required` and `readOnly`.** On a new page you can add a Section copy row
   but can never fill the ID, so the row cannot validate — the whole tab is unusable for pages the
   importer did not create. The same lock applies to `images.slot` and `cards.group`. Today a new
   page can only use the On this page, Process and FAQ tabs.
3. **The catch-all skips pages with no copy in any field.** A page with only a title logs
   `[wp-pages] <route> has no content in any field`, is not built, 404s, and is dropped from the
   sitemap. Worth rendering title-only pages instead.
4. **A test page is still published** at `/test/`. Pages → Test → Move to Trash when finished,
   then redeploy.
5. **The repo is public** and contains `cms/uploads/` with roughly 74 identifiable patient
   photographs, a 2.3 MB database dump, and a WordPress password hash in git history before commit
   `9f41107`. Not addressed.
6. **All-in-One WP Migration and its Unlimited Extension are active and undocumented** on an
   internet-facing admin. Neither is needed by the build. Review before launch.
7. **Cloudflare in front of the CMS** answers cold bursts with 429s. `warm-media-cache.mjs` and
   retry logic in `src/lib/wp.ts` paper over it; relaxing the rules for `/wp-content/uploads/` and
   `/graphql` is the durable fix.

## Gotchas, learned the hard way

- **`getStaticPaths` is hoisted out of Astro frontmatter.** Only imports travel with it, so
  anything else it needs must be declared inside it. Otherwise the build dies with
  `X is not defined`. This cost two failed deployments.
- **Pages have no `post_content`.** `vs-content-model.php` calls
  `remove_post_type_support('page','editor')` deliberately, so WPGraphQL exposes no `content`
  field on `Page` and querying it fails the build. A page *is* its structured fields; read them
  through `getCollection("pages")`.
- **`vsRoute` comes from `_vs_route` post meta, written only by the importer.** A page created by
  an editor has `vsRoute: null` and the loader falls back to `uri`. That is the intended path for
  new pages, not a bug.
- **Yoast's sitemap is generated on the CMS and rewritten by the Astro build.** Rewriting the
  hostname inside WordPress does not work — Yoast validates entries against its own host and
  silently drops foreign ones, producing a valid-looking file with zero URLs.
- **A sitemap URL with no corresponding built page is dropped with a warning, not a build
  failure.** That is deliberate, so an editor creating a page cannot break a deploy.
- **GraphQL introspection is disabled for public requests.** The structured field group is exposed
  as `pageFields` on `Page`; `vsSeo` and `vsRoute` are separate custom fields.
- **Read the actual Vercel build log rather than guessing.** Expand "Deploy Logs" on the
  deployment, then use "Find in logs". One blind fix wasted a whole cycle.
- **`raw.githubusercontent.com` and `/pull/N.diff` are not readable** from the browser tooling.
  Use `/blob/<branch>/<path>?plain=1` instead.

## Suggested next steps

1. Deploy `vs-deploy.php` and set `VS_DEPLOY_HOOK_URL` so publishing rebuilds the site
   automatically. This is what makes the whole "editors can add pages" story actually true.
2. Unlock Section ID for rows that do not yet have one, so new pages can use section copy.
3. Render title-only pages instead of skipping them.
4. Delete the `/test/` page and redeploy.
5. Update `cms/README.md` for the ten-plugin reality.
