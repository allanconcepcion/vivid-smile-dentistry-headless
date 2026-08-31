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
 * ─────────────────────────────────────────────────────────────────────────────
 * ALWAYS PUBLISH
 *
 * This loader never drops a post. Not for a missing hero, missing alt text, an
 * unexpected category, a missing date, or a missing title.
 *
 * It used to. Seven conditions each logged a warning and `continue`d, which
 * meant a post could read "Published" in wp-admin and simply not exist on the
 * website — no page, no sitemap entry (src/integrations/yoast-sitemap.ts drops
 * URLs it cannot match to a built page), and the only trace a line in a Vercel
 * build log. Nobody reads that log: cms/mu-plugins/vs-deploy.php fires the
 * deploy hook on transition_post_status, so the person who caused the drop is
 * an editor who never sees the build output at all.
 *
 * The rule now: the loader decides what a post RENDERS, not whether it exists.
 * Every degradation is (a) visible on the page — deliberately, so it reads as
 * unfinished rather than fine — and (b) logged as a warning naming the post and
 * the field, for the build log and for the wp-admin notice that mirrors these
 * same rules on the PHP side.
 *
 * What still fails the build, on purpose:
 *   - zero published posts, or an unreachable CMS (see src/lib/wp.ts) — a quiet
 *     empty blog is worse than a red build, because a red build leaves the
 *     previous deployment serving;
 *   - a post that fails the Zod schema. With the ladder gone that can only mean
 *     a bug in THIS file, not bad editor input, so it is left loud.
 * ─────────────────────────────────────────────────────────────────────────────
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
import { mediaFetchable } from "../lib/media-probe";
import { transform, walkSync, ELEMENT_NODE, renderSync } from "ultrahtml";
import sanitize from "ultrahtml/transformers/sanitize";
import GithubSlugger from "github-slugger";
// Public export (astro/package.json "exports": "./assets/utils"). Streams just
// enough of a remote image to read its header, then aborts — the same probe
// <Image inferSize> uses, which is how src/lib/wp-body-images.ts measures body
// images that carry no width/height in the markup.
import { inferRemoteSize } from "astro/assets/utils";
import { wpQueryAll, htmlToText, WordPressError } from "../lib/wp";

/**
 * The five categories the site is BUILT AROUND: seeded in
 * cms/mu-plugins/vs-content-model.php, enforced on save by vs-admin.php, and
 * the display order of the hub's chip rail (getCategories() in src/lib/blog.ts).
 *
 * This is a KNOWN list, not an allowed one. A post in some other category is
 * published under that category's real name — see resolveCategory().
 */
export const KNOWN_CATEGORIES = [
  "Dental Tips",
  "Cosmetic Dentistry",
  "Implant Dentistry",
  "General Dentistry",
  "Emergency Dentistry",
] as const;

export type KnownCategory = (typeof KNOWN_CATEGORIES)[number];

/**
 * Where a post with no category lands. Matches the WordPress default term in
 * cms/mu-plugins/vs-content-model.php, so the site and the CMS agree about
 * where an uncategorised post belongs.
 */
const DEFAULT_CATEGORY: KnownCategory = "Dental Tips";

/** Shown in place of a title the author never set. Ugly on purpose. */
const UNTITLED = "Untitled post";

/**
 * The stand-in hero: a 4:3 brand plate, inlined as a data: URI.
 *
 * WHY A DATA URI AND NOT A FILE
 *
 * BlogCard.astro renders <Image src={data.heroImage} width height> for every
 * post, unconditionally. It is a shared component with no "no image" branch, so
 * `heroImage` has to be a string that <Image> can always accept. Astro resolves
 * a string src against image.remotePatterns in astro.config.mjs; anything that
 * does not match is passed through verbatim and never fetched, downloaded or
 * transformed (astro/dist/assets/services/service.js getURL — `return
 * options.src`). A data: URI takes exactly that path: no network at build time,
 * no remotePatterns entry, no dependency on WordPress being reachable, and no
 * new binary asset to keep in sync with the design.
 *
 * WHY IT LOOKS LIKE THIS
 *
 * Cream ground on cream well (.bc-media is already background: var(--vs-cream))
 * with one sage-pale arc. It is deliberately quiet and obviously not a
 * photograph, so it can never misrepresent the article the way a stock image or
 * a promoted in-body image would — and its blankness is the nudge: paired with
 * the wp-admin warning naming the post, "add a hero image" becomes the path of
 * least resistance without ever costing the practice a publication.
 *
 * 4:3 matches .bc-media's aspect-ratio exactly, so object-fit: cover crops
 * nothing on the hub grid or the related-posts strip. Colours are the literal
 * values of --vs-cream and --vs-sage-pale in src/styles/tokens.css; an SVG in
 * an <img> cannot read CSS custom properties from the host page.
 */
const HERO_PLACEHOLDER_SVG =
  '<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="900" viewBox="0 0 1200 900">' +
  '<rect width="1200" height="900" fill="#fbf9f5"/>' +
  '<path d="M420 400q180 190 360 0" fill="none" stroke="#dee3d2" stroke-width="18" stroke-linecap="round"/>' +
  "</svg>";

export const HERO_PLACEHOLDER = `data:image/svg+xml,${encodeURIComponent(HERO_PLACEHOLDER_SVG)}`;
const HERO_PLACEHOLDER_WIDTH = 1200;
const HERO_PLACEHOLDER_HEIGHT = 900;

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
        modified
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
  modified: string | null;
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
 * One thing about a post that is not right, but is not fatal either.
 *
 * `code` is the shared vocabulary with the wp-admin notice computed in
 * cms/mu-plugins — same codes, same predicates, so the warning an author sees
 * on the edit screen and the warning in the build log describe one fact.
 * `text` names the field AND what the site does instead, because "missing hero
 * image" alone does not tell an author whether anything shipped.
 */
type Warning = { code: string; text: string };

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

/**
 * Normalise a WordPress timestamp to an unambiguous UTC ISO string, or null if
 * it cannot be parsed at all.
 *
 * WPGraphQL emits site-local timestamps with no zone designator ("2026-07-17T12:00:00"),
 * so `new Date()` reads them as the BUILD machine's local time and can shift the
 * displayed day. Pinning to UTC matches the importer.
 *
 * Two shapes come back in practice: full datetimes from `date`, and DATE-ONLY
 * strings from `contentUpdatedAt` ("2026-07-23" — three live posts today). Both
 * are handled; a date-only string is already UTC midnight under ISO 8601, so the
 * appended Z is a no-op there rather than a corruption.
 *
 * Returning null instead of a bad string is what stops a malformed timestamp
 * from reaching z.coerce.date(), producing an Invalid Date, and taking the post
 * down with it.
 */
function toUtcIso(wpDate: string | null | undefined): string | null {
  const raw = wpDate?.trim();
  if (!raw) return null;

  const iso = /(?:Z|[+-]\d{2}:?\d{2})$/i.test(raw) ? raw : `${raw}Z`;
  return Number.isNaN(new Date(iso).getTime()) ? null : iso;
}


/** The CMS origin, for repairing a relative sourceUrl. Mirrors wp-body-images.ts. */
function cmsOrigin(): string | null {
  const endpoint = import.meta.env.WP_GRAPHQL_ENDPOINT;
  if (!endpoint) return null;
  try {
    return new URL(endpoint).origin;
  } catch {
    return null;
  }
}

/**
 * Coerce whatever WordPress reports as the featured-image URL into an absolute
 * http(s) URL, or null.
 *
 * Media-offload plugins are the reason this is not just a pass-through: they
 * routinely emit a root-relative or protocol-relative sourceUrl, which used to
 * fail the schema's z.string().url() with a Zod error naming neither the post
 * nor the field.
 */
function absoluteHeroUrl(sourceUrl: string): string | null {
  const src = sourceUrl.trim();
  if (!src) return null;

  if (/^https?:\/\//i.test(src)) return src;
  if (src.startsWith("//")) return `https:${src}`;

  const origin = cmsOrigin();
  if (origin && src.startsWith("/")) return `${origin}${src}`;

  return null;
}

type ResolvedHero = {
  url: string;
  width: number;
  height: number;
  alt: string;
  /** True when `url` is HERO_PLACEHOLDER rather than an image the author chose. */
  placeholder: boolean;
};

const PLACEHOLDER_HERO: ResolvedHero = {
  url: HERO_PLACEHOLDER,
  width: HERO_PLACEHOLDER_WIDTH,
  height: HERO_PLACEHOLDER_HEIGHT,
  // Decorative, per the WAI convention: an unlabelled placeholder is correct,
  // narrating a filename or "placeholder image" to a screen reader is not. The
  // card's own link already carries the accessible name via aria-label.
  alt: "",
  placeholder: true,
};

/**
 * Decide what this post shows where its hero goes, and record why.
 *
 * Never throws and never returns a shape <Image> cannot render.
 */
async function resolveHero(node: PostNode, warn: (w: Warning) => void): Promise<ResolvedHero> {
  const raw = node.featuredImage?.node;
  const alt = node.postFields?.heroAlt?.trim() || raw?.altText?.trim() || "";

  const url = raw?.sourceUrl ? absoluteHeroUrl(raw.sourceUrl) : null;

  if (!url) {
    warn({
      code: raw?.sourceUrl ? "hero_url_unusable" : "no_hero",
      text: raw?.sourceUrl
        ? `featured image URL "${raw.sourceUrl}" is not an absolute address and could not be ` +
          `resolved against the CMS origin — showing the placeholder plate instead`
        : "no featured image set — the hub grid shows the placeholder plate, and link " +
          "previews fall back to the Vivid Smiles logo",
    });
    return PLACEHOLDER_HERO;
  }

  const declaredWidth = raw?.mediaDetails?.width ?? 0;
  const declaredHeight = raw?.mediaDetails?.height ?? 0;
  const hasDimensions =
    Number.isInteger(declaredWidth) && declaredWidth > 0 &&
    Number.isInteger(declaredHeight) && declaredHeight > 0;

  let width = declaredWidth;
  let height = declaredHeight;

  if (hasDimensions) {
    // Trust the media library, but confirm the file is still there — see
    // mediaFetchable() for why this one check is worth a request per post.
    if ((await mediaFetchable(url)) === "gone") {
      warn({
        code: "hero_missing_file",
        text:
          `featured image ${url} is no longer reachable (deleted from the media library, ` +
          "or moved) — showing the placeholder plate instead. Re-upload it and set it as " +
          "the featured image",
      });
      return PLACEHOLDER_HERO;
    }
  } else {
    // No width/height in the media library: an SVG, or an attachment whose
    // _wp_attachment_metadata is missing or still regenerating. Measure it —
    // which doubles as the existence check, since inferRemoteSize fetches.
    try {
      const measured = await inferRemoteSize(url);
      width = measured.width ?? 0;
      height = measured.height ?? 0;
    } catch {
      width = 0;
      height = 0;
    }

    // One warning, not two. Which one depends on whether measuring worked, so
    // neither ever promises something that did not happen.
    if (!(width > 0 && height > 0)) {
      warn({
        code: "hero_unmeasurable",
        text:
          `featured image ${url} has no width/height in the media library and could not be ` +
          "measured or fetched — showing the placeholder plate instead. Re-upload it as a " +
          "JPG, PNG or WebP and regenerate thumbnails",
      });
      return PLACEHOLDER_HERO;
    }

    warn({
      code: "hero_no_dimensions",
      text:
        `featured image has no width/height in the media library — the build measured it as ` +
        `${width}×${height} instead. Regenerate thumbnails to remove that round-trip`,
    });
  }

  if (!alt) {
    warn({
      code: "no_alt",
      text:
        "hero image has no alt text — it renders unlabelled for screen-reader users. " +
        'Fill in "Hero image alt text" on the post, or the Alternative Text field on the ' +
        "attachment in the media library",
    });
  }

  return { url, width, height, alt, placeholder: false };
}

/**
 * The category this post files under.
 *
 * An unexpected category is KEPT VERBATIM, never silently coerced into one of
 * the five. Coercing would reintroduce exactly the class of bug that
 * cms/mu-plugins/vs-admin.php exists to fix — a post quietly filed somewhere
 * its author did not choose. A category is a label, not a gate.
 *
 * The cost is visible and bounded: getCategories() in src/lib/blog.ts builds the
 * chip rail from KNOWN_CATEGORIES only, so an unexpected category gets no chip.
 * The post still appears on the hub under "All Articles" (blog-filter.ts only
 * ever hides cards that fail an ACTIVE filter, and an unknown ?category= value
 * falls back to "all"), but its category badge links to a filter that resets to
 * All. That is why the warning says so.
 */
function resolveCategory(node: PostNode, warn: (w: Warning) => void): string {
  const name = node.categories?.nodes?.[0]?.name?.trim();

  if (!name) {
    warn({
      code: "no_category",
      text: `no category assigned — filing this post under "${DEFAULT_CATEGORY}"`,
    });
    return DEFAULT_CATEGORY;
  }

  // Case-insensitive, matching the comparison vs-admin.php makes on save.
  const known = KNOWN_CATEGORIES.some((c) => c.toLowerCase() === name.toLowerCase());

  if (!known) {
    warn({
      code: "unknown_category",
      text:
        `category "${name}" is not one of the five the site is built around — the post ` +
        "publishes under that name, but the blog hub's filter rail has no chip for it, so " +
        "its category badge links to a filter that shows everything",
    });
  }

  return name;
}

export function blogLoader(): Loader {
  return {
    name: "wordpress-blog",

    async load({ store, logger, parseData }) {
      logger.info("Fetching posts from WordPress");

      const nodes = await wpQueryAll<PostNode>(POSTS_QUERY, (data) => data.posts, "posts");

      // The one drop that is still fatal. An empty array here is a broken query
      // or a CMS outage, not an editorial decision — and publishing a blog hub
      // with zero posts plus a sitemap that lost every URL reads as a
      // successful deploy. Failing leaves the previous deployment serving.
      if (nodes.length === 0) {
        throw new WordPressError(
          "WordPress returned 0 published posts. Expected at least one — refusing to " +
            "publish a blog with no content. Check that posts exist and are published.",
        );
      }

      store.clear();

      // Build time, resolved once, so every dateless post in a single build
      // shares one timestamp instead of drifting apart by milliseconds.
      const buildTime = new Date().toISOString();
      let postsWithWarnings = 0;

      for (const node of nodes) {
        const warnings: Warning[] = [];
        const warn = (w: Warning) => warnings.push(w);

        // ---------- title ----------
        let title = htmlToText(node.title ?? "").trim();
        if (!title) {
          title = UNTITLED;
          warn({
            code: "no_title",
            text: `no title — the post reads "${UNTITLED}" in its heading, its browser tab, the breadcrumb, the hub card and its structured data`,
          });
        }

        // ---------- date ----------
        // `date` is effectively always present for status: PUBLISH, so this is
        // defensive rather than expected. Falling back keeps the post sortable:
        // src/lib/blog.ts sorts on +data.date, and an Invalid Date there would
        // scramble the whole hub's ordering, not just this post's position.
        let date = toUtcIso(node.date);
        if (!date) {
          date = toUtcIso(node.modified) ?? toUtcIso(node.contentUpdatedAt) ?? buildTime;
          warn({
            code: "no_date",
            text:
              `no publish date (or an unreadable one) — the byline and the hub card show ` +
              `${date.slice(0, 10)} instead, and the post sorts to that position`,
          });
        }

        // Normalised through the same helper as `date`: passed raw, a zone-less
        // "2026-07-23T09:00:00" shifts by the build machine's UTC offset, and an
        // unparseable value would fail z.coerce.date() and drop the post.
        const updated = toUtcIso(node.contentUpdatedAt);

        // ---------- category / hero ----------
        const category = resolveCategory(node, warn);
        const hero = await resolveHero(node, warn);

        const { html, headings } = await processBody(node.content ?? "");

        // ---------- warn loudly, then publish anyway ----------
        if (warnings.length > 0) {
          postsWithWarnings++;
          logger.warn(
            `Post "${node.slug}" published with ${warnings.length} ` +
              `issue${warnings.length === 1 ? "" : "s"}:\n` +
              warnings.map((w) => `    • [${w.code}] ${w.text}`).join("\n"),
          );
        }

        // A failure here is a bug in this loader, not bad editor input — every
        // field above is constructed to satisfy the schema. Rethrow with the
        // slug attached, because a bare Zod error names neither post nor field.
        let data;
        try {
          data = await parseData({
            id: node.slug,
            data: {
              title,
              description: toDescription(node.excerpt ?? "", title),
              date,
              ...(updated ? { updated } : {}),
              author: node.postFields?.authorName?.trim() || node.author?.node?.name || "Slate",
              category,
              heroImage: hero.url,
              heroWidth: hero.width,
              heroHeight: hero.height,
              heroAlt: hero.alt,
              heroPlaceholder: hero.placeholder,
              draft: false,
            },
          });
        } catch (error) {
          throw new WordPressError(
            `Post "${node.slug}" failed the blog schema. Every field is meant to be ` +
              "degraded to something valid before it gets here, so this is a bug in " +
              "src/loaders/blog.ts or a schema change in src/content.config.ts that it " +
              "has not caught up with.",
            { cause: error },
          );
        }

        store.set({
          id: node.slug,
          data,
          // Plain text: readingTime() counts words and would otherwise count
          // every tag and attribute value in the markup.
          body: htmlToText(node.content ?? ""),
          rendered: { html, metadata: { headings } },
        });
      }

      // Unreachable while nodes.length > 0, since nothing is skipped any more.
      // Kept as the backstop for a future change that reintroduces a `continue`.
      if (store.keys().length === 0) {
        throw new WordPressError(
          `None of the ${nodes.length} posts WordPress returned made it into the store. ` +
            "Nothing in this loader skips a post, so this means one was added — see the " +
            "warnings above.",
        );
      }

      logger.info(`Loaded ${store.keys().length} posts`);
      if (postsWithWarnings > 0) {
        logger.warn(
          `${postsWithWarnings} of ${nodes.length} posts published with content warnings. ` +
            "Every one of them is live — nothing was dropped. Fix them in wp-admin → Posts.",
        );
      }
    },
  };
}
