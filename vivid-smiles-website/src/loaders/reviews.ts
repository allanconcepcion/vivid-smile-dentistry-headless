/**
 * Content-layer loader: WordPress `vs_testimonial` → the `reviews` collection.
 *
 * Replaces the glob() loader that read src/content/reviews/*.md. The entry
 * shape it produces is identical, so no consuming component changes:
 *
 *   entry.id        the WP slug        (was: markdown filename)
 *   entry.data      reviewer, rating, source, date, tags
 *   entry.body      plain-text review  (was: markdown body)
 *
 * `body` is deliberately plain text, not HTML: ReviewMarquee.astro renders it
 * as `{r.body}` and ReviewCard.astro takes it through <slot /> inside a <p>.
 * Both escape markup, so WordPress's `<p>`-wrapped HTML would render as visible
 * angle brackets — and a <p> inside a <p> is invalid markup besides.
 */

import type { Loader } from "astro/loaders";
import { wpQueryAll, htmlToText, acfSingle, WordPressError } from "../lib/wp";

const TESTIMONIALS_QUERY = /* GraphQL */ `
  query Testimonials($first: Int!, $after: String) {
    testimonials(first: $first, after: $after, where: { orderby: { field: DATE, order: DESC } }) {
      pageInfo {
        hasNextPage
        endCursor
      }
      nodes {
        slug
        date
        content
        testimonialFields {
          reviewer
          rating
          source
        }
        testimonialTags {
          nodes {
            name
          }
        }
      }
    }
  }
`;

type TestimonialNode = {
  slug: string;
  /** WordPress site-local ISO-ish timestamp, e.g. "2026-03-27T12:00:00". */
  date: string | null;
  content: string | null;
  testimonialFields: {
    reviewer: string | null;
    rating: number | null;
    /** ACF select — an array even for a single value. */
    source: string[] | string | null;
  } | null;
  testimonialTags: { nodes: Array<{ name: string }> } | null;
};

export function reviewsLoader(): Loader {
  return {
    name: "wordpress-reviews",

    async load({ store, logger, parseData, meta }) {
      logger.info("Fetching testimonials from WordPress");

      const nodes = await wpQueryAll<TestimonialNode>(
        TESTIMONIALS_QUERY,
        (data) => data.testimonials,
        "testimonials",
      );

      // A zero-length result is almost always a broken query or a post type
      // that stopped being publicly queryable — not a practice that deleted
      // every review. Silently shipping a site with no testimonials is worse
      // than failing the build.
      if (nodes.length === 0) {
        throw new WordPressError(
          "WordPress returned 0 testimonials. Expected at least one. " +
            "Check that the vs_testimonial post type is registered with public => true " +
            "(WPGraphQL hides non-public types from unauthenticated requests).",
        );
      }

      // Full replace on every build. This is the simple-and-correct choice for
      // ~20 short entries: it handles deletions for free, and the whole payload
      // is a single sub-100ms request.
      //
      // Note the tradeoff — clearing the store defeats Astro's digest-based
      // change detection, since store.set() can only skip a write when it finds
      // an existing entry to compare against. That does not matter at this size,
      // but the blog loader (larger entries, full post HTML) should instead diff
      // a persisted `meta` index of slug → modified timestamps and refetch only
      // what actually changed.
      store.clear();

      let skipped = 0;

      for (const node of nodes) {
        const fields = node.testimonialFields;
        const reviewer = fields?.reviewer?.trim();
        const rating = fields?.rating;
        const source = acfSingle(fields?.source);
        const body = htmlToText(node.content ?? "");

        // Validate before parseData so the message names the offending review.
        // Zod's own error would only say "reviews → rating: expected number".
        const problem =
          !reviewer ? "missing reviewer"
          : typeof rating !== "number" || !Number.isInteger(rating) || rating < 1 || rating > 5
            ? `rating ${JSON.stringify(rating)} is not an integer 1–5`
          : !source ? "missing source"
          : !node.date ? "missing date"
          : !body ? "empty body"
          : null;

        if (problem) {
          logger.warn(`Skipping testimonial "${node.slug}": ${problem}`);
          skipped++;
          continue;
        }

        const data = await parseData({
          id: node.slug,
          data: {
            reviewer,
            rating,
            source,
            // WPGraphQL returns a timestamp with no zone designator. Left as-is
            // it is parsed as *local* time, which shifts the date by the build
            // machine's offset — a review dated the 1st can render as the last
            // day of the previous month on a US-hosted build. Pin it to UTC,
            // matching the importer, which stores midday UTC.
            date: `${node.date}Z`,
            tags: (node.testimonialTags?.nodes ?? []).map((t) => t.name),
          },
        });

        store.set({ id: node.slug, data, body });
      }

      if (store.keys().length === 0) {
        throw new WordPressError(
          `All ${nodes.length} testimonials failed validation. See the warnings above.`,
        );
      }

      meta.set("lastFetched", new Date().toISOString());
      logger.info(
        `Loaded ${store.keys().length} testimonials` + (skipped ? ` (${skipped} skipped)` : ""),
      );
    },
  };
}
