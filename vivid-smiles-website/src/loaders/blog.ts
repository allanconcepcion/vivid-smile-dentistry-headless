/**
 * Content-layer loader: WordPress posts → the `blog` collection.
 *
 * Replaces the glob() loader that read src/content/blog/*.md. The entry shape
 * is preserved so src/lib/blog.ts, blog/[slug].astro, blog/index.astro,
 * BlogCard, RelatedPosts and BlogByline all keep working:
 *
 *   entry.id                          the WP slug — this IS the public URL
 *   entry.data                        title, description, date, updated, author,
 *                                     category, heroImage, heroAlt, draft
 *   entry.body                        plain text (reading-time estimate only)
 *   entry.rendered.html               the post body
 *   entry.rendered.metadata.headings  drives the sticky TOC
 *
 * Three things here are load-bearing and easy to get wrong:
 *
 * 1. `heroImage` is a URL STRING, not an Astro ImageMetadata object. The
 *    image() schema helper resolves paths against an entry's source file, and a
 *    remote entry has none — so it cannot work in a loader. `heroWidth` and
 *    `heroHeight` come through alongside it because <Image> requires explicit
 *    dimensions for a remote src (the alternative, inferSize, downloads every
 *    image at build just to measure it).
 *
 * 2. `body` is plain text, not the HTML. readingTime() in src/lib/blog.ts
 *    counts words; handing it markup inflates every estimate by counting tags
 *    and attribute values as words.
 *
 * 3. Headings must carry ids matching `rendered.metadata.headings[].slug`, or
 *    toc-spy.ts finds nothing and the sticky TOC silently dies. Posts imported
 *    from the original markdown already have ids (they were rendered with
 *    Astro's own processor); anything authored later in the WordPress block
 *    editor will not, so ids are generated here when missing.
 */

import type { Loader } from "astro/loaders";
import { transform, walkSync, ELEMENT_NODE, renderSync } from "ultrahtml";
import sanitize from "ultrahtml/transformers/sanitize";
import GithubSlugger from "github-slugger";
import { wpQueryAll, htmlToText, WordPressError } from "../lib/wp";

/** Mirrors the closed z.enum in content.config.ts. */
const ALLOWED_CATEGORIES = new Set([
  "Dental Tips",
  "Cosmetic Dentistry",
  "Implant Dentistry",
  "General Dentistry",
  "Emergency Dentistry",
]);

const POSTS_QUERY = /* GraphQL */ `
  query Posts($first: Int!, $after: String) {
    posts(first: $first, after: $after, where: { status: PUBLISH, orderby: { field: DATE, order: DESC } }) {
      pageInfo {
        hasNextPage
        endCursor
      }
      nodes {
        slug
        title
        date
        excerpt
        content
        contentUpdatedAt
        author {
          node {
            name
          }
        }
        categories {
          nodes {
            name
          }
        }
        postFields {
          heroAlt
          authorName
        }
        featuredImage {
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
`;

type PostNode = {
  slug: string;
  title: string | null;
  date: string | null;
  excerpt: string | null;
  content: string | null;
  contentUpdatedAt: string | null;
  author: { node: { name: string | null } | null } | null;
  categories: { nodes: Array<{ name: string }> } | null;
  postFields: { heroAlt: string | null; authorName: string | null } | null;
  featuredImage: {
    node: {
      sourceUrl: string | null;
      altText: string | null;
      mediaDetails: { width: number | null; height: number | null } | null;
    } | null;
  } | null;
};

export type Heading = { depth: number; slug: string; text: string };

/**
 * Sanitize the post body, then ensure every h2/h3 has a stable id and collect
 * the headings the TOC needs.
 *
 * Sanitizing matters because Astro's <Content /> injects rendered.html raw —
 * exactly like set:html, with no escaping. Anything a WordPress editor can save
 * would otherwise execute on the marketing site.
 */
async function processBody(html: string): Promise<{ html: string; headings: Heading[] }> {
  const clean = await transform(html, [
    sanitize({
      blockElements: ["script", "style", "iframe", "object", "embed", "form", "input", "button"],
      allowAttributes: {
        // Preserve the ids the markdown import already produced, so anchor
        // links shared before the migration keep resolving.
        id: ["h1", "h2", "h3", "h4", "h5", "h6", "div", "section"],
        href: ["a"],
        target: ["a"],
        rel: ["a"],
        src: ["img"],
        alt: ["img"],
        width: ["img"],
        height: ["img"],
        loading: ["img"],
        decoding: ["img"],
        class: ["*"],
        colspan: ["td", "th"],
        rowspan: ["td", "th"],
      },
    }),
  ]);

  const doc = (await import("ultrahtml")).parse(clean);
  const slugger = new GithubSlugger();
  const headings: Heading[] = [];

  walkSync(doc, (node) => {
    if (node.type !== ELEMENT_NODE) return;

    const match = /^h([1-6])$/.exec(node.name);
    if (!match) return;

    const depth = Number(match[1]);
    const text = textOf(node).trim();
    if (!text) return;

    // Respect an id the editor set explicitly (or that the markdown import
    // produced); only mint one when it is absent.
    let id = typeof node.attributes.id === "string" ? node.attributes.id : "";
    if (!id) {
      id = slugger.slug(text);
      node.attributes.id = id;
    } else {
      // Keep the slugger aware of ids already in use so a later duplicate
      // heading gets -1 rather than colliding.
      slugger.slug(id);
    }

    headings.push({ depth, slug: id, text });
  });

  return { html: renderSync(doc), headings };
}

function textOf(node: any): string {
  if (node.type === ELEMENT_NODE) {
    return (node.children ?? []).map(textOf).join("");
  }
  return typeof node.value === "string" ? node.value : "";
}

/** Strip tags/entities and clamp to the schema's 200-char ceiling. */
function toDescription(excerpt: string, title: string): string {
  const text = htmlToText(excerpt)
    // WordPress appends a "read more" ellipsis to auto-generated excerpts.
    .replace(/\s*\[[…\.]+\]\s*$/u, "")
    .replace(/\s+/g, " ")
    .trim();

  if (!text) return title.slice(0, 200);
  if (text.length <= 200) return text;

  // Cut on a word boundary so the meta description never ends mid-word.
  const clipped = text.slice(0, 199);
  const lastSpace = clipped.lastIndexOf(" ");
  return (lastSpace > 120 ? clipped.slice(0, lastSpace) : clipped).trimEnd() + "…";
}

export function blogLoader(): Loader {
  return {
    name: "wordpress-blog",

    async load({ store, logger, parseData }) {
      logger.info("Fetching posts from WordPress");

      const nodes = await wpQueryAll<PostNode>(POSTS_QUERY, (data) => data.posts, "posts");

      if (nodes.length === 0) {
        throw new WordPressError(
          "WordPress returned 0 published posts. Expected at least one — refusing to " +
            "publish a blog with no content. Check that posts exist and are published.",
        );
      }

      store.clear();

      let skipped = 0;

      for (const node of nodes) {
        const hero = node.featuredImage?.node;
        const category = node.categories?.nodes?.[0]?.name;
        const heroAlt = node.postFields?.heroAlt?.trim() || hero?.altText?.trim();

        // Every one of these is required by the Zod schema. Failing here names
        // the post and the field; letting it reach parseData produces a Zod
        // error that names neither.
        const problem =
          !node.title ? "missing title"
          : !node.date ? "missing date"
          : !category ? "no category assigned"
          : !ALLOWED_CATEGORIES.has(category)
            ? `category "${category}" is not one of the five the site supports`
          : !hero?.sourceUrl ? "no featured image set"
          : !hero.mediaDetails?.width || !hero.mediaDetails?.height
            ? "featured image has no width/height in the media library"
          : !heroAlt ? "missing hero alt text (set the Hero image alt text field)"
          : null;

        if (problem) {
          logger.warn(`Skipping post "${node.slug}": ${problem}`);
          skipped++;
          continue;
        }

        const { html, headings } = await processBody(node.content ?? "");

        const data = await parseData({
          id: node.slug,
          data: {
            title: htmlToText(node.title!),
            description: toDescription(node.excerpt ?? "", node.title!),
            // WPGraphQL emits site-local timestamps with no zone designator, so
            // Date() would read them as the BUILD machine's local time and can
            // shift the displayed day. Pin to UTC, matching the importer.
            date: `${node.date}Z`,
            ...(node.contentUpdatedAt ? { updated: node.contentUpdatedAt } : {}),
            author: node.postFields?.authorName?.trim() || node.author?.node?.name || "Slate",
            category,
            heroImage: hero!.sourceUrl,
            heroWidth: hero!.mediaDetails!.width,
            heroHeight: hero!.mediaDetails!.height,
            heroAlt,
            draft: false,
          },
        });

        store.set({
          id: node.slug,
          data,
          // Plain text: readingTime() counts words and would otherwise count
          // every tag and attribute value in the markup.
          body: htmlToText(node.content ?? ""),
          rendered: { html, metadata: { headings } },
        });
      }

      if (store.keys().length === 0) {
        throw new WordPressError(
          `All ${nodes.length} posts failed validation. See the warnings above.`,
        );
      }

      logger.info(`Loaded ${store.keys().length} posts` + (skipped ? ` (${skipped} skipped)` : ""));
    },
  };
}
