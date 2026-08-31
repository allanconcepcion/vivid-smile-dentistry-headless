/**
 * Site-data tokens for stored rich text.
 *
 * WHY THIS EXISTS. Some editable copy has to name the practice phone number
 * mid-sentence — an FAQ answer that says "please call (303) 841-5313 first",
 * a referral outro with a tel: link inside it. The href policy
 * (src/blocks/cta.ts) forbids storing the number, because a per-band copy of
 * a phone number is a per-band chance to publish a stale one after Practice
 * Settings changes. So the stored string carries `{phone}` / `{phone_href}`
 * and the component substitutes them at render time from src/data/contact.ts.
 *
 * This is the same mechanism AreaBand.astro:116-124 already uses for
 * `{street}` and `{city}`, lifted out because a second and third consumer
 * appeared. Two copies of a token table is how the tables drift.
 *
 * UNKNOWN TOKENS PASS THROUGH UNCHANGED, deliberately. A stored `{price}`
 * surviving to the page is a visible, findable bug; blanking it silently
 * deletes a sentence and nobody notices. Same call the AreaBand comment makes.
 *
 * MODULE-GRAPH WARNING, same as cta.ts and registry.ts: imported by block
 * COMPONENTS only. It must never be imported from manifest.ts or anything
 * page-content.ts reaches, or the components' CSS lands on all 48 routes.
 */
import { phoneHref, phoneLabel } from "../data/contact";

/** Stored token -> the site's own value. Extend here, never at a call site. */
export const SITE_TOKENS: Record<string, string> = {
  phone: phoneLabel,
  phone_href: phoneHref,
};

/**
 * Substitute site tokens in a stored string.
 *
 * Null-safe because ACF returns null for any field an editor has never saved,
 * and returns "" for those so a caller can hand the result straight to
 * `set:html` without a second guard.
 */
export function fillSiteTokens(raw?: string | null): string {
  if (typeof raw !== "string" || raw === "") return "";
  return raw.replace(
    /\{(phone_href|phone)\}/g,
    (whole, key: string) => SITE_TOKENS[key] ?? whole,
  );
}
