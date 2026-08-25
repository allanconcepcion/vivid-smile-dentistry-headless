<!-- Design document. Written 25 August 2026 by a design pass that read the
     repository, queried the live CMS, and wrote no code. Nothing here is built
     yet: the blog change shipped alongside it, the block system did not.
     Verify before acting — see the "facts established here" section, which
     corrected several assumptions this project started with. -->

# Composable Pages in WordPress — Architecture and Phased Plan

**Status:** design only. No files written, no git state touched. All PHP unchanged, so nothing to `php -l` yet — the lint gate is named in Phase 1 where the first edit lands.

**New evidence gathered for this document** (unauthenticated POSTs to `https://1230613.us28.myftpupload.com/graphql`, plus local reads). Four findings change the plan materially; they are marked ★ where they appear.

---

## 0. Four facts established here, not inherited

**★ Fact 1 — The repeater order already IS the DOM order, on all 27 pages.**

I compared each page's `sections[].sectionId` array order against the order of `<section id="…">` in its template. **27 of 27 pages match exactly**, with zero exceptions:

```
/cosmetic-dentistry/clear-aligners/   cms = why, what, process, compare, natural, lasting, gallery, faq
                                      dom = why(:178) what(:221) process(:254) compare(:291)
                                            natural(:340) lasting(:369) gallery(:417) faq(:447)
```

The CSS survey and the taxonomy both reasoned as if `find(section_id)` were hiding an arbitrary array. It is not. `src/lib/page-content.ts:121`'s `sections.find(...)` and a `sections.map(...)` return the same bands in the same order on every page in the CMS today. **The renderer swap from lookup to iteration is provably a no-op against current content** — which means Phase 1 can be shipped and verified byte-for-byte rather than argued about.

**★ Fact 2 — The FAQ `set:html` tension is not a tension. It is settled.**

The taxonomy called this "the one place where 'preserve the design exactly' and 'one block' are in genuine tension." It is resolvable with data. Of 122 live FAQ rows, **18 contain HTML**, and only two tag kinds appear: `<a class="vs-link" href="/…">` (27 closing tags) and `<em>` (4). The seven templates that render answers escaped are:

`cosmetic-dentistry/clear-aligners.astro`, `gum-contouring.astro`, `porcelain-veneers.astro`, `teeth-whitening.astro`, `implant-dentistry/all-on-4-single-arch.astro`, `bone-grafting.astro`, `sinus-lift.astro`

**None of those seven pages has a single tag-bearing answer.** Every one of the 18 lives on a page that already renders `set:html`. So the block adopts `set:html` and the change is a verified no-op on current content. The residual risk moves from "12 pages lose markup / 8 pages gain it" to "an editor can now type a tag" — handled by a sanitizer allow-list (§6, R7), not by a design compromise.

**★ Fact 3 — The rich-text surface is one tag wide.**

Across 213 section rows: 170 headings contain tags, and the *only* tags present are `<em>`, `</em>`, and `<em class="vs-italic-word">`. **Zero** bodies contain any tag. `ctaLabel` and `ctaHref` are `0/213` — re-verified live just now. A block schema that makes `heading` rich-but-`<em>`-only and `body` plain is not a compromise; it is a description of the data.

**Fact 4 — The band vocabulary inversion is real, and it is bigger than "one page."** Verified in source:

```
porcelain-veneers.css:244   .pveneers .section.alt  { background: var(--vs-charcoal-green) }   ← dark
porcelain-veneers.css:253   .pveneers .section.dark { background: var(--vs-sage-pale) }        ← light
teeth-whitening.css:237     .twhitening .section.alt  { background: var(--cream) }             ← light
teeth-whitening.css:238     .twhitening .section.dark { background: var(--sage); color:#fff }  ← mid-dark
```

`.alt` and `.dark` mean opposite things on two pages, and site-wide `.dark` is the *pale* one. Any block that reads a band from an ancestor class is wrong on at least one page. This is why band must become a value, not an inheritance.

---

## 1. THE MODEL

### 1.1 The field

One new field on the existing `group_vs_page` (`cms/mu-plugins/vs-content-model.php:329`, `graphql_field_name => 'pageFields'`), under its own tab, added alongside the six repeaters rather than replacing them:

```php
[ 'key' => 'field_vs_blocks_tab', 'label' => 'Page sections', 'type' => 'tab' ],
[
  'key'          => 'field_vs_blocks',
  'label'        => 'Sections',
  'name'         => 'blocks',
  'type'         => 'flexible_content',
  'button_label' => 'Add a section',
  'min'          => 0,
  'layouts'      => [ /* …see 1.3… */ ],
],
```

Verified available: `pro/fields/class-acf-field-flexible-content.php` returns **500** (exists, fatals without bootstrap) where a fabricated sibling returns **404** — re-run for this document. `blocks` is confirmed *not* on the schema yet: `Cannot query field "blocks" on type "PageFields"`.

Coexistence is free. ACF/SCF stores a repeater as `sections`, `sections_0_heading`, … and a flexible field as `blocks`, `blocks_0_<sub>`, plus an `acf_fc_layout` marker. Different meta-key prefixes; the 938 existing rows are untouched. The GraphQL change is additive — `src/loaders/pages.ts` receives only what it asks for.

**Naming rule, non-negotiable:** never set `graphql_field_name` on a layout. `wpgraphql-acf`'s `FlexibleContent.php` registers the type from `get_field_group_graphql_type_name($layout)` (L56) but resolves `__typename` from the raw `acf_fc_layout` string (L36) — set one and the resolver names a type that was never registered, producing a runtime failure on one page rather than a schema-build failure. Also: never register the field with zero layouts (L54, L84-86 return `list_of` an interface nothing implements).

Type names follow the convention confirmed live (`Cannot query field "zzz" on type "PageFieldsSections"`, and the "Did you mean … `PageFields_Fields`" hint). So `blocks` yields interface `PageFieldsBlocks_Layout` and layouts `PageFieldsBlocks<Name>Layout`.

### 1.2 The common preamble

Every layout that renders a band opens with the same five sub-fields, in the same order, so the editor learns one control set:

| Field | Type | Purpose |
|---|---|---|
| `anchor` | text | **The DOM `id`.** Split out from `section_id`'s double duty. Generated from the heading on first save; **never regenerated on reorder or on heading edit.** |
| `nav_label` | text | Label in the "On this page" rail. Blank ⇒ block omitted from the rail. |
| `band` | select | `paper` \| `cream` \| `sage-pale` \| `sage` \| `charcoal` — five values, covering every background found across the 34 sheets. |
| `eyebrow` | text | |
| `heading` | textarea | `<em>` permitted, nothing else (Fact 3). |
| `body` | textarea | Plain text. No tags, ever (Fact 3). |

`section_id` is doing two jobs today — the lookup key at `page-content.ts:121` *and* the anchor that `tocLinks[].href` and `src/scripts/toc-spy.ts:22` depend on. Ordering kills the first job. The second must survive: `anchor` is the survivor and it is editor-visible.

**The TOC is derived, not authored.** Once blocks are ordered, `toc_links` (166 rows) is a hand-maintained parallel list of the exact same ordering — and the single largest reorder-breakage surface on the site. The renderer builds the rail from `blocks[].anchor` + `blocks[].nav_label` in block order. `toc-spy.ts:22` reads its section list off the rendered links, so it keeps working unchanged. The 166 existing rows become the seed values for `nav_label` during backfill, then the repeater is retired. **This removes 166 rows of maintenance and makes it structurally impossible for a reorder to break an anchor link.**

### 1.3 The layouts

Ranked by build order. "Maps from" names the existing rows the backfill consumes.

**Wave A — CMS-backed today, order-safe, no new content needed.**

| `name` | GraphQL type | Fields beyond the preamble | Maps from |
|---|---|---|---|
| `faq` | `PageFieldsBlocksFaqLayout` | `pull`, `items[]{question, answer, open}`, `cta_label`, `cta_href` | `faqs` (122 rows) + the `sections` row with id `faq` |
| `card_grid` | `…CardGridLayout` | `columns` 2\|3\|4, `numbered` bool, `cards[]{meta, title, lead, body, href}` | `cards` groups (187 rows — **all 187 name-match a template `const`, 184 already `.map()`ed**) + owning `sections` row |
| `media_split` | `…MediaSplitLayout` | `image`, `media_side` left\|right, `ratio` even\|wide-text\|wide-media, `quote`, `checklist[]`, `cta_label`, `cta_href` | `sections` (`what` 12, `natural` 3, `candidacy` 6, `technology` 6, `laser`/`design`/`results`) + `images` (`whatImg` 11, `naturalImg` 3, `candidacyImg` 4) |
| `process_steps` | `…ProcessStepsLayout` | `layout` grid\|card\|divided, `columns`, `steps[]{tag, num, title, body}` | `process_steps` (50 rows) + `sections.process` |
| `gallery_marquee` | `…GalleryMarqueeLayout` | — (tiles come from `src/assets/images/smiles/` via `lib/smiles.ts`) | `sections.gallery` (10 rows) |
| `comparison_cards` | `…ComparisonCardsLayout` | `cards[]{tag, title, body, bullets[], ribbon, featured}` | `compareCards`, `compareTiles`, `alternatives`, `treatmentLevels`, `archConfigs`, `procedures`, `materials` (25 rows) |

**Wave B — already a shared component, content is literal today.**

| `name` | Wraps | Notes |
|---|---|---|
| `consult_cta` | `components/VirtualConsult.astro` (27 uses) | `typeform_id`, `headline`, `body`. 27 pairs of copy to enter. |
| `closing_band` | `components/FinalBand.astro` (20 uses) | Reference implementation for the whole library — band already a prop at `:25`, rhythm already self-owned at `:49`, zero margins. 20 pairs to enter. |
| `pricing_tiers` | `components/PricingTiers.astro` | `tiers[]`, `financing_note`. 1 of 10 call sites CMS-backed; ~13 tiers to enter. |
| `map_visit` | `components/LocalTrust.astro` | Address stays in `src/data/contact.ts` — correctly. `sections.area` supplies the eyebrow. |
| `reviews_band` | `components/ReviewMarquee.astro` | Reads the Testimonials CPT. No fields beyond `aria_label`. |
| `service_cards` | `.svc-grid` + `ArrowBadge` | `cards[]{title, body, href, image}` |
| `stat_strip` | "At a glance" | `items[]{value, caption}` ×4. 44 pairs, **zero CMS backing**. The most byte-identical block on the site (3 rule bodies, 11 identical instances each). |

**Wave C — needs a variant decision, or a split.**

| `name` | Decision |
|---|---|
| `copy_plus_stats` | **Split from `card_grid`.** The taxonomy is right and the CSS survey's own data proves it: the hub `.why-grid` is `1.15fr 1fr / gap 64px / align-items:center` wrapping `.stat-card` — a two-column copy layout that reuses the class name. Merging would let an editor put six cards into a layout that has never rendered more than a paragraph and two stats. 4 uses, 17 stat cards. |
| `tech_grid` / `tech_media` | **Two blocks, not one with a prop.** `.tech-grid` is a 3-column card grid on `cosmetic-dentistry.css`/`implant-dentistry.css` and a 2-column image+copy layout on `home.css`/`about-us.css`. That is a name collision, not a variant. |
| `doctor_profiles` | One block, `layout: stack\|grid`. `.doc-grid` uses `ImageFrame`, `.doctor-stack` does not — a prop, not a split. |
| `prose` | `.prose` / `.closing`. Rich body permitted here and only here, sanitized. |
| `stat_callout` | `.lasting-card` (identical ×5) with `.cost-card` / `.insurance-card` as variants. |

**The escape hatch — `code_section`.**

```
name: code_section
fields: anchor (text), nav_label (text), band (select, readonly),
        band_key (select — a closed list of registered bespoke bands, readonly label)
```

A layout with **no editable content**. It names one of the ~45 genuinely bespoke bands (the smile-gallery lightbox, the membership plan card, the emergency scenario grid, the about-us team grid, …) and the renderer emits that band's existing component with its existing props. An editor can **reorder it and remove it, but not author it.**

This is the mechanism that makes a partly-migrated page composable without a redesign, and it is what keeps the 13.4% bespoke tail from blocking the other 86.6%. It is also the honest answer to the client: some bands you can move but not rewrite.

**Not a layout: the hero.** 35 uses, 33 pages, 10 layout variants, and every headline is a template literal (`[...slug].astro:251` and `blog/[slug].astro:106` are the only two that read a title from data). A page has exactly one hero, it is always first, and it is never reordered. Making it a block invites an editor to delete it or bury it mid-page. **It becomes a `hero` *group* field on `group_vs_page`** — `eyebrow`, `h1` (rich), `sub`, `ctas[]`, `ratings` bool, `image`, `media_shape` — sitting above the `blocks` tab. Same editability, none of the ordering surface, and it removes the single most expensive item (5 days, 10 variants) from the block library's risk budget.

### 1.4 How the 938 rows are not thrown away

Backfill is a committed mapping table plus a script, not a hand-migration.

`cms/contracts/block-map.json` — one entry per `(route, section_id)`, reviewable in a PR before it runs:

```json
{ "/cosmetic-dentistry/clear-aligners/": [
    { "section_id": "why",     "layout": "card_grid",        "band": "paper",    "cards": ["careBullets"], "columns": 3, "numbered": true },
    { "section_id": "what",    "layout": "media_split",      "band": "charcoal", "image": "whatImg",  "media_side": "left",  "ratio": "even" },
    { "section_id": "process", "layout": "process_steps",    "band": "sage-pale","steps": "all", "layout_variant": "grid", "columns": 4 },
    { "section_id": "compare", "layout": "comparison_cards", "band": "paper" },
    { "section_id": "natural", "layout": "media_split",      "band": "charcoal", "image": "naturalImg", "media_side": "right", "ratio": "wide-media" },
    { "section_id": "lasting", "layout": "stat_callout",     "band": "paper" },
    { "section_id": "gallery", "layout": "gallery_marquee",  "band": "sage-pale" },
    { "section_id": "faq",     "layout": "faq",              "band": "paper" }
] }
```

The algorithm:

1. Walk `sections` in **array order** — safe, per ★Fact 1.
2. For each row, look up its layout in the map. Emit one `blocks` row: preamble filled from the section row (`anchor` ← `section_id`, `eyebrow`/`heading`/`body` verbatim), `band` from the map.
3. Move claimed sub-rows in: `cards` rows whose `group` the map names; the `images` row whose `slot` it names; all `process_steps` if it claims them; all `faqs` if it is the `faq` block.
4. `nav_label` ← the `toc_links` row whose `anchor` equals this `section_id`.
5. **Anything unclaimed becomes a `code_section` row** at the position the template renders it. Nothing is silently dropped. The script fails loudly if a `cards` group, an `images` slot or a `toc_links` anchor is left unconsumed and unmapped.
6. **The `band` value is resolved by parsing the page's own stylesheet**, not guessed — `.section.alt` on `porcelain-veneers.css:244` resolves to `charcoal`, on `teeth-whitening.css:237` to `cream`. Fact 4 gets fixed once, at backfill, mechanically and auditably, instead of living on as an inheritance hazard.

Net: **938 rows → ~250 block rows, zero content lost, zero re-typing of anything that exists.** The 187 dead card rows in particular go from "re-enter by hand" to "delete a `const`, read `block.cards`."

**Importer contract:** `cms/import/import-sections.php:82,87` calls `update_field()` — a **wholesale replace**, as its own header says at `:10`. The backfill must (a) never write `blocks` from a payload, (b) skip any page whose `blocks` is already non-empty, and (c) live in the importer's own directory so a future re-import cannot resurrect a stale state. Re-running `import:sections` on a migrated page rewrites a repeater nothing reads — harmless, and worth stating in the file header so the next person does not "fix" it.

---

## 2. THE RENDERER

### 2.1 The registry

One file is the single source of truth binding a layout name to its GraphQL type, its selection set, and its component:

```
src/blocks/registry.ts     layout → { typeName, fragment, component }
src/blocks/FaqBlock.astro          component + co-located <style>
src/blocks/MediaSplit.astro
src/blocks/…
src/components/PageBlocks.astro    the ordered map
```

The query is **not generated**. Introspection is off — re-verified: `{ __schema { queryType { name } } }` returns *"GraphQL introspection is not allowed for public requests by default."* But execution validates against the server's in-memory schema regardless (`{ __typename }` → `"RootQuery"` works; a fragment on a fake type is rejected with a "Did you mean" list). `src/lib/wp.ts:63-68` sends only `Content-Type`, so the build is an anonymous POST like those probes.

So `src/loaders/pages.ts` concatenates the registry's fragments into `PAGES_QUERY`:

```graphql
pageFields {
  blocks {
    __typename
    ... on PageFieldsBlocksFaqLayout        { anchor navLabel band eyebrow heading pull items { question answer open } }
    ... on PageFieldsBlocksMediaSplitLayout { anchor navLabel band eyebrow heading body mediaSide ratio quote image { … } }
    ... on PageFieldsBlocksCodeSectionLayout{ anchor navLabel band bandKey }
  }
}
```

Adding a block is **one PHP layout + one registry entry, in the same commit** — the discipline `content.config.ts:50-56` already spells out for the category enum. If codegen is ever wanted, WPGraphQL allows introspection for *authenticated* requests: run it as a developer step and commit the output. **Never make the production build depend on introspection.**

Discriminate on `__typename`. Keep `fieldGroupName` (a plain `String` on the interface, `FlexibleContent.php:32-39`) in mind only as an escape hatch if a future release ever blocks meta-fields alongside introspection.

### 2.2 Consuming the ordered list

`src/components/PageBlocks.astro`:

```
{blocks.map((b, i) => {
   const entry = registry[b.__typename];
   if (!entry) return <UnknownBlock block={b} index={i} />;
   const C = entry.component;
   return <C {...b} id={b.anchor || undefined} />;
 })}
```

Three rules baked in:

- **`id={b.anchor || undefined}`** — exactly the pattern `[...slug].astro:271` already ships. Never emit `id=""`.
- **Duplicate anchors are deduplicated, not fatal.** A build-time pass suffixes the second `#faq` to `#faq-2` and `console.warn`s naming page and block. A duplicate id is invalid HTML and sends `scroll-margin-top` (61 declarations across 16 files) to the wrong element — but it must not fail a build an editor triggered.
- **The old lookup stays.** `page-content.ts:121`'s `find()` remains for pages not yet migrated. `blocks.length > 0` is the switch, per page.

### 2.3 The migration switch

```
blocks.length === 0  ⇒ render the existing template body, unchanged
blocks.length  >  0  ⇒ render <PageBlocks blocks={blocks} />
```

This is the whole safety architecture in two lines. Every page migrates independently; **every page's migration is undone by emptying one field in wp-admin, with no deploy and no code change** (the next scheduled build picks it up; `cms/mu-plugins/vs-deploy.php:142-158` fires the hook on save).

### 2.4 Unknown layouts

An editor will eventually see a block the build does not know — a layout added in PHP and deployed before the Astro side ships, or a rollback of the Astro side alone.

**It must never fail the build.** `src/loaders/blog.ts:280-284` and `src/integrations/yoast-sitemap.ts:18-20` already encode the reason: editors trigger deploys and never see the output (`vs-deploy.php:142-158`), so an editor must not be able to break one.

- **Zod (`content.config.ts`)**: `blocks` is a discriminated union on `__typename` with a permissive fallback member `{ __typename: z.string() }.passthrough()`. A strict union throws on an unknown member and takes the site down.
- **Production**: `UnknownBlock` renders **nothing**, and `console.warn`s `[blocks] unknown layout "X" on /route/ at position N`.
- **Dev / preview**: renders a visible dashed placeholder naming the layout. The person who can fix it is the one who sees it.
- **wp-admin**: the same `admin_notices` machinery `vs-admin.php:516-554` already uses, listing any block on this page whose layout the site does not render. Persistent, recomputed on `acf/save_post` priority 20 (the hook `vs-deploy.php:172` already uses, which guarantees ACF has written before the check reads).

### 2.5 How a block's styles travel with it

Co-located Astro `<style>`, modelled exactly on `src/components/FinalBand.astro` — the reference implementation, and one already proven on 20 pages:

- **No page namespace.** `vs-final-band__wrap` BEM (`:34-47`), not `.pveneers .final-band`.
- **`--vs-*` tokens directly** — no local alias layer (30 files re-declare `--sage`/`--cream`/`--ink` etc., ~600 lines that vanish).
- **Band is a prop** (`:25`) → `vs-final-band--${background}` (`:34`, `:55`).
- **Self-owned rhythm**: `padding: 90px 40px` (`:49`), **zero margin**. It cannot collapse into a neighbour.
- **Own responsive rules inside the block** (`:109-120`), not deferred to a page-level "responsive" section.
- **`:global()` only for slotted content** (`:87`, `:93`).

Astro scopes these to a hashed attribute selector, so a block renders identically on any page — which is precisely the 89.3% namespace lock going away, one block at a time.

---

## 3. THE CSS STRATEGY

The single most important section, and the one where the two surveys need reconciling.

### 3.1 What we are actually facing

- 34 files, 25,282 lines, 5,487 rules, 19,638 declarations.
- **62.0%** of rule instances appear byte-identically in ≥2 files. **72.3%** of non-blank lines appear verbatim in at least one other sheet.
- `veneers-lp.css` has **1,080 of its 1,080** code lines present identically in `cosmetic-dentistry-lp.css`.
- The provenance is written down: `implant-dentistry.css:5` "`.cdent` → `.impd` namespace swap"; `general-dentistry.css:4` "Lifted from…"; `emergency-dentistry.css:4` "Lifted from…"; `general-dentistry.css:1090` "`FAQ (lifted verbatim)`".
- **89.3%** of selector parts (5,509 / 6,168) are scoped by a page namespace class.
- **563** selector parts condition a component's appearance on the band its ancestor happens to carry.
- **0** vertical margins on section wrappers, **0** id-keyed selectors, **0** `:target`, **0** `:has()`, **1** positional band selector (`services.css:121`), **25** equal-specificity source-order tie-breaks, **6** deliberate end-of-file override blocks.

Reordering is nearly free. **Cross-page placement is the entire problem**, and it is a scoping problem, not a layout problem.

### 3.2 The order of operations

**Step 1 — `@layer`, before anything moves. One commit, zero declaration changes.**

There is no `@layer` anywhere in `src/styles/` today (verified: zero matches), so adoption is additive.

Declared once at the top of `src/styles/tokens.css`, which `BaseLayout.astro:13` imports first:

```css
@layer tokens, base, page, overrides;
```

- `tokens.css`, `global.css` → `@layer tokens` / `@layer base`
- all 34 page sheets → `@layer page`, wrapped, untouched inside
- the 6 end-of-file blocks (`porcelain-veneers.css:720`, `cosmetic-dentistry.css:993`, `clear-aligners.css:739`, `full-mouth-rehabilitation.css:732`, `gum-contouring.css:726`, `smile-makeover.css:793` — 186 lines whose comments say they work *because* of file position) → `@layer overrides`
- **Astro component `<style>` output stays unlayered.** Unlayered styles beat every layered style regardless of specificity — which is exactly the property a self-contained block needs to render correctly on a page whose sheet has a competing rule.

That single commit neutralises all 31 cascade dependencies before a single block moves. It is the mitigation for the top risk of naive extraction: split `porcelain-veneers.css` per-block and `:58` (`.eyebrow.light`) vs `:624` (`.final-band-text .eyebrow`) stop having a defined winner — ×25 pairs across 17 files.

**This step is all-or-nothing within CSS.** A sheet left unlayered wins over every layered one. That is the only big-bang in the whole plan, it touches no declaration, and the harness (§3.5) proves it.

The repo already knows this technique in miniature — `services.css:48-51`: *":where pins specificity to (0,0,0) so component-internal typography wins over these page-level defaults at equal specificity instead of losing on source order."* `@layer` is that idea generalised and made bundler-proof.

**Watch for:** rules that today *deliberately* beat a shared component, e.g. `general-dentistry.css:1128-1135` bumping closed FAQ titles to `--ink` so they are not white-on-white in a dark band. Unlayering component styles inverts that. Those go to `@layer overrides` on the way through — and most get deleted outright, because a block that owns its band no longer needs the patch.

**Step 2 — Canonical band vocabulary. One value list, five values.**

Reuse what exists: `global.css:621-641` already ships `.vs-band-paper` / `.vs-band-cream` / `.vs-band-sage` with band-conditional typography, and `dental-membership-plan/index.astro` (1,141 lines, **no page stylesheet at all**) already runs on it. Extend to five (`paper`, `cream`, `sage-pale`, `sage`, `charcoal`) to cover every background found across the corpus.

The block writes its own band class from its `band` prop. `.alt` / `.dark` are retired per page as it migrates. **And the eyebrow rule colour is computed from the block's own band class, not from an ancestor** — `porcelain-veneers.css:269-278` currently repaints `.section-head .eyebrow::before/::after` off `.section.alt` / `.section.dark`, so a reordered block would get an invisible eyebrow. Fixing this is CSS work done *before* any editor touches anything.

**Step 3 — Extraction recipe. Mechanical, per block, same nine moves every time.**

1. Pick the canonical rule body — the modal one from the normalised diff (e.g. `.keys-grid`: 3 rule bodies, 11 identical instances each; `.faq-list`: identical in 17 of 20 sheets).
2. Strip the page namespace prefix from every selector part. *This is the 89.3% going away.*
3. Re-unite base and `@media` rules — 24 files have a trailing "responsive" section totalling 1,648 lines, physically separated from what they modify.
4. Normalise the breakpoint. Ten are in use (480/479/600/640/768/780/880/991/1100/1240); the block set is 991 / 780 / 479, per `FinalBand.astro:109,117` and `global.css`.
5. Rename to `vs-<block>__<element>` BEM.
6. Add a variant rule per band the block supports. This is where the 563 band-conditional selector parts get absorbed — as *five* explicit variants instead of an inheritance.
7. Ship the component. **Nothing changes on the site yet** — no page uses it.
8. Convert one page: swap the inline markup for `<Block>`, delete that block's rules from that page's stylesheet, run the harness.
9. Repeat per page. When the last page is off, the block's rules are gone from every sheet **by construction**.

Step 8's deletion is not optional. The cautionary tale is in the tree: `FinalBand.astro:5` says it "replaces the inline `.final-band` section that was duplicated across every service detail page" — and **13 page stylesheets still carry ~13 dead lines each**, ~160 lines matched by no markup anywhere in `src`. Extraction leaves litter unless cleanup is in the definition of done.

**Step 4 — Do not split page stylesheets into per-block files.** Rules move *into components*; a page sheet only ever shrinks. This sidesteps the "the bundler decides which rule is later" trap entirely.

### 3.3 The identity requirement, and how it is enforced

"Preserve the design exactly" is enforceable, not aspirational: **for its default variant, a block's rendered HTML must be byte-identical to the markup it replaces.** The block is extracted *from* the existing markup, never designed anew. That reduces "did the design change?" to a text diff a machine can run.

### 3.4 The measured payoff

A 20-family library covers **3,005 of 5,487 rules (55%)** at 3.4× duplication, collapsing **9,844 declarations to 3,043 (−69%)**. Representative: the FAQ block is 20 files, 289 rule instances, 38 unique rules — 7.6× duplication, 1,017 declarations → 128.

The residual 2,482 rules concentrate almost entirely in pages that do not need composability at all (§7).

### 3.5 How the site stays live — the harness is Phase 0, not Phase 4

There is no CSS test suite today. Building one is the precondition, not the epilogue.

1. **HTML diff.** `astro build` before and after, normalise whitespace, diff `dist/**/*.html` across all 36 routes. For every step through Phase 2 the expected diff is **empty**.
2. **Screenshot diff.** Playwright full-page captures, 36 routes × 3 widths (1440 / 768 / 390), pixel-compared with a small tolerance.
3. **Blast radius is one page.** The 89.3% namespace scoping is the obstacle to composability *and* the safety mechanism during migration: `.impd .faq` cannot reach `/our-office/`. A mistake is confined to the page in front of you.
4. **Precedent.** This exact migration has already been done once here, incrementally, on a live site: `FinalBand` on 20 templates, `VirtualConsult` on 27, `Button` on 29, and the old rules sitting inert in 13 sheets. That is what a safe mid-flight migration looks like.
5. **A working order-independent renderer is already in production.** `[...slug].astro:271` iterates `sections` in array order, assigns ids from the field, and styles with `wp-page.css` — 310 lines, no page namespace, all intra-component rules, wrapper-owned rhythm (`wp-page.css:158`, `.wp-page-section + .wp-page-section { margin-top: … }` — the only inter-section sibling rule in the corpus, and order-*independent* by construction). It is plain rather than designed, but it is architecturally the target, running live today.

---

## 4. THE PHASED PLAN

Each phase is independently shippable and leaves the site working. Days are one engineer.

### Phase 0 — Safety net · 5 days

- Visual-regression harness (HTML diff + Playwright, 36 routes × 3 widths).
- `@layer tokens, base, page, overrides;` adopted across `tokens.css`, `global.css` and all 34 page sheets. Zero declaration changes. The 6 end-of-file blocks move to `@layer overrides`.
- **Free win, shipped here as the harness's first proof:** collapse `veneers-lp.css` into `cosmetic-dentistry-lp.css` with a prefix. 1,194 lines removed, no visual change, no composability work. If the harness reports anything, the harness is wrong.

*Exit: site byte-identical. Cascade dependencies neutralised.*

### Phase 1 — The field and the runtime, both inert · 5 days

- `blocks` flexible_content added to `group_vs_page`, **empty on all 33 pages**. `php -l` before deploy — a parse error in a must-use plugin takes down wp-admin, and `vs-admin.php:14-20` notes these cannot be deactivated from there.
- `hero` group field added, empty.
- `src/blocks/registry.ts`, `PageBlocks.astro`, loader query, Zod discriminated union with the unknown-tolerant member, `UnknownBlock`.
- `page-content.ts` gains `blocks`, keeps `find()`. The `blocks.length > 0` switch.

*Exit: site byte-identical, verified. Nothing renders differently anywhere.*

### Phase 2 — Pilot: `/cosmetic-dentistry/clear-aligners/` · 10 days

**Why this page, specifically:**

- ★ Its 8 CMS section rows are in **exactly** the order of its 8 template bands — verified. The renderer swap is provably a no-op *here first*.
- Every band it has is a Wave-A or Wave-B block: `why`→`card_grid`, `what`→`media_split`, `process`→`process_steps`, `compare`→`comparison_cards`, `natural`→`media_split`, `lasting`→`stat_callout`, `gallery`→`gallery_marquee`, `faq`→`faq`. **It appears nowhere in the 45-band bespoke list** — zero `code_section` rows needed.
- Fully CMS-backed already: 8 sections, 6 cards, 3 images, 8 FAQs, 4 process steps, 8 TOC links. Almost nothing to type.
- ★ It is one of the seven templates that render FAQ answers escaped, and it has **zero tag-bearing answers** — so adopting `set:html` is a verified no-op on the pilot.
- `clear-aligners.css` is **96.9% line-identical to `porcelain-veneers.css`**. Every block extracted here lands ready-made on five or more sibling pages.
- It is a service detail page, not the homepage or a hub. Lowest traffic risk among the well-backed candidates.
- 609 template lines + 767 stylesheet lines. Small enough to hold in one head.

Deliverables: 8 layouts + the hero group + `block-map.json` for one route + the backfill script + the page rendering from `blocks` + harness green + the derived TOC replacing `toc_links` on this route.

*Exit: one page fully composable. Reordering it in wp-admin reorders the site. Rollback = empty one field.*

### Phase 3 — The detail-page family, 10 more pages · 20 days

`porcelain-veneers`, `teeth-whitening`, `smile-makeover`, `gum-contouring`, `full-mouth-rehabilitation`, `single-tooth-dental-implants`, `full-mouth-dental-implants`, `all-on-4-single-arch`, `bone-grafting`, `sinus-lift`.

They share the block set; ~2 days each covers the variants each new page forces (`ratio: wide-text` for sinus-lift, `.alt-flip` for gum-contouring, the emergency ratio, `.why-3up-card`, `.why-4up-card`). The 187 card rows get wired here — a `const` deletion per template, not data entry.

***Exit — the end of week 8 — the client has genuine composability on 11 pages.***

### Phase 4 — Hubs and content pages, 10 pages · 20 days

`implant-dentistry`, `cosmetic-dentistry`, `general-dentistry`, `emergency-dentistry`, `services`, `new-patients`, `our-office`, `contact`, `referral-program`, `dental-membership-plan`.

Adds `copy_plus_stats`, the `tech_grid`/`tech_media` split, `service_cards`, `doctor_profiles`, `map_visit`, `pricing_tiers`. The ~30 genuinely bespoke bands land as `code_section` rows — reorderable, removable, not editable. `services.css:121`'s `main > .section:first-of-type` positional rule is deleted here and replaced by an explicit `first` prop.

*Exit: all 21 composable pages on blocks.*

### Phase 5 — New page types, content entry, dead-CSS sweep · 10 days engineer

- **The second half of the promise:** a blank WordPress page composes from `blocks` with no template. Route it through `PageBlocks` instead of `[...slug].astro`'s plain renderer. ~2 days, and it is the payoff for everything above.
- Content-entry surfaces and empty states for what starts blank: ~27 hero pairs, 44 stat pairs, 27 consult-CTA pairs, 20 closing-band pairs, 20 pull-quotes, ~14 comparison cards, ~13 pricing tiers, ~30 missing process steps, plus the bulk of the audit's 43% hardcoded prose. **This is client work.** It can start at week 8 and run in parallel.
- Dead-CSS sweep, including the ~160 lines of `.final-band` residue across 13 sheets.

### Testing the 6-week estimate

**Correct it upward, with a staged commitment rather than a single number.**

Total engineering: **5 + 5 + 10 + 20 + 20 + 10 = 70 days ≈ 14 weeks** for one engineer — which lands within a day of the CSS survey's independent bottom-up figure of 72, from a different decomposition. That agreement is worth something.

Six weeks lands mid-Phase 3: a working composable system on roughly four pages. Real, demonstrable, not the delivery.

The honest framing:

| | |
|---|---|
| **Week 2** | Field exists, runtime exists, site unchanged. |
| **Week 4** | One page fully composable end to end. |
| **Week 8** | 11 pages composable — the whole cosmetic + implant detail family. |
| **Week 12** | All 21 composable pages. |
| **Week 14** | New page types; content entry surfaces done. |

Two things push against 14, in opposite directions.

**Compressing it:** ★Fact 1 and the 187/187 card name-match remove the two largest unknowns the original estimate had to carry — nobody now has to reconcile an unknown ordering or re-key 187 rows. And Phases 3 and 4 are page-parallel: two engineers take 14 weeks to about 9.

**Stretching it:** the content that starts empty is not engineering time but it *is* calendar time, and the site is not "done" while a third of its heroes are still template literals. If the client wants every band editable, add 2–3 weeks of their own writing, startable at week 8.

So: **"6+ weeks" was not wrong so much as under-specified.** Say 8 weeks to the first fully composable family, 14 to full coverage, 9 with two engineers.

---

## 5. WHAT THE EDITOR SEES

One job, end to end: **add a two-column text-and-image section to `/cosmetic-dentistry/clear-aligners/`, then move it above the FAQ.**

**1.** Pages → *Clear Aligners (Invisalign)*. The Page content box sits directly under the title (`vs-content-model.php` sets `'position' => 'acf_after_title'`, deliberately, so it cannot be dragged below the fold). Tabs across the top: **Hero · Page sections · Images · FAQ · Legacy content**.

**2.** They click **Page sections**. Eight collapsed rows, each showing its layout name and its heading:

```
☰  Numbered cards      Why patients choose clear aligners        [paper]
☰  Text + image        What Invisalign actually is               [charcoal]
☰  Process steps       How treatment works                       [sage pale]
☰  Comparison cards    Aligners vs. veneers vs. bonding          [paper]
☰  Text + image        Results that look like you                [charcoal]
☰  Stat callout        How long results last                     [paper]
☰  Smile marquee       Real patients, real results               [sage pale]
☰  FAQ                 Common questions                          [paper]
```

**3.** **Add a section** at the bottom opens the layout picker — a closed list of about twenty named types. There is no "custom HTML". They choose **Text + image**.

**4.** The new row expands with the same controls they have already seen five times:

- **Anchor** — blank; the site fills it from the heading on save.
- **Show in the "On this page" rail** — a label field. Filled in ⇒ the link appears, in this block's position, automatically. There is no separate list of links to keep in step.
- **Band** — a five-item dropdown with colour swatches: Paper · Cream · Sage pale · Sage · Charcoal. Preselected to whatever keeps the alternation going from the block above (§6, R1).
- **Eyebrow** · **Heading** (with a note: *"You can wrap a phrase in italics"*) · **Body**.
- **Image** — the media picker, restricted to `webp/jpg/jpeg/png` (`vs-content-model.php` `mime_types`, because sharp has no decoder for bmp or ico and the failure would otherwise surface at build time as a filename).
- **Image side** — Left / Right. **Column balance** — Even / Wider text / Wider image.
- Optional **pull-quote** and **checklist**.

**5.** They fill it in and drag the row by its handle from position 9 to position 8 — above **FAQ**. ACF renumbers on drop.

**6.** **Update.** On save:

- `fill_blank_row_id()`-style logic generates the anchor from the heading, **once**, and never again. Moving the block later does not change it.
- Any warning — a band that duplicates its neighbour, a missing image, a layout the site does not yet render — appears as a persistent `notice notice-warning` above the editor and as a count in the Posts list column. Never `notice-error`: nothing here blocks saving, and an error style that does not block trains people to ignore errors.
- `vs-deploy.php` debounces and fires the Vercel deploy hook.

**7.** Two to three minutes later the section is live, in its new position, above the FAQ, with the "On this page" rail already listing it in the right place — because the rail is computed from the blocks, not maintained beside them.

**What they cannot do, by design:** paste HTML, choose an arbitrary colour, set padding, delete the hero, or move a `code_section` band's contents. They can move that band and remove it; they cannot rewrite it.

---

## 6. THE RISK REGISTER

The rollback for almost everything is one line: **`blocks` empty ⇒ the old template renders.** Emptying the field in wp-admin un-migrates a page with no deploy and no code change.

| # | Risk | Detection | Undo |
|---|---|---|---|
| **R1** | **★ Band sequence destroyed by reordering.** The highest-risk item and it has no CSS fix. Nothing computes the alternation — `gum-contouring.astro` emits `alt, dark, plain, dark, plain, alt, plain, plain` (two adjacent plain bands); `clear-aligners.astro` emits `plain, alt, dark, plain, alt, plain, dark, plain`. There is no rule, only a sequence, and an editor can put three charcoal bands in a row. `porcelain-veneers.astro:365-368` even carries a comment placing a section "so the band rhythm stays cream → paper → sage → paper." | Build-time check: two adjacent blocks with the same band ⇒ `console.warn`. Save-time check in PHP ⇒ persistent wp-admin warning naming the two blocks. Neither ever fails. | Change the dropdown. **Mitigation, not detection:** the picker preselects a band that continues the alternation, so the default is always right and the editor must actively choose to break it. |
| **R2** | **★ Invisible eyebrow after a reorder.** `porcelain-veneers.css:269-278` repaints `.section-head .eyebrow::before/::after` from the *ancestor* `.section.alt` / `.section.dark`. A block moved to a different band gets an eyebrow rule the colour of its own background. | Screenshot diff catches it; the human eye does not, reliably. | **Fixed before any editor sees the field**, in Phase 0/2: the rule is computed from the block's own band class. Not a live risk if sequenced correctly. |
| **R3** | **Unstyled layout — a block placed on a page whose CSS cannot reach it.** Today `.faq` exists only as `.vs-office .faq`, `.impd .faq`, … A FAQ dropped on `/our-office/` renders naked. **All 20 block families have this property.** | Screenshot diff on the receiving page. Also structural: a block whose co-located `<style>` is complete cannot depend on an ancestor, so this is caught at extraction review, not at runtime. | Not reachable by an editor: a page cannot be composed until it is migrated, and migration means its blocks are namespace-free. The risk lives in the *engineering* of each block, not in the editor's hands. |
| **R4** | **A block deleted while a page depends on it** — layout removed from the PHP `layouts` array while rows still reference it. | Two gates. (a) A CI check reads every published page's `blocks` and fails the *pull request* if any live `acf_fc_layout` value is absent from the registry. (b) At runtime, `UnknownBlock` renders nothing and warns; wp-admin shows a persistent notice. | Restore the layout in PHP. The rows are untouched — ACF keeps orphaned `blocks_N_*` meta, so re-adding the layout restores the content intact. **Never delete a layout; deprecate it** — hide it from the picker (`acf/prepare_field`, the pattern `hide_retired_section_fields()` already uses for the retired CTA fields) while it still renders. |
| **R5** | **Build fails on unknown content.** The gravest failure mode, because `vs-deploy.php:142-158` means an *editor* triggered the build and never sees its output. | Vercel build log. Nobody reads it. | **Prevented, not detected.** Zod union has a permissive fallback member; `UnknownBlock` renders nothing; the anchor deduplicator warns rather than throws; missing images degrade rather than throw. The one thing that must *stay* fatal is a total CMS outage — a failed build leaves the previous deployment serving (`src/lib/wp.ts:8-12`), which is exactly what you want then. **Note the existing hazard this must not inherit:** `page-content.ts:128-133` throws on a missing image slot. A block's image must warn-and-omit instead, or one editor clearing one image takes the whole site's build down. |
| **R6** | **★ Duplicate anchors.** Two `faq` blocks on one page ⇒ duplicate DOM ids, invalid HTML, `#faq` links hitting the wrong one, and `scroll-margin-top` (61 declarations, 16 files) landing on the wrong element. Today this also fails earlier: `page-content.ts:121`'s `find()` returns only the first match and `:123` *throws* on a missing image. | Build-time pass; `console.warn` naming page and blocks. | Auto-suffixed to `-2`, plus a wp-admin warning. Never fatal. |
| **R7** | **★ HTML injection via FAQ answers.** Adopting `set:html` (justified by ★Fact 2) removes today's escaping on 7 templates. An editor can now paste a `<script>`. | Sanitizer rejects and logs. | Allow-list sanitizer at the loader: `<a href>` (relative or same-origin only), `<em>`, `<strong>`. Everything else stripped and warned. The live data needs exactly `<a>` and `<em>` and nothing more, so the allow-list costs nothing. |
| **R8** | **The importer clobbers a migrated page.** `import-sections.php:82,87` `update_field()` replaces repeaters wholesale (`:10`), and `vs-content-model.php`'s own comment notes rows added by hand do not survive a re-import. | Rows silently revert. Would be invisible without a check. | The backfill skips any page whose `blocks` is non-empty; no importer ever writes `blocks`. A re-import rewrites repeaters nothing reads on a migrated page — harmless. Stated in the file header so nobody "fixes" it later. |
| **R9** | **`@layer` adoption changes a page.** All-or-nothing within CSS, and it inverts the ~6 places (e.g. `general-dentistry.css:1128-1135`) where a page sheet deliberately beats a shared component. | The Phase 0 harness. This is the whole reason the harness precedes it. | Revert one commit — no declarations changed, so the revert is exact. The genuinely-deliberate overrides move to `@layer overrides` and mostly get deleted, because a block owning its band no longer needs the patch. |
| **R10** | **A `graphql_field_name` on a layout.** Resolver names a type that was never registered (`FlexibleContent.php` L36 vs L56) ⇒ runtime `resolveType` failure on one page, not a schema-build failure. | Build fails against one page, cryptically. | Never set it. Enforced by a comment at the `layouts` array and a grep in CI. Same for registering a flexible field with zero layouts. |
| **R11** | **Parse error in a must-use plugin takes down wp-admin** on a live client site. `vs-admin.php:14-20` notes these cannot be deactivated from wp-admin. | Immediate, total, and the client sees it first. | `php -l` every PHP file before it leaves the branch; `cms/bin/deploy-mu-plugins.sh` gates on it. PHP 8.4.7 is on this machine. |
| **R12** | **The guardrail itself is gone.** Today it is *structurally impossible* for an editor to break a layout — `section_id` is `readonly` with "Set by the migration. Do not change." (`vs-content-model.php:466-468`) and the group's own docblock says the fields are "Deliberately NOT a free-form page builder … These fields change the words, not the design." **That sentence stops being true.** | Screenshot-diff runs on a schedule against production, not only in CI. A weekly report naming pages whose rendered output changed since last week is the only detector for "an editor made it worse". | **Nothing undoes a deliberate editorial choice.** What the design does is bound the damage: a closed layout picker, five bands, no free HTML, no padding control, block-owned rhythm, a preselected band that continues the alternation, and per-page rollback by emptying one field. **The client accepted this risk; this is the shape it takes.** Recommend one procedural control alongside: a WordPress revision restore is a one-click undo for a page's `blocks` field, so *show the editor where revisions are* during handover. |

---

## 7. WHAT NOT TO DO

Composability is needed on ~21 service and content pages. Everything below stays in code, and I would push back on any request to change that.

**Stay in code — behaviour, not layout.** These bands carry JavaScript, third-party embeds, or data contracts. A composable version would be a rewrite pretending to be a re-plumbing.

- **`smile-gallery/index.astro`** — the case gallery and lightbox (`.gallery-grid`, `.case-card`, `.lightbox-card`). Keyboard trap, focus management, image preloading.
- **`blog/index.astro`** — the filter rail and card grid, driven by `src/scripts/blog-filter.ts`.
- **`blog/[slug].astro`** — post hero and prose body. Already fully data-driven from a different collection.
- **The two inline Typeform embeds** — `contact/index.astro`'s hero-embedded form and `emergency-dentistry/index.astro`'s `.contact-section`. A third-party script whose container sizing is load-bearing.
- **Nav, footer, `StickyMobileCTA`** — not page content.

**Stay in code — compliance, not composition.**

- **`privacy-policy/index.astro` (19 sections) and `terms-conditions/index.astro` (23 sections).** Their `sections` and `tocLinks` are already live, already correct, and already order-driven. They are 42 prose subsections, not compositional units. The one thing an editor should never be able to do to a HIPAA notice is reorder it. Leave them exactly as they are — they are the best-behaved pages on the site.

**Stay in code — the block library would not pay for itself.**

- **`index.astro` (home).** 220 page-unique rules and four one-off bands: the services accordion + `.hero-card`, the home process band, the notable-patients grid, `.membership-band`. It is the highest-traffic page and the least block-shaped. Its `sections` rows already give the client editable copy where it matters.
- **The three landing pages** — `cosmetic-dentistry-lp`, `veneers-lp`, `general-lp`. Noindexed, excluded from the sitemap (`astro.config.mjs`, alongside `/design-system/` and `/thank-you/`), campaign-scoped, and **0 section rows on all three**. They are ad creative with a URL. **Do collapse their CSS** — `veneers-lp.css` is 99.4% identical to `cosmetic-dentistry-lp.css`, 1,194 lines for one day, zero visual change, the cheapest win in the corpus — but do not compose them.
- **`404.astro` and `thank-you.astro`.**
- **`patient-testimonials/index.astro`** — three one-off grids (featured story, veneer stories, divider strip) and a video modal. Its four section rows already cover the editable copy.

**Not a block, even though it is a band.**

- **The hero.** One per page, always first, never reordered, 10 layout variants, 34 of 36 `<h1>`s are literals. A `hero` group field gives the client every bit of the editability they want and none of the ordering surface. Making it a block is how you get a page with two heroes and no `<h1>`.
- **The "On this page" rail.** Derived from `blocks[].anchor` + `nav_label`. Authoring it separately is 166 rows of hand-maintained duplication and the single largest way a reorder breaks a link.

**Do not build, at all.**

- **A free-form HTML block.** It is the one feature that converts "an editor can break a layout" from a bounded risk into an unbounded one, and it would make every guarantee above void. If someone needs arbitrary markup, they need a developer.
- **Per-block padding, colour, font-size or width controls.** Five bands, three ratios, three column counts. That is the whole design surface. `FinalBand.astro` has shipped on 20 pages with exactly two options.
- **Auto-regenerating anchors.** An anchor is generated once, from the heading, on first save, and is then permanent. Regenerate it on rename or reorder and every inbound link, every rail entry, and every `scroll-margin-top` target silently detaches.
- **Introspection-dependent codegen in the production build.** Introspection is off for public requests, confirmed live. The registry is hand-written and that is a feature: adding a block is deliberately one PHP change and one TS change in one commit.

---

### Files and evidence

- Block runtime target: `/Volumes/Concepcion Work/Vivid smiles/headless setup/vivid-smiles-website/src/components/FinalBand.astro` (band prop `:25`, self-owned rhythm `:49`, provenance `:5`)
- Section lookup to replace: `…/vivid-smiles-website/src/lib/page-content.ts:121` (and the fatal image lookup at `:128-133`)
- Order-independent renderer already in production: `…/vivid-smiles-website/src/pages/[...slug].astro:271` + `src/styles/pages/wp-page.css:158`
- Existing band system to extend: `…/vivid-smiles-website/src/styles/global.css:621-641`
- Field group to extend: `/Volumes/Concepcion Work/Vivid smiles/headless setup/cms/mu-plugins/vs-content-model.php:329` (group), `:444-506` (sections repeater), `:466-468` (`readonly` / "Do not change")
- Importer contract: `…/cms/import/import-sections.php:10, :82, :87`
- Band inversion: `…/src/styles/pages/porcelain-veneers.css:244,253` vs `teeth-whitening.css:237,238`
- Ancestor-dependent eyebrow: `…/src/styles/pages/porcelain-veneers.css:269-278`
- Positional band rule: `…/src/styles/pages/services.css:121`
- `:where()` precedent for cascade control: `…/src/styles/pages/services.css:48-51`
- Pilot page: `…/src/pages/cosmetic-dentistry/clear-aligners.astro` (609 lines) + `src/styles/pages/clear-aligners.css` (767 lines)
- Live GraphQL, re-verified for this document: 33 published pages; 213 section rows with `ctaLabel`/`ctaHref` **0/213**; `blocks` absent from `PageFields`; type-name convention confirmed via `PageFieldsSections`; introspection blocked but validation fully type-aware; SCF `pro/fields/class-acf-field-flexible-content.php` → 500 (exists) vs fabricated sibling → 404.
