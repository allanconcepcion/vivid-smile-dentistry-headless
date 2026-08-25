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
 *
 * Failure policy for images: a picture the build cannot place fails the build,
 * it is never dropped. Dropping it would remove a photo from a live page with
 * nothing to show for it — and where a template does need that slot, the drop
 * only relocates the same failure to a message about a missing page, which
 * sends the editor to fix something that was never broken. So the loader reads
 * every page, collects every unusable picture, and reports the lot at once,
 * naming the page, the slot, the file and the fix. A failed build costs a
 * deploy, not an outage: the previous deployment carries on serving.
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
        vsSeo {
          title
          description
          canonical
          noindex
          ogImage
        }
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
          images {
            slot
            alt
            image {
              node {
                sourceUrl
                altText
                mediaDetails {
                  width
                  height
                }
              }
            }
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
  vsSeo: {
    title: string | null;
    description: string | null;
    canonical: string | null;
    noindex: boolean | null;
    ogImage: string | null;
  } | null;
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
    images: Array<{
      slot: string | null;
      alt: string | null;
      image: {
        node: {
          sourceUrl: string | null;
          altText: string | null;
          mediaDetails: { width: number | null; height: number | null } | null;
        } | null;
      } | null;
    }> | null;
  } | null;
};

/**
 * The file's name as it appears in the Media Library.
 *
 * A sourceUrl is a wp-content path no editor has ever looked at. What they can
 * recognise on sight — and paste into the Media Library search box — is the
 * file name, so that is what an error names.
 */
function mediaFileName(url: string): string {
  try {
    return decodeURIComponent(new URL(url).pathname.split("/").pop() || url);
  } catch {
    // sourceUrl is not guaranteed to be absolute (an offload plugin can emit a
    // relative path). If it will not parse, the raw value still beats nothing.
    return url;
  }
}

/**
 * Why a media item has no dimensions, and what the editor should do about it.
 *
 * WordPress stores intrinsic width and height when it generates an
 * attachment's metadata. Two different things stop that happening and they need
 * opposite fixes, so a single generic message would send half of the people
 * reading it after the wrong problem.
 *
 * SVG is the case worth spelling out, even though the Images repeater now
 * restricts itself to webp/jpg/jpeg/png the way the smile gallery always has
 * (see cms/mu-plugins/vs-content-model.php). That restriction filters the media
 * picker; it does not audit rows chosen before it existed, and it does not
 * follow the file if someone replaces the attachment behind an existing one. A
 * logo saved as SVG looks perfectly correct in the preview thumbnail and carries
 * no pixel size at all, so the case stays worth naming.
 */
function whyNoDimensions(url: string): string {
  if (mediaFileName(url).toLowerCase().endsWith(".svg")) {
    return (
      "SVG pictures have no fixed pixel size, so WordPress records none. " +
      "Save this one as JPG, PNG or WebP and upload that version instead."
    );
  }

  return (
    "WordPress recorded no size for this file — usually an upload that did not " +
    "finish, or a file that has since been moved on the server. Upload it again."
  );
}

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

      // Problems are gathered across every page and reported together at the
      // end rather than thrown on sight. An editor who uploaded four unusable
      // pictures should learn about four of them in one go; throwing on the
      // first costs four build cycles to discover four faults, against a CMS
      // that rate-limits and a build that is not quick.
      const badImages: string[] = [];
      const badPages: string[] = [];
      let imageCount = 0;

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

        // Named the way an editor would name it, with the route to disambiguate
        // two pages that share a title.
        const pageName = node.title?.trim() || node.slug;
        const where = `${pageName} (${route})`;

        const images: Array<{
          slot: string;
          url: string;
          width: number;
          height: number;
          alt: string;
        }> = [];

        for (const row of f?.images ?? []) {
          const media = row.image?.node;

          // ACF marks the slot required and readonly but leaves the picture
          // itself optional, so a row with nothing chosen is a half-finished
          // edit rather than a fault — warn and carry on. Nothing disappears
          // quietly by doing so: if a template actually needs that slot,
          // image() in src/lib/page-content.ts already fails with a message
          // naming the slot and the page.
          if (!row.slot || !media?.sourceUrl) {
            const which = row.slot ? `slot "${row.slot}"` : "a row with no slot";
            logger.warn(`${where}: ${which} has no picture chosen — nothing to load.`);
            continue;
          }

          imageCount++;

          const width = media.mediaDetails?.width ?? 0;
          const height = media.mediaDetails?.height ?? 0;

          // This is the case that used to take the whole deploy down. The old
          // code defaulted a missing dimension to 0, which the schema rejects
          // as not a positive integer — producing a Zod error that named the
          // field but neither the page nor the file, for a reader whose only
          // sight of it was a Vercel build log. Catch it here, where the page,
          // the slot and the file name are all still in hand.
          if (width < 1 || height < 1) {
            badImages.push(
              `  ${where}\n` +
                `    slot "${row.slot}" -> ${mediaFileName(media.sourceUrl)}\n` +
                `    ${whyNoDimensions(media.sourceUrl)}\n` +
                `    Then pick it under Pages -> ${pageName} -> Images -> ${row.slot}.`,
            );
            continue;
          }

          images.push({
            slot: row.slot,
            url: media.sourceUrl,
            width,
            height,
            alt: (row.alt || media.altText || "").trim(),
          });
        }

        // parseData applies the Zod schema. Anything it rejects that was not
        // caught above throws a report naming the field but not the page it
        // came from, which is close to useless across thirty-odd of them.
        // Attach the page, keep reading the rest so every fault surfaces in
        // one build, and fail below — never skip the page, because a page
        // missing from the store fails later as "no WordPress page for this
        // route", pointing the reader at the importer instead of the fault.
        try {
          const data = await parseData({
            id: route,
            data: {
              route,
              title: node.title ?? "",
              seo: {
                title: node.vsSeo?.title?.trim() ?? "",
                description: node.vsSeo?.description?.trim() ?? "",
                canonical: node.vsSeo?.canonical?.trim() ?? "",
                noindex: Boolean(node.vsSeo?.noindex),
                ogImage: node.vsSeo?.ogImage?.trim() ?? "",
              },
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
              // Dimensions are required, not optional: <Image> refuses a remote
              // source without them, and they are what stops the page reflowing
              // as it loads. Every row here has already been checked to carry
              // them — one that could not is in `badImages` and will fail the
              // build below, rather than being quietly left out of the page.
              images,
            },
          });

          store.set({ id: route, data });
        } catch (error) {
          const detail = error instanceof Error ? error.message : String(error);
          badPages.push(`  ${where}\n    ${detail.replace(/\n/g, "\n    ")}`);
        }
      }

      // Images first: this is the one an editor causes by doing something
      // ordinary, so it is the one most likely to be read by someone who did
      // not expect to be reading a build log at all. Every line of it is
      // addressed to that reader — no stack, no field paths, no jargon.
      if (badImages.length > 0) {
        throw new WordPressError(
          `${badImages.length} of ${imageCount} pictures on the site have no width and ` +
            `height recorded in the WordPress Media Library, so the build cannot place ` +
            `them:\n\n${badImages.join("\n\n")}\n\n` +
            `Nothing has been published. The site that is online right now is unchanged ` +
            `and still serving — fix the pictures above and deploy again.`,
        );
      }

      if (badPages.length > 0) {
        throw new WordPressError(
          `${badPages.length} of ${nodes.length} pages hold content WordPress cannot ` +
            `publish:\n\n${badPages.join("\n\n")}\n\n` +
            `Nothing has been published. The site that is online right now is unchanged.`,
        );
      }

      // Reached only when every page lacked both a route and a uri, so nothing
      // above had anything to complain about and nothing was stored either.
      if (store.keys().length === 0) {
        throw new WordPressError(`All ${nodes.length} pages failed validation.`);
      }

      logger.info(`Loaded ${store.keys().length} pages (${imageCount} images)`);
    },
  };
}
