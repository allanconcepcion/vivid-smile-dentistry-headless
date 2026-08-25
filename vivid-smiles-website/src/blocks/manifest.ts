/**
 * Block metadata: what layouts exist and what to ask GraphQL for.
 *
 * SPLIT OUT OF registry.ts DELIBERATELY, AND THE REASON IS NOT TIDINESS.
 *
 * registry.ts binds each layout to its Astro component, so it imports eight
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
 * has `anchor navLabel band bandKey` and nothing else; asking it for `heading`
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
 * pilot page's eight bands consume them in.
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
   */
  PageFieldsBlocksCardGridLayout: {
    typeName: "PageFieldsBlocksCardGridLayout",
    fields: `${BLOCK_PREAMBLE_FIELDS} columns numbered cards { meta title lead body }`,
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
   */
  PageFieldsBlocksMediaSplitLayout: {
    typeName: "PageFieldsBlocksMediaSplitLayout",
    fields: `${BLOCK_PREAMBLE_FIELDS} ${BLOCK_IMAGE_FIELDS} imageAlt mediaSide ratio quote ctaLabel ctaHref`,
  },

  /**
   * Numbered process steps.
   *
   * `layout` here is the sub-field's own name — the shape the steps are drawn
   * in, grid | card | divided — and has nothing to do with ACF's `layout`
   * setting or with the flexible-content layout this row is. The PHP carries
   * the same warning at the field.
   */
  PageFieldsBlocksProcessStepsLayout: {
    typeName: "PageFieldsBlocksProcessStepsLayout",
    fields: `${BLOCK_PREAMBLE_FIELDS} layout columns steps { tag num title body }`,
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
   */
  PageFieldsBlocksComparisonCardsLayout: {
    typeName: "PageFieldsBlocksComparisonCardsLayout",
    fields: `${BLOCK_PREAMBLE_FIELDS} tiers { tag title body ribbon featured }`,
  },

  /**
   * One large figure beside a paragraph and a list — the `.lasting-card` shell.
   *
   * `body` and `intro` are two paragraphs in two places and are easy to swap:
   * `body` is the preamble's, under the section heading; `intro` opens the card
   * itself. The pilot uses both. `value` and `unit` are the two halves of one
   * line, and both are text — the figures in the corpus read "20–22" and "10+".
   */
  PageFieldsBlocksStatCalloutLayout: {
    typeName: "PageFieldsBlocksStatCalloutLayout",
    fields: `${BLOCK_PREAMBLE_FIELDS} value unit caption intro points { lead body }`,
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
