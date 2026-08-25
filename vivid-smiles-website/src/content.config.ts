import { defineCollection, z } from "astro:content";
// `astro:content` exports `z` as a value only, so the two type names below come
// from the same zod that astro re-exports rather than from a direct dependency.
import type { ZodLiteral, ZodObject } from "astro/zod";
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
 * A page section, exactly as WordPress hands it over.
 *
 * `blocks` is the ordered flexible-content field that replaces the six
 * repeaters one page at a time. It is empty on all 33 pages today, and empty is
 * load-bearing rather than incidental: `blocks.length === 0` means "this page
 * has not been migrated" and the page renders from its template as it always
 * has, so clearing the field in wp-admin rolls a page back with no deploy and
 * no code change (docs/PAGE-BLOCKS.md 2.3).
 *
 * THIS SHAPE MUST NEVER REJECT A SECTION IT DOES NOT RECOGNISE. A layout can
 * reach the CMS before the code that draws it — the mu-plugins are hand-copied
 * to the host and the front end ships on its own schedule, and either side can
 * be rolled back alone. Editors trigger these builds and never see the output
 * (cms/mu-plugins/vs-deploy.php), so a layout this build has not been taught
 * has to survive validation and be reported by the renderer, not take the whole
 * site down. src/loaders/blog.ts and src/integrations/yoast-sitemap.ts hold the
 * same line for the same reason.
 *
 * Hence a permissive member, and a very specific way of attaching it.
 */
const UNKNOWN_BLOCK = z.looseObject({ __typename: z.string().min(1) });

/**
 * The layouts this build knows how to draw.
 *
 * Empty in this phase: the field exists, no component does. Every section
 * therefore parses through UNKNOWN_BLOCK, which is correct — nothing renders
 * blocks yet, and nothing has filled one.
 *
 * A member joins this list in the same commit as its PHP layout, its fragment
 * in src/loaders/pages.ts and its registry entry — and it must not lead them.
 * A member naming fields the query does not select simply fails, and every
 * section of that layout drops to UNKNOWN_BLOCK instead: no louder than
 * success, and invisible until someone notices a band missing from a page.
 *
 *   z.object({
 *     __typename: z.literal("PageFieldsBlocksFaqLayout"),
 *     anchor: z.string(),
 *     navLabel: z.string(),
 *     band: z.string(),
 *     items: z.array(z.object({ question: z.string(), answer: z.string() })),
 *   })
 *
 * The guard below is not defensive tidiness. A zero-member discriminated union
 * is not an empty union, it is a crash — z.discriminatedUnion("__typename", [])
 * throws "Cannot read properties of undefined" while being constructed, which
 * on this list's first day would mean the module never loads. It is the same
 * rule the PHP side has for the flexible field itself (never register it with
 * zero layouts), for the same underlying reason.
 */
const KNOWN_BLOCK_LAYOUTS: ZodObject<{ __typename: ZodLiteral<string> }>[] = [];

const [firstKnownLayout, ...otherKnownLayouts] = KNOWN_BLOCK_LAYOUTS;

/**
 * Why the permissive member is NOT a member of the discriminated union.
 *
 * z.discriminatedUnion indexes its members by the literal value of the
 * discriminator, so it can pick the right one directly and report errors
 * against that one member instead of against every member at once. A member
 * whose `__typename` is z.string() has no literal to index by, so it cannot go
 * in that map — and Zod does not say so when the schema is built. Verified on
 * zod 4.3.6, the version astro re-exports:
 *
 *   z.discriminatedUnion("__typename", [faqLayout, UNKNOWN_BLOCK])
 *
 * constructs without a word, then throws on the FIRST parse of ANY value —
 * a known layout as readily as an unknown one — with
 * `Invalid discriminated union option at index "1"`. It reads correctly, it
 * type-checks, and it fails on the first page of the first build. Worse, it
 * throws a plain Error rather than a ZodError, so it would not even arrive as
 * the loader's "pages hold content WordPress cannot publish" report; it would
 * surface as a stack trace with no page named.
 *
 * So the two sit side by side in a plain z.union. A union tries its members in
 * order and keeps the first that parses: the discriminated union gets first
 * refusal and gives precise, per-layout errors for what it knows, and whatever
 * it turns down lands in UNKNOWN_BLOCK and reaches the renderer intact.
 *
 * Two consequences, both accepted deliberately:
 *
 *  - UNKNOWN_BLOCK is z.looseObject, not z.object. A plain object STRIPS the
 *    keys it does not declare, which would hand the renderer a layout name with
 *    no content behind it — nothing to draw, and nothing to describe in the
 *    warning that says so.
 *
 *  - A KNOWN layout whose fields are malformed also falls through to
 *    UNKNOWN_BLOCK rather than failing the build. That is what first-match-wins
 *    costs, and it is the right price here — but it means a block component
 *    must treat its own props as untrusted, exactly as if the layout were one
 *    it had never heard of.
 */
const PAGE_BLOCK = firstKnownLayout
  ? z.union([
      z.discriminatedUnion("__typename", [firstKnownLayout, ...otherKnownLayouts]),
      UNKNOWN_BLOCK,
    ])
  : UNKNOWN_BLOCK;

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
    // The ordered section list. Empty on every page until one is migrated,
    // and empty again the moment an editor clears the field.
    blocks: z.array(PAGE_BLOCK).default([]),
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
