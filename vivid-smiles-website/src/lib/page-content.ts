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

  return rows.filter(
    (row): row is PageBlock =>
      typeof row === "object" &&
      row !== null &&
      typeof (row as PageBlock).__typename === "string",
  );
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
