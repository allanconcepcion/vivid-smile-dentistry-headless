import { defineCollection, z } from "astro:content";
import { glob } from "astro/loaders";
import { reviewsLoader } from "./loaders/reviews";
import { blogLoader } from "./loaders/blog";
import { pagesLoader } from "./loaders/pages";

/**
 * Reviews / testimonials — sourced from WordPress.
 *
 * Reviews are edited in wp-admin under "Testimonials", not in this repo. The
 * markdown files that previously backed this collection were imported into
 * WordPress once (see cms/import/import-reviews.php) and are no longer read.
 *
 * The entry shape is unchanged from the old glob() loader, so every consumer
 * (ReviewMarquee, cards/ReviewCard, the testimonials page, and the landing
 * pages) works without modification:
 *
 *   entry.id    the WordPress slug
 *   entry.data  reviewer, rating, source, date, tags
 *   entry.body  plain-text review copy
 *
 * Requires WP_GRAPHQL_ENDPOINT. For local development: cd cms && npm start
 *
 * `tags` are free-form strings (filter / theme / topic), managed in WordPress
 * under Testimonials → Review Tags. Keep them Title Case for display.
 */
const reviews = defineCollection({
  loader: reviewsLoader(),
  schema: z.object({
    reviewer: z.string(),
    rating: z.number().int().min(1).max(5),
    source: z.string(),
    date: z.coerce.date(),
    tags: z.array(z.string()).default([]),
  }),
});

/**
 * Blog posts — sourced from WordPress.
 *
 * Posts are written in wp-admin under "Posts", not in this repo. The markdown
 * files that previously backed this collection were imported once (see
 * cms/import/) and are no longer read.
 *
 * URL: posts render at /blog/<slug>/, where the slug is the WordPress post
 * slug. Changing a slug in WordPress changes the public URL — set up a redirect
 * if the old one was ever shared.
 *
 * Filter the hub by category via /blog/?category=<Category>.
 *
 * Categories are a CLOSED enum, and the same five names are seeded in
 * cms/mu-plugins/vs-content-model.php. They are also the runtime keys for the
 * client-side hub filter and appear verbatim in shared URLs, so adding a
 * category means editing both places; renaming one breaks existing links.
 * A post in any other category is skipped by the loader with a warning.
 *
 * Requires WP_GRAPHQL_ENDPOINT. For local development: cd cms && npm start
 */
const blog = defineCollection({
  loader: blogLoader(),
  schema: () =>
    z.object({
      title: z.string(),
      description: z.string().max(200),
      date: z.coerce.date(),
      updated: z.coerce.date().optional(),
      author: z.string().default("Slate"),
      category: z.enum([
        "Dental Tips",
        "Cosmetic Dentistry",
        "Implant Dentistry",
        "General Dentistry",
        "Emergency Dentistry",
      ]),
      // A URL string rather than image(). The image() helper resolves paths
      // relative to an entry's source file, and a WordPress-backed entry has
      // none — so it cannot be used from a remote loader. Astro still optimizes
      // these at build time and emits local hashed assets, because the CMS host
      // is authorized in astro.config.mjs under image.remotePatterns.
      heroImage: z.string().url(),
      // <Image> requires explicit dimensions for a remote src. Without them it
      // either throws or (with inferSize) downloads every image at build just
      // to measure it. WordPress reports these from the media library.
      heroWidth: z.number().int().positive(),
      heroHeight: z.number().int().positive(),
      heroAlt: z.string(),
      draft: z.boolean().default(false),
    }),
});

/**
 * Page content — sourced from WordPress.
 *
 * Does NOT generate routes. The 35 routes under src/pages remain hand-built
 * Astro templates; this collection only supplies the content they render — the
 * table of contents, process steps and FAQ entries that used to be arrays in
 * each page's frontmatter.
 *
 * Entries are keyed by route ("/cosmetic-dentistry/porcelain-veneers/"), so a
 * template fetches its own content with getPageContent(Astro.url.pathname).
 *
 * Edited in wp-admin under Pages. The page hierarchy there mirrors the site.
 */
const pages = defineCollection({
  loader: pagesLoader(),
  schema: z.object({
    route: z.string(),
    title: z.string(),
    heroEyebrow: z.string().optional(),
    heroHeading: z.string().optional(),
    heroSubheading: z.string().optional(),
    // Shapes match what the templates already destructure, so adopting this
    // collection is a one-line change per page rather than a rewrite.
    tocLinks: z.array(z.object({ href: z.string(), label: z.string() })).default([]),
    processSteps: z
      .array(
        z.object({
          tag: z.string(),
          num: z.string(),
          title: z.string(),
          body: z.string(),
        }),
      )
      .default([]),
    faqs: z
      .array(z.object({ q: z.string(), a: z.string(), open: z.boolean() }))
      .default([]),
  }),
});

export const collections = { reviews, blog, pages };
