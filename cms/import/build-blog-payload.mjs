/**
 * Stage 1 of the blog migration: markdown → an import payload for WordPress.
 *
 *   node cms/import/build-blog-payload.mjs
 *
 * Reads vivid-smiles-website/src/content/blog/*.md and writes
 * cms/import/blog-payload.json, containing for each post its front matter, the
 * rendered HTML body, and the resolved on-disk path of every image it needs.
 *
 * Stage 2 (import-blog.php) consumes that file: it sideloads the images into
 * the media library, rewrites the image URLs in the HTML, and creates posts.
 *
 * Markdown is rendered with @astrojs/markdown-remark — Astro's OWN processor,
 * already a dependency of the site. Using anything else (marked, markdown-it)
 * would produce subtly different HTML from what the site renders today:
 * different smart-quote handling, different GFM table markup, different
 * heading-id slugs. Matching Astro exactly is the point, because these posts
 * currently render through this same processor.
 */

import { readFile, writeFile, readdir, access } from "node:fs/promises";
import { fileURLToPath, pathToFileURL } from "node:url";
import { createRequire } from "node:module";
import path from "node:path";

const HERE = path.dirname(fileURLToPath(import.meta.url));
const SITE = path.resolve(HERE, "../../vivid-smiles-website");
const BLOG_DIR = path.join(SITE, "src/content/blog");
const OUT = path.join(HERE, "blog-payload.json");

// This script lives in cms/ but the markdown processor is a dependency of the
// Astro site next door. Node resolves bare specifiers from the importing
// FILE's location, not the cwd, so a plain import fails here regardless of
// where the script is run from. Resolve against the site's package.json
// instead — that also guarantees we use the exact version the site builds with.
const siteRequire = createRequire(path.join(SITE, "package.json"));
const { createMarkdownProcessor } = await import(
  pathToFileURL(siteRequire.resolve("@astrojs/markdown-remark")).href
);

/** Mirrors the closed z.enum in src/content.config.ts. */
const CATEGORIES = new Set([
  "Dental Tips",
  "Cosmetic Dentistry",
  "Implant Dentistry",
  "General Dentistry",
  "Emergency Dentistry",
]);

/**
 * Parse the simple `key: value` front matter these files use. Deliberately not
 * a general YAML parser — see the sibling lib-frontmatter.php for the same
 * reasoning. Anything unexpected should surface as a missing required field
 * rather than be silently half-parsed.
 */
function parseFrontMatter(raw, file) {
  const normalized = raw.replace(/^﻿/, "").replace(/\r\n?/g, "\n");

  if (!normalized.startsWith("---\n")) {
    throw new Error(`${file}: no opening front-matter fence`);
  }

  const end = normalized.indexOf("\n---\n", 3);
  if (end === -1) throw new Error(`${file}: no closing front-matter fence`);

  const front = normalized.slice(4, end + 1);
  const body = normalized.slice(end + 5).trim();

  const data = {};
  for (const line of front.split("\n")) {
    const match = /^([A-Za-z_][A-Za-z0-9_]*):\s*(.*)$/.exec(line);
    if (!match) continue;

    let value = match[2].trim();
    if (
      value.length >= 2 &&
      ((value.startsWith('"') && value.endsWith('"')) ||
        (value.startsWith("'") && value.endsWith("'")))
    ) {
      value = value.slice(1, -1).replace(/\\"/g, '"');
    }
    data[match[1]] = value;
  }

  return { data, body };
}

/**
 * Resolve an image reference from a post into an absolute path on disk.
 *
 * Front matter and body both use paths relative to the markdown file, e.g.
 * "../../assets/images/blog/<slug>/hero.webp".
 */
const ASSETS_DIR = path.join(SITE, "src/assets");

async function resolveImage(ref, mdFile) {
  if (/^https?:\/\//i.test(ref)) return null; // already remote; leave alone

  const abs = path.resolve(path.dirname(mdFile), ref);
  try {
    await access(abs);
  } catch {
    throw new Error(`${path.basename(mdFile)}: image not found on disk: ${ref}`);
  }

  // The PHP importer runs INSIDE the wp-env container, where these host paths
  // do not exist. cms/.wp-env.json maps src/assets to wp-content/vs-import/assets,
  // so emit the path relative to src/assets and let PHP prefix the container
  // location. An absolute host path here would fail every sideload.
  const rel = path.relative(ASSETS_DIR, abs);
  if (rel.startsWith("..")) {
    throw new Error(
      `${path.basename(mdFile)}: image lives outside src/assets and is not reachable ` +
        `from the container: ${ref}`,
    );
  }

  return { abs, rel };
}

const processor = await createMarkdownProcessor({
  gfm: true,
  smartypants: true,
});

const files = (await readdir(BLOG_DIR)).filter((f) => f.endsWith(".md")).sort();
const posts = [];
const problems = [];

for (const file of files) {
  const full = path.join(BLOG_DIR, file);
  const slug = file.replace(/\.md$/, "");

  try {
    const { data, body } = parseFrontMatter(await readFile(full, "utf8"), file);

    for (const key of ["title", "description", "date", "category", "heroImage", "heroAlt"]) {
      if (!data[key]) throw new Error(`missing required front-matter key '${key}'`);
    }

    if (!CATEGORIES.has(data.category)) {
      throw new Error(
        `category "${data.category}" is not one of the five allowed values`,
      );
    }

    // description feeds the Astro schema's z.string().max(200) and the meta
    // description. Catch overruns here rather than at build time.
    if (data.description.length > 200) {
      throw new Error(`description is ${data.description.length} chars (max 200)`);
    }

    const rendered = await processor.render(body);
    const html = rendered.code;

    // Collect every image the post needs: the hero, plus each <img> the
    // renderer emitted from markdown image syntax.
    const images = [];
    const hero = await resolveImage(data.heroImage, full);
    if (hero) images.push({ role: "hero", ref: data.heroImage, rel: hero.rel });

    for (const match of html.matchAll(/<img[^>]+src="([^"]+)"/g)) {
      const ref = match[1];
      const resolved = await resolveImage(ref, full);
      if (resolved) images.push({ role: "body", ref, rel: resolved.rel });
    }

    posts.push({
      slug,
      title: data.title,
      description: data.description,
      date: data.date,
      updated: data.updated ?? null,
      author: data.author ?? "Slate",
      category: data.category,
      heroAlt: data.heroAlt,
      draft: data.draft === "true",
      html,
      headings: rendered.metadata.headings.map((h) => ({
        depth: h.depth,
        slug: h.slug,
        text: h.text,
      })),
      images,
    });
  } catch (error) {
    problems.push(`  ${slug}: ${error.message}`);
  }
}

if (problems.length) {
  console.error(`\n${problems.length} post(s) failed:`);
  console.error(problems.join("\n"));
  process.exit(1);
}

await writeFile(OUT, JSON.stringify({ posts }, null, 2));

const imageCount = posts.reduce((n, p) => n + p.images.length, 0);
const unique = new Set(posts.flatMap((p) => p.images.map((i) => i.rel))).size;

console.log(`Wrote ${path.relative(process.cwd(), OUT)}`);
console.log(`  posts:          ${posts.length}`);
console.log(`  drafts:         ${posts.filter((p) => p.draft).length}`);
console.log(`  image refs:     ${imageCount} (${unique} unique files)`);
console.log(`  categories:     ${[...new Set(posts.map((p) => p.category))].join(", ")}`);
