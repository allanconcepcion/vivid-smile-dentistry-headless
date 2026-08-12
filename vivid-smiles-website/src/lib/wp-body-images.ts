/**
 * Route images inside WordPress post bodies through Astro's image pipeline.
 *
 * Astro optimizes images it can see in a template — but a post body is an
 * opaque HTML string injected raw by <Content /> (or set:html), so the pipeline
 * never sees the <img> tags inside it. Featured images get optimized because
 * they are passed to <Image> in the template; body images silently do not.
 *
 * Left alone, that means every in-body image on the blog:
 *   - is served from the CMS host, so the "static" site has a runtime
 *     dependency on WordPress being up and fast,
 *   - ships at whatever size and format the editor uploaded — no webp, no
 *     srcset, no responsive variants,
 *   - misses the immutable cache headers that /_assets/* gets in public/_headers.
 *
 * This rewrites them at BUILD time: each image is downloaded, re-encoded by
 * sharp, and replaced with a hashed same-origin URL plus a srcset.
 */

import { getImage } from "astro:assets";

/** Widths to emit for in-body images — narrower than heroes; these sit in prose. */
const BODY_WIDTHS = [480, 768, 1024, 1440];

/** Fallback width when an image's real dimensions cannot be determined. */
const FALLBACK_WIDTH = 1024;

/**
 * Hosts whose images may be downloaded and optimized.
 *
 * Derived from the configured endpoint so local and production builds each
 * rewrite their own CMS without extra config. Must stay in sync with
 * `image.remotePatterns` in astro.config.mjs — a host allowed here but not
 * there will fail the build rather than silently pass through.
 */
function cmsOrigin(): string | null {
  const endpoint = import.meta.env.WP_GRAPHQL_ENDPOINT;
  if (!endpoint) return null;

  try {
    return new URL(endpoint).origin;
  } catch {
    return null;
  }
}

function attr(tag: string, name: string): string | null {
  const match = new RegExp(`\\b${name}="([^"]*)"`, "i").exec(tag);
  return match ? match[1] : null;
}

/**
 * Rewrite every CMS-hosted <img> in `html` to an optimized local asset.
 *
 * A failure on one image is logged and left as-is rather than failing the
 * build: a single broken image URL in one old post should not block publishing
 * the whole site.
 */
export async function optimizeBodyImages(html: string): Promise<string> {
  const origin = cmsOrigin();
  if (!origin || !html.includes("<img")) return html;

  const tags = html.match(/<img\b[^>]*>/gi);
  if (!tags) return html;

  // Same image can appear more than once; only transform each src once.
  const rewrites = new Map<string, string>();

  for (const tag of tags) {
    const src = attr(tag, "src");
    if (!src || !src.startsWith(origin) || rewrites.has(tag)) continue;

    // Prefer the dimensions WordPress wrote into the markup. inferSize is the
    // fallback, and it costs an extra network round-trip per image at build
    // just to read the header — correct, but avoid it when we can.
    const width = Number(attr(tag, "width")) || 0;
    const height = Number(attr(tag, "height")) || 0;
    const hasDimensions = width > 0 && height > 0;

    try {
      const optimized = await getImage({
        src,
        format: "webp",
        widths: BODY_WIDTHS,
        sizes: "(max-width: 768px) 100vw, 768px",
        ...(hasDimensions
          ? { width, height }
          : { inferSize: true, width: FALLBACK_WIDTH }),
      });

      const alt = attr(tag, "alt") ?? "";
      const parts = [
        `<img src="${optimized.src}"`,
        `alt="${alt}"`,
        optimized.srcSet?.attribute ? `srcset="${optimized.srcSet.attribute}"` : "",
        `sizes="(max-width: 768px) 100vw, 768px"`,
        optimized.attributes?.width ? `width="${optimized.attributes.width}"` : "",
        optimized.attributes?.height ? `height="${optimized.attributes.height}"` : "",
        `loading="lazy"`,
        `decoding="async"`,
      ].filter(Boolean);

      rewrites.set(tag, parts.join(" ") + ">");
    } catch (error) {
      console.warn(
        `[wp-body-images] Left ${src} unoptimized: ${(error as Error).message}`,
      );
    }
  }

  let out = html;
  for (const [from, to] of rewrites) {
    out = out.split(from).join(to);
  }

  return out;
}
