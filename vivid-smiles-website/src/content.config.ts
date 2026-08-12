import { defineCollection, z } from "astro:content";
import { glob } from "astro/loaders";
import { reviewsLoader } from "./loaders/reviews";

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
 * Blog posts.
 *
 * Add a post by creating src/content/blog/<slug>.md with frontmatter:
 *
 *   ---
 *   title: "Common Myths About Dental Care Debunked"
 *   description: "A 1–2 sentence summary, <=160 chars — used for hub card + meta description."
 *   date: 2025-01-13
 *   author: "Slate"
 *   category: "Dental Tips"
 *   heroImage: "../../assets/images/blog/common-myths-about-dental-care-debunked/00-hero.webp"
 *   heroAlt: "A dental hygienist demonstrating proper brushing"
 *   draft: false
 *   ---
 *
 *   Markdown body. Body images live alongside the hero in src/assets/images/blog/<slug>/
 *   and are referenced via the same relative-path pattern as heroImage above.
 *
 * URL: posts render at /blog/<slug>/. Filter the hub by category via /blog?category=<Category>.
 *
 * Categories are a closed enum — extend the list below if the SEO director introduces a new
 * one, then re-run a migration helper on any source files in that category.
 *
 * Drafts (draft: true) are excluded from getStaticPaths in src/pages/blog/[slug].astro, from
 * the hub listing in src/pages/blog/index.astro, and from the sitemap filter in astro.config.mjs.
 *
 * Migrating an old WordPress export: see a migration helper — that script handles
 * the WP-noise stripping, frontmatter extraction, and image webp conversion.
 */
const blog = defineCollection({
  loader: glob({ pattern: "**/*.md", base: "./src/content/blog" }),
  schema: ({ image }) =>
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
      heroImage: image(),
      heroAlt: z.string(),
      draft: z.boolean().default(false),
    }),
});

export const collections = { reviews, blog };
