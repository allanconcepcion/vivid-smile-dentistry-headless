/**
 * Block metadata: what layouts exist and what to ask GraphQL for.
 *
 * SPLIT OUT OF registry.ts DELIBERATELY, AND THE REASON IS NOT TIDINESS.
 *
 * registry.ts binds each layout to its Astro component, so it imports nine
 * .astro files — and, through CodeSectionBlock, the bespoke bands those name.
 * Astro ships a component's scoped CSS whenever the component is
 * in the module graph — imported is enough, rendered is not required. Because
 * src/lib/page-content.ts and src/loaders/pages.ts both consume block data, and
 * every page consumes page-content.ts, importing the component map from either
 * of them puts ~62 kB of block CSS on all 48 routes, moves every page
 * stylesheet's content hash, and changes every page's <link href> — on pages
 * that render no blocks at all.
 *
 * So: anything that needs to KNOW ABOUT blocks imports this file. Only the
 * thing that RENDERS them — PageBlocks.astro, via registry.ts — imports the
 * components. Keep it that way; the coupling is invisible until a build diff
 * shows all 48 routes changed.
 */



/**
 * One row of `pageFields.blocks`, as it arrives from GraphQL.
 *
 * Deliberately loose. `__typename` is the only field guaranteed to be present
 * on every layout — `anchor` is absent from any layout that does not render a
 * band, and everything else varies. The index signature is what lets
 * PageBlocks.astro spread an unknown-to-us block into a component without
 * knowing its shape, and what lets a layout deployed to WordPress before the
 * Astro side ships arrive here without a type error.
 */
export interface BlockNode {
  __typename: string;
  /** The DOM id for this band. Split out from the old `section_id`; see §1.2. */
  anchor?: string | null;
  [field: string]: unknown;
}

/** What the registry knows about one layout. */
export interface BlockManifestEntry {
  /**
   * The `__typename` GraphQL returns for this layout.
   *
   * Convention, confirmed live: `blocks` yields `PageFieldsBlocks<Name>Layout`.
   *
   * This value is resolved by wpgraphql-acf from the raw `acf_fc_layout` string
   * (FlexibleContent.php:36) while the *type* is registered from the layout's
   * name (L56). Setting `graphql_field_name` on a layout in PHP makes those two
   * disagree and produces a runtime failure on one page instead of a
   * schema-build failure. Never set it. See §1.1.
   */
  typeName: string;

  /**
   * The selection set for this layout — field names only, no braces, no
   * `... on <Type>` line.
   *
   * The spec calls this the layout's "fragment". It is stored as the body alone
   * so `blockSelectionSet()` can generate the `... on <typeName>` wrapper: that
   * makes it structurally impossible for the type named in the fragment to
   * drift from the type named in `typeName`.
   *
   * Every field named here must exist on this layout. GraphQL validates the
   * whole query before executing any of it, so one wrong field name fails every
   * page, not one.
   */
  fields: string;

  /**
   * The component that renders it.
   *
   * NOTE for whoever registers the first block: importing a `.astro` file from
   * a `.ts` file is fine for Astro and for `npm run check` (`astro check`
   * understands `.astro`), but bare `npx tsc --noEmit` cannot resolve it and
   * reports TS2307. Verified both ways. The project's gate is `npm run check`;
   * do not "fix" that error by moving the component binding out of this file.
   */
}

/**
 * The six sub-fields every band-rendering layout opens with (§1.2), in CMS
 * order, camelCased as wpgraphql-acf exposes them.
 *
 * NOT universal — only for layouts that ship the full preamble. `code_section`
 * has `anchor navLabel bandKey` and nothing else — THREE fields, not four; a
 * `band` was written here once and the layout has never had one, which failed
 * query validation for all 48 routes. Asking it for `heading`
 * fails query validation for the entire site. Check the layout's PHP before
 * reaching for this.
 */
export const BLOCK_PREAMBLE_FIELDS = "anchor navLabel band eyebrow heading body";

/**
 * The image sub-field's selection set.
 *
 * ACF returns an image as a media item, which wpgraphql-acf exposes as a
 * connection — hence the `node`. The three leaves are not optional in practice:
 * `<Image>` refuses a remote source without explicit intrinsic dimensions, so a
 * selection that drops `mediaDetails` produces a build error about a filename
 * rather than about a query. Identical to the shape src/loaders/pages.ts:120-133
 * already asks the `images` repeater for, and for the same reasons.
 */
const BLOCK_IMAGE_FIELDS =
  "image { node { sourceUrl altText mediaDetails { width height } } }";

/**
 * layout `__typename` → definition.
 *
 * The key repeats `typeName` on purpose: the key is what PageBlocks.astro looks
 * up, `typeName` is what the query is built from, and a mismatch between them is
 * the one drift this file cannot catch for itself. Keep them literally equal.
 *
 * Ordered as docs/PAGE-BLOCKS.md 1.3 ranks them, which is also the order the
 * pilot page's eight bands consume them in. `pricing_tiers` is newer than that
 * ranking and is appended after the eight rather than slotted into them, with
 * `code_section` kept last because it is the escape hatch and not a band. The
 * order here is documentation only — blockSelectionSet() emits the fragments in
 * it, and GraphQL does not care what order inline fragments arrive in.
 */
export const BLOCK_MANIFEST: Record<string, BlockManifestEntry> = {
  /**
   * FAQ — `sections.faq` plus the whole `faqs` repeater.
   *
   * `pull` is a second paragraph, not the preamble's `body`: on the pages that
   * have both, `body` sits under the heading and `pull` in the column beside
   * the questions. The backfill maps `sections.faq.body` → `pull`.
   */
  PageFieldsBlocksFaqLayout: {
    typeName: "PageFieldsBlocksFaqLayout",
    fields: `${BLOCK_PREAMBLE_FIELDS} pull items { question answer open } ctaLabel ctaHref`,
  },

  /**
   * A grid of cards — the `why` band on the pilot, and the 187 `cards` rows
   * across the site.
   *
   * `columns` and `numbered` are ACF select/true_false, so they arrive as the
   * string "2" | "3" | "4" and a boolean. Not coerced here: the registry states
   * the query, the component decides what to do with the answer.
   *
   * `cards[].href` exists on the layout and is deliberately NOT selected.
   * Nothing in the `.why-card` family is a link, so CardGridBlock draws no
   * anchor for it — and a field that is queried and then dropped reads as
   * supported. Same rule as `media_split.checklist` below.
   *
   * `body2` (PHP `body_2`) is the card's SECOND paragraph, and it is here for
   * the same reason media_split's is: a card draws `p.lead` and then one `<p>`,
   * while porcelain-veneers' `why` card 1 has three paragraphs. This selects the
   * second, so the card's three paragraphs map to `lead`, `body` and `body2` and
   * the card is carried in full — the gap this closed is no longer open. Folding
   * two paragraphs into `body` would run them together inside a single `<p>`,
   * which is the same words in the wrong markup.
   *
   * Digit-suffixed name, same derivation as `ctaLabel2` above: wpgraphql-acf
   * replaces each non-alphanumeric run with a space, title-cases, joins and
   * lowers the first letter, so `body_2` is `body2` — no underscore, no trailing
   * separator. That is no longer a derivation on trust: media_split already
   * ships `body2`/`ctaLabel2`/`ctaHref2` against the live schema.
   *
   * Additive to all 187 saved rows: the field is optional on the layout and
   * blank on every one of them, and CardGridBlock emits the second `<p>` only
   * when `body2` is non-empty — so a card saved before this batch renders
   * byte-for-byte as it does now.
   */
  PageFieldsBlocksCardGridLayout: {
    typeName: "PageFieldsBlocksCardGridLayout",
    fields: `${BLOCK_PREAMBLE_FIELDS} columns numbered cards { meta title lead body body2 }`,
  },

  /**
   * Copy on one side, a photo on the other — the pilot's `what` and `natural`
   * bands, from one layout with `mediaSide` flipped.
   *
   * `imageAlt` is a field of its own rather than the media item's `altText`
   * because the `images` repeater this backfills from carries a per-slot alt,
   * and that is the wording the page has been shipping.
   *
   * The layout also declares `checklist` (a list-of-lines repeater,
   * vs-content-model.php:1294). It is not selected because MediaSplitBlock does
   * not draw one — no band on the pilot has a checklist, and a selection set
   * that asks for data nothing renders stops being a statement of what the site
   * uses. It goes in when the component that needs it does, in the same commit.
   *
   * `body2`, `ctaLabel2` and `ctaHref2` are the three sub-fields this batch adds
   * (PHP names `body_2`, `cta_label_2`, `cta_href_2`). They exist because the
   * backfill was dropping a whole paragraph and a whole button on every band
   * that ships two of either — the second button is half the CTA row on the
   * teeth-whitening page, and `body_2` carries the inline <a class="vs-link">
   * that the preamble's `body` cannot.
   *
   * THE CAMELCASE OF A DIGIT-SUFFIXED NAME IS THE ONE THING HERE THAT IS
   * DERIVED RATHER THAN READ. wpgraphql-acf formats a field name by replacing
   * every non-alphanumeric run with a space, title-casing, joining, and
   * lowering the first letter — the fallback in vs-content-model.php's
   * graphql_type_segment() (L497) is the same rule spelled out. So `cta_label_2`
   * becomes `ctaLabel2`, not `ctaLabel_2` and not `ctaLabel2_`. Nothing in this
   * repo carried a digit-suffixed ACF name before today, so there is no live
   * precedent to point at; scripts/check-block-schema.mjs is what confirms it
   * once the PHP is deployed, and it must be run before the next build.
   *
   * All three are optional on the layout and all three are additive to two live
   * pages: MediaSplitBlock emits the second button only when `ctaLabel2` is
   * non-empty and the second paragraph only when `body2` is, so a row saved
   * before this batch renders byte-for-byte as it does now.
   */
  PageFieldsBlocksMediaSplitLayout: {
    typeName: "PageFieldsBlocksMediaSplitLayout",
    fields: `${BLOCK_PREAMBLE_FIELDS} ${BLOCK_IMAGE_FIELDS} imageAlt mediaSide ratio body2 calloutHeading calloutBody quote checklist { lead item } ctaLabel ctaHref ctaLabel2 ctaHref2`,
  },

  /**
   * Numbered process steps.
   *
   * `layout` here is the sub-field's own name — the shape the steps are drawn
   * in, grid | card | divided — and has nothing to do with ACF's `layout`
   * setting or with the flexible-content layout this row is. The PHP carries
   * the same warning at the field.
   *
   * `preCards` (PHP `pre_cards`) is the `.process-pre` mini-grid that sits
   * ABOVE the numbered steps on the pages that have one, and `ctaText` is the
   * closing `.inline-cta` sentence BELOW them. Selected in that order so the
   * fragment reads in DOM order; GraphQL does not care, the next reader does.
   *
   * `preCards` is a repeater, so it mints a type — `PageFieldsBlocksPreCards`,
   * from the field name alone, because a layout's sub-fields hang off the
   * flexible field and not off the layout (vs-content-model.php:539-541). That
   * makes the name it must not share the one held by any OTHER layout's
   * repeater in `blocks`: `items`, `cards`, `checklist`, `steps`, `tiers`,
   * `points`. `pre_cards` is none of them — checked against the layout list in
   * the PHP, not assumed from the brief. Sharing one would merge the two types
   * and silently drop one side's fields, which reads as a healthy schema right
   * up until a query stops validating.
   *
   * Both are optional and both are additive to live pages: an empty `preCards`
   * is an empty list and ProcessStepsBlock draws no `.process-pre` wrapper for
   * it, an empty `ctaText` draws no `.inline-cta`. A band saved before this
   * batch renders exactly as it does now.
   *
   * `columns` GAINED A FIFTH CHOICE THIS BATCH AND THE SELECTION SET DID NOT
   * CHANGE. The field was already queried; what changed in the PHP is that its
   * select now offers "5" as well as "2" | "3" | "4", because
   * porcelain-veneers' `.process-grid` is `repeat(5, 1fr)` over five steps.
   * ProcessStepsBlock therefore has to handle the string "5" — a component that
   * maps 2/3/4 and falls back for anything else will silently draw that band
   * four across. Nothing here can catch that: "5" arrives as a plain string on
   * a field this fragment already asks for, so it is invisible to query
   * validation and to `scripts/check-block-schema.mjs`. Additive to live rows —
   * none of them stores "5".
   */
  PageFieldsBlocksProcessStepsLayout: {
    typeName: "PageFieldsBlocksProcessStepsLayout",
    fields: `${BLOCK_PREAMBLE_FIELDS} layout columns preCards { heading body } steps { tag num title body } ctaText`,
  },

  /**
   * The smile-gallery strip.
   *
   * Preamble only, and that is the whole layout: the photographs come from
   * wp-admin -> Practice Settings -> Smile gallery via src/lib/smiles.ts and
   * appear in every marquee on the site, so there is nothing per-band for an
   * editor to fill in. Asking this type for any other field fails the query.
   */
  PageFieldsBlocksGalleryMarqueeLayout: {
    typeName: "PageFieldsBlocksGalleryMarqueeLayout",
    fields: BLOCK_PREAMBLE_FIELDS,
  },

  /**
   * Side-by-side comparison cards.
   *
   * No CTA fields are selected because this layout has none — unlike `faq`
   * above. The `.cta-row` the band ends with is drawn by the component as a
   * literal, on the same grounds as FaqBlock's phone button: all five `compare`
   * bands in the family emit the identical pair of buttons and the identical
   * note. Do not "fix" that by adding `ctaLabel ctaHref` here — naming a field
   * the deployed layout does not have fails query validation for every page on
   * the site.
   *
   * `calloutHeading` and `calloutBody` ARE new, and they are not a CTA: they are
   * the `.safety-callout` aside that follows the cards on the pages that carry
   * one. Two plain sub-fields rather than a group, so they mint no type and
   * cannot collide with anything. `calloutBody` is html-bearing — the real copy
   * runs an inline link mid-sentence — so the component renders it as HTML, the
   * same treatment `body_2` gets on media_split.
   *
   * Additive: with both blank ComparisonCardsBlock emits no `<aside>` at all,
   * not an empty one. Blank heading with a body (or the reverse) is an editor
   * mistake, not a layout mode — the component renders whichever half is
   * present rather than suppressing both, so the mistake is visible on the page
   * instead of silently swallowing copy.
   */
  PageFieldsBlocksComparisonCardsLayout: {
    typeName: "PageFieldsBlocksComparisonCardsLayout",
    fields: `${BLOCK_PREAMBLE_FIELDS} tiers { tag title body ribbon featured } calloutHeading calloutBody`,
  },

  /**
   * One large figure beside a paragraph and a list — the `.lasting-card` shell.
   *
   * `body` and `intro` are two paragraphs in two places and are easy to swap:
   * `body` is the preamble's, under the section heading; `intro` opens the card
   * itself. The pilot uses both. `value` and `unit` are the two halves of one
   * line, and both are text — the figures in the corpus read "20–22" and "10+".
   *
   * `bodyHeading` (PHP `body_heading`) is the third heading in this band and the
   * easiest of the three to misplace, so: the preamble's `heading` is the
   * section's `<h2>`; `caption` labels the figure; `bodyHeading` is the `<h3>`
   * that OPENS `.lasting-body`, immediately above `intro`. Selected next to
   * `intro` for that reason. Without it the backfill was dropping that `<h3>` on
   * every page that has one and running the paragraph straight under the figure.
   *
   * `intro2` (PHP `intro_2`) is the card's SECOND paragraph — `.lasting-body` on
   * porcelain-veneers runs two, and this layout had one slot, so the backfill
   * was dropping the second whole. Selected immediately after `intro` because
   * that is its place in the card: card heading, paragraph, paragraph, list.
   * A second field rather than a longer `intro`, on the house rule — two
   * paragraphs in one textarea come back as one `<p>` with a newline in it.
   *
   * Digit-suffixed name, derived the same way `body2` is: `intro_2` → `intro2`.
   *
   * Additive: blank `bodyHeading` means StatCalloutBlock emits no `<h3>`, so
   * `.lasting-body` still opens with `intro` exactly as it does today, and blank
   * `intro2` means no second `<p>` between that paragraph and the `<ul>` — not
   * an empty one, which would show as a gap in a card nobody edited.
   */
  PageFieldsBlocksStatCalloutLayout: {
    typeName: "PageFieldsBlocksStatCalloutLayout",
    fields: `${BLOCK_PREAMBLE_FIELDS} value unit caption bodyHeading intro intro2 points { lead body }`,
  },

  /**
   * The cost table — a row of plans, one of them ringed, and a financing line
   * under them.
   *
   * ITS OWN BAND, DELIBERATELY. Five pages render this as `<section id="cost">`
   * with nothing wrapped around it, so this layout is a standalone band and the
   * preamble's `anchor` is what carries that id. That is the whole reason it is
   * worth one layout rather than five bespoke components.
   *
   * WHAT THIS LAYOUT IS NOT: teeth-whitening's price table is NESTED — it sits
   * inside `#lasting` as `.lasting-cost-wrap`, not as a band of its own. That
   * page is a recorded gap, not a case for a second layout. Un-nesting
   * `.lasting-cost-wrap` into its own `<section id="cost">` would make it the
   * sixth page matching this shape and would need no bespoke fields at all;
   * building a nested variant instead mints a second set of plan types to serve
   * one page. Whoever picks that gap up should reach for the markup first.
   *
   * NAMES, AND WHERE THEY CAME FROM. `plans` mints `PageFieldsBlocksPlans`, and
   * the `features` nested inside it mints `PageFieldsBlocksPlansFeatures`: a
   * repeater's type is its field name appended to its PARENT CONTAINER's prefix,
   * and a layout contributes nothing to that prefix, because a layout's
   * sub-fields hang off the flexible field rather than off the layout
   * (vs-content-model.php:539-541). So `plans` competes with every repeater in
   * every other layout of `blocks`. Taken there today: `items`, `cards`,
   * `checklist`, `steps`, `tiers`, `points`. Neither `plans` nor `features`
   * appears anywhere in vs-content-model.php — verified by reading the PHP, not
   * by trusting the brief, because a collision does not error: it merges the two
   * types and drops one side's fields, leaving a schema that looks healthy until
   * a query stops validating.
   *
   * `features` is the house list-of-lines shape — a repeater built by
   * block_list_field() (vs-content-model.php:382-451), the same factory behind
   * `media_split.checklist` and `comparison_cards.tiers.bullets` — so the Astro
   * side reads the same sub-fields off it as off every other list on the site.
   *
   * THAT FACTORY GAINED AN OPTIONAL `lead` THIS BATCH, AND IT IS DELIBERATELY
   * NOT SELECTED HERE. `lead` is the bolded lead-in of the `.candidate-list`
   * shape on the implant pages — `<b>Lead text —</b> body`, marker derived from
   * the row index, never stored — and because the factory is shared it now
   * appears on all three lists, `features` included. No component draws it yet,
   * and no page that has that shape is migrated yet, so there is no selection
   * set it belongs in today: PricingTiers draws a feature as one plain line, and
   * querying `lead` here would ask for data nothing renders, which is the exact
   * thing `cards[].href` and `media_split.checklist` are kept out for. It goes
   * into whichever list's fragment first draws `.candidate-list`, in the same
   * commit as the component — probably `checklist`, which is not selected either.
   * Leaving it out is additive by construction: an unqueried field cannot change
   * a rendered page.
   *
   * `price` and `meta` are text, not numbers: the corpus reads "$2,500–$6,000"
   * and "per arch". `highlighted` is a true_false and arrives as a boolean; it
   * is the ring, and `ribbon` is the label sitting on top of it. They are two
   * fields because a page can ring a plan without labelling it.
   *
   * `note` is the financing line under the table — a field on the layout rather
   * than a seventh sub-field of the last plan, which is where the old markup
   * effectively kept it.
   *
   * Nothing here is additive to a live page: no row of this layout exists yet,
   * so the empty-field rule the other four entries carry has nothing to protect.
   * PricingTiersBlock still has to tolerate an empty `plans` — an editor adds
   * the row before filling it — and draw no `.plan-grid` for it.
   */
  PageFieldsBlocksPricingTiersLayout: {
    typeName: "PageFieldsBlocksPricingTiersLayout",
    fields: `${BLOCK_PREAMBLE_FIELDS} plans { name price meta priceNote priceSuffix ribbon highlighted features { item } } note`,
  },

  /**
   * The escape hatch — a position in the ordered list for a band that stays in
   * code (§1.3, §7). `band_key` names one of the registered bespoke bands and
   * CodeSectionBlock draws it with the props it has always had. An editor can
   * move this row and delete it; there is nothing in it to author.
   *
   * DOES NOT USE BLOCK_PREAMBLE_FIELDS, and this is the layout that comment
   * warns about: the layout has no `eyebrow`, `heading` or `body`, because the
   * band supplies its own. Naming a field the deployed layout does not have
   * fails query validation for every page on the site, not for this one row —
   * so the four fields below are the whole selection set and stay that way.
   *
   * It never grows either. A new bespoke band is one entry in
   * CodeSectionBlock's BANDS map plus one choice in the PHP select; nothing
   * per-band ever arrives over GraphQL, which is what "not editable" means
   * here.
   */
  PageFieldsBlocksCodeSectionLayout: {
    typeName: "PageFieldsBlocksCodeSectionLayout",
    // Three fields, and it never grows: a code-owned band draws its own
    // heading and background, so there is nothing per-band to query. `band`
    // was here and the layout has no such sub-field — GraphQL validates the
    // whole document before executing any of it, so that one word failed the
    // build for all 48 routes, not for this row.
    fields: "anchor navLabel bandKey",
  },
};

/**
 * True once at least one layout is registered.
 *
 * The loader needs this, and needs to not over-read it. It answers "does this
 * build know how to render any block?" — it does NOT answer "does the CMS host
 * answer for the `blocks` field?". Those are separate facts with separate
 * timelines: the Astro side ships on the next build, while the PHP that adds
 * the field is inert until someone hand-deploys it to the host
 * (cms/bin/deploy-mu-plugins.sh). Querying `blocks` against a host that has not
 * been updated returns `Cannot query field "blocks" on type "PageFields"` and
 * fails the build — so the loader's tolerance for that error is what makes the
 * query safe, not this flag.
 */
export const hasRegisteredBlocks: boolean =
  Object.keys(BLOCK_MANIFEST).length > 0;

/**
 * Find the definition for a block, or `undefined` if this build has never heard
 * of it — a layout deployed to WordPress before the Astro side shipped, or an
 * Astro rollback. Callers render UnknownBlock; nobody throws. See §2.4.
 *
 * `Object.hasOwn` rather than a bare index read: `BLOCK_MANIFEST` is an object
 * literal, so `registry["constructor"]` would otherwise hand back something off
 * Object.prototype and the caller would read `.component` off a function. The
 * `PageFieldsBlocks…Layout` prefix makes that collision unreachable in practice;
 * one function call is cheaper than reasoning about it again later.
 */
export function isRegisteredLayout(typeName: string | null | undefined): boolean {
  if (typeof typeName !== "string" || typeName === "") return false;
  return Object.hasOwn(BLOCK_MANIFEST, typeName);
}

/**
 * The `blocks { … }` selection set, assembled from the registry, for
 * src/loaders/pages.ts to concatenate into PAGES_QUERY.
 *
 * The query is assembled here rather than generated from the schema because
 * introspection is off for public requests — re-verified: `{ __schema { … } }`
 * returns "GraphQL introspection is not allowed for public requests by
 * default", and src/lib/wp.ts sends no auth header. Execution still validates
 * against the server's schema, so a wrong field name here is caught at build
 * time, loudly, by the CMS. Never make the production build depend on
 * introspection. See §2.1.
 *
 * `__typename` is always selected: it is what PageBlocks.astro discriminates
 * on, and it is the only field the interface guarantees.
 *
 * Returns `blocks { __typename }` alone when nothing is registered. That is
 * valid GraphQL and it is what Phase 1 asks for — a well-formed query that
 * proves the field is reachable and returns rows this build will route to
 * UnknownBlock. A caller that would rather omit `blocks` entirely until a real
 * layout exists should branch on `hasRegisteredBlocks`.
 */
export function blockSelectionSet(indent = ""): string {
  const inner = Object.values(BLOCK_MANIFEST).map(
    (def) => `${indent}    ... on ${def.typeName} { ${def.fields} }`,
  );
  return [
    `${indent}blocks {`,
    `${indent}    __typename`,
    ...inner,
    `${indent}}`,
  ].join("\n");
}
