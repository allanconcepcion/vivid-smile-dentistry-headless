<!-- Originally a design document, written 25 August 2026 by a pass that read the
     repository, queried the live CMS, and wrote no code. Rewritten at HEAD
     07c027d to describe what was actually BUILT.

     The design's predictions are kept where they were wrong, and the outcome is
     recorded next to them, because the divergence is the useful part: three of
     this document's original recommendations (@layer, a canonical band
     vocabulary, per-page CSS deletion) were tried and abandoned for reasons
     that are now load-bearing. A reader who sees only the outcome will propose
     them again.

     Verify before acting. Every claim below is either cited to file:line or
     marked as a measurement that cannot be re-run offline. -->

# Composable Pages in WordPress — Architecture, and What Shipped

**Status:** built. All five phases have shipped. Twenty routes compose from
WordPress through an ordered ACF flexible-content field called `blocks`,
rendered by `src/blocks/PageBlocks.astro` through a registry of sixteen layouts.
48 routes build.

**What is still open**, and where to read the detail:

- One mapped route is deferred — `/dental-membership-plan/`, §4 Phase 4.
- 128 of the 148 mapped block rows carry a `known_gaps` entry, §1.4.
- The un-migrated template branch on every page is the rollback path and must
  keep working indefinitely, §3.2 Step 8.
- Three things this document originally specified were never built and should
  not be assumed: `@layer` (§3.2), a canonicalised `.vs-band-*` vocabulary
  (§3.2), and CI (§6, R4/R10).

**Reading order for the code:** `src/blocks/manifest.ts` (what is queried),
`src/blocks/registry.ts` (what draws it), `src/blocks/PageBlocks.astro` (how the
list is walked), `cms/mu-plugins/vs-content-model.php:1620` (the field itself).

---

## 0. Four facts established by the design pass

Facts 1–3 were measured against the live CMS in August 2026 by unauthenticated
POSTs to the WordPress GraphQL endpoint. **They cannot be re-run offline and are
therefore recorded as history, not re-verified here.** The architecture built on
each of them is intact, and that is noted per fact. Fact 4 is a source claim and
is re-verified.

**★ Fact 1 — The repeater order already IS the DOM order, on all 27 pages.**

Each page's `sections[].sectionId` array order was compared against the order of
`<section id="…">` in its template. **27 of 27 pages matched exactly.**

```
/cosmetic-dentistry/clear-aligners/   cms = why, what, process, compare, natural, lasting, gallery, faq
                                      dom = why what process compare natural lasting gallery faq
```

The CSS survey and the taxonomy had both reasoned as if `find(section_id)` were
hiding an arbitrary array. It was not, which is why the renderer swap from
lookup to iteration could be shipped and verified byte-for-byte rather than
argued about.

*Still standing:* `PageBlocks.astro` renders in array order with no `find()`,
and its own header restates this fact with the same 27-of-27 number.

**★ Fact 2 — The FAQ `set:html` tension is not a tension.**

Of 122 live FAQ rows, **18 contained HTML**, in only two tag kinds: `<a
class="vs-link" href="/…">` (27 closing tags) and `<em>` (4). Seven templates
rendered answers escaped, and **none of those seven had a tag-bearing answer**,
so adopting `set:html` was a verified no-op on the content of the day.

*Shipped, with a narrower claim than the design made.* `FaqBlock.astro` adopts
`set:html`. Its header does **not** repeat the corpus-wide safety argument; it
scopes the check to the page being converted and says to grep that page's
answers for `<` and `&` before converting it. See §6 R7 — the sanitizer this
document originally promised was deliberately not built, and the reasoning
changed.

**★ Fact 3 — The rich-text surface is one tag wide.**

Across 213 section rows: 170 headings contained tags, and the only tags present
were `<em>`, `</em>`, and `<em class="vs-italic-word">`. **Zero** bodies
contained any tag. `ctaLabel` and `ctaHref` were `0/213`.

*Shipped as specified.* `block_preamble()` gives `heading` the instruction "May
contain `<em>…</em>` for the accent styling" and `body` the instruction "Plain
text" (`vs-content-model.php:355-366`).

**Fact 4 — The band vocabulary inversion is real.** Re-verified in source at
HEAD:

```
porcelain-veneers.css:244   .pveneers .section.alt  { background: var(--vs-charcoal-green) }   ← dark
porcelain-veneers.css:253   .pveneers .section.dark { background: var(--vs-sage-pale) }        ← light
teeth-whitening.css:237     .twhitening .section.alt  { background: var(--cream) }             ← light
teeth-whitening.css:238     .twhitening .section.dark { background: var(--sage); color:#fff }  ← mid-dark
```

`.alt` and `.dark` mean opposite things on two pages, and site-wide `.dark` is
the *pale* one. Any block that reads a band from an ancestor class is wrong on
at least one page. This is why band is a value on the row, not an inheritance —
and `FaqBlock.astro:122-126` cites this fact by name as the reason.

---

## 1. THE MODEL

### 1.1 The field

One field on the existing `group_vs_page` (`cms/mu-plugins/vs-content-model.php:1072`,
`graphql_field_name => 'pageFields'`), under its own tab, added alongside the six
repeaters rather than replacing them (`:1620-1631`):

```php
[ 'key' => 'field_vs_blocks_tab', 'label' => 'Page sections', 'type' => 'tab' ],   // :1558
[
  'key'          => 'field_vs_blocks',
  'label'        => 'Sections',
  'name'         => 'blocks',
  'type'         => 'flexible_content',
  'button_label' => 'Add a section',
  'min'          => 0,
  'required'     => 0,   // explicit: `required` here would make all 33 pages unsavable
  'layouts'      => [ /* …sixteen, see 1.3… */ ],
]
```

Coexistence is free, as predicted. ACF/SCF stores a repeater as `sections`,
`sections_0_heading`, … and a flexible field as `blocks`, `blocks_0_<sub>`, plus
an `acf_fc_layout` marker. Different meta-key prefixes; the existing repeater
rows are untouched, which is what makes the rollback in §2.3 work.

**Naming rule, non-negotiable, and now written into the source at
`vs-content-model.php:1600-1613`:** never set `graphql_field_name` on a layout.
WPGraphQL for ACF registers the layout's type from the layout name but resolves
a row's `__typename` from the raw `acf_fc_layout` string — set one and the
resolver names a type that was never registered, producing a schema that builds
and a query that dies on whichever page uses that layout. Also: never register
the field with zero layouts, because the GraphQL field is a list of an interface
and with no layouts nothing implements it.

Type names follow the convention confirmed live: `blocks` yields interface
`PageFieldsBlocks_Layout` and layouts `PageFieldsBlocks<Name>Layout`.

### 1.2 The common preamble

**The design said five sub-fields and then listed six. It is six**, and
`block_preamble()` (`vs-content-model.php:299-366`) says so in its own docblock —
"the Astro side reads these six off every block regardless of type".

| Field | Type | Purpose |
|---|---|---|
| `anchor` | text | **The DOM `id`.** Split out from `section_id`'s double duty. Filled on save if left blank; **never regenerated on reorder or on heading edit.** |
| `nav_label` | text | Label in the "On this page" rail. Blank ⇒ block omitted from the rail. Editor label is "Rail label". |
| `band` | select | `paper` \| `cream` \| `sage-pale` \| `sage` \| `charcoal` (`BLOCK_BANDS`, `:199-205`). Editor label is "Background". |
| `eyebrow` | text | |
| `heading` | textarea | `<em>` permitted, nothing else (Fact 3). |
| `body` | textarea | Plain text (Fact 3). |

**The anchor is a counter, not a slug — the design was wrong about this, in
three places.** It predicted the anchor would be "generated from the heading on
first save". What ships is a sequential fallback: `fill_blank_row_id()`
(`:5149-5178`) delegates to `next_generated_id()` (`:5099-5125`), which returns
`GENERATED_ID_PREFIX . $n` where `const GENERATED_ID_PREFIX = 'custom-'`
(`:5024`). A blank anchor becomes `custom-1`, `custom-2`, … scanning existing
postmeta so it cannot collide.

The half of the claim that did survive is the important half: an anchor is
generated **once**. `fill_blank_row_id()` returns early when a value is already
present (`:5170-5174`), so a heading edit or a reorder never moves it. That is
what §7 "Do not build: auto-regenerating anchors" is protecting, and it holds.

`section_id` was doing two jobs — the lookup key and the anchor that
`tocLinks[].href` and `src/scripts/toc-spy.ts:22` depend on. Ordering killed the
first job. `anchor` is the survivor of the second, and it is editor-visible.

**The TOC is derived — but the repeater was not retired, and that is a
divergence.** The design said the 166 `toc_links` rows "become the seed values
for `nav_label` during backfill, then the repeater is retired." The seeding
happened. The retirement did not: `field_vs_toc_links` is still registered under
a live "On this page" tab (`vs-content-model.php:1125-1150`) and
`page-content.ts:238` still returns `tocLinks`. Migrated templates choose between
the two at render time —

```astro
{(hasBlocks ? derivedTocLinks : tocLinks).map(...)}   // clear-aligners.astro:243
```

— because the un-migrated branch still needs the authored list. The repeater can
only be retired when that branch is retired, which is the same constraint as
§3.2 Step 8.

**The rail is derived per page, not in `PageBlocks`.** The design put this in the
renderer. It is not there, and the renderer's own header says so: each page
derives its rail itself from `anchor` + `navLabel`. `clear-aligners.astro:126-151`
is the loop — a dedup counter, a `nested` skip, and an anchor-shape guard — and
every migrated template carries its own copy. Twenty copies of a thirty-line
loop is the known cost of not having moved it.

### 1.3 The layouts

**Sixteen shipped.** The count is the same in all three places that must agree —
sixteen layouts in `vs-content-model.php`, sixteen entries in `manifest.ts`,
sixteen bindings in `registry.ts`. Editor-facing labels are quoted exactly as
registered, because §5 shows them to the client.

| `name` | PHP | Editor label | Rows in map |
|---|---|---|---|
| `faq` | `:1644` | FAQ | 19 |
| `card_grid` | `:1800` | Card grid | 17 |
| `media_split` | `:2065` | Photo and copy | 31 |
| `process_steps` | `:2670` | Process steps | 14 |
| `gallery_marquee` | `:2949` | Smile gallery strip | 10 |
| `comparison_cards` | `:3008` | Comparison cards | 11 |
| `pricing_tiers` | `:3445` | Pricing plans | 6 |
| `stat_callout` | `:3698` | Figure and list | 6 |
| `copy_plus_stats` | `:3908` | Copy + stat cards | 4 |
| `tech_grid` | `:4066` | Technology cards | 2 |
| `service_cards` | `:4174` | Service tiles | 7 |
| `doctor_profiles` | `:4453` | Doctor profiles | 3 |
| `candidacy_ledger` | `:4557` | Candidacy + ledger | 2 |
| `callout_list` | `:4796` | Bulleted list | 1 |
| `map_visit` | `:4846` | Visit us — address, hours and map | 2 |
| `code_section` | `:4903` | Built-in section | 13 |

**Where the design's wave plan diverged.** Six of the layouts it named were never
built, and two it never named were:

- **Never built: `consult_cta` and `closing_band`.** They exist nowhere in the
  PHP, the manifest, or the registry. `VirtualConsult.astro` and
  `FinalBand.astro` are still template-only, and — this is the part worth
  knowing — they are rendered *outside* the migration switch.
  `clear-aligners.astro:604` and `:615` are unguarded while every band above them
  is `{hasBlocks ? null : …}`. **So on a fully migrated page the consult CTA and
  the closing band are still not editor-orderable.** They sit where the template
  puts them, at the bottom.
- **Never built: `stat_strip`, `tech_media`, `prose`.** `tech_grid` shipped
  alone, so the design's "two blocks, not one with a prop" call for the
  `.tech-grid` name collision resolved as one block and no collision.
- **Never built as a layout: `reviews_band`.** It survives as the `code_section`
  band key `new_patients_reviews` (`:283`) — placeable, not authorable.
- **Built but unnamed by the design: `candidacy_ledger` and `callout_list`.**
  Both are in all three places and both carry rows.

`card_grid`'s `columns` is now **2 | 3 | 4 | 5**, default `3` — and the renderer draws all four. It did not at first: `07c027d` landed the PHP choice and the `.why-grid.cols-5` rules but left the guard that EMITS the class at `3 || 4` (`CardGridBlock.astro`), so a row storing `5` emitted no modifier, fell through to the two-column base, and would have drawn a five-card band **two** across. An adversarial docs review caught it; the guard now enumerates `5` and it was proved end to end by forcing every `card_grid` row to `5` through the real render path — 17 rows emitted `why-grid cols-5` and the band computed five tracks. It is this project's most-repeated defect in another costume: a registered select VALUE with no branch in the component, invisible to query validation and to `check-block-schema.mjs` because it changes no fragment
(`vs-content-model.php:1827-1834`, commit `07c027d`). The two 5-up bands
deliberately stay on 4: the column count is coupled to a card-family rename that
has not happened, and moving them was measured to change the render.

**The escape hatch — `code_section`.** Shipped as designed in substance, and the
reasoning at `vs-content-model.php:219-222` is worth keeping: the value is a
`select`, never free text, because free text lets an editor name a band that does
not exist, and an unrecognised key renders as nothing — on a layout with no other
content, that is a section which silently is not there, with nothing in wp-admin
to say why.

Two corrections to the design's description of it:

1. **`band` is not a sub-field.** `block_code_preamble()` (`:369-410`) keeps
   exactly `['anchor', 'nav_label']` and drops `band`, `eyebrow`, `heading` and
   `body`, because the component already draws all four and nothing here can
   reach them. A control that posts a value the renderer never reads is the
   precise fault this field group keeps removing.

   This is not a nicety. The design's own example fragment (§2.1, below) printed
   `band` on the `code_section` selection set, and shipping that word **failed
   the build for all 48 routes**, because GraphQL validates the whole document
   before executing any of it. `manifest.ts:1103-1110` carries the headstone:
   `fields: "anchor navLabel bandKey"`, above a comment naming the failure.

2. **The list is 13, not "~45".** `BLOCK_CODE_BANDS` (`:235-284`) holds thirteen
   registered keys, all thirteen bound in `CodeSectionBlock.astro`, and the map
   places thirteen `code_section` rows. "~45" was the design's survey estimate of
   the bespoke tail; the closed list that exists is thirteen, and the constant's
   own docblock still quotes the old figure when explaining why `band` is not
   wire-able.

**Not a layout: the hero — half shipped.** The design argued a page has exactly
one hero, it is always first, it is never reordered, and making it a block
invites an editor to delete it or bury it mid-page. That argument stands and §7
restates it.

The group field was built exactly as specified — `eyebrow`, `h1`, `sub`,
`ctas[]`, `ratings`, `image`, `media_shape` (`:1445-1537`). **Nothing reads it.**
The tab opens with a message field saying so in as many words (`:1418-1425`):
"**Not connected yet.** The hero is still written into each page's template, and
the site does not read these boxes." Neither `src/loaders/pages.ts` nor
`src/content.config.ts` mentions `hero`. The design's claim of "same editability"
is not yet true, and the note comes down when it is.

### 1.4 How the existing rows are not thrown away

Backfill is a committed mapping table plus a script, as designed. **The path is
`cms/import/block-map.json`, not the `cms/contracts/` the design named** — no
such directory exists. The file is read by `cms/import/backfill-blocks.php`.

Measured at HEAD, by parsing the map:

- **20 routes**, 148 block rows.
- Layout spread: media_split 31, faq 19, card_grid 17, process_steps 14,
  code_section 13, comparison_cards 11, gallery_marquee 10, service_cards 7,
  pricing_tiers 6, stat_callout 6, copy_plus_stats 4, doctor_profiles 3,
  tech_grid 2, candidacy_ledger 2, map_visit 2, callout_list 1.
- **128 of the 148 rows carry a `known_gaps` entry** — a mechanism the design had
  no concept of. About twenty name a concrete closing fix. Most of the rest wait
  on retiring the un-migrated `else` branch, which cannot happen (§3.2 Step 8).

The design predicted "938 rows → ~250 block rows". The real figure is 148, over
20 routes rather than the 21 it planned for.

`block-map.json`'s own `the_rule_that_governs_every_value_below` key restates the
identity requirement (§3.3) as the constraint on every value in the file, and its
`from_template` and `band_evidence` fields exist to make each transcription
reviewable rather than trusted.

The algorithm, as implemented in `backfill-blocks.php`:

1. Walk `sections` in **array order** — safe, per ★Fact 1.
2. For each row, look up its layout in the map. Emit one `blocks` row: preamble
   filled from the section row, `band` from the map.
3. Move claimed sub-rows in: `cards` rows whose `group` the map names; the
   `images` row whose `slot` it names; process steps and FAQs where claimed.
4. `nav_label` ← the `toc_links` row whose `anchor` equals this `section_id`.
5. **Anything unclaimed becomes a `code_section` row**, or the run aborts.
   Implemented and stricter than designed: `backfill-blocks.php:35-40` fails
   before writing anything if a cards group, an images slot, a `toc_links` anchor
   or a `sections` row is left over and the map does not name it in `exempt`.
   Planning happens for every route first, so a multi-route pass cannot
   half-migrate the site.
6. **The `band` value is resolved from the page's own stylesheet**, not guessed —
   `.section.alt` on `porcelain-veneers.css:244` resolves to `charcoal`, on
   `teeth-whitening.css:237` to `cream`. Fact 4 is fixed once, at backfill.

**How the backfill is actually run — the design did not cover this, and it
matters.** `backfill-blocks.php:5-7` documents itself as a `wp eval-file` script.
That is unusable on this host, and `cms/mu-plugins/vs-migrate.php:11-15` says
why: the hosted CMS offers no SSH and therefore no WP-CLI. "Without this screen
the pilot page cannot be migrated at all." The real runner is a wp-admin screen
under **Tools → Page sections migration**, administrators only, one route at a
time, dry run first. `vs-migrate.php` is a front end and nothing else — every
judgement stays in `backfill-blocks.php` and `block-map.json`, because two copies
of that logic writing to one live CMS is a worse failure than any convenience.

That screen has a documented deletion date: when the mapped routes have all been
migrated and the map has stopped growing. It writes page content on a live,
internet-facing admin and will then have no remaining job.

**Importer contract — holds as designed.** `cms/import/import-sections.php:82,87`
calls `update_field()`, a wholesale replace, as its own header says at `:10`. All
three conditions the design required are implemented and stated in
`backfill-blocks.php:14-33`: it writes only `blocks`, it leaves all six source
repeaters exactly as they are, and it refuses a non-empty `blocks` without
`force`. Re-running `import:sections` on a migrated page rewrites a repeater
nothing reads — harmless, and stated in the header so nobody "fixes" it.

---

## 2. THE RENDERER

### 2.1 The registry — which is two files, not one

The design specified one file binding layout name to GraphQL type, selection set,
and component. **It was deliberately split in two, and the reason is a real
architectural constraint the design missed.**

```
src/blocks/manifest.ts      typeName + selection set + `hosts`   (1,177 lines)
src/blocks/registry.ts      __typename → component               (component map only)
src/blocks/PageBlocks.astro the ordered walk                     (273 lines)
src/blocks/FaqBlock.astro   component + co-located <style>
src/blocks/…                every component carries the `…Block` suffix
```

`registry.ts`'s header states the cost of merging them: it imports sixteen
`.astro` files, and Astro ships a component's scoped CSS for anything in the
module graph whether or not it renders — so importing the registry from
`page-content.ts` or from a loader "puts block CSS on all 48 routes and moves
every page's asset hashes". Metadata lives in `manifest.ts` precisely so those
callers have somewhere else to look. `src/loaders/pages.ts:36` imports from the
manifest, never the registry.

**Consequence: adding a block is three places, not the two the design promised** —
the PHP layout, the `manifest.ts` entry, and the `registry.ts` binding, in the
same commit. Both files say so. A manifest entry with no component renders as
`UnknownBlock`; a component with no manifest entry is never queried.

The query is **not generated**. Introspection is off for public requests, and
`src/lib/wp.ts:63-68` sends only `Content-Type`, so the build is an anonymous
POST. But execution validates against the server's in-memory schema regardless,
which is what makes the pre-flight below possible.

**The design's own example fragment was the defect.** It printed:

```graphql
... on PageFieldsBlocksCodeSectionLayout { anchor navLabel band bandKey }
```

`code_section` has no `band` sub-field (§1.3), and GraphQL validates the whole
document before executing any of it, so that one word failed the build for all 48
routes — not for that one row. It happened twice in Phase 2.

**What was built in response, and is not in the design at all:**
`scripts/check-block-schema.mjs`, run as `npm run check:blocks`. It POSTs each
fragment inside a query whose `where` clause matches no page, so the server
validates the full document and then resolves zero rows — a pass costs one round
trip and no page render. It exists because `manifest.ts` and
`vs-content-model.php` are two halves of one contract with nothing holding them
together, and the halves ship on different timelines: the manifest goes out with
the next Astro build, the PHP only when somebody hand-deploys it.

It deliberately does **not** check for CMS fields the manifest declines to select
(there are dozens, and a noisy pre-flight gets skipped), and it deliberately does
not check *shapes* — see §6 R13, which name validation cannot see.

### 2.2 Consuming the ordered list

`src/blocks/PageBlocks.astro`. The design's sketch used `registry[b.__typename]`;
the real entry point is `lookupBlock(block?.__typename)`, a two-stage check —
manifest membership, then component binding — returning `undefined` for either
half being absent, deliberately, so that the half-finished state of adding a
layout degrades to a visible placeholder in dev rather than a blank band in
production.

Three rules baked in, as designed:

- **`id={anchor || undefined}`** — the pattern `[...slug].astro:475` already
  shipped. Never emit `id=""`.
- **Duplicate anchors are deduplicated, not fatal.** `PageBlocks.astro:189-215`
  suffixes the second `#faq` to `#faq-2` and `console.warn`s naming route and
  position. A duplicate id is invalid HTML and sends `scroll-margin-top` (35
  declarations across 34 files in `src/`, re-measured at HEAD) to the wrong element — but it must not fail a
  build an editor triggered. The deduped value is passed back as `anchor` as well
  as `id`, so a block that builds its own rail entry cannot link to a name the
  dedup pass just renamed.
- **The old lookup stays.** `page-content.ts:255`'s `find()` remains for pages not
  yet migrated.

**Nesting — roughly half of `PageBlocks.astro`, and absent from the design.** A
row flagged `nested` renders through the previous row's `<slot />` instead of as
its own band. Two passes (`:127-215`): pass 1 groups the flat list, because a row
only learns it is a host from the row that follows it; pass 2 resolves anchors
over the *groups*, not the rows, so a nested row takes no id and does not move the
dedup counter.

Three properties are load-bearing:

- **Host eligibility is explicit.** `canHost` requires `definition?.hosts === true`
  (`manifest.ts:647`). `definition !== undefined` is not the question — fifteen of
  the sixteen layouts have no `<slot />` and would swallow the guest whole.
- **An orphan is demoted, not dropped.** A nested row with nothing above it
  renders as its own band, with a warning. Dropping it was the alternative and is
  wrong twice over: the content at stake is a price table, and the person who
  ticked the box triggers the deploy without ever seeing its log.
- **The guest shell is not rendered standalone.** `.lasting-cost-wrap` carries no
  `.wrap`, so it would run full-bleed across a live client page.

It exists for one real shape: teeth-whitening's `.lasting-cost-wrap` inside
`#lasting`, held by a 56px margin (`teeth-whitening.css:601`) that promoting to a
band would swap for ~110px of padding.

### 2.3 The migration switch

**The design specified `blocks.length > 0`. The code rejects that formulation by
name**, and the comment above it (`page-content.ts:243-252`) is the reason:

```ts
hasBlocks: blocks.some((block) => isRegisteredLayout(block.__typename))   // :253
```

> Deliberately NOT `blocks.length > 0`. That asks "did WordPress send rows"; the
> templates are asking "should I stand aside and let PageBlocks draw this page",
> and those diverge exactly when it hurts. A row whose layout this build has no
> component for renders as nothing in production, so a page migrated in wp-admin
> against a layout that has not shipped yet would go blank rather than fall back
> to the markup it still has.

Asking the registry instead means an unshipped layout degrades to "not migrated
yet", which is the honest answer and a visible one. `[...slug].astro:248-252`
restates it, and forbids re-deriving it locally.

**The rollback promise holds, and is the reason for almost everything else.**
Emptying `blocks` in wp-admin un-migrates a page with no deploy and no code
change. Verified in the field's own instructions (`vs-content-model.php:1573-1575`),
in `backfill-blocks.php:19-22` (the six source repeaters are never consumed), and
in the per-band `{hasBlocks ? null : …}` guards in every migrated template.

### 2.4 Unknown layouts

An editor will eventually see a block the build does not know — a layout added in
PHP and deployed before the Astro side ships, or a rollback of the Astro side
alone. **It must never fail the build**, because editors trigger deploys
(`vs-deploy.php:142-158`) and never see the output. That rationale is quoted back
in four separate files.

- **Production**: `UnknownBlock` renders **nothing** and `console.warn`s
  `[blocks] unknown layout "X" on /route/ at position N`. Shipped, with that
  wording.
- **Dev / preview**: a visible dashed placeholder naming the layout, under
  `import.meta.env.DEV` or `PUBLIC_VS_BLOCK_DEBUG=1`. Shipped.
- **Zod**: shipped, but not as designed — see below.
- **wp-admin**: **not built.** The design promised a persistent notice listing any
  block on a page whose layout the site does not render. There is no such notice.
  The CMS registers four `admin_notices` — `vs-admin.php:554` (blog categories),
  `vs-content-model.php:5676`, and two deploy notices in `vs-deploy.php:326,432` —
  and the one that looks relevant, `post_warning_notice()`, is gated to blog posts
  only: `warning_screen_post()` (`:5620`) returns null unless the screen is a
  `post`. Nothing warns a page editor about an unrenderable block.

**The Zod story diverged twice, and the second divergence is the interesting
one.**

The design specified "a discriminated union on `__typename` with a permissive
fallback member `{ __typename: z.string() }.passthrough()`."

1. **The permissive member cannot be a member of the discriminated union at all.**
   `content.config.ts:205-220` records the finding, verified on zod 4.3.6:
   `z.discriminatedUnion` indexes members by the literal value of the
   discriminator, so a member whose `__typename` is `z.string()` has no literal to
   index by — and Zod does not say so when the schema is built. It constructs
   without a word, then throws on the **first parse of any value**, a known layout
   as readily as an unknown one, with a plain `Error` rather than a `ZodError`, so
   it would surface as a stack trace with no page named. The two therefore sit
   side by side in a plain `z.union`, first-match-wins, and the fallback is
   `z.looseObject` (Zod 4), not `.passthrough()`. `z.object` would strip the keys
   it does not declare, handing the renderer a layout name with nothing behind it.

2. **There is no discriminated union in effect today.**
   `const KNOWN_BLOCK_LAYOUTS: ZodObject<…>[] = []` (`:198`) is still empty after
   five phases, so `PAGE_BLOCK` resolves to the bare `UNKNOWN_BLOCK` member
   (`:240-249`). **Every block on every migrated route parses through the
   permissive path with zero field validation.** The file's own comment is stale
   in the same direction (`:172`: "Empty in this phase: the field exists, no
   component does") — sixteen components now do.

   The consequence is already written down at `:230-240` and applies to all
   sixteen: a known layout whose fields are malformed also falls through to the
   permissive member rather than failing the build, "which means a block component
   must treat its own props as untrusted, exactly as if the layout were one it had
   never heard of."

### 2.5 How a block's styles travel with it

Co-located Astro `<style>`, modelled on `src/components/FinalBand.astro` — the
reference implementation, proven on 20 pages before any block existed, and cited
as the model in every block header. **Shipped in substance, on every count:**

- **No page namespace.** BEM, not `.pveneers .final-band`.
- **`--vs-*` tokens directly**, no local alias layer. True of the blocks. **Not
  true of the corpus** — 30 page sheets still re-declare `--sage`/`--cream`/`--ink`
  (`grep -ln "  --sage:" src/styles/pages/*.css` → 30). The design said ~600 lines
  "vanish"; they have not, because the sheets are the rollback path. The blocks
  simply do not use them.
- **Band is a prop** (`FinalBand.astro:25`) → a `--<band>` modifier class.
- **Self-owned rhythm**: `padding: 90px 40px` (`:51`), **zero margin**. It cannot
  collapse into a neighbour.
- **Own responsive rules inside the block**, not deferred to a page-level
  "responsive" section.

Astro scopes these to a hashed attribute selector, so a block renders identically
on any page. **The design called this "the 89.3% namespace lock going away, one
block at a time." It is not going away**, by design — see §3.2 Step 8.

---

## 3. THE CSS STRATEGY

The section where the design diverged most from what shipped. Two of its four
steps were abandoned outright, and the reasons are worth more than the steps
were.

### 3.1 What we were facing

Measured by the design pass across 34 files, 25,282 lines, 5,487 rules, 19,638
declarations. These are August 2026 corpus figures and are **not re-measured
here**; the sheet count has since changed (`lp-shared.css` was added,
`wp-page-blocks.css` was added, 133 rules were deleted).

- **62.0%** of rule instances appeared byte-identically in ≥2 files.
- `veneers-lp.css` had **1,080 of its 1,080** code lines present identically in
  `cosmetic-dentistry-lp.css`.
- The provenance was written down: `implant-dentistry.css:5` "`.cdent` → `.impd`
  namespace swap"; `general-dentistry.css:4` "Lifted from…".
- **89.3%** of selector parts (5,509 / 6,168) were scoped by a page namespace
  class.
- **563** selector parts conditioned a component's appearance on the band its
  ancestor happened to carry.
- **0** vertical margins on section wrappers, **0** id-keyed selectors, **0**
  `:has()`, **1** positional band selector, **25** equal-specificity source-order
  tie-breaks, **6** deliberate end-of-file override blocks.

Two of these are independently re-asserted in the source and still hold:
`services.css:121` is still the only positional band selector
(`.services main > .section:first-of-type`), and `vs-content-model.php:1055-1058`
restates the no-margin / no-id / no-`:has()` findings.

Reordering is nearly free. **Cross-page placement was the entire problem**, and it
is a scoping problem, not a layout problem.

### 3.2 The order of operations — as designed, and as it went

**Step 1 — `@layer`. NEVER ADOPTED.**

The design made this the first commit and the linchpin: `@layer tokens, base,
page, overrides;` in `tokens.css`, all 34 page sheets wrapped, Astro component
styles left unlayered so they beat everything.

`grep -rn "@layer" src/` returns **zero matches** across every stylesheet and
every component `<style>`. `tokens.css` is 72 lines with no layer statement.

**Everything the design built on it is therefore unrealised**, and a reader should
treat all of the following as never-happened rather than as done: "that single
commit neutralises all 31 cascade dependencies"; "the only big-bang in the whole
plan"; moving the 6 end-of-file blocks to `@layer overrides`; the
`general-dentistry.css:1128-1135` handling; and risk R9 in its entirety.

It was not needed, and the reason is Step 8's reversal: the blocks never split a
page sheet per-block, so the equal-specificity pairs the layering was going to
protect were never separated in the first place. The repo's `:where()` precedent
(`services.css:48-51`) is still the only cascade-control technique in use.

**Step 2 — Canonical band vocabulary. NOT CANONICALISED.**

The design said: extend `global.css`'s `.vs-band-*` from three values to five,
have each block write its own band class, and retire `.alt` / `.dark` per page as
it migrates.

`global.css` still defines exactly three bands — `.vs-band-paper` (`:621`),
`.vs-band-cream` (`:624`), `.vs-band-sage` (`:627`). It was never extended.

**What shipped is the inverse of the design's last sentence.** Each block maps its
`band` value to the *legacy* class the page markup already carries, so the HTML
stays byte-identical (§3.3). `FaqBlock.astro`:

```ts
const BAND_CLASS = { paper: undefined, cream: "cream", "sage-pale": "dark", sage: "sage", charcoal: "alt" };
```

`.alt` and `.dark` are what the blocks **emit**, not what they retire — and note
the mapping is the Fact 4 inversion made explicit: `charcoal → alt`,
`sage-pale → dark`. The block writes the colour itself, against the *value*; the
class is only the hook the un-migrated page sheet still needs. `FaqBlock.astro:127-129`
states the intent as future tense: "When the last page in a family is converted,
this map collapses to `vs-band-*`." That has not happened and cannot until the
rollback branch is retired.

The eyebrow half of Step 2 did ship: each block computes its eyebrow treatment
from its own band value rather than from an ancestor. See §6 R2 for the precise
extent, which is narrower than "fixed".

**Step 3 — Extraction recipe.** Shipped as designed, and unchanged: pick the
canonical rule body, strip the namespace, re-unite base and `@media` rules,
normalise the breakpoint to 991 / 780 / 479, rename to BEM, add a variant rule per
band, ship the component without using it, then convert one page at a time.

**Step 8 — THE DELETION IS NOW FORBIDDEN, NOT MANDATORY. This is the single most
important reversal in the document.**

The design said: "Step 8's deletion is not optional… when the last page is off,
the block's rules are gone from every sheet **by construction**." It cited
`FinalBand.astro:5` and ~160 lines of dead `.final-band` residue as the
cautionary tale.

What is true now: `clear-aligners.css` is still 764 lines with all seven block
families intact, and its header (`:13-23`) says why in capitals —

> **BLOCK OWNERSHIP — READ BEFORE DELETING ANYTHING IN HERE.** … The rules below
> marked `[block: X]` are the ones those components reproduce. They are dead ONLY
> while the page is rendering from `blocks` … It goes on doing that whenever an
> editor empties the field — which is the whole rollback story, and it has no
> deploy behind it, so this sheet has to keep working forever, not until the
> backfill.

The `[block: X]` markers are "a map for the dead-CSS sweep, not permission". The
rollback promise of §2.3 and the deletion of Step 8 are mutually exclusive, and
the rollback won. This is also why 128 map rows carry `known_gaps` that cannot be
closed (§1.4), and why the namespace lock (§2.5) is not going away.

The one deletion that *was* safe is the residue the design named, because it was
matched by no markup on any branch: commit `68fba34` removed **133 `.final-band`
rules across 11 sheets**. Six mentions survive in `src/styles/`, all in comments.

**A deletion is proven differently from a migration.** Deleting CSS changes no
markup, so the HTML harness (§3.5) is blind to it by construction. A CSS deletion
is proven by selector-set equality plus reachability — that the removed selectors
match nothing on any branch — not by a diff coming back empty.

**Step 4 — Do not split page stylesheets into per-block files.** Held. Rules moved
*into* components; no page sheet was ever split. This is what made Step 1
unnecessary.

### 3.3 The identity requirement — the design's most durable claim

**For its default variant, a block's rendered HTML must be byte-identical to the
markup it replaces.** The block is extracted *from* the existing markup, never
designed anew, which reduces "did the design change?" to a text diff a machine
can run.

This is the one methodological claim that survived every phase intact. It is
enforced by the `.vr/html` vs `.vr/html-template` pair (§3.5) and restated in
`block-map.json` as the rule governing every value in the file.

### 3.4 The measured payoff — recompute before quoting

The design projected a **20-family** library covering 3,005 of 5,487 rules (55%)
and collapsing 9,844 declarations to 3,043 (−69%).

**Both halves of that arithmetic are void.** The library is **16** families, not
20; and the −69% assumed per-page deletion, which Step 8 now forbids. The rules
still exist in the page sheets *and* in the components, so the corpus grew rather
than shrank. Do not quote these figures.

What was actually bought is not a line count: it is that twenty routes are
editor-orderable, and that the same markup renders from two independent sources
that measure identical.

### 3.5 How the site stays live — the harness

Three things exist, and it is worth being precise about which check catches what,
because each was added only after the previous set reported clean while something
real was broken.

**1. `scripts/vr-html.mjs` — byte-exact HTML diff, 48 routes.** `npm run
vr:snapshot` / `vr:compare`. Exit 0 when nothing differs, 1 when something does;
the non-zero exit is the point.

Equality is decided on exact normalised bytes, so this subsumes any word-level or
class-level comparison — a lost `class="section alt"` fails it. Normalisations are
kept short because each is a blind spot: only content hashes in
`/_assets/<name>.<hash>.css|js`, with the `<name>` preserved and a separate
asset-links check covering the sixteen routes that share the base name
`index_astro`. Whitespace is deliberately **not** normalised, because whitespace
between inline elements is rendered whitespace. Inline `<style>` blocks are
deliberately not normalised either — 46 of 48 pages carry one, so CSS work does
show up here by design.

**What is compared is a branch pair, not a before/after.** The design framed this
as a diff across a CSS refactor with an expected-empty result. What the repo holds
is `.vr/html/` (blocks render) against `.vr/html-template/` (template render),
**48 HTML files in each**, which is how the identity requirement is proved.

**2. `scripts/vr-screens.mjs` — Playwright, 3 widths.** 1440×900, 768×1024, and
390×844 with `hasTouch` (`:109-113`). Note `.vr/` currently holds only `html/` and
`html-template/` — **there is no committed screenshot baseline**, so `vr:compare`'s
screen half has nothing to compare against until someone runs `vr:snapshot`.

**3. `scripts/check-block-schema.mjs` — schema pre-flight.** §2.1. Catches the
manifest/PHP contract drift that fails all 48 routes at once.

**What no committed script does:** read computed styles. `grep -rn
"getComputedStyle" scripts/` returns zero. That check has been performed in a
browser and it is the only thing that has caught a band whose class attribute
matched the template and still rendered at a different width — a defect invisible
to both the byte diff (the markup matched) and the screenshot pass at the widths
sampled. Treat it as a manual step, not a gate.

**Two defects that motivated the current shape, both documented in code:**

- **Every ACF select arrives as a one-element array.** `band` arrives as
  `["charcoal"]`, eight components tested `typeof band === "string"`, and every one
  silently fell back to its default. Two charcoal bands shipped as paper on
  `/cosmetic-dentistry/clear-aligners/` — losing the background, the white
  headings and the 85%-white body copy — and **a word-level diff reported zero
  differences, because a band that loses its modifier class keeps all of its
  words.** Fixed once at the boundary: `unwrapSelects()`
  (`page-content.ts:156-199`) flattens single-element arrays, maps `[]` to `null`,
  and passes longer arrays through untouched so a genuine multi-select cannot lose
  data. `[...slug].astro:236-246` forbids reading `page.blocks` directly for
  exactly this reason.
- **`.section-head center` was dropped on backfill**, on five bands across three
  live pages. Closed by a `head_align` sub-field, now on three layouts
  (`vs-content-model.php:1652`, `:2678`, `:4182`).

**Blast radius is one page.** The 89.3% namespace scoping is the obstacle to
composability *and* the safety mechanism during migration: `.impd .faq` cannot
reach `/our-office/`.

---

## 4. THE PHASES — a historical record

**All five phases have shipped.** The plan below is preserved as written, with the
outcome recorded against each, because several estimates and exits were wrong in
ways worth keeping. The delivery table it carried (weeks 2/4/8/12/14, 70 days,
"9 weeks with two engineers") is not re-litigated here; it is history and the work
is done.

### Phase 0 — Safety net

*Planned:* harness, `@layer` adoption, and a "free win" collapsing
`veneers-lp.css` into `cosmetic-dentistry-lp.css` with a prefix, 1,194 lines
removed.

*Shipped:* the harness (§3.5, at 48 routes rather than the 36 the design assumed
throughout). `@layer` never happened (§3.2). The LP collapse was delivered
**differently**: not a prefix on one sheet, but a third file,
`src/styles/pages/lp-shared.css` (1,456 lines), with both namespaces doubled into
every selector. The two page sheets shrink to a comment plus an `@import` (855B
and 648B). `lp-shared.css:9-12` gives the reason the design's approach was
rejected: "the templates carry `.cdlp-*` and `.vlp-*` class attributes and the
migration is not allowed to touch markup." A prefix would have required a markup
change, which §3.3 forbids.

### Phase 1 — The field and the runtime, both inert

*Shipped as planned*, with `blocks` empty everywhere and the site byte-identical.
Two corrections: the switch is `hasBlocks`, not `blocks.length > 0` (§2.3), and
the Zod union has no known members even now (§2.4). The `hero` group was added
and remains inert (§1.3).

### Phase 2 — Pilot: `/cosmetic-dentistry/clear-aligners/`

The reasons for choosing this page held: its section rows were in template order
(★Fact 1), it had zero tag-bearing FAQ answers (★Fact 2), it was already fully
CMS-backed, and `clear-aligners.css` was 96.9% line-identical to
`porcelain-veneers.css`, so every block extracted here landed ready-made on five
or more siblings.

*Two predictions failed here, and both cost a build:*

- The design said the pilot "appears nowhere in the bespoke list — zero
  `code_section` rows needed." It needed exactly one. The template drew
  `LocalTrust` outside the `blocks` switch, so `PageBlocks` rendered the eight
  editor-ordered sections and the ninth band landed wherever the template put it —
  the bottom. `vs-content-model.php:207-217` records this as the reason
  `code_section` exists at all: "stays in code" and "cannot be positioned by an
  editor" are two different claims, and treating them as one moved a band on a
  live page.
- The `band` field on the `code_section` fragment failed all 48 routes (§2.1).

The page is 717 template lines and 764 stylesheet lines — the design said 609 and
767.

The derived TOC "replacing `toc_links` on this route" is overstated: it exists
and is used when `hasBlocks`, but the repeater is untouched and the authored list
still renders on the fallback branch (§1.2).

### Phase 3 — The detail-page family

Ten more pages, all shipped and all in the map.

### Phase 4 — Hubs and content pages

*Planned:* ten pages, exit "all 21 composable pages on blocks."

*Shipped: nine of ten, and the exit is 20 routes, not 21.*
`/dental-membership-plan/` is **deferred**, with the reason recorded on the route.
Its bands use `vs-band-cream` / `vs-band-paper` / `vs-band-sage` and then override
them per page — `dental-membership-plan/index.astro:403-415` repaints
`.membership .vs-band-cream` to sage, including its eyebrow, headings and italic
words. It does not sit on the `section` + `alt`/`dark` system every block emits
(§3.2 Step 2), so migrating it would require either changing its markup (§3.3
forbids) or a band mapping no other page uses.

The design also said `services.css:121`'s positional rule "is deleted here and
replaced by an explicit `first` prop." It was not: the rule is still there and no
`first` prop exists on any layout.

`candidacy_ledger`, `callout_list` and `map_visit` were added in this phase;
`copy_plus_stats`, `service_cards`, `doctor_profiles`, `tech_grid` and
`pricing_tiers` landed as planned.

### Phase 5 — New page types, content entry, dead-CSS sweep

*Three deliverables, of which two shipped:*

- **A blank WordPress page composes from `blocks` with no template — shipped.**
  `src/pages/[...slug].astro` branches on `hasBlocks` at `:441`, `:454` and `:460`,
  with `src/styles/pages/wp-page-blocks.css` (304 lines) as its branch-only sheet.
  **It builds zero routes today**, because every WordPress page still has a
  hand-built template that wins, so the change is inert by construction. That is
  the payoff the design called "the second half of the promise", and it is
  currently a capability rather than a running feature.
- **Dead-CSS sweep — shipped**, at a different size than estimated: 133
  `.final-band` rules across 11 sheets, against the design's "~160 lines across 13
  sheets" (§3.2 Step 8).
- **Content-entry surfaces — not shipped.** The design listed ~27 hero pairs, 44
  stat pairs, 27 consult-CTA pairs and so on as client work. The hero group is
  inert (§1.3), `stat_strip` and `consult_cta` were never built (§1.3), and the
  residue is tracked instead as the 128 `known_gaps` entries in the map (§1.4) — a
  mechanism the design did not have.

---

## 5. WHAT THE EDITOR SEES

One job, end to end: **add a two-column text-and-image section to
`/cosmetic-dentistry/clear-aligners/`, then move it above the FAQ.**

**1.** Pages → *Clear Aligners (Invisalign)*. The Page content box sits directly
under the title (`vs-content-model.php:1089` sets `'position' => 'acf_after_title'`,
deliberately, so it cannot be dragged below the fold).

**The tab list is eight, not the five the design drew**, and the order matters to
the experience:

```
On this page · Process · Section copy · Images · Cards & lists · FAQ · Hero · Page sections
   :1125        :1154      :1189        :1258      :1320        :1372   :1409     :1558
```

There is no "Legacy content" tab. **Hero is seventh and inert (§1.3), and Page
sections is last** — the editor scrolls past six legacy repeaters to reach the one
tab that drives a migrated page. That is a known wart, not a design intent.

**2.** They click **Page sections**. Eight collapsed rows, each showing its layout
label and its heading. **The labels are the registered ones** (§1.3) — the design's
mock-up invented four of them:

```
☰  Card grid            Why patients choose clear aligners        [paper]
☰  Photo and copy       What Invisalign actually is               [charcoal]
☰  Process steps        How treatment works                       [sage pale]
☰  Comparison cards     Aligners vs. veneers vs. bonding          [paper]
☰  Photo and copy       Results that look like you                [charcoal]
☰  Figure and list      How long results last                     [paper]
☰  Smile gallery strip  Real patients, real results               [sage pale]
☰  FAQ                  Common questions                          [paper]
```

**3.** **Add a section** opens the layout picker — a closed list of **sixteen**
named types, not the "about twenty" the design promised. There is no "custom
HTML". They choose **Photo and copy**.

**4.** The new row expands with the same controls they have already seen five
times:

- **Anchor** — blank; one is made on save. It will be `custom-1`, not a slug of
  the heading (§1.2). The instructions say what it is for and that changing it
  later breaks inbound links.
- **Rail label** — filled in ⇒ the link appears in this block's position
  automatically. There is no separate list of links to keep in step.
- **Background** — a five-item dropdown: Paper — white · Cream · Pale sage · Sage
  · Charcoal green — dark, white text. **It is a plain `<select>` with no colour
  swatches** (`'ui' => 0`, `:339`), **and it does not preselect an alternating
  value.** The design promised the picker would continue the band rhythm; the
  field has `'default_value' => 'paper'`, a fixed constant whose comment says only
  that it stops a section saving with no background at all. See §6 R1 — this was
  the stated mitigation for the highest-risk item, and it does not exist.
- **Eyebrow** · **Heading** ("May contain `<em>…</em>`") · **Body** (plain text).
- **Image** — the media picker, restricted by `mime_types`, because sharp has no
  decoder for bmp or ico and the failure would otherwise surface at build time as
  a filename.
- **Photo on the** — Left / Right. **Split** — Even / More room for the words /
  More room for the photo. (The design called these "Image side" and "Column
  balance"; both were reworded to name the outcome rather than the mechanism.)
- Optional **pull-quote** and **checklist**.

**5.** They drag the row from position 9 to position 8, above **FAQ**. ACF
renumbers on drop.

**6.** **Update.** On save, `fill_blank_row_id()` gives the anchor a value once
and never again, and `vs-deploy.php` debounces and fires the deploy hook.

**No warning appears.** The design specified a persistent `notice notice-warning`
above the editor and a count in the list column for a duplicated band, a missing
image, or an unrenderable layout. Neither surface exists for pages (§2.4), and no
adjacent-band check exists anywhere — in PHP or in Astro. The only thing that
mentions band adjacency is the field's own instruction text (`:342`): "Alternating
them is what gives the page its rhythm; two of the same in a row read as one long
section."

**7.** Two to three minutes later the section is live, in its new position, with
the "On this page" rail already listing it in the right place — because the rail
is computed from the blocks, not maintained beside them.

**What they cannot do, by design:** paste HTML, choose an arbitrary colour, set
padding, delete the hero, or move a `code_section` band's contents. They can move
that band and remove it; they cannot rewrite it.

---

## 6. THE RISK REGISTER

Rollback for almost everything is one line: **`blocks` empty ⇒ the old template
renders**, with no deploy and no code change (§2.3).

Each row carries its status at HEAD. Several mitigations the original register
claimed as done were never built, and those are marked **UNMITIGATED** rather than
quietly dropped.

| # | Risk | Status at HEAD |
|---|---|---|
| **R1** | **★ Band sequence destroyed by reordering.** Nothing computes the alternation. `gum-contouring.astro` emits `alt, dark, plain, dark, plain, alt, plain, plain` (verified at `:285`–`:538`); `porcelain-veneers.astro:512` carries a comment placing a section so the "rhythm stays cream → paper → sage-pale → paper for the closing run." An editor can put three charcoal bands in a row. | **UNMITIGATED, and the register originally claimed otherwise.** No build-time adjacent-band `console.warn` in `PageBlocks.astro`, no save-time PHP check, and the stated mitigation — "the picker preselects a band that continues the alternation" — is a fixed `'paper'` default (§5). The only control is the instruction text. Undo is still one dropdown. |
| **R2** | **★ Invisible eyebrow after a reorder.** `porcelain-veneers.css:269-278` repaints `.section-head .eyebrow::before/::after` from the *ancestor* `.section.alt` / `.section.dark`. | **Bypassed, not fixed** — the register's "fixed before any editor sees the field" overstates it. Each block computes its own eyebrow treatment from its own band value (e.g. `FaqBlock.astro:233` derives `eyebrowLight` from the band), so a block is immune. The ancestor-dependent page rule is still there, still live on the un-migrated branch, and must stay (§3.2 Step 8). |
| **R3** | **Unstyled layout** — a block placed on a page whose CSS cannot reach it. | **Structurally prevented, as designed.** A block whose co-located `<style>` is complete cannot depend on an ancestor, so this is caught at extraction review rather than at runtime. Not reachable by an editor. |
| **R4** | **A block deleted while a page depends on it** — a layout removed from the PHP `layouts` array while rows still reference it. | **Half built.** The runtime gate exists and works: `UnknownBlock` renders nothing and warns (§2.4). **The CI gate does not exist — there is no CI.** No `.github/` at the repo root or in the Astro app. The discipline holds anyway: **never delete a layout; deprecate it.** `BLOCK_CODE_BANDS`' docblock spells out why removing a key is worse than it looks — the rows keep the value, the select can no longer display it, and the band stops rendering with no error anywhere. ACF keeps orphaned `blocks_N_*` meta, so re-adding a layout restores its content intact. |
| **R5** | **Build fails on unknown content**, triggered by an editor who never sees the log. | **Prevented, as designed** — permissive Zod member, `UnknownBlock` renders nothing, anchors dedupe rather than throw, orphaned nested rows demote rather than drop. A total CMS outage stays fatal on purpose: a failed build leaves the previous deployment serving (`src/lib/wp.ts:8-12`). **The hazard the register said must not be inherited was inherited:** `page-content.ts:263-269` still throws on a missing image slot, now with a comment defending it — an absent image cannot render as "nothing", because `<Image>` would receive an empty src and fail the build pointing at Astro internals rather than at the slot. Blocks reach images through the same path. |
| **R6** | **★ Duplicate anchors.** Two `faq` blocks on one page ⇒ duplicate ids, invalid HTML, and `scroll-margin-top` landing on the wrong element. (The design pass counted 61 declarations across 16 files; re-measured at HEAD it is **35 declarations across 34 files** in `src/` — `grep -rho 'scroll-margin-top:' src/`. The old figure was inherited verbatim into a column headed "Status at HEAD" and never re-measured, which is the one place this table's own method slipped.) | **Mitigated as designed**, at `PageBlocks.astro:189-215`: auto-suffixed to `-2`, `console.warn` naming route and position, never fatal. One addition the design could not have known: nested rows deliberately do not advance the counter, because they are not bands and take no id. |
| **R7** | **★ HTML injection via FAQ answers.** Adopting `set:html` removed escaping on 7 templates. | **The mitigation was deliberately rejected, and the reasoning replaced.** No allow-list sanitizer was built; the only `sanitize` import in the tree is `blog.ts:66` (ultrahtml), for blog bodies. `FaqBlock.astro:30-37` argues the opposite of the register: "The trust boundary is the practice's own editors — this is not public input — so sanitizing is not the answer; knowing which pages carry tags is." The operating rule is a pre-conversion grep of that page's answers for `<` and `&`, and fixing the content in wp-admin rather than softening the component. **This is a live decision to re-examine if authoring ever widens beyond the practice's own staff.** |
| **R8** | **The importer clobbers a migrated page.** | **Prevented as designed.** The backfill skips any page whose `blocks` is non-empty without `force`; no importer writes `blocks`; a re-import rewrites repeaters nothing reads on a migrated page. Stated in `backfill-blocks.php`'s header so nobody "fixes" it. |
| **R9** | **`@layer` adoption changes a page.** | **MOOT.** `@layer` was never adopted (§3.2 Step 1). |
| **R10** | **A `graphql_field_name` on a layout**, or a flexible field registered with zero layouts. | **Half built.** The comment gate exists and states both rules in full (`vs-content-model.php:1600-1613`). The CI grep does not, because there is no CI. |
| **R11** | **Parse error in a must-use plugin takes down wp-admin** on a live client site; `vs-admin.php:14-20` notes these cannot be deactivated from there. | **UNMITIGATED as a gate.** `cms/bin/deploy-mu-plugins.sh` contains no `php -l` and no lint step — zero matches. And that script is not the route in use: it needs an SFTP password typed at a prompt. The working route is WP File Manager in wp-admin (see `docs/DEPLOYING.md`), which has no lint gate either. **`php -l` before deploy is therefore a human step, and skipping it is a site outage.** |
| **R12** | **The guardrail itself is gone** — the group's docblock used to say the fields "change the words, not the design," and blocks make that untrue. | **Handled in the source rather than left standing.** That sentence no longer exists. The docblock was rewritten (`vs-content-model.php:1050-1067`) to say what is now true: what an editor gains is "the ORDER of a page's sections and a closed set of section kinds… What they still cannot do is invent a section, write markup, or set a colour, a width or a spacing." The `section_id` `readonly` guard is still there, at `:1215-1216`. The residual risk is unchanged and was accepted: nothing undoes a deliberate editorial choice, so the design bounds the damage instead. Recommend the procedural control the register named — **show the editor where WordPress revisions are during handover**, since a revision restore is a one-click undo for a page's `blocks` field. |
| **R13** | **★ Every ACF select arrives as a one-element array.** NEW — this is the defect that actually shipped, and the original register did not contain it. `band` arrives as `["charcoal"]`; eight components tested `typeof band === "string"` and silently fell back to their defaults. Two charcoal bands shipped as paper on the pilot. | **Fixed at the boundary** by `unwrapSelects()` (`page-content.ts:156-199`), so no component has to know. **Its lasting value is the measurement lesson:** a word-level diff reported zero differences, because a band that loses its modifier class keeps all of its words. Name-level schema validation cannot see it either — `check-block-schema.mjs` says so explicitly, since `band` is a valid leaf whether it is a string or a list. Only a class-level or byte-level comparison catches it, which is why §3.5's harness is byte-exact and why a word count is not a substitute. |

---

## 7. WHAT NOT TO DO

Composability is needed on service and content pages. Everything below stays in
code. **Every item here held through all five phases** — none of these pages
appears among the map's 20 routes, and none of the four "do not build" items was
built.

**Stay in code — behaviour, not layout.** These bands carry JavaScript,
third-party embeds, or data contracts. A composable version would be a rewrite
pretending to be a re-plumbing.

- **`smile-gallery/index.astro`** — the case gallery and lightbox. Keyboard trap,
  focus management, image preloading.
- **`blog/index.astro`** — the filter rail and card grid, driven by
  `src/scripts/blog-filter.ts`.
- **`blog/[slug].astro`** — post hero and prose body, already fully data-driven
  from a different collection.
- **The two inline Typeform embeds** — `contact/index.astro`'s hero-embedded form
  and `emergency-dentistry/index.astro`'s `.contact-section`. A third-party script
  whose container sizing is load-bearing.
- **Nav, footer, `StickyMobileCTA`** — not page content.

**Stay in code — compliance, not composition.**

- **`privacy-policy/index.astro` and `terms-conditions/index.astro`.** Their
  `sections` and `tocLinks` are already live, already correct, and already
  order-driven. They are prose subsections, not compositional units. The one thing
  an editor should never be able to do to a HIPAA notice is reorder it.

**Stay in code — the block library would not pay for itself.**

- **`index.astro` (home).** Four one-off bands and the highest traffic on the
  site. Its `sections` rows already give the client editable copy where it
  matters.
- **The three landing pages** — `cosmetic-dentistry-lp`, `veneers-lp`,
  `general-lp`. Noindexed, excluded from the sitemap, campaign-scoped, and zero
  section rows on all three. They are ad creative with a URL. **Their CSS collapse
  was the right call and was done** — see Phase 0 for how the approach changed.
- **`404.astro` and `thank-you.astro`.**
- **`patient-testimonials/index.astro`** — three one-off grids and a video modal.

**Not a block, even though it is a band.**

- **The hero.** One per page, always first, never reordered. A `hero` group field
  gives the client the editability without the ordering surface. Making it a block
  is how you get a page with two heroes and no `<h1>`. (Built; not yet wired —
  §1.3.)
- **The "On this page" rail.** Derived from `anchor` + `nav_label`. Authoring it
  separately is hand-maintained duplication and the largest way a reorder breaks a
  link. (Derived per template rather than in the renderer — §1.2.)

**Do not build, at all.** All four held.

- **A free-form HTML block.** The one feature that converts "an editor can break a
  layout" from a bounded risk into an unbounded one. If someone needs arbitrary
  markup, they need a developer.
- **Per-block padding, colour, font-size or width controls.** Five bands, three
  ratios, four column counts. That is the whole design surface.
- **Auto-regenerating anchors.** An anchor is generated once and is then
  permanent. Regenerate it on rename or reorder and every inbound link, every rail
  entry, and every `scroll-margin-top` target silently detaches.
- **Introspection-dependent codegen in the production build.** Introspection is
  off for public requests. The manifest is hand-written and that is a feature —
  and `check:blocks` (§2.1) gets the safety of codegen without the dependency.

---

### Files and evidence

Line references verified at HEAD `07c027d`.

**The block runtime**

- `vivid-smiles-website/src/blocks/manifest.ts` — selection sets, `hosts`, and the
  `code_section` headstone at `:1103-1110`
- `vivid-smiles-website/src/blocks/registry.ts` — component map only; its header
  gives the CSS-graph reason for the split
- `vivid-smiles-website/src/blocks/PageBlocks.astro` — grouping and nesting
  `:127-215`, anchor dedup `:189-215`
- `vivid-smiles-website/src/components/FinalBand.astro` — the reference block:
  band prop `:25`, self-owned rhythm `:51`, provenance `:5`
- `vivid-smiles-website/src/lib/page-content.ts` — `unwrapSelects` `:156-199`,
  `hasBlocks` `:253`, section lookup `:255`, the fatal image lookup `:263-269`
- `vivid-smiles-website/src/pages/[...slug].astro` — blocks branch `:441`, `:454`,
  `:460`; `id={… || undefined}` `:475`
- `vivid-smiles-website/src/content.config.ts` — `UNKNOWN_BLOCK` `:168`, the empty
  `KNOWN_BLOCK_LAYOUTS` `:198`, the zod-4 discriminated-union finding `:205-220`

**The schema**

- `cms/mu-plugins/vs-content-model.php` — group `:1072`, tabs `:1125`–`:1558`,
  `blocks` field `:1620`, the two runtime rules `:1600-1613`, `block_preamble()`
  `:299-366`, `block_code_preamble()` `:369-410`, `BLOCK_BANDS` `:199-205`,
  `BLOCK_CODE_BANDS` `:235-284`, anchor generation `:5024`/`:5099-5125`/`:5149-5178`,
  `section_id` readonly `:1215-1216`
- `cms/mu-plugins/vs-migrate.php:11-15` — why the backfill runs from Tools, not
  WP-CLI
- `cms/import/backfill-blocks.php` — what it does not touch `:14-33`, fail-before-write
  `:35-40`
- `cms/import/block-map.json` — 20 routes, 148 rows, 128 with `known_gaps`
- `cms/import/import-sections.php:10, :82, :87` — the wholesale-replace contract

**The harness**

- `vivid-smiles-website/scripts/vr-html.mjs` — byte-exact diff; `.vr/html/` vs
  `.vr/html-template/`, 48 files each
- `vivid-smiles-website/scripts/vr-screens.mjs:109-113` — 1440 / 768 / 390
- `vivid-smiles-website/scripts/check-block-schema.mjs` — `npm run check:blocks`

**CSS**

- Band inversion (Fact 4): `porcelain-veneers.css:244,253` vs
  `teeth-whitening.css:237,238`
- Block-ownership warning: `clear-aligners.css:13-23`
- Ancestor-dependent eyebrow: `porcelain-veneers.css:269-278`
- Positional band rule, still present: `services.css:121`
- `:where()` precedent for cascade control: `services.css:48-51`
- Existing three-value band system: `global.css:621-641`
- The order-independent renderer's sheet: `wp-page.css:158` (310 lines); the
  blocks-branch sheet `wp-page-blocks.css` (304 lines)
- LP collapse: `lp-shared.css` (1,456 lines), reasoning at `:9-12`
- The nested-row margin that motivated nesting: `teeth-whitening.css:601`

**Not re-verified offline:** §0 Facts 1–3 and the §3.1 corpus statistics are
August 2026 measurements against the live CMS and the then-current sheets. The
sheet count has since changed. Re-measure before quoting any of them.
