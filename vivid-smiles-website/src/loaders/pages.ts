/**
 * Content-layer loader: WordPress pages → the `pages` collection.
 *
 * Unlike `blog` and `reviews`, this collection does not generate routes. The 35
 * routes under src/pages stay exactly as they are — hand-built Astro templates
 * with bespoke layouts. This collection only supplies the *content* those
 * templates render: the table of contents, process steps and FAQ entries that
 * used to live as arrays in each page's frontmatter.
 *
 * Entries are keyed by ROUTE (e.g. "/cosmetic-dentistry/porcelain-veneers/"),
 * which is WordPress's own `uri` for the page, so a template looks up its own
 * content with getPageContent(Astro.url.pathname).
 *
 * The design is deliberately conservative: WordPress changes the words, not the
 * layout. There is no section ordering and no free-form block list, because the
 * per-page CSS assumes a fixed structure.
 */

import type { Loader } from "astro/loaders";
import { wpQueryAll, WordPressError } from "../lib/wp";

const PAGES_QUERY = /* GraphQL */ `
  query Pages($first: Int!, $after: String) {
    pages(first: $first, after: $after, where: { status: PUBLISH }) {
      pageInfo {
        hasNextPage
        endCursor
      }
      nodes {
        vsRoute
        uri
        slug
        title
        pageFields {
          tocLinks {
            label
            anchor
          }
          processSteps {
            tag
            title
            body
          }
          faqs {
            question
            answer
            open
          }
          sections {
            sectionId
            eyebrow
            heading
            body
            ctaLabel
            ctaHref
          }
          cards {
            group
            title
            body
            meta
            href
          }
        }
      }
    }
  }
`;

type PageNode = {
  /** Canonical Astro route from the importer — see vs-content-model.php. */
  vsRoute: string | null;
  uri: string | null;
  slug: string;
  title: string | null;
  pageFields: {
    tocLinks: Array<{ label: string | null; anchor: string | null }> | null;
    processSteps: Array<{ tag: string | null; title: string | null; body: string | null }> | null;
    faqs: Array<{ question: string | null; answer: string | null; open: boolean | null }> | null;
    sections: Array<{
      sectionId: string | null;
      eyebrow: string | null;
      heading: string | null;
      body: string | null;
      ctaLabel: string | null;
      ctaHref: string | null;
    }> | null;
    cards: Array<{
      group: string | null;
      title: string | null;
      body: string | null;
      meta: string | null;
      href: string | null;
    }> | null;
  } | null;
};

export function pagesLoader(): Loader {
  return {
    name: "wordpress-pages",

    async load({ store, logger, parseData }) {
      logger.info("Fetching pages from WordPress");

      const nodes = await wpQueryAll<PageNode>(PAGES_QUERY, (data) => data.pages, "pages");

      if (nodes.length === 0) {
        throw new WordPressError(
          "WordPress returned 0 published pages. Expected 31. " +
            "Run `npm run import:pages` in cms/ to populate them.",
        );
      }

      store.clear();

      for (const node of nodes) {
        // Prefer the route the importer recorded. WordPress's own uri is wrong
        // for the home page ("/home/" vs "/"), and it moves whenever a slug or
        // parent changes — which would quietly detach content from its page.
        const route = node.vsRoute ?? node.uri;

        if (!route) {
          logger.warn(`Skipping page "${node.slug}": no route or uri`);
          continue;
        }

        const f = node.pageFields;

        const data = await parseData({
          id: route,
          data: {
            route,
            title: node.title ?? "",
            // Normalized to the shape the templates already use, so a template
            // swaps `const faqs = [...]` for a lookup and changes nothing else.
            tocLinks: (f?.tocLinks ?? [])
              .filter((t) => t.label && t.anchor)
              .map((t) => ({ href: `#${t.anchor}`, label: t.label! })),
            processSteps: (f?.processSteps ?? [])
              .filter((s) => s.title)
              .map((s, i) => ({
                tag: s.tag ?? "",
                // `num` was authored as "01", "02"… in frontmatter. Deriving it
                // from position means an editor reordering steps in WordPress
                // cannot leave the numbering out of sequence.
                num: String(i + 1).padStart(2, "0"),
                title: s.title!,
                body: s.body ?? "",
              })),
            faqs: (f?.faqs ?? [])
              .filter((q) => q.question)
              .map((q) => ({
                q: q.question!,
                a: q.answer ?? "",
                open: Boolean(q.open),
              })),
            sections: (f?.sections ?? [])
              .filter((s) => s.sectionId)
              .map((s) => ({
                section_id: s.sectionId!,
                eyebrow: s.eyebrow ?? "",
                heading: s.heading ?? "",
                body: s.body ?? "",
                cta_label: s.ctaLabel ?? "",
                cta_href: s.ctaHref ?? "",
              })),
            cards: (f?.cards ?? [])
              .filter((c) => c.group)
              .map((c) => ({
                group: c.group!,
                title: c.title ?? "",
                body: c.body ?? "",
                meta: c.meta ?? "",
                href: c.href ?? "",
              })),
          },
        });

        store.set({ id: route, data });
      }

      if (store.keys().length === 0) {
        throw new WordPressError(`All ${nodes.length} pages failed validation.`);
      }

      logger.info(`Loaded ${store.keys().length} pages`);
    },
  };
}
