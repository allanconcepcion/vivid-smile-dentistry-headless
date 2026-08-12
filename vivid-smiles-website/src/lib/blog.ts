/**
 * Central helpers for the blog collection.
 *
 * Every blog page (hub + post detail) goes through these functions so the
 * sort/filter/excerpt rules stay consistent. Drop a markdown file into
 * src/content/blog/ and it flows through automatically — see content.config.ts
 * for the frontmatter schema.
 */

import { getCollection, type CollectionEntry } from "astro:content";

export type BlogPost = CollectionEntry<"blog">;
export type BlogCategory = BlogPost["data"]["category"];

interface GetAllPostsOptions {
  /** Default true. Set false from the design-system route only. */
  includeDrafts?: boolean;
}

/**
 * Get every blog post, sorted newest-first.
 * Drafts are excluded unless includeDrafts is explicitly true.
 */
export async function getAllPosts(
  options: GetAllPostsOptions = {},
): Promise<BlogPost[]> {
  const all = await getCollection("blog");
  const filtered = options.includeDrafts ? all : all.filter((p) => !p.data.draft);
  return filtered.sort((a, b) => +b.data.date - +a.data.date);
}

/**
 * Distinct categories that actually have posts. Used by the hub to render
 * only the chip rail's live categories — empty ones are hidden so users
 * can't filter into a dead state.
 */
export function getCategories(posts: BlogPost[]): BlogCategory[] {
  const seen = new Set<BlogCategory>();
  for (const p of posts) seen.add(p.data.category);
  // Stable display order — match the enum order in content.config.ts.
  const order: BlogCategory[] = [
    "Dental Tips",
    "Cosmetic Dentistry",
    "Implant Dentistry",
    "General Dentistry",
    "Emergency Dentistry",
  ];
  return order.filter((c) => seen.has(c));
}

/**
 * Top-N posts from the same category as `current`, excluding `current` itself.
 * If fewer than N same-category posts exist, fills from other categories
 * (newest-first) so the section is never sparse on a slow-growing blog.
 */
export function getRelatedPosts(
  current: BlogPost,
  posts: BlogPost[],
  limit = 3,
): BlogPost[] {
  const others = posts.filter((p) => p.id !== current.id);
  const sameCat = others.filter((p) => p.data.category === current.data.category);
  if (sameCat.length >= limit) return sameCat.slice(0, limit);
  const filler = others
    .filter((p) => p.data.category !== current.data.category)
    .slice(0, limit - sameCat.length);
  return [...sameCat, ...filler];
}

/**
 * Previous + next post by date for the post template's pagination strip.
 * Both can be null when current is at an edge of the timeline.
 */
export function getPrevNext(
  current: BlogPost,
  posts: BlogPost[],
): { prev: BlogPost | null; next: BlogPost | null } {
  // `posts` is already sorted newest-first by getAllPosts.
  const idx = posts.findIndex((p) => p.id === current.id);
  if (idx === -1) return { prev: null, next: null };
  // Newest-first index means a *newer* post sits at a *lower* index.
  // "Prev" reads as "the older one" (chronologically prior to this article).
  const next = idx > 0 ? posts[idx - 1] : null;             // newer
  const prev = idx < posts.length - 1 ? posts[idx + 1] : null; // older
  return { prev, next };
}

/**
 * Reading-time estimate in whole minutes. Counts words in the raw markdown
 * body, strips image markdown so a gallery-heavy post doesn't underestimate,
 * and rounds at 200 wpm (the conventional silent-reading rate).
 */
export function readingTime(body: string): number {
  const stripped = body
    .replace(/!\[[^\]]*\]\([^)]*\)/g, "")     // image markdown
    .replace(/\[[^\]]*\]\([^)]*\)/g, "$1")    // link text, drop URL
    .replace(/[#*_`~]/g, " ");
  const words = stripped.trim().split(/\s+/).filter(Boolean).length;
  return Math.max(1, Math.round(words / 200));
}

/**
 * Format a post date for display. Locale "en-US" matches the rest of the site.
 */
export function formatDate(date: Date): string {
  return date.toLocaleDateString("en-US", {
    year: "numeric",
    month: "long",
    day: "numeric",
    timeZone: "UTC",
  });
}

/**
 * URL helper: hub with a category filter applied.
 * Encodes spaces so the link works both copy-pasted and clicked.
 */
export function categoryUrl(category: BlogCategory): string {
  return `/blog/?category=${encodeURIComponent(category)}`;
}

/**
 * URL helper: a post's canonical URL. Mirrors trailingSlash: "always" in astro.config.
 */
export function postUrl(post: BlogPost): string {
  return `/blog/${post.id}/`;
}
