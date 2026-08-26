/**
 * layout `__typename` → the component that draws it.
 *
 * THIS FILE IS IMPORTED BY PageBlocks.astro AND NOTHING ELSE. It reaches nine
 * .astro files — ten counting src/components/LocalTrust.astro, which
 * CodeSectionBlock pulls in behind them — and Astro ships a component's scoped
 * CSS for anything in the
 * module graph whether or not it renders — so importing this from page-content.ts
 * or from a loader puts block CSS on all 48 routes and moves every page's asset
 * hashes. Metadata lives in ./manifest.ts precisely so those callers have
 * somewhere else to look.
 *
 * The `... on <Type>` selection sets and the layout list live in ./manifest.ts.
 * A layout is added in BOTH files, in the same commit: a manifest entry with no
 * component renders as UnknownBlock, and a component with no manifest entry is
 * never queried.
 */

import type { AstroComponentFactory } from "astro/runtime/server/index.js";

import CardGridBlock from "./CardGridBlock.astro";
import CodeSectionBlock from "./CodeSectionBlock.astro";
import ComparisonCardsBlock from "./ComparisonCardsBlock.astro";
import FaqBlock from "./FaqBlock.astro";
import GalleryMarqueeBlock from "./GalleryMarqueeBlock.astro";
import MediaSplitBlock from "./MediaSplitBlock.astro";
import PricingTiersBlock from "./PricingTiersBlock.astro";
import ProcessStepsBlock from "./ProcessStepsBlock.astro";
import StatCalloutBlock from "./StatCalloutBlock.astro";

import { BLOCK_MANIFEST, isRegisteredLayout, type BlockManifestEntry } from "./manifest";

export type { BlockNode } from "./manifest";

const COMPONENTS: Record<string, AstroComponentFactory> = {
  PageFieldsBlocksFaqLayout: FaqBlock,
  PageFieldsBlocksCardGridLayout: CardGridBlock,
  PageFieldsBlocksMediaSplitLayout: MediaSplitBlock,
  PageFieldsBlocksProcessStepsLayout: ProcessStepsBlock,
  PageFieldsBlocksComparisonCardsLayout: ComparisonCardsBlock,
  PageFieldsBlocksGalleryMarqueeLayout: GalleryMarqueeBlock,
  PageFieldsBlocksStatCalloutLayout: StatCalloutBlock,
  PageFieldsBlocksPricingTiersLayout: PricingTiersBlock,

  // The escape hatch. Unlike every entry above, this component draws no band of
  // its own — it looks its row's `band_key` up in its own BANDS map and renders
  // the code-owned component that key names, unwrapped. A new bespoke band is an
  // entry in that map, not a line here.
  PageFieldsBlocksCodeSectionLayout: CodeSectionBlock,
};

export interface BlockDefinition extends BlockManifestEntry {
  component: AstroComponentFactory;
}

/**
 * The definition for a block, or `undefined` if this build has never heard of
 * it — a layout deployed to WordPress before the Astro side shipped, or an Astro
 * rollback. Callers render UnknownBlock; nobody throws. See docs 2.4.
 *
 * Undefined ALSO when the manifest knows the layout but no component is bound,
 * which is the half-finished state of adding one. Same outcome, deliberately:
 * a visible placeholder in dev beats a blank band in production.
 */
export function lookupBlock(
  typeName: string | null | undefined,
): BlockDefinition | undefined {
  if (!isRegisteredLayout(typeName)) return undefined;
  const component = COMPONENTS[typeName as string];
  if (!component) return undefined;
  return { ...BLOCK_MANIFEST[typeName as string], component };
}
