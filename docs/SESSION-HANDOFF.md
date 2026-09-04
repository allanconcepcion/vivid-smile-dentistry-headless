# Vivid Smiles headless — session handoff

Written to hand this work to a fresh session — human, or an AI of any model — without
re-deriving any of it. **If this file and `git log` disagree, `git log` wins**; this file was
last brought fully in line with the tree at the commit below.

**State this file describes:** branch `main`, HEAD is on `origin/main` (2026-09-04) — everything
from the wp-admin round is committed AND pushed, so the repo, the live CMS and this file agree.
Everything before them is merged —
PR #10 (`page-blocks` → `cms-editor-safety`) and PR #9 (`cms-editor-safety` → `main`) both landed
on 2026-08-31 with merge commits, zero open PRs, both branches kept. The public domain has NOT
been cut over: the built site is a `Disallow: /` Vercel preview at
`vivid-smiles-headless.vercel.app`; the practice's real domain still serves the old site. The
cutover is a separate deliberate step (`docs/CUTOVER-PROMPT.md`).

A machine-local memory for Claude Code sessions also exists at
`~/.claude/projects/-Volumes-Concepcion-Work-Vivid-smiles-headless-setup/memory/` — it is a
convenience for one machine, not a source of truth. **This file is the portable one.**

## Read this first — the state in one screen

**What is editable in WordPress today, and where** (verified in wp-admin and in the build on
2026-09-01):

| Thing | Where it is edited | Coverage |
| --- | --- | --- |
| Section content (bands), incl. drag-to-reorder | Page → **Page sections** tab (16 layouts) | all 20 mapped routes, 148 rows; a blank new page composes too |
| Headline area (kicker, headline, intro, 2 buttons) | Page → **Hero** tab | 25 routes wired, 24 back-filled with current wording |
| Consult invite + booking strip at the bottom | Page → **Bottom of page** tab | consult on 20 routes, note on 17 — **boxes empty, backfill pending** |
| Photos on template-driven pages | Page → **Images** tab, with a per-page plain-English guide | home, about-us, testimonials, gallery, membership + hero photos everywhere |
| Menus | Appearance → Menus | nav, mobile, footer, 3 mega-menus |
| Phone, address, booking link, both Typeform IDs, hours | **Practice Settings** | site-wide |
| Reviews, smile gallery, SEO title/description | Testimonials · Practice Settings · per page | wired |
| Blog posts | Posts | title/body/hero image/date; the post-page chrome is code |

**Deliberately NOT editable, each with a recorded reason:** the home page hero (hand-built, rails
and glass badges the group does not model); 13 `code_section` bands whose copy is frozen in
`src/blocks/CodeSectionBlock.astro`; template bands no layout can draw (our-office #tour /
#technology / #expect, new-patients #membership, contact #reach, referral #program);
`/dental-membership-plan/` bands (a different band system); the contact page's closing note
(it prints live opening hours); legal-page bodies; campaign LPs; utility pages.

**The three recurring defect classes — check for them before calling anything done:**
1. A field declared in PHP, selected in the loader, read by NOTHING (caught seven times in
   Wave B alone, then again on `[...slug].astro`, then on the hero's `ratings` gate).
2. A registered select VALUE with no branch in the component (`card_grid` 5 columns shipped
   with the choice and the CSS but not the class-emitting guard — it drew TWO across).
3. A paired list extended on one side only (`global.css` charcoal `.vs-link` override vs its
   white-card rescue — links went to 1.22:1 contrast).

**Next actions, in order** — see "Suggested next steps" for detail:
1. Backfill the four "Bottom of page" boxes with each page's current wording (the hero
   pipeline: extract → prove byte-identical offline → deploy → dry run → write).
2. Give the two hero subheadings that carry markup (sinus-lift's link, referral's `<b>`) an
   HTML-capable field; today they stay on the template on purpose.
3. Owner-side: make the repo private; rotate the WordPress password (hash in git history);
   confirm the `s.ksrndkehqnwntyxlhgto.com` call-tracking script; decide the domain cutover.

## What this project is

Headless WordPress (content) → Astro (build) → Vercel (static hosting). WordPress renders no
public pages; every front-end request to the CMS is redirected, and the Astro build reads
content from WPGraphQL at build time. Nothing is fetched by a visitor's browser.

| Piece | Where |
| --- | --- |
| Repo | github.com/allanconcepcion/vivid-smile-dentistry-headless |
| Front end (production) | https://vivid-smiles-headless.vercel.app |
| Hosted CMS | https://cms.vividsmilesdentistry.com (GoDaddy Managed WP) |
| Vercel project | vercel.com/allans-projects-cc55d7b7/vivid-smiles-headless |
| Old CMS hostname | 1230613.us28.myftpupload.com — still answers; retire its allowances when GoDaddy drops it |

The repo remote is confirmed from `git remote -v`. All four hostnames were verified live on
2026-09-01 (the CMS answers GraphQL on its new domain and reports it as its own URL; the
front-end 302 from the CMS lands on the Vercel preview; Vercel deployed green against it).

## Where the work actually is: pages compose from WordPress

**The mechanism.** `pageFields.blocks` is an ACF flexible-content field with 16 layouts
(`cms/mu-plugins/vs-content-model.php`). `src/loaders/pages.ts` selects it through
`src/blocks/manifest.ts` (the ONE place that says what the build asks GraphQL for), gated on a
capability probe that sends the exact selection to the host first — a host predating a field
degrades to "not selected" instead of failing 48 routes. `src/lib/page-content.ts` normalises
every row (WPGraphQL returns every ACF select as a ONE-ELEMENT ARRAY — `unwrapSelects` is where
that is fixed, once) and exposes `hasBlocks`, `hero`, `closing`, `section()`, `image()`.
`src/blocks/PageBlocks.astro` draws the rows through `src/blocks/registry.ts`; each template is
`{hasBlocks ? <PageBlocks/> : <its original markup>}` band by band. **The else branch is the
rollback** — empty `blocks` in wp-admin and the page renders from its sheet again.

`hasBlocks` is `blocks.some(row => isRegisteredLayout(row.__typename))`, deliberately not
`length > 0`, so a row saved against an unshipped layout degrades to "not migrated" rather than
a blank page. **One registered row flips the whole page** — the wp-admin note says so now.

**Coverage.** 20 routes in `cms/import/block-map.json` (148 rows, 128 carrying `known_gaps`
with the reason each residual difference exists). Hero on 25 routes (`pageFields.hero`, gated on
`h1` non-empty; `ratings` defaults ON in PHP because ACF cannot tell "untouched" from "off").
Closing bands on 20/17 routes (`pageFields.closing`, its own probe). A blank WordPress page with
no template composes through `src/pages/[...slug].astro` (builds zero routes today; every page
has a template that wins). `src/blocks/site-tokens.ts` substitutes `{phone}`/`{phone_href}` at
render so a phone number is never stored twice; `src/blocks/cta.ts` is the one CTA href/hover
table — stored hrefs are anchors, site paths or tokens, never pasted URLs.

**The editor's experience** (`cms/mu-plugins/vs-editor-guide.php`, plus the labels in
`vs-content-model.php`): guidance is rewritten PER PAGE at `acf/prepare_field` from hardcoded
maps — three orientation variants (blocks / template / home), an honest Hero note on home, an
Images guide locating every photo in plain English with live / moved-into-Page-sections / unused
status, a Section-copy guide, and "check the Page sections tab first" notes on the three tabs
whose rows mostly stopped changing the site when their bands moved (143 of 213 Section-copy
rows, 124 of 166 On-this-page rows, all 50 Process rows). **When a template's image slots or
sections change, the maps in `vs-editor-guide.php` must be updated by hand.**

**The CMS domain** is `cms.vividsmilesdentistry.com` since 2026-09-01 (owner did the GoDaddy
side; repo, docs, `.env.example`, and all three Vercel `WP_GRAPHQL_ENDPOINT` rows updated; the
old `1230613.us28.myftpupload.com` still answers and stays in the CORS list and the image
allowlist until GoDaddy retires it). The built site was BYTE-IDENTICAL across the move — media
is rehosted into `/_assets` at build time, so the live site has no runtime CMS dependency.

## How this actually gets deployed from here

- **Front end:** Vercel builds `main` on push (production) and PR branches (preview).
  Publishing in WordPress also fires the deploy hook (`vs-deploy.php`). Env var
  `WP_GRAPHQL_ENDPOINT` = `https://cms.vividsmilesdentistry.com/graphql` in all three
  environments. Local: copy `.env.example` to `.env`. Build = `npm run build` in
  `vivid-smiles-website/` (`prebuild` warms the media cache).
- **mu-plugins:** `cms/bin/deploy-mu-plugins.sh` needs an SFTP password typed at a prompt, which
  an agent cannot supply. The route that IS used: wp-admin → **WP File Manager** →
  `wp-content/mu-plugins` → Upload → **YES** to replace. **Never BACKUP.** `php -l` first, always.
  Driving elFinder from JS: `fm.exec('open', hash)` only works once the parent is loaded — walk
  root → wp-content → mu-plugins with waits, and check `fm.cwd().name` before opening the upload
  dialog (a guard that has caught the wrong directory twice).
- **Three hosted files the script does not carry:** `wp-content/vs-import/bin/block-map.json`
  and `backfill-blocks.php` (sections), `backfill-hero.php` + `hero-payload.json` (hero). Same
  File Manager route into `vs-import/bin`.
- **Backfills run from wp-admin → Tools → "Page content migration"** (`vs-migrate.php`), two
  modes on one screen: *Page sections* and *Hero copy*. One route at a time. **Dry run first**,
  every time — it prints exactly what would be written and refuses populated rows unless the
  overwrite box is ticked. Idempotent; bookkeeping meta records the payload hash.
- **The proof that makes a backfill safe without touching the CMS:** templates fall back to
  their literals when a field is blank, so storing IDENTICAL text must leave the built site
  byte-identical. Overlay the payload locally, rebuild, diff 48 routes. The hero payload passed
  with a recorded, accepted residue (58 edge-whitespace chars, 6 `&#39;`, 1 inert scoped
  attribute) and zero word diffs.
- **Order of operations for a new field:** write PHP → `php -l` → upload → confirm queryable
  (`curl` the endpoint) → then wire loader/zod/page-content/templates → build → measure. New
  page-level groups are probe-gated so the reverse order does not fail, but do not rely on it.

## The wp-admin editing screen (2026-09-03, commits `d3c84b3`..`41359a4`)

Allan used the CMS and said, twice: adding instruction text is not the same as making a field
clear, and what he wants is that **"the fields of each page will be like a mirror on the front
end"**, grouped **per section**. That framing drove this whole round.

**What an editor sees now.** A page opens with a per-page note that ends in a link to that page
on the live site. *Page sections* rows are titled by their own heading (`Photo and copy: What Are
Porcelain Veneers?`). Opening a row gives three to eight named groups that walk down the section
as a visitor's eye does, with every shape control collected under *Settings — you can usually
leave these alone*. Every repeater row arrives folded and titled by its own text, so
/terms-conditions/ is a 23-line contents list instead of 23 open clauses.

**Where the code lives, and why that matters.** All of it is in `cms/mu-plugins/vs-editor-guide.php`
on `acf/prepare_field`, which runs only when wp-admin DRAWS a field — not when the schema is
registered, not when a query resolves. `vs-content-model.php` (the GraphQL contract) was touched
only for labels, instructions and one field-group title. Nothing in this round can reach the
build, and that is structural rather than careful.

**The three checks this round added, all cheap and all repeatable:**

1. **Identifier multiset.** `'key'`/`'name'`/`'graphql_field_name'` values extracted from
   `vs-content-model.php` at HEAD and now, compared as a multiset — 536 of them, identical. Order
   may differ (field order is presentation only); membership may not.
2. **Schema fingerprint.** Introspect every `PageFieldsBlocks*` type and hash its sorted field
   names: **37 types, 325 fields** is the baseline. Introspection is off for public requests, so
   it must be run from the GraphiQL IDE page with its nonce —
   `fetch('/graphql', {headers:{'X-WP-Nonce': window.wpGraphiQLSettings.nonce}, credentials:'include'})`.
   This is also the authoritative list of what fields a layout actually has: a regex over the repo
   MISSES everything `block_preamble()` contributes, because those keys are concatenated.
3. **Save round-trip.** Press Update on a real page and re-query it; the digest and byte length
   must match. Done on /cosmetic-dentistry/ (FAQ), /porcelain-veneers/ (the 32-field Photo and
   copy) and /terms-conditions/ (23 sections). All byte-identical.

**Pages the client makes** get their own orientation and tab trimming, keyed off
`current_page()` returning a synthesized entry when the id is not in `PAGES`.
What is true there is decided by `src/pages/[...slug].astro`: it has no reference
to `closing` or FinalBand anywhere, and its own comment at :184 records that it
"never reads `page.images`" — so *Bottom of page* and *Images* do nothing on a
new page and are hidden. Everything else works, including the hero. The default
message they used to fall back to named both of those tabs as usable.

**Coverage:** all 33 published pages. 20 have `blocks` rows and so get the grouped sections; the
other 13 have none and are still edited through the older Section copy / Cards & lists / Images
tabs — those got the folding repeaters, the renamed labels and the per-page guidance.

**Known rough edges.** `.vr/html` is a stale baseline (it predates the merged page-blocks work) so
`npm run vr:html -- compare` reports all 48 routes changed for reasons that are not this round's;
it was left alone rather than overwritten. The "open this page on the website" link points at the
Vercel URL because the domain cutover has not happened — it reads `VS_FRONTEND_URL`, so it
follows the cutover with no edit. Group headings are Claude's wording and Allan has not reviewed
them; each is a one-line edit in `SECTION_GROUPS`.

## vs-admin.php is inert, because the only account is an administrator

`cms/mu-plugins/vs-admin.php` exists to "curate wp-admin down to the screens an
editor actually owns": it removes Plugins, Tools, Comments, the ACF field-group
screen and the GraphiQL IDE, trims Appearance to Menus alone, refuses those
screens outright rather than merely unlinking them, locks the blog categories to
the canonical five, and trims the admin bar.

**Every one of those is gated on `restrictions_apply()`, which returns false for
anyone with `manage_options`. `wp-admin/users.php` lists exactly one user —
`admin`, an Administrator. So none of it has ever applied to anybody.**

That is not a bug in the file; it is the missing half of the plan. The plugin was
written for an editor account that does not exist yet. Whoever hands this site
over needs to create one — an Editor, not an Administrator — and hand the client
THAT login. Until then the client will see Plugins, Tools, Users, Settings,
All-in-One WP Migration and WP File Manager alongside the five things they
actually edit, and WP File Manager can delete the site.

Claude cannot create the account (creating accounts and setting passwords are
off-limits), and the curation cannot be tested without one. When the account
exists, check: the five menus the dashboard signpost names are present, the five
REMOVE_MENUS entries are gone, Appearance shows only Menus, and WP File Manager
and All-in-One WP Migration are absent — the last two are NOT in REMOVE_MENUS and
are expected to hide themselves on capability, which is worth confirming rather
than assuming.

## 2026-09-04, second wp-admin round — what changed and what it found

Deployed and verified on the live CMS; every item below was checked on real screens, not
reasoned about.

**Tabs a page does not use are no longer drawn.** Nine tabs shipped on every page and almost no
page used nine: of 297 tab-instances across the 33 pages, 126 held no data at all. Three states
now — live (untouched), dead-and-empty (hidden, 89 instances), dead-but-holding-words (kept,
label gains "(not used here)", 85 instances). *Page sections* is never hidden even when empty:
it is the migration switch. Checked across all 33 pages, twice: **no live tab missing, and no
hidden tab holding data.** A save on /privacy-policy/ (6 tabs hidden) round-tripped
byte-identically.

**Pages the client creates** now get their own guidance. They had none — the guide is keyed by
post id, so anything outside the 33 fell through to a default that was actively wrong here: it
named *Bottom of page* and *Images*, and `[...slug].astro` reads neither (no `closing`/FinalBand
reference anywhere; its own comment at :184 says it "never reads `page.images`"). Add Page now
shows seven tabs and says a new page is in no menu until someone puts it there.

**A "Start here" panel** is pinned to the top of the dashboard — the five things a receptionist
owns, each linked. NOT role-gated, and that is the finding: `vs-admin.php` curates the admin menu
only for non-administrators, and `users.php` lists exactly one account, `admin`, an
Administrator. **All of that curation is inert.** See its own section below.

**Bottom of page shows what the page says today** (30 pages), scraped from `dist/`. See the
rewritten next-step 1 for why the real backfill was measured and deliberately deferred.

**Each photo row says where that photo goes**, beside its code, instead of only in a guide above
a table that runs to 25 rows.

**That found a real defect in the content.** `teamAlly` on /about-us/ was a photo row the site
never read — not in the guide map, not in the Astro source, "Ally" appearing zero times in the
built page. Allan confirmed she has left the company. The row was removed from the CMS on
2026-09-04 and verified in the database: 25 images → 24, **only that slot removed**, every other
slot in identical order, sections/cards/hero untouched; rebuild gives 48 routes, "Ally" nowhere,
all 9 team cards intact. Comparing every page's saved slots against the map found it was the
only orphan on the site. **Her image file is still in the Media Library** — deleting a real
person's photo is Allan's call, not a cleanup step, and nothing on the site points at it.

Two method notes worth keeping. The File Manager's replace dialog moves between windows, so two
deploys silently did not land when clicked by coordinate — **click YES by JS
(`.ui-dialog button` filtered to visible), and always confirm the deployed byte size against the
local one.** And a wp-admin save is not confirmed by the editor DOM: a disconnect once left 24
rows on screen while the database still had 25. **Confirm a save against GraphQL, not the page.**

## The verification method this project learned

Each sweep exists because the previous set reported clean while something real was broken.

1. **Visible words** — misses anything that keeps the text.
2. **`<section class>` values** — added after 26 bands silently lost their band colour (the
   one-element-array select).
3. **`.section-head` / `.eyebrow` modifier classes** — added after five bands lost
   `section-head center` on three live pages.
4. **Raw `<body>` bytes** — whitespace text nodes are real diffs.
5. **Computed styles in a browser** — the only thing that found a band whose class attribute
   MATCHED the template and still rendered at 760px where the template rendered 587.355px.

**The technique:** build the tree, snapshot `dist` (`node scripts/vr-html.mjs snapshot <dir>`);
`git stash -u`, build HEAD, snapshot; `git stash pop`; compare in `python3` reading files
directly (not through shell `diff`, see the rtk caveat). For a change that should be inert, the
bar is 48/48 identical, `<head>` included. For a backfill, overlay the payload locally first.
For a CSS deletion the sweeps are blind — prove selector-set equality plus reachability instead.
`scripts/check-block-schema.mjs` checks the manifest against the deployed schema; it explicitly
does NOT catch declared-but-unread fields or values with no branch — a forced-value smoke into
`dist/` does.

**CSS rule:** Astro compiles ATTRIBUTE scoping. A block's `.section-head` compiles to
`.section-head[data-astro-cid-x]` at (0,2,0) and TIES a page sheet's `.np .section-head`; bundle
order decides. Root component selectors one compound deeper, and never build on a tie unless
both sides paint the same value.

## Where the plan predicted one thing and reality did another

These divergences are the useful part of this document. Do not smooth them into outcomes.

- **`docs/PAGE-BLOCKS.md`'s Phase 4 planned ten pages including `dental-membership-plan`, exiting
  at "all 21 composable pages on blocks."** Reality: **20**.
  `/dental-membership-plan/` was deferred on the band-system mismatch described above.
- **The Phase 5 plan predicted "~160 lines of `.final-band` residue across 13 sheets."** Measured:
  **133 rules across 11 sheets.** Two of the thirteen — `contact.css` and `new-patients.css` —
  mention `final-band` only in prose and were left completely untouched.
- **The `card_grid` gap note assumed adding a 5th column choice would close gap (1) and merely
  leave (2) and (3) open.** Measured in a browser at 1440px before committing, that is **wrong**:
  the three are **coupled**, and closing the column count alone makes the other two worse.

  | | card | content | `<h3>` wraps to |
  | --- | --- | --- | --- |
  | `cols-4` (today) | 268px | 192px | 70px |
  | `cols-5` | 207px | 131px | 117px |

  `.why-card` carries 42px/38px padding and a 22px display `<h3>`, so a 131px column breaks
  "Severe staining that no longer responds to whitening" into about five very narrow lines and
  grows the cards from 202px to 249px. The template gets five across comfortably only because
  `.issues-card` is a 15px *label* — which is gap (3), a card-family rename no field can close.
  **So the column count closes WITH the card family or not at all.** The choice is live in
  wp-admin; the two bands stay on 4, and both blocks now record the measurement instead of the
  assumption.
- **Two standing sweeps were assumed sufficient. Twice they were not** — hence sweeps 3 and 4
  above. This is the project's most repeated lesson.
- **PR #2 shipped `[...slug].astro` to "build any published WordPress page that has no hand-built
  Astro route."** At HEAD it builds **zero** routes, because every WordPress page has a template
  that wins. That is by construction, not a regression — but the old handoff's description of that
  route no longer matches what it does.
- **This file's own precedence claim inverted**, as recorded at the top. Its last line used to
  read *"Two of its three highest-priority items were stale within a day."* That instinct was
  right and the file still did not survive it.

## History — the 13 August 2026 PR round

Kept as a record, **not as current state**. All five hashes resolve and are ancestors of `main`.
`git log` is the authority here; this list exists so the numbering below still reads.

- **#1 `sitemap-frontend-origin`** (`409764d`) — `astro.config.mjs` derives `site` from
  `SITE_URL` → `VERCEL_PROJECT_PRODUCTION_URL` → `FRONT_END_URL`; `src/integrations/robots.ts`
  writes `dist/robots.txt` per host.
- **#2 `wp-pages-catch-all`** (`e572bf1`) — `src/pages/[...slug].astro`, plus
  `src/styles/pages/wp-page.css`. See the divergence above for what it does today.
- **#3 `wp-pages-title-only`** (`3de80cd`) — builds a page that has only a title. Then unmerged to
  production; it has since been an ancestor of `main`.
- **#4 `docs-handoff-correction`** — this file and `cms/README.md`.
- **#5 `scf-unlock-empty-importer-ids`** (`62c887c`) — unlocks importer-owned repeater IDs on rows
  holding no value. The fix is intact and still verifiable in this tree:
  `vs-content-model.php:5068` sets `$field['readonly'] = 0` on `acf/prepare_field`.
- **#6 `deploy-mu-plugins-script`** (`d9cc1a6`) — `cms/bin/deploy-mu-plugins.sh`. See the
  deployment section for why this is not the route in use.

Production then sat at `cddbf6d` with `49 page(s) built`. **That reconciles with today's 48:**
trashing the `/test/` page (issue 4) removed exactly one route. The old handoff recorded both
numbers and never connected them.

### The 13 August checklist — historical, and three of its six items can no longer be run

That checklist verified canonicals, `robots.txt` and sitemap hosts, and it passed 6 of 6 on
13 August 2026. **Items 1, 2 and 5 all keyed off `/test/`, which was trashed the same day** (issue
4 below) — so they cannot pass again, and its own table is internally inconsistent with issue 4:
the table records `page-sitemap 28 <loc>`, issue 4 records the drop to 27. The table is therefore
dropped rather than reprinted with a stale verdict.

What survives as a re-runnable check on whichever host is being verified, reported **with the
actual value observed**:

1. `/robots.txt` — a `.vercel.app` host must say `Disallow: /` with no `Sitemap:` line; a real
   custom domain, `Allow: /` plus two `Sitemap:` lines.
2. `/sitemap_index.xml` and `/page-sitemap.xml` — the `<loc>` host must equal the host being
   browsed. A mismatch is the original bug this work fixed.
3. Spot-check `/`, `/cosmetic-dentistry/` and `/blog/` canonicals.

Fastest way to run it: open the site, then a same-origin `fetch()` loop over the routes, regexing
out `<link rel="canonical" href="...">`, `<title>` and `<h1>`. Cross-origin fetch is blocked, so it
has to run from the site's own origin.

**This checklist is not the project's verification method.** It covers canonicals and sitemaps and
nothing else — no coverage of the block system at all. The four sweeps above are the method.

## Plugins

**Provenance, stated honestly:** this inventory was checked in wp-admin on 13 August 2026 and
**has not been re-checked since.** No commit on `page-blocks` touches the plugin set, so it is
*unconfirmed*, not known-wrong. It is byte-consistent with `cms/README.md:130-140`, which owns
this inventory. **`cms/README.md` is the file to update; prefer it over this section.**

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

**Correction made 13 August 2026, still standing.** An earlier revision said WP File Manager had
been removed and listed Hello Dolly as inactive. Both were wrong, and the wrong one matters:
**WP File Manager 8.0.4 is still installed, merely deactivated** — one click from live on an
internet-facing admin, on a plugin with a long history of remote-code-execution bugs. Hello Dolly
is the plugin that is actually gone. Observed in wp-admin: All (10), Active (7), Inactive (3).
See the deployment section for the unresolved tension between "inactive" and the upload route.

**README drift: resolved**, re-checked in the commit that recorded it (`73e9865`, 2026-08-25 —
*not* 13 August, as the previous revision's prose claimed). `cms/README.md` lists the same ten
plugins as above and records Hello Dolly's removal, so the two no longer disagree.

**Must-use plugins: the count in this file was 10 and is now wrong.** `cms/mu-plugins/` held
**eight** `vs-*` files at `d274f3b` (13 August) and holds **ten** at HEAD:

| Added | Commit | Date |
| --- | --- | --- |
| `vs-admin.php` | `d376e6d` | 2026-08-25 |
| `vs-migrate.php` | `6db4126` | 2026-08-26 |

So if the standing schema-first deploy order was followed, the host carries **twelve** must-use
plugins — the ten `vs-*` files plus Object Cache Pro (MU) and GoDaddy's System Plugin.
UNVERIFIABLE HERE. `vs-deploy.php` **is** among them; see issue 1.

## Open issues

*Carried forward from the 13 August round; items 1–4 resolved as recorded, 5–7 still open at
`3761258`. Numbering is stable because other files link to it.*

Numbered for reference, not ranked — other files link to these numbers, so they stay put. By
severity, **5 is first**: a working WordPress password hash is published in this repository's
history right now and **it has still not been rotated.**

1. ~~**`vs-deploy.php` is missing on the host.**~~ **Resolved — and it was never true.** Evidence,
   13 August 2026: wp-admin listed "Vivid Smiles — Deploy trigger" under Must-Use; publishing a
   page raised the plugin's own notice, "Front-end rebuild queued — the live site updates in about
   2 minutes", which `notice()` only prints when `wp_next_scheduled('vs_deploy_build')` is set, and
   `queue()` returns early when `VS_DEPLOY_HOOK_URL` is undefined — so the constant is defined in
   `vs-config.php` on the host. Roughly two minutes later Vercel showed a Production deployment
   with **Created: Deploy Hook**, Ready. Trashing the page fired it again. Publishing in WordPress
   rebuilds the front end; the manual-redeploy instruction elsewhere is a fallback. Corroborated by
   `docs/DEPLOYING.md`'s "Wire the deploy hook" section.
2. **Section ID was both `required` and `readOnly`.** On a new page an editor could add a Section
   copy row but never fill the ID, so the row could not validate. The same lock applied to
   `images.slot` and `cards.group`. The mechanism: `readonly` renders the input with a readonly
   attribute, so it still posts — it posts an empty string, and `required` then rejects it. Not
   theoretical: on 13 August an editor filled in a section's eyebrow, heading, body and button,
   could not type an ID, and lost the save. **Fixed in PR #5 (`62c887c`)** — the field locks only
   once it holds a value, via `acf/prepare_field` (`vs-content-model.php:5068`). Deployed and
   verified in wp-admin 13 August 2026.

   **The old "today a new page can only use the On this page, Process and FAQ tabs" is no longer
   true and has been removed.** That is exactly what Phase 5 retired: a page is now authored
   through `blocks` — 16 layouts, "Add a section" — and a blank page composes with no template at
   all. Leaving that sentence in place would send the next reader to re-solve a solved problem.
3. **The catch-all skipped pages with no copy in any field.** A page with only a title logged
   `[wp-pages] <route> has no content in any field`, was not built, 404'd, and was dropped from the
   sitemap. **Fixed in PR #3 (`3de80cd`)**, verified end to end on the branch preview before
   merging. The PR also makes `tocLinks` count as copy. *(The old note that "the same URL returned
   404 on production, which still runs `main`" was true when written and has expired — `3de80cd`
   is an ancestor of `main`.)*
4. ~~**A test page is still published** at `/test/`.~~ **Resolved 13 August 2026.** Moved to Trash.
   No manual redeploy was needed: the deploy hook rebuilt production on its own, `/test/` returned
   404, and `page-sitemap.xml` went to 27 URLs from 28. This is what took the build from 49 to 48.
5. **The repo is public, and a WordPress password hash is still retrievable from its history.**
   Re-verified in this tree at HEAD:

   ```
   git log --oneline -- cms/backup/database.sql | wc -l          # 15 revisions
   git show 9f41107^:cms/backup/database.sql | grep -c 'CREATE TABLE `wp_users`'   # 1
   grep -c wp_users cms/backup/database.sql                      # 0
   ```

   Commit `9f41107` removed those tables going forward. That does not retire what is already
   published, and neither would making the repository private, because anything already cloned or
   indexed stays out. **Rotate that password.** It is the only remedy that works without rewriting
   history, and **it has not been done.**

   Two claims an earlier revision made about this issue did not survive checking, and both
   re-verify at HEAD:

   - **The dump at HEAD is clean.** `cms/backup/database.sql` is 2,457,765 bytes across 16
     `CREATE TABLE` statements with no `wp_users` and no `wp_usermeta` — `backup.sh`'s table-level
     exclusion works as its commit message claims. The email addresses in it are 20
     `@gutenbergtimes.com` (WordPress demo content), 2 `@example.com` and 2
     `@vividsmilesdentistry.com`. No patient data. The exposure is in the 15 older revisions of
     that path, not the current one.
   - **"Roughly 74 identifiable patient photographs" is not supported.** `cms/uploads/` holds 661
     files, 30,202,385 bytes. **Recounted at HEAD, and the previous revision's own figures were
     slightly wrong:** they collapse to **133** distinct base images (not 131) — **26**
     `procedures-` (not 25), 19 `team-`, 17 `people-`, 9 `smiles-`, and the rest facility, video
     and diagram assets. This is not drift: `git diff --diff-filter=A d274f3b HEAD -- cms/uploads`
     is empty, so no upload was added or removed; it was miscounted when written. The conclusion
     is unaffected — the nine `smiles-*` files are named `smiles-01-frontal-arch-berry-lip` and
     similar, which reads as art direction, not clinical records. Read from filenames,
     deliberately not from the images, so **whether any `team-*` or `people-*` portrait is a real
     patient rather than staff or stock remains an open question for someone who knows the shoot.**

   Needing a person rather than a checker: rotate the password; decide whether to `git filter-repo`
   the dump out of all 15 revisions, which force-pushes `main`; confirm the portraits' provenance.
6. **All-in-One WP Migration and its Unlimited Extension are active and undocumented** on an
   internet-facing admin. Neither is needed by the build. Review before launch. (Plugin state
   UNVERIFIABLE HERE; last observed 13 August 2026.)
7. **Cloudflare in front of the CMS** answers cold bursts with 429s. `src/lib/wp.ts:74-82` treats
   429 as retryable with a Cloudflare comment, and `scripts/warm-media-cache.mjs` is wired as
   `prebuild` in `package.json:17`. Both paper over it; relaxing the rules for
   `/wp-content/uploads/` and `/graphql` is the durable fix.

## Gotchas, learned the hard way

Every one of these re-verifies at HEAD against its own call site.

- **`getStaticPaths` is hoisted out of Astro frontmatter.** Only imports travel with it, so
  anything else it needs must be declared inside it. Otherwise the build dies with
  `X is not defined`. This cost two failed deployments. `src/pages/[...slug].astro:69-74` says the
  same and places its imports at module scope for that reason.
- **Pages have no `post_content`.** `vs-content-model.php:86` calls
  `remove_post_type_support('page','editor')` deliberately, so WPGraphQL exposes no `content`
  field on `Page` and querying it fails the build. A page *is* its structured fields; read them
  through `getCollection("pages")`.
- **`vsRoute` comes from `_vs_route` post meta, written only by the importer**
  (`vs-content-model.php:5239`). A page created by an editor has `vsRoute: null` and the loader
  falls back to `uri` — `src/loaders/pages.ts:454` is literally `const route = node.vsRoute ?? node.uri;`.
  That is the intended path for new pages, not a bug.
- **Yoast's sitemap is generated on the CMS and rewritten by the Astro build**
  (`src/integrations/yoast-sitemap.ts`). Rewriting the hostname inside WordPress does not work —
  Yoast validates entries against its own host and silently drops foreign ones, producing a
  valid-looking file with zero URLs.
- **A sitemap URL with no corresponding built page is dropped with a warning, not a build
  failure** — `yoast-sitemap.ts:180` ("This used to abort the build; it must…") and the `dropped`
  array at `:189`. Deliberate, so an editor creating a page cannot break a deploy.
- **GraphQL introspection is disabled for public requests** (`vs-content-model.php:1616`,
  re-confirmed against the host by `scripts/check-block-schema.mjs:24-25`). The structured field
  group is exposed as `pageFields` on `Page`; `vsSeo` and `vsRoute` are separate custom fields.
- **Read the actual Vercel build log rather than guessing.** Expand "Deploy Logs" on the
  deployment, then use "Find in logs". One blind fix wasted a whole cycle.
- **`raw.githubusercontent.com` and `/pull/N.diff` are not readable** from the browser tooling.
  Use `/blob/<branch>/<path>?plain=1` instead. (No conflict with `docs/DEPLOYING.md`, which curls
  that host *from the host over SSH*, not from browser tooling.)
- **The `rtk` Bash hook rewrites commands and summarises output.** Two independent review agents
  saw `diff` print "identical" on files that differed and exit 0; it could not be reproduced in
  the main session's shell. Every measurement in this project is therefore done in `python3`
  reading files directly, and `/opt/homebrew/bin/git` is called by path where exactness matters.
- **Multiple Chrome browsers are connected to the account.** When the tooling asks, choose
  "send a Connect prompt to all" — it lands on the browser named **work**, signed into wp-admin
  and Vercel. Tabs die between turns; call `tabs_context` again rather than reusing an id.
- **Subagents collide on one tree.** A verifier once found three templates deleted by a
  concurrent writer. One writer per file, disjoint sets, and verifiers must restore byte-exact.
- **A safety classifier can block subagents on prior conversation content**, not on the task.
  Three audit readers were blocked at once; the synthesiser recovered by direct inspection. If
  a workflow returns empty audits, read the journal before assuming the work was done.
- **`hasCopy` in `[...slug].astro` gates only a log line**, not the build — a page is built
  regardless. And that route builds zero routes today, so "byte-identical" proves nothing about
  it; move a template aside to make it claim a route when you need real proof.
- **WP File Manager is ACTIVE**, not inactive as the 13 August inventory said — it has been the
  deploy route every day of the block-system work (observed 2026-08-28 → 2026-09-01). The
  security question about it stands; the "contradiction" does not.

## Who owns what

This file is deliberately not the source of truth for any of these. Go to the owner.

| Subject | Owner |
| --- | --- |
| Deployment, restore, the deploy hook | `docs/DEPLOYING.md` |
| CMS, plugin inventory, content model | `cms/README.md` |
| Blocks, layouts, per-route gaps | `docs/PAGE-BLOCKS.md` + `cms/import/block-map.json` |
| What each edit screen tells the editor | `cms/mu-plugins/vs-editor-guide.php` (per-page maps) |
| Every session's starting rules | `CLAUDE.md` at the repo root (auto-loaded by Claude Code) |
| What the build asks GraphQL for | `src/blocks/manifest.ts` |
| Schema/manifest contract check | `scripts/check-block-schema.mjs` |
| Markup-diff harness | `scripts/vr-html.mjs` |
| PR and commit history | `git log` |
| The original plan (historical, unmaintained) | `docs/MIGRATION-PLAN.md` |

`docs/CUTOVER-PROMPT.md` links this file on `main` — which is now the current revision, so that
pointer is correct again. Its CMS row is marked done (2026-09-01); the front-end cutover rows
are not.

## Suggested next steps

0. **Decide on the eight unpushed commits** (`d3c84b3`..`41359a4`). They are deployed to the live
   CMS already — the mu-plugins on the host match these files byte for byte — so the repo is the
   only thing behind. Push, or say what to change first. Also worth Allan's eye: the section group
   headings, which are Claude's words for parts of his pages.
1. **Backfill the "Bottom of page" boxes — and know before you start that it is NOT
   byte-identical.** Measured 2026-09-04: all 70 extractable values match the live output, 45
   byte-exact and 25 differing only in whitespace, none differing in a word. The 25 are the
   multi-line JSX fallbacks — **Astro renders those with their source newlines and indentation
   baked into the HTML**, so a clean one-line CMS value changes the bytes of roughly 20 live
   routes while changing no words. That is acceptable (the change is the point, and emptying a
   box restores the template exactly) but it must be a deliberate decision, not a surprise
   halfway through.
   Seven fields must stay on the template: they carry an inline `<a>` or a `{phoneLabel}`
   expression, and `consultBody`/`note` are rendered as plain text, so a stored value would show
   the markup. They are all-on-4/bone-grafting/full-mouth/single-tooth `consult_body`, and
   full-mouth/sinus-lift/referral-program `note`.
   Mechanically the blocker is that `backfill-hero.php` is parameterised by four functions
   (`vs_hb_group_key/group_name/receipt_meta/writable_fields`) but its function names would
   collide with a copy, so a closing engine needs a `vs_cb_*` prefix or the engine needs
   generalising. In the meantime the wording is at least VISIBLE — see below.
2. **An HTML-capable sub for two heroes.** sinus-lift's sub carries a real `<a class="vs-link">`
   and referral-program's a `<b>$50 credit</b>`; `hero.sub` is plain text, so both stay on the
   template. Needs a field type change, not a payload fix.
3. **Owner-side security.** Rotate the WordPress password (issue 5) and the host SFTP password;
   make the repo private; confirm the unattributed `s.ksrndkehqnwntyxlhgto.com` call-tracking
   script. Then decide whether to `git filter-repo` the old dump out of history.
4. **Retire the old hostname's allowances** (`astro.config.mjs` image allowlist, the CMS CORS
   list) once GoDaddy retires `1230613.us28.myftpupload.com`.
5. **Later, larger:** the card-family rename that would let smile-makeover and single-tooth go
   five across (the choice exists; measured, five-up with today's card chrome wraps the heading
   to five lines); un-freezing `code_section` bands into layouts if a second page ever needs
   one; `/dental-membership-plan/`; the home hero.
6. **Verify anything this file claims before acting on it** — its own history is the argument.

