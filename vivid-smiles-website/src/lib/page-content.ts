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

  return {
    title: entry.data.title,
    seo: entry.data.seo,
    tocLinks: entry.data.tocLinks,
    processSteps: entry.data.processSteps,
    faqs: entry.data.faqs,
    sections,
    cards,
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
