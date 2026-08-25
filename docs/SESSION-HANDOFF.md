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

Production sat at `cddbf6d` with `49 page(s) built` and `Yoast sitemap: 43 URLs across 2 child
sitemap(s), every one built`. Recent production deployments are **triggered by the deploy hook**,
not by hand — Vercel labels them `Created: Deploy Hook`.

**Four PRs merged on 13 August 2026:**

- **#3 `wp-pages-title-only`**, merged as `3de80cd` — builds a page that has only a title instead
  of skipping it. Verified end to end on the branch preview before merging. See Open issues 3.
- **#4 `docs-handoff-correction`** — this file and `cms/README.md`.
- **#5 `scf-unlock-empty-importer-ids`**, merged as `62c887c` — unlocks the importer-owned repeater
  IDs on rows that hold no value yet. CMS-side only, so it has no build impact and **does nothing
  until `vs-content-model.php` reaches the host.** See Open issues 2.
- **#6 `deploy-mu-plugins-script`**, merged as `d9cc1a6` — `cms/bin/deploy-mu-plugins.sh`, which is
  how that copy is now supposed to happen.

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

Inactive (3): Advanced Custom Fields 6.8.7 · Akismet 5.7 · **WP File Manager 8.0.4**

**Correction, 13 August 2026.** An earlier revision of this file said WP File Manager had been
removed and listed Hello Dolly as inactive. Both were wrong, and the wrong one matters: **WP File
Manager 8.0.4 is still installed, merely deactivated** — one click from live on an
internet-facing admin, on a plugin with a long history of remote-code-execution bugs. Hello Dolly
is the plugin that is actually gone. Verified in wp-admin: All (10), Active (7), Inactive (3).

**README drift: resolved.** Re-checked 13 August 2026 — `cms/README.md` lists the same ten
plugins as the table above and records that Hello Dolly was removed, so the two no longer
disagree. Auto-updates are off for the five setup plugins and on for the other five, which
is correct.

**Must-use plugins on the host = 10:** Object Cache Pro (MU), GoDaddy's System Plugin, and eight
`vs-*` plugins. **`vs-deploy.php` IS among them** — it appears in wp-admin under Must-Use as
"Vivid Smiles — Deploy trigger 0.1.0". An earlier revision of this file claimed it had never been
uploaded. That was wrong; see Open issues.

## Open issues

Numbered for reference, not ranked — other files link to these numbers, so they stay put. By
severity, **5 is first**: a working WordPress password hash is published in this repository's
history right now and nobody has rotated it.

1. ~~**`vs-deploy.php` is missing on the host.**~~ **Resolved — and it was never true.** Both host
   steps are done. Evidence, 13 August 2026: wp-admin lists "Vivid Smiles — Deploy trigger" under
   Must-Use; publishing a page raised the plugin's own admin notice, "Front-end rebuild queued —
   the live site updates in about 2 minutes", which `notice()` only prints when
   `wp_next_scheduled('vs_deploy_build')` is set, and `queue()` returns early when
   `VS_DEPLOY_HOOK_URL` is undefined — so the constant is defined in `vs-config.php` on the host.
   Roughly two minutes later Vercel showed a Production deployment with **Created: Deploy Hook**,
   Ready. Trashing the page fired it again. Publishing in WordPress rebuilds the front end. The
   manual-redeploy instruction elsewhere in this repo is now only a fallback.
2. **Section ID is both `required` and `readOnly`.** On a new page you can add a Section copy row
   but can never fill the ID, so the row cannot validate — the whole tab is unusable for pages the
   importer did not create. The same lock applies to `images.slot` and `cards.group`. Today a new
   page can only use the On this page, Process and FAQ tabs. The mechanism: `readonly` renders the
   input with a readonly attribute, so it still posts — it posts an empty string, and `required`
   then rejects it. This is not theoretical: on 13 August an editor filled in a section's eyebrow,
   heading, body and button, could not type an ID, and lost the save. **Fixed in PR #5, merged as
   `62c887c`** — the field locks only once it holds a value, via `acf/prepare_field`.
   **Deployed to the host and verified live, 13 August 2026** (`md5 ebc646b0…`, `php -l` clean).
   On Pages, Add Page all three fields report `readonly: false` with the new instruction text; on
   the imported Cosmetic Dentistry page all **9** saved section rows — `why`, `services`,
   `technology`, `doctors`, `process` and the rest — still report `readonly: true`, and its blank
   clone row is unlocked so a new row can be added. Both halves of the rule hold.
3. **The catch-all skips pages with no copy in any field.** A page with only a title logs
   `[wp-pages] <route> has no content in any field`, is not built, 404s, and is dropped from the
   sitemap. **Fixed in PR #3, merged as `3de80cd`.** Verified end to end on the branch preview
   before merging: a published `Title Only Test` page logged
   `[wp-pages] /title-only-test/ has no content in any field — building it title-only.`, emitted
   `/title-only-test/index.html`, and rendered as hero-only (50 pages built, 44 sitemap URLs, all
   built). The same URL returned **404** on production, which still runs `main`. The PR also makes
   `tocLinks` count as copy — it did not before, so a page using only the "On this page" tab was
   also being skipped.
4. ~~**A test page is still published** at `/test/`.~~ **Resolved 13 August 2026.** Moved to Trash.
   No manual redeploy was needed: the deploy hook rebuilt production on its own, `/test/` now
   returns 404, and `page-sitemap.xml` is down to 27 URLs from 28.
5. **The repo is public, and a WordPress password hash is still retrievable from its history.**
   Verified 13 August 2026 by cloning unauthenticated and running
   `git show 9f41107^:cms/backup/database.sql`, which returns a dump containing `wp_users`,
   `wp_usermeta` and one password hash. Commit `9f41107` removed those tables going forward. That
   does not retire what is already published, and neither would making the repository private,
   because anything already cloned or indexed stays out. **Rotate that password.** It is the only
   remedy that works without rewriting history, and it has not been done.

   Two claims an earlier revision of this file made about this issue did not survive checking:

   - **The dump at HEAD is clean.** `cms/backup/database.sql` is 2.4 MB across 16 tables with no
     `wp_users` and no `wp_usermeta` — `backup.sh`'s table-level exclusion works as its commit
     message claims. The email addresses in it are 20 `@gutenbergtimes.com` (WordPress demo
     content), 2 `@example.com` and 2 `@vividsmilesdentistry.com`. No patient data. The exposure
     is in the 15 older revisions of that path, not the current one.
   - **"Roughly 74 identifiable patient photographs" is not supported.** `cms/uploads/` holds 661
     files, 31 MB, collapsing to 131 distinct base images: 25 `procedures-`, 19 `team-`,
     17 `people-`, 9 `smiles-`, and the rest facility, video and diagram assets. The nine
     `smiles-*` files are named `smiles-01-frontal-arch-berry-lip` and similar — art direction,
     not clinical records. This was read from filenames, deliberately not from the images, so
     whether any `team-*` or `people-*` portrait is a real patient rather than staff or stock
     remains an open question for someone who knows the shoot.

   Needing a person rather than a checker: rotate the password; decide whether to `git filter-repo`
   the dump out of all 15 revisions, which force-pushes `main`; confirm the portraits' provenance.
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

1. **Rotate two passwords.** The WordPress one whose hash is published in this repository's history
   (issue 5), and the host's SSH/SFTP password, which was pasted into a chat transcript on
   13 August. GoDaddy dashboard, site Settings, SSH/SFTP. A key would remove the second one
   permanently.
2. Decide what to do about WP File Manager — delete it, or accept an inactive RCE-history plugin
   on a public admin. Same question for All-in-One WP Migration, which is still active.
3. Verify anything this file claims before acting on it. Two of its three highest-priority items
   were stale within a day.
