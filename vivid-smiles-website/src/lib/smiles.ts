/**
 * Central finished-smile photo loader.
 *
 * Every smile-gallery photo lives in src/assets/images/smiles/ and is
 * auto-discovered here via Vite's import.meta.glob. Drop a new webp into that
 * folder and every gallery marquee + the /smile-gallery/ catalog page picks it
 * up on the next build. Remove a file and it disappears everywhere. There is no
 * per-page list to keep in sync.
 *
 * The folder is GALLERY-ONLY. Nothing else on the site may import from it — a
 * direct import on a page that also carries a marquee puts the same photo on
 * that page twice. Section-specific images stay as
 * direct explicit imports from procedures/ or people/, which are editorial
 * picks rather than rotation.
 *
 * Used by:
 *   - src/pages/smile-gallery/index.astro (canonical catalog)
 *   - the home #gallery strip and every service-page #gallery / #results marquee
 *
 * Replaces src/lib/before-afters.ts, which still exists and still works — the
 * old catalog page is parked at an archived copy and can be
 * restored by moving it back.
 */
import type { ImageMetadata } from 'astro';

const modules = import.meta.glob<{ default: ImageMetadata }>(
  '../assets/images/smiles/*.{webp,jpg,jpeg,png}',
  { eager: true },
);

export interface Smile {
  /** Optimized Astro asset — pass straight into <Image src={...}> */
  src: ImageMetadata;
  /** Auto-derived screen-reader caption. Describes the PHOTOGRAPH only. */
  alt: string;
}

/**
 * Alt text describes what the photo SHOWS, never what treatment produced it.
 * These are real, identifiable patients; a treatment claim about a real person
 * is a claims problem, and the surrounding page copy already does that job.
 * Keep stems purely visual for the same reason — see src/assets/images/README.md.
 */
function filenameToAlt(path: string): string {
  const stem =
    path
      .split('/')
      .pop()
      ?.replace(/\.\w+$/, '') ?? '';
  // Stems carry a numeric prefix ("01-frontal-arch-berry-lip") purely to fix
  // gallery order, the same convention blog/<slug>/ uses. It is ordering
  // metadata, not description, so it never reaches the alt text.
  const descriptor = stem
    .replace(/^\d+[-_]?/, '')
    .replace(/-/g, ' ')
    .trim();
  return descriptor
    ? `Close-up photograph of a Vivid Smiles patient smiling — ${descriptor}`
    : 'Close-up photograph of a Vivid Smiles patient smiling';
}

/** Every smile photo in the folder, sorted by filename for stable build order. */
export const smiles: Smile[] = Object.entries(modules)
  .sort(([a], [b]) => a.localeCompare(b))
  .map(([path, mod]) => ({
    src: mod.default,
    alt: filenameToAlt(path),
  }));

/** Every gallery marquee calls this bare. Kept as a function so a page never
 *  imports the array and mutates it. */
export function getSmiles(): Smile[] {
  return smiles;
}
