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
 * ALWAYS PUBLISH — read src/loaders/blog.ts before loosening or tightening
 * anything below. That loader never drops a post: every field it hands over has
 * already been degraded to something valid (a placeholder hero, an empty alt, a
 * fallback date, "Untitled post"). So this schema's job has changed. It is no
 * longer the gate that decides which posts exist — it is the assertion that the
 * loader did its job. A failure here now means a bug in the loader, not bad
 * editor input, which is why the remaining constraints are deliberately tight
 * (.min(1), .positive(), an explicit heroImage shape) rather than relaxed to
 * z.string()/z.any(). Loosen one and a real loader regression ships silently.
 *
 * Requires WP_GRAPHQL_ENDPOINT. For local development: cd cms && npm start
 */
const blog = defineCollection({
  loader: blogLoader(),
  schema: () =>
    z.object({
      // .min(1) rather than a bare string: the loader substitutes "Untitled post"
      // for a blank title, so an empty one reaching here is a loader bug.
      title: z.string().min(1),
      description: z.string().max(200),
      date: z.coerce.date(),
      updated: z.coerce.date().optional(),
      author: z.string().default("Slate"),
      /**
       * OPEN, not an enum — and this is the one deliberate loosening.
       *
       * It used to be a closed z.enum of the five categories seeded in
       * cms/mu-plugins/vs-content-model.php, which meant a post in any other
       * category was dropped from the site entirely. A category is a label, not
       * a gate: an unexpected one must never cost the practice a publication.
       *
       * The five live in KNOWN_CATEGORIES in src/loaders/blog.ts, which is the
       * single source of truth (getCategories() in src/lib/blog.ts orders the
       * hub's chip rail by the same list). The loader keeps an unexpected
       * category verbatim and logs a warning explaining that it gets no chip.
       *
       * Consequence to know about: BlogCategory in src/lib/blog.ts is derived
       * from this type, so it is now `string`. Nothing switches exhaustively on
       * it. .min(1) still holds — the loader defaults an absent category to
       * "Dental Tips", so an empty string here is a loader bug.
       */
      category: z.string().min(1),
      /**
       * A URL string rather than image(). The image() helper resolves paths
       * relative to an entry's source file, and a WordPress-backed entry has
       * none — so it cannot be used from a remote loader. Astro still optimizes
       * these at build time and emits local hashed assets, because the CMS host
       * is authorized in astro.config.mjs under image.remotePatterns.
       *
       * Two shapes are legal, and only two. An absolute http(s) URL (a real
       * featured image from the media library), or the inline data: URI the
       * loader substitutes when there is no usable hero — see HERO_PLACEHOLDER
       * in src/loaders/blog.ts for why that is a data: URI and not a file.
       *
       * This is TIGHTER than the z.string().url() it replaces, which also
       * accepted ftp:, mailto: and file: — none of which <Image> can render.
       */
      heroImage: z
        .string()
        .refine((src) => /^https?:\/\//i.test(src) || src.startsWith("data:image/"), {
          message:
            "heroImage must be an absolute http(s) URL or the loader's inline placeholder data: URI",
        }),
      // <Image> requires explicit dimensions for a remote src. Without them it
      // either throws or (with inferSize) downloads every image at build just
      // to measure it. WordPress reports these from the media library; when it
      // cannot, the loader measures the file or falls back to the placeholder,
      // so these are always real positive integers by the time they land here.
      heroWidth: z.number().int().positive(),
      heroHeight: z.number().int().positive(),
      /**
       * May be the empty string, and that is meaningful rather than sloppy:
       * alt="" is the WAI convention for a decorative image. A hero whose alt
       * text nobody wrote is better left unlabelled than narrated as a filename,
       * and the missing text is reported as a build warning (and, on the PHP
       * side, on the post edit screen) rather than swallowed.
       */
      heroAlt: z.string(),
      /**
       * True when heroImage is the loader's placeholder plate rather than an
       * image the author chose. Consumers that must not present a stand-in as
       * real content branch on this: blog/[slug].astro omits the post hero
       * figure entirely, drops `image` from the BlogPosting JSON-LD, and lets
       * og:image fall through to BaseLayout's site-logo default.
       *
       * BlogCard.astro deliberately does NOT branch on it — the hub grid needs
       * every tile to have a media well or the layout goes ragged.
       */
      heroPlaceholder: z.boolean().default(false),
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
    // Editor-managed SEO. Empty strings mean "no override" — the template
    // keeps whatever it already had, so a blank field in WordPress can never
    // blank out a page's title tag.
    seo: z.object({
      title: z.string(),
      description: z.string(),
      canonical: z.string(),
      noindex: z.boolean(),
      ogImage: z.string(),
    }),
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
    sections: z
      .array(
        z.object({
          section_id: z.string(),
          eyebrow: z.string(),
          heading: z.string(),
          body: z.string(),
          cta_label: z.string(),
          cta_href: z.string(),
        }),
      )
      .default([]),
    cards: z
      .array(
        z.object({
          group: z.string(),
          title: z.string(),
          body: z.string(),
          meta: z.string(),
          href: z.string(),
        }),
      )
      .default([]),
    images: z
      .array(
        z.object({
          slot: z.string(),
          url: z.string().url(),
          width: z.number().int().positive(),
          height: z.number().int().positive(),
          alt: z.string(),
        }),
      )
      .default([]),
  }),
});

export const collections = { reviews, blog, pages };
