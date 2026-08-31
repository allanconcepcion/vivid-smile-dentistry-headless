/**
 * Look up a page's WordPress-managed content by route.
 *
 * Usage from any page template:
 *
 *   import { getPageContent } from '../../lib/page-content';
 *   const { tocLinks, processSteps, faqs } = await getPageContent(Astro.url.pathname);
 *
 * Passing Astro.url.pathname rather than a hardcoded string means the lookup key
 * cannot drift from the route the file actually serves.
 */

import { getEntry } from "astro:content";
import { getImage } from "astro:assets";
import { isRegisteredLayout } from "../blocks/manifest";

export type TocLink = { href: string; label: string };
export type ProcessStep = { tag: string; num: string; title: string; body: string };
export type Faq = { q: string; a: string; open: boolean };
export type Section = {
  section_id: string;
  eyebrow: string;
  heading: string;
  body: string;
  cta_label: string;
  cta_href: string;
};
export type Card = { group: string; title: string; body: string; meta: string; href: string };
export type PageSeo = {
  title: string;
  description: string;
  canonical: string;
  noindex: boolean;
  ogImage: string;
};
export type PageImage = {
  slot: string;
  url: string;
  width: number;
  height: number;
  alt: string;
};

export type HeroCta = { label: string; href: string };

/**
 * The hero an editor typed, or the absence of one.
 *
 * THE GATE IS APPLIED HERE, NOT EXPOSED. Every member is already gated: with
 * no headline, `eyebrow`/`h1`/`sub` are "" and `showRatings` is true, so every
 * template renders exactly what it renders today and never has to ask two
 * questions. An `on` boolean was exposed too and read by nothing, which is the
 * same unread-surface defect this project keeps writing down — removed.
 */
export type Hero = {
  eyebrow: string;
  /** HTML — may carry <em>. Rendered with set:html, never interpolated. */
  h1: string;
  /** Plain text, escaped on output — the field says so (vs-content-model.php:1471). */
  sub: string;
  /**
   * Whether to draw the review line.
   *
   * NOT the raw `ratings` switch, and the reason is worth keeping even though
   * the CMS side has since been fixed.
   *
   * `ratings` originally declared no `default_value`, so ACF answered `false`
   * for every untouched page — indistinguishable from a deliberate "off". This
   * gate was written to survive that, and an adversarial review then proved the
   * gate ALONE was not enough: it protects a page with nothing typed, but the
   * moment an editor typed only a HEADLINE — the likeliest first action, and
   * the whole point of wiring the hero — `heroOn` flipped true and this
   * collapsed to a false `ratings`. Measured: the 22 routes rendering
   * `class="ratings"` became 0.
   *
   * Closed at the source instead. `field_vs_page_hero_ratings` now declares
   * `'default_value' => 1`, so "never touched" means what the site already
   * does. Verified against the live endpoint after deploying: six pages with
   * `h1: null` all return `ratings: true`. The `!heroOn ||` half stays as belt
   * and braces for any install whose stored value predates that default.
   *
   * The rule lives here, once, for the same reason `hasBlocks` does: so it
   * means the same thing in all 25 templates and no template can restate it
   * wrongly.
   */
  showRatings: boolean;
  /**
   * Button i (0 = the solid one, 1 = the outlined one), or null to keep the
   * template's own.
   *
   * A function rather than an array so a template reads `hero.cta(1)` and gets
   * null for "the editor typed one button", instead of indexing off the end.
   * Label and href only: the variant and the hover label are band and motion
   * decisions the `ctas` repeater does not model, and the template keeps them.
   */
  cta: (i: number) => HeroCta | null;
};

/**
 * One row of the `blocks` flexible-content field.
 *
 * Deliberately open. `__typename` is the layout name the renderer switches on
 * (docs/PAGE-BLOCKS.md 2.1), and the preamble three are the only sub-fields
 * every layout carries, so those are the ones named here; the rest belong to
 * one layout each and are read by the component the registry picks for it.
 *
 * Even the preamble is optional, because a layout this build has never heard
 * of — one added in PHP and deployed to the CMS before the Astro side ships,
 * or left behind by a rollback of the Astro side alone — arrives carrying
 * nothing but its name. That has to render as nothing, not throw.
 */
export type PageBlock = {
  __typename: string;
  anchor?: string | null;
  navLabel?: string | null;
  band?: string | null;
  [field: string]: unknown;
};

const EMPTY_SECTION: Section = {
  section_id: "",
  eyebrow: "",
  heading: "",
  body: "",
  cta_label: "",
  cta_href: "",
};

export type PageContent = {
  title: string;
  tocLinks: TocLink[];
  processSteps: ProcessStep[];
  faqs: Faq[];
  sections: Section[];
  /** Card rows grouped by their source array name, e.g. cards.whyCards. */
  cards: Record<string, Card[]>;
  /**
   * The page's sections, in the order an editor arranged them in wp-admin.
   *
   * Empty on every page today, and empty is the ordinary state of any page
   * nobody has migrated yet: the template renders the body it always has and
   * never looks at this array. See `hasBlocks`.
   *
   * Empty ALSO when WordPress has no `blocks` field at all. That field lives in
   * a must-use plugin someone hand-deploys to the CMS host, while this code
   * ships on the next build — so for a window of unknown length the front end
   * builds against a WordPress that has never heard of it. That window has to
   * look exactly like "no page is migrated", never like a failure.
   */
  blocks: PageBlock[];
  /**
   * The migration switch, one page at a time (docs/PAGE-BLOCKS.md 2.3):
   *
   *   {hasBlocks ? <PageBlocks blocks={blocks} /> : <the existing markup />}
   *
   * The rule is defined here rather than restated in each of the 33 templates
   * so that it means the same thing everywhere. Un-migrating a page is meant to
   * be "empty one field in wp-admin" — no deploy, no code change — and that
   * only holds if every template asks the question the same way.
   */
  hasBlocks: boolean;
  /**
   * Copy for one band of the page, by its section id.
   *
   * Returns empty strings rather than throwing when a section is absent. A
   * missing FAQ row is a content gap the editor can see and fix; a hard failure
   * here would take the whole site's build down over one blank heading.
   */
  seo: PageSeo;
  /**
   * The hero band, as an override of the one in the template.
   *
   * Empty on every page today — the CMS fields exist and nobody has typed in
   * them — and empty must look exactly like the site does now, not like a
   * failure and not like a deliberately blanked headline. See `Hero`.
   */
  hero: Hero;
  section: (id: string) => Section;
  /**
   * A page image by slot, for <Image src={…} width={…} height={…} />.
   *
   * `src` must be the URL STRING and width/height must be passed explicitly.
   * Handing <Image> an ImageMetadata-shaped object whose src is a remote URL
   * looks like it should work and does not — Astro treats it as a local asset
   * and emits "/http://host/…", a broken path. Verified, not assumed.
   */
  image: (slot: string) => PageImage;
};

/**
 * The `blocks` rows off a page entry, defensively.
 *
 * Read through `unknown` rather than off the collection's inferred type on
 * purpose: the rows come from a WordPress that may not have the field yet (see
 * PageContent.blocks), and an absent field must never become a build failure on
 * 33 pages that do not use it. An editor triggers every deploy and never sees
 * the output, so nothing they can do — or fail to do — may break a build.
 *
 * A row with no `__typename` cannot be matched to a component or even named in
 * a warning, so it is dropped rather than handed on. The worst that costs is a
 * page whose rows were all malformed rendering its existing template body,
 * which is the same safe direction an empty field already takes.
 */
function readBlocks(data: unknown): PageBlock[] {
  const rows = (data as { blocks?: unknown }).blocks;

  if (!Array.isArray(rows)) return [];

  return rows
    .filter(
      (row): row is PageBlock =>
        typeof row === "object" &&
        row !== null &&
        typeof (row as PageBlock).__typename === "string",
    )
    .map(unwrapSelects);
}

/**
 * WPGraphQL RETURNS EVERY ACF SELECT AS A LIST, even a single-valued one:
 * `band` arrives as `["charcoal"]`, not `"charcoal"`. Normalised here, at the
 * one boundary every block row crosses, rather than in each component.
 *
 * THIS WAS A LIVE DEFECT, not a theoretical one. Eight components test
 * `typeof band === "string"`, which a one-element array fails, so every one of
 * them silently fell back to its hard-coded default — and a fallback is
 * indistinguishable from a deliberate value. On /cosmetic-dentistry/
 * clear-aligners/ the `what` and `natural` bands are `charcoal` in the map and
 * `<section class="section alt">` in the template, and the built page was
 * shipping `<section class="section">`: two charcoal bands rendering as paper,
 * losing the background, the white headings and the 85%-white body copy.
 *
 * IT SURVIVED BECAUSE THE MEASUREMENT COULD NOT SEE IT. This migration is
 * graded by a word-level diff of built HTML against the template baseline, and
 * a band that loses its modifier class keeps every one of its words — the diff
 * reported zero. Only a class-level comparison catches it, which is why
 * scripts/vr-html.mjs exists and why a word count is not a substitute for it.
 *
 * CodeSectionBlock had already unwrapped `bandKey` by hand, which is the proof
 * the shape was known — and the argument for fixing it once, here, instead of
 * in the ninth component to hit it.
 *
 * Single-element arrays only. A genuine multi-select would lose data if
 * flattened, so anything longer is passed through untouched, and a
 * zero-element array becomes `null` — "the editor picked nothing", which is
 * what every component's fallback already handles.
 */
function unwrapSelects(row: PageBlock): PageBlock {
  const out: Record<string, unknown> = { ...row };

  for (const [key, value] of Object.entries(out)) {
    if (!Array.isArray(value)) continue;
    if (value.length === 0) {
      out[key] = null;
    } else if (value.length === 1 && (typeof value[0] === "string" || typeof value[0] === "number")) {
      out[key] = value[0];
    }
    // Longer arrays, and arrays of objects, are repeaters — left alone.
  }

  return out as PageBlock;
}

/**
 * Fetch content for `route`.
 *
 * Throws when the route has no WordPress page. That is deliberate: the
 * alternative is a page that builds successfully with an empty FAQ section and
 * an empty table of contents, which looks like a styling bug and can sit
 * unnoticed in production for weeks. A failed build is loud and reversible.
 */
export async function getPageContent(route: string): Promise<PageContent> {
  // The site is configured with trailingSlash: 'always'; normalize anyway so a
  // caller passing "/contact" gets the same entry as "/contact/".
  const id = route.endsWith("/") ? route : `${route}/`;

  const entry = await getEntry("pages", id);

  if (!entry) {
    throw new Error(
      `No WordPress page found for route "${id}".\n` +
        `Create it in wp-admin under Pages, or re-run the importer:\n` +
        `  cd cms && npm run import:pages`,
    );
  }

  const sections = entry.data.sections;

  const cards: Record<string, Card[]> = {};
  for (const card of entry.data.cards) {
    (cards[card.group] ??= []).push(card);
  }

  const images = entry.data.images;

  const blocks = readBlocks(entry.data);

  // THE HERO GATE, applied once, here, so that no template can implement it
  // differently. A hero is "on" when an editor has typed a headline and only
  // then: the h1 is the one field with no safe blank reading, because every
  // other member of the group has a meaning when empty that is
  // indistinguishable from never having been touched. `ratings` is the sharp
  // one (see Hero.showRatings), but eyebrow, sub and ctas have the same shape
  // of problem — a page whose editor cleared the headline and nothing else
  // should not lose its kicker.
  //
  // Consequence worth naming: a page can never be given a hero SUB without a
  // hero H1. That is the trade, and it is the right way round — the h1 is the
  // point of this whole change, and a sub with no headline is not a state
  // anybody wants to ship.
  const h = entry.data.hero;
  const heroOn = h.h1 !== ""; // "" is what the loader stores for blank

  return {
    title: entry.data.title,
    seo: entry.data.seo,
    tocLinks: entry.data.tocLinks,
    processSteps: entry.data.processSteps,
    faqs: entry.data.faqs,
    sections,
    cards,
    blocks,
    // Deliberately NOT `blocks.length > 0`. That asks "did WordPress send
    // rows"; the templates are asking "should I stand aside and let PageBlocks
    // draw this page", and those diverge exactly when it hurts. A row whose
    // layout this build has no component for renders as nothing in production,
    // so a page migrated in wp-admin against a layout that has not shipped yet
    // would go blank rather than fall back to the markup it still has.
    //
    // Asking the registry instead means an unshipped layout degrades to "not
    // migrated yet", which is the honest answer and a visible one.
    hasBlocks: blocks.some((block) => isRegisteredLayout(block.__typename)),
    hero: {
      eyebrow: heroOn ? h.eyebrow : "",
      h1: heroOn ? h.h1 : "",
      sub: heroOn ? h.sub : "",
      // The only member that is not simply blanked: "draw the stars" is what
      // every template does today, so that is what the gate has to mean.
      showRatings: !heroOn || h.ratings,
      // Never throws, unlike image() below. A button an editor has not typed
      // is not a missing asset — the template still has its own, and "nothing"
      // is a thing a hero can render.
      cta: (i: number) => (heroOn ? (h.ctas[i] ?? null) : null),
    },
    section: (sectionId: string) =>
      sections.find((s) => s.section_id === sectionId) ?? EMPTY_SECTION,
    image: (slot: string) => {
      const found = images.find((i) => i.slot === slot);

      // Unlike section(), this throws. An absent image cannot render as
      // "nothing" — <Image> would receive an empty src and fail the build with
      // a message pointing at Astro internals rather than at the missing slot.
      if (!found) {
        throw new Error(
          `No image in WordPress for slot "${slot}" on "${id}".\n` +
            `Add it under Pages -> this page -> Images, or re-run:\n` +
            `  cd cms && npm run import:images`,
        );
      }

      return found;
    },
  };
}

/**
 * Absolute, optimized URL for a page image — for structured data.
 *
 * Structured data must point at the PUBLIC site, not the CMS. A PageImage's
 * `url` is the WordPress media URL, so putting it straight into JSON-LD tells
 * Google the canonical image lives on the CMS host — which is noindexed,
 * password-able, and not where visitors are sent.
 *
 * Running it through getImage() yields the same hashed asset the page renders,
 * and resolving against `site` makes it absolute as schema.org requires.
 */
export async function schemaImageUrl(
  img: PageImage,
  site: URL | undefined,
  width = 1200,
): Promise<string> {
  const optimized = await getImage({
    src: img.url,
    width,
    height: Math.max(1, Math.round((width * img.height) / img.width)),
    format: "webp",
  });

  return new URL(optimized.src, site).href;
}
