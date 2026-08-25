/**
 * The block registry — the single binding between a CMS layout and the code
 * that renders it.
 *
 * One entry per layout, holding all three things that must agree about it:
 *
 *   typeName   the GraphQL `__typename` WordPress returns for the layout
 *   fields     the selection set the loader asks for
 *   component  the Astro component that renders it
 *
 * Keeping them in one literal is the point. Split across three files they drift,
 * and every way they can drift is a runtime failure on one page rather than a
 * compile error: a selection set that names a field the layout does not have
 * fails query validation and takes down the whole build; a `typeName` that does
 * not match what the resolver emits silently falls through to UnknownBlock and
 * the band disappears from a live page. Adding a block is therefore one PHP
 * layout plus one entry here, in the same commit — the same discipline
 * content.config.ts:50-56 spells out for the category enum.
 *
 * PHASE 1 SHIPS THIS EMPTY, AND THAT IS THE DESIGN.
 * The field exists in WordPress with no rows in it, so nothing reaches this
 * table. Phase 2 registers the first layout by uncommenting the shape below.
 * Until then every code path here is exercised only by its own emptiness — see
 * `hasRegisteredBlocks`, which the loader needs precisely because "the registry
 * knows about blocks" and "the CMS host can answer for blocks" are different
 * facts (below).
 *
 * WHAT THIS FILE MUST NEVER DO: throw. An editor triggers deploys and never
 * sees the output (cms/mu-plugins/vs-deploy.php:142-158), so an editor must not
 * be able to break a build. src/loaders/blog.ts and src/integrations/
 * yoast-sitemap.ts already encode that rule; lookups here degrade to
 * `undefined` and the caller renders UnknownBlock.
 *
 * Spec: docs/PAGE-BLOCKS.md §2.1.
 */

import type { AstroComponentFactory } from "astro/runtime/server/index.js";

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
export interface BlockDefinition {
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
  component: AstroComponentFactory;
}

/**
 * The five sub-fields every band-rendering layout opens with (§1.2), in CMS
 * order, camelCased as wpgraphql-acf exposes them.
 *
 * NOT universal — only for layouts that ship the full preamble. `code_section`
 * has `anchor navLabel band bandKey` and nothing else; asking it for `heading`
 * fails query validation for the entire site. Check the layout's PHP before
 * reaching for this.
 */
export const BLOCK_PREAMBLE_FIELDS = "anchor navLabel band eyebrow heading body";

/**
 * layout `__typename` → definition.
 *
 * Empty in Phase 1: the field exists in WordPress with no rows in it, so the
 * runtime is inert by construction. Adding a block is one entry:
 *
 *   import FaqBlock from "./FaqBlock.astro";
 *
 *   "PageFieldsBlocksFaqLayout": {
 *     typeName:  "PageFieldsBlocksFaqLayout",
 *     fields:    `${BLOCK_PREAMBLE_FIELDS} pull items { question answer open } ctaLabel ctaHref`,
 *     component: FaqBlock,
 *   },
 *
 * The key repeats `typeName` on purpose: the key is what PageBlocks.astro looks
 * up, `typeName` is what the query is built from, and a mismatch between them is
 * the one drift this file cannot catch for itself. Keep them literally equal.
 */
export const BLOCK_REGISTRY: Record<string, BlockDefinition> = {};

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
  Object.keys(BLOCK_REGISTRY).length > 0;

/**
 * Find the definition for a block, or `undefined` if this build has never heard
 * of it — a layout deployed to WordPress before the Astro side shipped, or an
 * Astro rollback. Callers render UnknownBlock; nobody throws. See §2.4.
 *
 * `Object.hasOwn` rather than a bare index read: `BLOCK_REGISTRY` is an object
 * literal, so `registry["constructor"]` would otherwise hand back something off
 * Object.prototype and the caller would read `.component` off a function. The
 * `PageFieldsBlocks…Layout` prefix makes that collision unreachable in practice;
 * one function call is cheaper than reasoning about it again later.
 */
export function lookupBlock(
  typeName: string | null | undefined,
): BlockDefinition | undefined {
  if (typeof typeName !== "string" || typeName === "") return undefined;
  return Object.hasOwn(BLOCK_REGISTRY, typeName)
    ? BLOCK_REGISTRY[typeName]
    : undefined;
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
  const inner = Object.values(BLOCK_REGISTRY).map(
    (def) => `${indent}    ... on ${def.typeName} { ${def.fields} }`,
  );
  return [
    `${indent}blocks {`,
    `${indent}    __typename`,
    ...inner,
    `${indent}}`,
  ].join("\n");
}
