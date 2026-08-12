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

export type PageContent = {
  title: string;
  heroEyebrow?: string;
  heroHeading?: string;
  heroSubheading?: string;
  tocLinks: TocLink[];
  processSteps: ProcessStep[];
  faqs: Faq[];
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

  return {
    title: entry.data.title,
    heroEyebrow: entry.data.heroEyebrow,
    heroHeading: entry.data.heroHeading,
    heroSubheading: entry.data.heroSubheading,
    tocLinks: entry.data.tocLinks,
    processSteps: entry.data.processSteps,
    faqs: entry.data.faqs,
  };
}
