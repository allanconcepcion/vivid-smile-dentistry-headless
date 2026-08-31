# Vivid Smiles headless — session handoff

Written to hand this work to a fresh session (human or AI) without re-deriving any of it.

**State this file describes:** branch `page-blocks`, HEAD `07c027d`. `main` is at `73e9865` and is
**33 commits behind** (`git rev-list --count main..HEAD` → 33). Everything in the block system
below lives on `page-blocks` and is *not* on `main`.

**Retracted, and the retraction is the point.** Earlier revisions of this file opened with
_"Where this file and any other README disagree, this file was checked against the live systems
more recently."_ **That claim is withdrawn and must not come back.** It was false by the time it
mattered: `73e9865`, the last commit to touch this file, *is* the fork point — so this file
described none of Phases 0–5 while still asserting precedence over `docs/DEPLOYING.md` and
`cms/README.md`, which were of the same vintage and were correct. A pointer file cannot assert
precedence. Where this file and another disagree now, **check the code**, which is where the real
state lives: `src/blocks/manifest.ts`, `scripts/check-block-schema.mjs`, `scripts/vr-html.mjs`,
`src/pages/[...slug].astro`.

The old header also carried `_Last verified: 13 August 2026._` while containing text added on
2026-08-25. Dates in this revision are only ever the dates of commits or of things measured in
this tree. **Anything requiring wp-admin, the GoDaddy host, or Vercel cannot be checked from a
terminal in this repo and is marked UNVERIFIABLE HERE.** Do not launder such a claim into a date.

## What this project is

Headless WordPress (content) → Astro (build) → Vercel (static hosting). WordPress renders no
public pages; every front-end request to the CMS is redirected, and the Astro build reads
content from WPGraphQL at build time. Nothing is fetched by a visitor's browser.

| Piece | Where |
| --- | --- |
| Repo | github.com/allanconcepcion/vivid-smile-dentistry-headless |
| Front end (production) | https://vivid-smiles-headless.vercel.app |
| Hosted CMS | https://1230613.us28.myftpupload.com (GoDaddy Managed WP, temporary hostname) |
| Vercel project | vercel.com/allans-projects-cc55d7b7/vivid-smiles-headless |
| Future CMS domain | cms.vividsmilesdentistry.com (not moved yet) |

The repo remote is confirmed from `git remote -v`. The four hostnames are consistent across this
file, `docs/DEPLOYING.md` and `cms/README.md` but are UNVERIFIABLE HERE.

## Where the work actually is: pages compose from WordPress

This is the subsystem the old handoff never mentioned, and it is now most of the project.

**All twenty routes that `cms/import/block-map.json` maps compose from WordPress** through an
ordered ACF flexible-content field named `blocks`, rendered by `src/blocks/PageBlocks.astro`
through `src/blocks/registry.ts`.

- **16 layouts**, and all three halves agree — verified by name, one by one:
  `faq`, `card_grid`, `media_split`, `process_steps`, `gallery_marquee`, `comparison_cards`,
  `stat_callout`, `pricing_tiers`, `copy_plus_stats`, `tech_grid`, `service_cards`,
  `doctor_profiles`, `candidacy_ledger`, `callout_list`, `map_visit`, `code_section`.
  Registered in `cms/mu-plugins/vs-content-model.php` (field `field_vs_blocks`, label "Sections",
  `:1620-1624`), described in `src/blocks/manifest.ts` (16 keys), bound to components in
  `src/blocks/registry.ts` (16 entries).
- **21 files carry the `hasBlocks` guard**: the 20 mapped route templates plus
  `src/pages/[...slug].astro`. The guard is `{hasBlocks ? <PageBlocks …/> : <the existing markup />}`
  — see `src/lib/page-content.ts:99`.
- **48 routes build.** Stated independently at `scripts/vr-html.mjs:19` and
  `src/blocks/manifest.ts:12`. The most recent measurement, recorded in commit `b1c4550`:
  *"48 routes, 0 differ in words, 0 in section classes."*
- **`cms/import/block-map.json` carries 129 `known_gaps` keys** (counted by walking the JSON at
  HEAD): **128 on block rows**, plus **one at route level on `/contact/`**. `docs/PAGE-BLOCKS.md`
  says "128 of the 148 mapped block rows" and is the more precise of the two — both numbers are
  right, they count different things, and neither should be "corrected" into the other. Some name a concrete closing fix; the rest wait on retiring the un-migrated
  `else` branch. **How many of each is not machine-countable from the file — do not quote a
  split you have not counted yourself.**

**THE `else` BRANCH IS THE ROLLBACK PATH AND MUST KEEP WORKING FOREVER.** This is the single most
important thing to carry forward. `src/styles/pages/clear-aligners.css:13-29` states the rule:
emptying the `blocks` field in wp-admin drops the page back to its template, that undo *has no
deploy behind it*, and so the page sheets "have to keep working forever, not until the backfill."
Its `[block: X]` markers are, in its own words, "a map for the Phase 5 dead-CSS sweep, not
permission."

  *Caveat on that same header, flagged not fixed:* it also says "the `blocks` field is not on the
  CMS host yet." That sentence is stale at HEAD — the field is registered and deployed. The rule
  above is unaffected. That file is out of scope for this document.

`/dental-membership-plan/` is **deferred**, and it is the one composable page that is not on
blocks. It is absent from `block-map.json`'s 20 routes and has no `hasBlocks` guard. Its bands
paint from `.vs-band-paper` / `.vs-band-cream` / `.vs-band-sage` set on the `<section>` elements
(`src/pages/dental-membership-plan/index.astro:169`, and the note at `:436-440`) rather than the
`section` / `section alt` / `section dark` system every block component assumes. The deferral is
recorded in commit `b1c4550`'s message.

**Phase 5 engineering is complete** as of the three commits at the tip:

- `cfe6409` — **a blank WordPress page composes from `blocks` with no template.**
  `src/pages/[...slug].astro` branches on `hasBlocks`; branch-only CSS is
  `src/styles/pages/wp-page-blocks.css`. **It builds ZERO routes today**, by construction: every
  WordPress page still has a hand-built template, and a static route always wins. The route's own
  header says so at `:33-35`. Proven end to end anyway, by moving `implant-dentistry/index.astro`
  aside so the catch-all claimed the route, then restoring the template byte-exact. An adversarial
  review of the first pass found five defects in a browser — all five closed in
  `wp-page-blocks.css`, including link contrast that had gone 14.38:1 → 1.22:1 inside white cards
  on charcoal bands.
- `68fba34` — **dead-CSS sweep: 133 `.final-band` rules removed across 11 sheets.**
- `07c027d` — **`card_grid` gained a 5-column choice**, live in wp-admin. **The two 5-up bands
  deliberately stay on 4** (see divergences below). That commit shipped the choice and the CSS
  but NOT the guard that emits the class, so `5` would have drawn **two** across; caught by an
  adversarial docs review and fixed immediately after. Read "live in wp-admin" as "the editor
  can pick it", and check the component enumerates the value before believing a select works.

Full design and rationale: `docs/PAGE-BLOCKS.md` and `cms/import/block-map.json`, which own this
subsystem. `docs/MIGRATION-PLAN.md` is a historical record and is not maintained.

## How this actually gets deployed from here

The old handoff presented `cms/bin/deploy-mu-plugins.sh` as *"how that copy is now supposed to
happen"* and left it there. It is not the route in use, and an agent cannot run it.

- **`cms/bin/deploy-mu-plugins.sh` needs an interactive password.** `:149` runs
  `sftp -P "$PORT" -b "$BATCH" "$USER@$HOST"`; `:148` prints "sftp will prompt for the password if
  no key is loaded"; `:24` confirms "the password is typed at sftp's own prompt". No agent can
  supply that. Loading a key removes the prompt — `:26` — but no key is set up here.
- **The route that IS used is WP File Manager in wp-admin:** upload the file and choose **YES to
  replace**.
- **NEVER choose BACKUP.** elFinder writes the backup *beside* the original, and **any extra
  `.php` in `mu-plugins` auto-loads** — which fatals the site.
- **Two hosted files:** `wp-content/mu-plugins/vs-content-model.php` (the schema) and
  `wp-content/vs-import/bin/block-map.json` (the migration map).
- **The backfill does not run via WP-CLI on this host.** It runs from wp-admin at
  **Tools → Page sections migration** (`cms/mu-plugins/vs-migrate.php:512`, registered at `:520`).
  **Dry run first** — the dry-run button is separate, at `:855`.

**STANDING ORDER — the PHP schema ships BEFORE the manifest selects new fields.** Independently
documented at `scripts/check-block-schema.mjs:16-20`: GraphQL validates the *whole* document
before executing any of it, so one wrong sub-field name in one fragment fails every page in the
build — *48 routes down because of one word.* That happened **twice in Phase 2**. Run
`npm run check:blocks` (`scripts/check-block-schema.mjs`) before trusting a manifest change; it
probes the live host by POSTing each fragment inside a query whose `where` clause matches no page,
so validation runs and zero rows resolve. Introspection is off for public requests, which is why
the check is built this way rather than diffing a published schema.

**An unresolved contradiction, flagged rather than settled.** The upload route above requires WP
File Manager to be *active*. `cms/README.md:133` records it as **installed but inactive**. Either
that row is stale or the route changed. Settling it needs a person with wp-admin access; do not
guess.

`docs/DEPLOYING.md` owns deployment and now carries both the File Manager route and the
never-choose-BACKUP rule — see its "How the two hosted files actually get there" section
(`grep -ic "file manager" docs/DEPLOYING.md` → 7). An earlier draft of this file said the
opposite, with a grep that returned nothing; that was true when written and expired inside the
same working tree, because the two files were rewritten in parallel. Re-run the grep rather than
trusting either sentence.

## The verification method this project learned

Each sweep was added **only after the previous set reported clean while something real was
broken.** Run all four. A green light from a subset is what shipped every defect below.

1. **Visible words.** A word-level diff of built HTML against the template baseline.
2. **`<section class>` values.** Added after commit `6943244`: **WPGraphQL returns every ACF
   select as a ONE-ELEMENT ARRAY** — `band` arrives as `["charcoal"]`, not `"charcoal"`. Eight
   components tested `typeof band === "string"`, failed, and silently fell back to their
   hard-coded default. A fallback is indistinguishable from a deliberate value, so nothing
   complained. **26 of 95 bands were wrong across the eleven then-migrated routes**, live, on
   client pages. The word diff said zero — "correctly and uselessly." Normalised once at
   `readBlocks()`, the single boundary every block row crosses.
3. **`.section-head` / `.eyebrow` modifier classes.** Added after commit `b1c4550`: five bands
   across three live pages lost `section-head center`. Same words, and not a `<section>` class, so
   sweeps 1 and 2 are both blind to it.
4. **COMPUTED STYLES read in a browser.** The only sweep that caught `/new-patients/` `#services`,
   whose class attribute **matched the template** and still rendered centred at **760px** where the
   template renders left at **587.355px**. A plain `.section-head` does not mean "the block
   default" on those sheets; it means left-aligned at 60ch. That is what produced the `head_align`
   field (`center` / `narrow`) on `faq`, `process_steps` and `service_cards`.

**A CSS deletion is proven differently.** Deleting CSS changes no markup, so sweeps 1–3 sail past
a wrong deletion. The proof is **selector-set equality plus reachability**. That is how `68fba34`
cleared its 133 rules: 0 elements across all 48 built pages carried a `final-band` class token
(exact token test — the first version of that check matched `vs-final-band` too and proved
nothing), against 120 carrying `vs-final-band`, and no source file emits the class. Two rules that
shared a selector list with live CSS were **carved, not cut**.

Tooling: `npm run vr:snapshot` / `npm run vr:compare` (`scripts/vr-html.mjs`, `scripts/vr-screens.mjs`).
`vr-html.mjs` normalises content hashes in `/_assets/<name>.<hash>.css|js` and nothing else; its
header at `:23-64` justifies every normalisation against a failure it would otherwise cause, and
lists what is deliberately *not* normalised. Read it before adding one.

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

## Who owns what

This file is deliberately not the source of truth for any of these. Go to the owner.

| Subject | Owner |
| --- | --- |
| Deployment, restore, the deploy hook | `docs/DEPLOYING.md` |
| CMS, plugin inventory, content model | `cms/README.md` |
| Blocks, layouts, per-route gaps | `docs/PAGE-BLOCKS.md` + `cms/import/block-map.json` |
| What the build asks GraphQL for | `src/blocks/manifest.ts` |
| Schema/manifest contract check | `scripts/check-block-schema.mjs` |
| Markup-diff harness | `scripts/vr-html.mjs` |
| PR and commit history | `git log` |
| The original plan (historical, unmaintained) | `docs/MIGRATION-PLAN.md` |

**One inbound dependency, flagged for whoever owns that file.** `docs/CUTOVER-PROMPT.md` opens by
instructing a reader to *"treat it as the source of truth for current state"* and links this file
**on `main`** — which is `73e9865`, the pre-block-system revision. A domain cutover run from that
pointer would read a description of a subsystem that did not exist yet. `CUTOVER-PROMPT.md` is
outside this document's scope; that link and that sentence need repointing.

## Suggested next steps

1. **Rotate two passwords.** The WordPress one whose hash is published in this repository's
   history (issue 5), and the host's SSH/SFTP password, which was pasted into a chat transcript on
   13 August. GoDaddy dashboard, site Settings, SSH/SFTP. A key would remove the second one
   permanently — and would also let `deploy-mu-plugins.sh` run unattended, which is the whole
   reason the File Manager route exists.
2. **Repoint `docs/CUTOVER-PROMPT.md`'s "source of truth" link.** (The second half of this
   item — adding the File Manager route and the never-choose-BACKUP rule to
   `docs/DEPLOYING.md` — is DONE; that file carries both now.)
3. **Settle the WP File Manager contradiction** in wp-admin — active or inactive — then decide
   what to do about it: delete it, or accept an inactive RCE-history plugin on a public admin.
   Same question for All-in-One WP Migration, which is still active.
4. **Re-confirm the plugin inventory in wp-admin.** It is unconfirmed since 13 August, and the
   must-use count in this file was wrong for six days before anyone counted.
5. **Verify anything this file claims before acting on it.** Two of its three highest-priority
   items were once stale within a day, and the revision that recorded that lesson then went 33
   commits out of date while still claiming precedence over the docs that were right.
