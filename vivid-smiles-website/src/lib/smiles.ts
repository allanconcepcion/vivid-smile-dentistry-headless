/**
 * Central finished-smile photo loader — sourced from WordPress.
 *
 * The gallery is edited at wp-admin -> Practice Settings -> Smile gallery. Add
 * a photo there and it appears on /smile-gallery/ and in every marquee on the
 * next build; remove one and it disappears everywhere. Order is the order of
 * the gallery field, so an editor drags to reorder.
 *
 * This used to glob src/assets/images/smiles/ at build time, which meant adding
 * a patient photo required a commit and a deploy.
 *
 * ALT TEXT describes what the photo SHOWS, never what treatment produced it.
 * These are real, identifiable patients; a treatment claim about a real person
 * is a claims problem, and the surrounding page copy already does that job. The
 * import seeded the same wording the folder-based loader derived; editors
 * change it in the Media Library.
 *
 * Used by:
 *   - src/pages/smile-gallery/index.astro (canonical catalog)
 *   - the home #gallery strip and every service-page #gallery / #results marquee
 *
 * Build-time only — top-level await is safe here for the same reason as
 * contact.ts: nothing in src/scripts/ imports it, so it never reaches a browser
 * bundle. Check that before adding a client-side consumer.
 */

import { getSettings } from "./settings";

export interface Smile {
  /**
   * WordPress media URL. A STRING, not ImageMetadata — <Image> needs a remote
   * source as a string plus explicit dimensions, and handing it an
   * ImageMetadata-shaped object with a remote src emits a broken "/http://…"
   * path. Astro still downloads and optimizes it at build.
   */
  src: string;
  /** Intrinsic width, required for a remote <Image> and to prevent layout shift. */
  width: number;
  /** Intrinsic height, same reason. */
  height: number;
  /** Screen-reader caption. Describes the PHOTOGRAPH only. */
  alt: string;
}

const settings = await getSettings();

const FALLBACK_ALT = "Close-up photograph of a Vivid Smiles patient smiling";

/** Every smile photo in the WordPress gallery, in the editor's chosen order. */
export const smiles: Smile[] = (settings.smileGallery?.nodes ?? [])
  .filter((node) => node.sourceUrl && node.mediaDetails?.width && node.mediaDetails?.height)
  .map((node) => ({
    src: node.sourceUrl!,
    width: node.mediaDetails!.width!,
    height: node.mediaDetails!.height!,
    alt: node.altText?.trim() || FALLBACK_ALT,
  }));

/**
 * Every gallery marquee calls this bare. Kept as a function so a page never
 * imports the array and mutates it.
 *
 * Throws when the gallery is empty. The marquees are a structural part of a
 * dozen pages — shipping them blank leaves visible holes in the layout, and an
 * empty gallery is far more likely to mean a broken query than a practice that
 * deleted every patient photo.
 */
export function getSmiles(): Smile[] {
  if (smiles.length === 0) {
    throw new Error(
      "The WordPress smile gallery is empty.\n" +
        "Add photos at wp-admin -> Practice Settings -> Smile gallery, or re-run:\n" +
        "  cd cms && npm run import:gallery",
    );
  }

  return smiles;
}
