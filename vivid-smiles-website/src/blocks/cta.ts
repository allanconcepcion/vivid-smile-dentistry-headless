/**
 * The CTA contract shared by every block that draws a button row.
 *
 * ONE COPY, ON PURPOSE. Five components (CardGrid, MediaSplit,
 * ComparisonCards, GalleryMarquee, PricingTiers) draw editor-filled CTA
 * buttons, and until this file existed each carried a character-identical
 * copy of the token map and the hover table. Character-identical is the
 * problem, not the reassurance: the tables only work because the blocks agree
 * about what "book" resolves to and what hovering it promises, and five
 * copies is five places for the next hover-label edit to miss. The
 * teeth-whitening "View Levels" case is the live proof that these tables get
 * edited — so they get edited HERE.
 *
 * THE HREF POLICY this file implements: a cta href field in WordPress stores
 * an in-page anchor ("#consult"), a site path ("/smile-gallery/"), or one of
 * the three tokens below — never a pasted booking/tel/maps URL. Those are
 * site data (src/data/contact.ts, edited once in Practice Settings), and a
 * per-band copy of one is a per-band chance to publish a stale phone number.
 *
 * MODULE-GRAPH WARNING, same as registry.ts: this file is imported by block
 * COMPONENTS only. It must never be imported from manifest.ts or anything
 * page-content.ts reaches, or the components' CSS lands on all 48 routes.
 * (This file itself carries no CSS, but keeping the rule uniform is what
 * keeps it checkable.)
 */
import { bookNowHref, directionsHref, phoneHref } from "../data/contact";

/** Destination tokens -> the site's own hrefs. Unknown strings pass through. */
export const CTA_TOKEN: Record<string, string> = {
  book: bookNowHref,
  phone: phoneHref,
  map: directionsHref,
};

/**
 * Hover labels by STORED destination (token or anchor), never the resolved
 * URL — the table must keep working when contact.ts values change. The
 * pairings are the site's own unanimous ones (52 of 54 `#consult` buttons
 * hover "Get a Video"; book/phone/map are unanimous). A per-row `cta_hover*`
 * field overrides the table, because one destination can need two labels —
 * `#process` hovers "View Steps" on five pages and "View Levels" on
 * teeth-whitening. An unknown destination yields `undefined`, which
 * Button.astro treats as "reuse the visible label".
 */
export const CTA_HOVER: Record<string, string> = {
  "#consult": "Get a Video",
  "#process": "View Steps",
  "#faq": "See Answers",
  book: "Let's Talk",
  phone: "Tap to Dial",
  map: "Open Map",
};

/** Token or blank -> a usable href. Blank degrades to "#", not to a broken link. */
export const resolveCtaHref = (dest: string): string =>
  CTA_TOKEN[dest] ?? (dest === "" ? "#" : dest);

/** Field override first, table second, undefined (= visible label) last. */
export const resolveCtaHover = (dest: string, hover: string): string | undefined =>
  hover !== "" ? hover : CTA_HOVER[dest];

/**
 * The one-call form the simpler blocks use: raw field values in, a resolved
 * {href, hover} pair out. Null-safe because ACF returns null for any field an
 * editor has never saved.
 */
export function resolveCta(rawHref?: string | null, rawHover?: string | null) {
  const raw = typeof rawHref === "string" ? rawHref.trim() : "";
  const hover = typeof rawHover === "string" ? rawHover.trim() : "";
  return { href: resolveCtaHref(raw), hover: resolveCtaHover(raw, hover) };
}
