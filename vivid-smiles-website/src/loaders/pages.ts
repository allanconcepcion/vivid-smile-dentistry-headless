/**
 * Content-layer loader: WordPress pages → the `pages` collection.
 *
 * Unlike `blog` and `reviews`, this collection does not generate routes. The 35
 * routes under src/pages stay exactly as they are — hand-built Astro templates
 * with bespoke layouts. This collection only supplies the *content* those
 * templates render: the table of contents, process steps and FAQ entries that
 * used to live as arrays in each page's frontmatter.
 *
 * Entries are keyed by ROUTE (e.g. "/cosmetic-dentistry/porcelain-veneers/"),
 * which is WordPress's own `uri` for the page, so a template looks up its own
 * content with getPageContent(Astro.url.pathname).
 *
 * Two shapes of BODY content live side by side here, and every page uses
 * exactly one of them. The six repeaters (sections, cards, images, faqs,
 * processSteps, tocLinks) are what all 33 pages use today: WordPress supplies
 * the words, the template owns the order. `blocks` is the ordered
 * flexible-content field that replaces them one page at a time — an empty
 * `blocks` means "this page has not been migrated", which is the whole rollback
 * story (docs/PAGE-BLOCKS.md 2.3). Both are read on every build; nothing here
 * decides which one renders.
 *
 * `hero` sits outside that choice. It is the band above both of them, and a
 * page has exactly one — which is why the CMS models it as a group and not as
 * a section an editor can add twice or delete (vs-content-model.php:1444). It
 * is read on every build for every page, migrated or not, and every field in
 * it is an override: blank means "the template keeps what it has".
 *
 * `blocks` is also the one field this loader cannot simply ask for — see
 * cmsSupportsBlocks below.
 *
 * Failure policy for images: a picture the build cannot place fails the build,
 * it is never dropped. Dropping it would remove a photo from a live page with
 * nothing to show for it — and where a template does need that slot, the drop
 * only relocates the same failure to a message about a missing page, which
 * sends the editor to fix something that was never broken. So the loader reads
 * every page, collects every unusable picture, and reports the lot at once,
 * naming the page, the slot, the file and the fix. A failed build costs a
 * deploy, not an outage: the previous deployment carries on serving.
 */

import type { Loader, LoaderContext } from "astro/loaders";
import { blockSelectionSet, hasRegisteredBlocks, type BlockNode } from "../blocks/manifest";
import { wpQuery, wpQueryAll, WordPressError } from "../lib/wp";

/**
 * The `blocks` selection, indented to sit inside `pageFields { … }`.
 *
 * Assembled by src/blocks/manifest.ts rather than written here. That file is
 * the one place a layout's GraphQL type, its selection set and its component
 * are bound together, so they cannot drift (docs/PAGE-BLOCKS.md 2.1); a loader
 * holding its own copy of the field list would be the drift. It holds no
 * layouts in this phase, which yields `blocks { __typename }` — a well-formed
 * query for a field nothing renders yet.
 *
 * The registry offers `hasRegisteredBlocks` so a caller can leave `blocks` out
 * of the query entirely until a layout exists. Not taken, deliberately: it
 * would mean the capability check below never runs until the day the pilot
 * page is migrated, and the whole point of shipping this phase inert is that
 * the machinery is exercised on every build before anything depends on it. The
 * build log says which side of the transition it is on from today.
 *
 * A wrong field name in the registry is not a local mistake. GraphQL validates
 * a document before executing any of it, so a fragment naming a field its
 * layout does not have fails every page at once — which the probe below reads
 * as "no blocks" and turns into a site-wide fallback to the existing content.
 * That is the safe direction to fail in, but it is a quiet one: the log line is
 * the only thing that says so.
 *
 * The same string goes into the probe and into the real query, because the
 * probe's only job is to find out whether the server will accept exactly what
 * the real query is about to send.
 */
const BLOCKS_SELECTION = blockSelectionSet("          ");

/**
 * The `hero` selection, indented to sit inside `pageFields { … }`.
 *
 * Gated on the same probe answer as BLOCKS_SELECTION, and for a structural
 * reason rather than a stylistic one: `hero` and `blocks` are siblings in one
 * 'fields' array of one acf_add_local_field_group() call
 * (cms/mu-plugins/vs-content-model.php:1070 -> 1094; the hero group at :1444,
 * the sections tab at :1558). There is no deployment that has one and not the
 * other, so one probe answers for both — and a CMS predating the mu-plugin
 * omits both, which is the same safe fallback the blocks docblock describes.
 *
 * MEASURED against the live CMS while writing this, over /our-office/:
 *
 *   HTTP 200
 *   hero: { eyebrow: null, h1: null, sub: null, ctas: null, ratings: false }
 *
 * Two things worth keeping from that. The schema really does carry all five,
 * so the selection below is valid today. And `ratings` answers `false` on a
 * page nobody has touched, because the field declares no default_value and
 * says why at vs-content-model.php:1509-1516 — indistinguishable from a
 * deliberate "off". Nothing may read it until a headline exists; that rule
 * lives once, in src/lib/page-content.ts, so 25 templates cannot each get it
 * wrong. `ctas` comes back null rather than [], which is why the assembly
 * below coalesces before it filters.
 *
 * `image`, `imageAlt` and `mediaShape` are deliberately NOT selected. A field
 * this loader fetches and no template reads is this project's most-repeated
 * defect — commit 07c027d shipped a card_grid `5` column choice whose class
 * was never emitted, and the band drew two across. The hero photo is not
 * missing by leaving `image` out: 26 of the 33 heroes already take it from the
 * `images` repeater through page-content.ts's image(slot), so a second control
 * for one photo is worse than none — and image() throws on a missing slot
 * (page-content.ts:262-268), which would put a live build dependency on a row
 * nobody maintains.
 */
const HERO_SELECTION = `          hero {
            eyebrow
            h1
            sub
            ctas {
              label
              href
            }
            ratings
          }`;

function pagesQuery(includeBlocks: boolean): string {
  return /* GraphQL */ `
  query Pages($first: Int!, $after: String) {
    pages(first: $first, after: $after, where: { status: PUBLISH }) {
      pageInfo {
        hasNextPage
        endCursor
      }
      nodes {
        vsRoute
        uri
        slug
        title
        vsSeo {
          title
          description
          canonical
          noindex
          ogImage
        }
        pageFields {
${includeBlocks ? BLOCKS_SELECTION : ""}
${includeBlocks ? HERO_SELECTION : ""}
          tocLinks {
            label
            anchor
          }
          processSteps {
            tag
            title
            body
          }
          faqs {
            question
            answer
            open
          }
          sections {
            sectionId
            eyebrow
            heading
            body
            ctaLabel
            ctaHref
          }
          cards {
            group
            title
            body
            meta
            href
          }
          images {
            slot
            alt
            image {
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
    }
  }
`;
}

/**
 * The probe's answer, kept for the life of the process.
 *
 * The loader runs once per build, so this exists for a dev server that reloads
 * the collection repeatedly: re-asking a rate-limited CMS the same settled
 * question is a good way to earn a 429 on the query that matters. Only a real
 * answer is remembered — a transport failure leaves it unset (see below).
 * A dev server started before the mu-plugin was deployed needs a restart.
 */
let blocksSupport: boolean | undefined;

/**
 * Ask the CMS whether its schema carries `blocks` and `hero` yet, and never
 * fail doing so.
 *
 * One probe for both, because they cannot arrive separately: they are siblings
 * in one field group (see HERO_SELECTION above). Sending both is the invariant
 * BLOCKS_SELECTION's docblock already states — the same string goes into the
 * probe and into the real query. Asking for `hero` in the query alone would
 * break it: a CMS predating the mu-plugin would pass the probe and then fail
 * every page, which is the one failure this whole function exists to prevent.
 *
 * The mu-plugin that adds the field is hand-deployed to the host (see
 * cms/bin/deploy-mu-plugins.sh); this code ships on the next build. The two
 * events are days or weeks apart and nobody coordinates them, so for an unknown
 * stretch the front end is querying a WordPress that has never heard of the
 * field. GraphQL offers no way to ask for a field "if it exists": naming one
 * the schema lacks is a validation error that kills the whole document before a
 * row is read. Verified against the live CMS while writing this:
 *
 *   { pages(first: 1) { nodes { pageFields { blocks { __typename } } } } }
 *   -> HTTP 200, no `data`, errors: [{ message:
 *      'Cannot query field "blocks" on type "PageFields".' }]
 *
 * Introspection cannot answer the question either — re-verified rather than
 * assumed: `{ __schema { queryType { name } } }` comes back "GraphQL
 * introspection is not allowed for public requests by default", and src/lib/wp.ts
 * sends no credentials, so the build is one of those public requests.
 *
 * So the build asks the cheapest question there is. It sends the selection set
 * the real query is about to use, over a single page, and looks at whether the
 * server accepted it. Validation runs before execution, so a schema without the
 * field rejects this having touched no data at all. Cost: one POST, ~0.4s and
 * 272 bytes measured against the hosted CMS, on a build that takes ~3 minutes.
 *
 * ANY failure means "no blocks". The probe is not allowed to have an opinion
 * about why it failed, because every reason lands in the same safe place — a
 * build that renders all 33 pages exactly the way it renders them today. The
 * message is read only to choose between an ordinary log line and a warning, so
 * if a future WPGraphQL release rewords it the log tone changes and the
 * behaviour does not.
 *
 * What this does NOT cover: a `blocks` row naming a layout the PHP no longer
 * registers. That resolves at execution, not validation, so the probe cannot
 * see it and the build fails. Only a developer deleting a layout can cause it —
 * an editor cannot author a row for a layout that is not offered — which is why
 * it is left to fail loudly instead of being guessed around.
 */
async function cmsSupportsBlocks(logger: LoaderContext["logger"]): Promise<boolean> {
  if (blocksSupport !== undefined) return blocksSupport;

  const query = /* GraphQL */ `
  query PageBlocksProbe {
    pages(first: 1) {
      nodes {
        pageFields {
${BLOCKS_SELECTION}
${HERO_SELECTION}
        }
      }
    }
  }
`;

  try {
    await wpQuery(query, {}, "page-blocks probe");
    logger.info("Page sections: available in WordPress.");
    blocksSupport = true;
    return blocksSupport;
  } catch (error) {
    const detail = error instanceof Error ? error.message : String(error);

    // graphql-php's own wording for a field that is not on the type. Its only
    // consequence is which of the two messages below gets logged.
    // "Cannot query field \"blocks\"" — or \"hero\", the other field this probe
    // sends — means the mu-plugin is not deployed yet.
    // "Cannot query field \"tag\" on type \"PageFieldsBlocksCards\"" means the
    // mu-plugin IS deployed and one of OUR fragments asks for something that
    // does not exist — a typo, or two layouts colliding on a repeater name so
    // that one of them silently lost its sub-fields.
    //
    // graphql-php words both the same way, and treating the second as the first
    // is how the whole feature switches itself off and reports a friendly
    // message: that is exactly what happened the first time this ran against a
    // deployed host. Only an error naming one of the two top-level page fields
    // is the benign one — a wrong sub-field of `hero` (say `subhead` for `sub`)
    // is our mistake and must fail loudly, exactly as a wrong block sub-field
    // does.
    const missingPageFields = /Cannot query field ["'](blocks|hero)["']/i.test(detail);

    if (!missingPageFields && /Cannot query field/i.test(detail)) {
      throw new Error(
        "WordPress has the page-content fields, but this build asked it for something " +
          "it does not have. That is a mismatch between what this build selects and " +
          "what cms/mu-plugins/vs-content-model.php declares. The message below names " +
          "the field; the two places that could have asked for it are the layout " +
          "selection sets in src/blocks/manifest.ts — most often two layouts sharing a " +
          "repeater name, which makes WPGraphQL merge their types and drop one side's " +
          "fields — and HERO_SELECTION at the top of this file.\n\n" +
          detail,
      );
    }

    if (missingPageFields) {
      logger.info(
        "Page sections: not in WordPress yet, so every page is built from its " +
          "existing content. Run cms/bin/deploy-mu-plugins.sh to add them.",
      );
      blocksSupport = false;
      return blocksSupport;
    }

    // Everything else is "I could not ask", which is a different answer from
    // "the field is not there" and stops being harmless the moment any page is
    // actually migrated. Omitting `blocks` after a 429 or a timeout would build
    // every migrated page from markup its editor has already replaced, and
    // report success — a whole-site content regression caused by a network
    // blip, with nothing in the log an editor would ever see.
    //
    // Which of those two worlds we are in is not a flag anyone has to remember:
    // an empty registry means no page CAN be migrated, so guessing "no blocks"
    // is provably safe. A non-empty one means it is not, and a failed build is
    // the better outcome — the previous deployment keeps serving, unchanged,
    // instead of being replaced by a wrong one.
    if (hasRegisteredBlocks) {
      throw new Error(
        "Could not determine whether WordPress has page sections, and this build " +
          "renders sections on at least one page. Refusing to build every page from " +
          "its pre-migration markup on a guess. " +
          `Reason: ${detail}`,
      );
    }

    // Not remembered: this is an accident, not an answer, and the next build
    // (or the next dev-server sync) should ask again rather than inherit it.
    logger.warn(
      "Could not tell whether WordPress has page sections yet, so this build leaves " +
        "them out and every page is built from its existing content. No page renders " +
        `sections yet, so the site is unaffected. Reason: ${detail}`,
    );
    return false;
  }
}

/**
 * Rows are carried in the registry's own BlockNode shape — `__typename` plus
 * whatever else the selection set asked for.
 *
 * Deliberately open. If this loader named the fields of each layout, a layout
 * it had not been taught would arrive stripped to nothing, and the whole point
 * of the unknown-layout path (docs/PAGE-BLOCKS.md 2.4) is that the renderer can
 * name what it could not draw. Whatever the query asked for is carried through
 * verbatim; the permissive Zod member in src/content.config.ts keeps it that
 * way through validation.
 */

/**
 * Rows worth handing to the schema.
 *
 * A row that is not an object with a layout name cannot be rendered by anything
 * and would fail validation for the entire page, taking the build down over a
 * single malformed row. Editors trigger these builds and never see the output
 * (cms/mu-plugins/vs-deploy.php), so it is skipped and reported instead.
 */
function usableBlocks(
  rows: Array<BlockNode | null> | null | undefined,
  where: string,
  logger: LoaderContext["logger"],
): BlockNode[] {
  const usable: BlockNode[] = [];

  (rows ?? []).forEach((row, i) => {
    if (row && typeof row === "object" && typeof row.__typename === "string" && row.__typename) {
      usable.push(row);
      return;
    }
    logger.warn(`${where}: section ${i + 1} arrived without a layout name — skipping it.`);
  });

  return usable;
}

type PageNode = {
  /** Canonical Astro route from the importer — see vs-content-model.php. */
  vsRoute: string | null;
  uri: string | null;
  slug: string;
  title: string | null;
  vsSeo: {
    title: string | null;
    description: string | null;
    canonical: string | null;
    noindex: boolean | null;
    ogImage: string | null;
  } | null;
  pageFields: {
    /**
     * Optional rather than nullable, and the difference matters: it is absent
     * from the response entirely on a CMS whose schema predates the field, and
     * null on one that has it but where the page has no sections.
     */
    blocks?: Array<BlockNode | null> | null;
    /**
     * Optional for the same reason `blocks` is, and absent under exactly the
     * same condition — the two are queried together or not at all.
     *
     * Every member is nullable inside it because ACF answers an untouched
     * group with nulls, measured: /our-office/ returns
     * { eyebrow: null, h1: null, sub: null, ctas: null, ratings: false }.
     * `ctas` is null, not [], so it is coalesced before it is filtered.
     */
    hero?: {
      eyebrow: string | null;
      h1: string | null;
      sub: string | null;
      ctas: Array<{ label: string | null; href: string | null }> | null;
      ratings: boolean | null;
    } | null;
    tocLinks: Array<{ label: string | null; anchor: string | null }> | null;
    processSteps: Array<{ tag: string | null; title: string | null; body: string | null }> | null;
    faqs: Array<{ question: string | null; answer: string | null; open: boolean | null }> | null;
    sections: Array<{
      sectionId: string | null;
      eyebrow: string | null;
      heading: string | null;
      body: string | null;
      ctaLabel: string | null;
      ctaHref: string | null;
    }> | null;
    cards: Array<{
      group: string | null;
      title: string | null;
      body: string | null;
      meta: string | null;
      href: string | null;
    }> | null;
    images: Array<{
      slot: string | null;
      alt: string | null;
      image: {
        node: {
          sourceUrl: string | null;
          altText: string | null;
          mediaDetails: { width: number | null; height: number | null } | null;
        } | null;
      } | null;
    }> | null;
  } | null;
};

/**
 * The file's name as it appears in the Media Library.
 *
 * A sourceUrl is a wp-content path no editor has ever looked at. What they can
 * recognise on sight — and paste into the Media Library search box — is the
 * file name, so that is what an error names.
 */
function mediaFileName(url: string): string {
  try {
    return decodeURIComponent(new URL(url).pathname.split("/").pop() || url);
  } catch {
    // sourceUrl is not guaranteed to be absolute (an offload plugin can emit a
    // relative path). If it will not parse, the raw value still beats nothing.
    return url;
  }
}

/**
 * Why a media item has no dimensions, and what the editor should do about it.
 *
 * WordPress stores intrinsic width and height when it generates an
 * attachment's metadata. Two different things stop that happening and they need
 * opposite fixes, so a single generic message would send half of the people
 * reading it after the wrong problem.
 *
 * SVG is the case worth spelling out, even though the Images repeater now
 * restricts itself to webp/jpg/jpeg/png the way the smile gallery always has
 * (see cms/mu-plugins/vs-content-model.php). That restriction filters the media
 * picker; it does not audit rows chosen before it existed, and it does not
 * follow the file if someone replaces the attachment behind an existing one. A
 * logo saved as SVG looks perfectly correct in the preview thumbnail and carries
 * no pixel size at all, so the case stays worth naming.
 */
function whyNoDimensions(url: string): string {
  if (mediaFileName(url).toLowerCase().endsWith(".svg")) {
    return (
      "SVG pictures have no fixed pixel size, so WordPress records none. " +
      "Save this one as JPG, PNG or WebP and upload that version instead."
    );
  }

  return (
    "WordPress recorded no size for this file — usually an upload that did not " +
    "finish, or a file that has since been moved on the server. Upload it again."
  );
}

export function pagesLoader(): Loader {
  return {
    name: "wordpress-pages",

    async load({ store, logger, parseData }) {
      logger.info("Fetching pages from WordPress");

      const includeBlocks = await cmsSupportsBlocks(logger);

      const nodes = await wpQueryAll<PageNode>(
        pagesQuery(includeBlocks),
        (data) => data.pages,
        "pages",
      );

      if (nodes.length === 0) {
        throw new WordPressError(
          "WordPress returned 0 published pages. Expected 31. " +
            "Run `npm run import:pages` in cms/ to populate them.",
        );
      }

      store.clear();

      // Problems are gathered across every page and reported together at the
      // end rather than thrown on sight. An editor who uploaded four unusable
      // pictures should learn about four of them in one go; throwing on the
      // first costs four build cycles to discover four faults, against a CMS
      // that rate-limits and a build that is not quick.
      const badImages: string[] = [];
      const badPages: string[] = [];
      let imageCount = 0;

      for (const node of nodes) {
        // Prefer the route the importer recorded. WordPress's own uri is wrong
        // for the home page ("/home/" vs "/"), and it moves whenever a slug or
        // parent changes — which would quietly detach content from its page.
        const route = node.vsRoute ?? node.uri;

        if (!route) {
          logger.warn(`Skipping page "${node.slug}": no route or uri`);
          continue;
        }

        const f = node.pageFields;

        // Named the way an editor would name it, with the route to disambiguate
        // two pages that share a title.
        const pageName = node.title?.trim() || node.slug;
        const where = `${pageName} (${route})`;

        const images: Array<{
          slot: string;
          url: string;
          width: number;
          height: number;
          alt: string;
        }> = [];

        for (const row of f?.images ?? []) {
          const media = row.image?.node;

          // ACF marks the slot required and readonly but leaves the picture
          // itself optional, so a row with nothing chosen is a half-finished
          // edit rather than a fault — warn and carry on. Nothing disappears
          // quietly by doing so: if a template actually needs that slot,
          // image() in src/lib/page-content.ts already fails with a message
          // naming the slot and the page.
          if (!row.slot || !media?.sourceUrl) {
            const which = row.slot ? `slot "${row.slot}"` : "a row with no slot";
            logger.warn(`${where}: ${which} has no picture chosen — nothing to load.`);
            continue;
          }

          imageCount++;

          const width = media.mediaDetails?.width ?? 0;
          const height = media.mediaDetails?.height ?? 0;

          // This is the case that used to take the whole deploy down. The old
          // code defaulted a missing dimension to 0, which the schema rejects
          // as not a positive integer — producing a Zod error that named the
          // field but neither the page nor the file, for a reader whose only
          // sight of it was a Vercel build log. Catch it here, where the page,
          // the slot and the file name are all still in hand.
          if (width < 1 || height < 1) {
            badImages.push(
              `  ${where}\n` +
                `    slot "${row.slot}" -> ${mediaFileName(media.sourceUrl)}\n` +
                `    ${whyNoDimensions(media.sourceUrl)}\n` +
                `    Then pick it under Pages -> ${pageName} -> Images -> ${row.slot}.`,
            );
            continue;
          }

          images.push({
            slot: row.slot,
            url: media.sourceUrl,
            width,
            height,
            alt: (row.alt || media.altText || "").trim(),
          });
        }

        // parseData applies the Zod schema. Anything it rejects that was not
        // caught above throws a report naming the field but not the page it
        // came from, which is close to useless across thirty-odd of them.
        // Attach the page, keep reading the rest so every fault surfaces in
        // one build, and fail below — never skip the page, because a page
        // missing from the store fails later as "no WordPress page for this
        // route", pointing the reader at the importer instead of the fault.
        try {
          const data = await parseData({
            id: route,
            data: {
              route,
              title: node.title ?? "",
              seo: {
                title: node.vsSeo?.title?.trim() ?? "",
                description: node.vsSeo?.description?.trim() ?? "",
                canonical: node.vsSeo?.canonical?.trim() ?? "",
                noindex: Boolean(node.vsSeo?.noindex),
                ogImage: node.vsSeo?.ogImage?.trim() ?? "",
              },
              // The hero, above every band on the page — the headline, kicker,
              // intro paragraph and buttons that were written into each
              // template until now.
              //
              // Normalized to empty strings the way `seo` directly above is,
              // not left nullable. "" is falsy, so every template gate reads
              // the same, and 25 templates do not each have to spell out the
              // difference between null and blank. Nothing here can throw, and
              // nothing is added to badImages/badPages: a half-typed hero is an
              // edit in progress, never a failed build.
              //
              // A CTA row counts only when BOTH label and href are filled. A
              // half-typed row that kept the template's href under a new label
              // would point a renamed button at the old place — a wrong link is
              // worse than the template's own correct one.
              hero: {
                eyebrow: f?.hero?.eyebrow?.trim() ?? "",
                h1: f?.hero?.h1?.trim() ?? "",
                sub: f?.hero?.sub?.trim() ?? "",
                ratings: Boolean(f?.hero?.ratings),
                ctas: (f?.hero?.ctas ?? [])
                  .filter((c) => c?.label?.trim() && c?.href?.trim())
                  // ACF caps the repeater at 2 (vs-content-model.php:1484) and
                  // no hero has a third button designed for it. Sliced rather
                  // than rejected: a third row should be dropped, not take the
                  // page down.
                  .slice(0, 2)
                  .map((c) => ({ label: c.label!.trim(), href: c.href!.trim() })),
              },
              // Passed through exactly as WordPress sent it, unlike everything
              // below. The fields of a block belong to its layout, and a loader
              // that reshaped them would have to know every layout — which is
              // the one thing that must stay untrue if an unrecognised layout is
              // to survive the build. Empty until a page is migrated, and empty
              // again the moment an editor clears the field.
              blocks: usableBlocks(f?.blocks, where, logger),
              // Normalized to the shape the templates already use, so a template
              // swaps `const faqs = [...]` for a lookup and changes nothing else.
              tocLinks: (f?.tocLinks ?? [])
                .filter((t) => t.label && t.anchor)
                .map((t) => ({ href: `#${t.anchor}`, label: t.label! })),
              processSteps: (f?.processSteps ?? [])
                .filter((s) => s.title)
                .map((s, i) => ({
                  tag: s.tag ?? "",
                  // `num` was authored as "01", "02"… in frontmatter. Deriving it
                  // from position means an editor reordering steps in WordPress
                  // cannot leave the numbering out of sequence.
                  num: String(i + 1).padStart(2, "0"),
                  title: s.title!,
                  body: s.body ?? "",
                })),
              faqs: (f?.faqs ?? [])
                .filter((q) => q.question)
                .map((q) => ({
                  q: q.question!,
                  a: q.answer ?? "",
                  open: Boolean(q.open),
                })),
              sections: (f?.sections ?? [])
                .filter((s) => s.sectionId)
                .map((s) => ({
                  section_id: s.sectionId!,
                  eyebrow: s.eyebrow ?? "",
                  heading: s.heading ?? "",
                  body: s.body ?? "",
                  cta_label: s.ctaLabel ?? "",
                  cta_href: s.ctaHref ?? "",
                })),
              cards: (f?.cards ?? [])
                .filter((c) => c.group)
                .map((c) => ({
                  group: c.group!,
                  title: c.title ?? "",
                  body: c.body ?? "",
                  meta: c.meta ?? "",
                  href: c.href ?? "",
                })),
              // Dimensions are required, not optional: <Image> refuses a remote
              // source without them, and they are what stops the page reflowing
              // as it loads. Every row here has already been checked to carry
              // them — one that could not is in `badImages` and will fail the
              // build below, rather than being quietly left out of the page.
              images,
            },
          });

          store.set({ id: route, data });
        } catch (error) {
          const detail = error instanceof Error ? error.message : String(error);
          badPages.push(`  ${where}\n    ${detail.replace(/\n/g, "\n    ")}`);
        }
      }

      // Images first: this is the one an editor causes by doing something
      // ordinary, so it is the one most likely to be read by someone who did
      // not expect to be reading a build log at all. Every line of it is
      // addressed to that reader — no stack, no field paths, no jargon.
      if (badImages.length > 0) {
        throw new WordPressError(
          `${badImages.length} of ${imageCount} pictures on the site have no width and ` +
            `height recorded in the WordPress Media Library, so the build cannot place ` +
            `them:\n\n${badImages.join("\n\n")}\n\n` +
            `Nothing has been published. The site that is online right now is unchanged ` +
            `and still serving — fix the pictures above and deploy again.`,
        );
      }

      if (badPages.length > 0) {
        throw new WordPressError(
          `${badPages.length} of ${nodes.length} pages hold content WordPress cannot ` +
            `publish:\n\n${badPages.join("\n\n")}\n\n` +
            `Nothing has been published. The site that is online right now is unchanged.`,
        );
      }

      // Reached only when every page lacked both a route and a uri, so nothing
      // above had anything to complain about and nothing was stored either.
      if (store.keys().length === 0) {
        throw new WordPressError(`All ${nodes.length} pages failed validation.`);
      }

      logger.info(`Loaded ${store.keys().length} pages (${imageCount} images)`);
    },
  };
}
