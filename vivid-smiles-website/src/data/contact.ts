/*
 * Vivid Smiles — contact constants, sourced from WordPress.
 *
 * Edited at wp-admin -> Practice Settings. The exports below are unchanged, so
 * the 36 files importing them (Nav, MobileMenu, Footer, and every page with a
 * Book Online or Call CTA) need no edits.
 *
 * Top-level await is safe here: this module is only ever evaluated during the
 * build in Node. Nothing in src/scripts/ imports it, so it never reaches a
 * browser bundle. If that ever changes, this has to become an async accessor.
 */

import { getSettings } from "../lib/settings";

const settings = await getSettings();

export const phoneLabel = settings.phoneLabel;
export const phoneE164 = settings.phoneE164;
/** tel: link. Derived so it can never disagree with the dialable number. */
export const phoneHref = `tel:${settings.phoneE164.replace(/[^\d+]/g, "")}`;

export const bookNowHref = settings.bookNowHref;

export const emailAddress = settings.emailAddress;
export const emailHref = `mailto:${settings.emailAddress}`;

export const addressStreet = settings.addressStreet;
export const addressCity = settings.addressCity;
export const addressState = settings.addressState;
export const addressZip = settings.addressZip;
export const addressLine = `${addressStreet}, ${addressCity}, ${addressState} ${addressZip}`;

/* Where every "Get Directions" CTA points. Mobile browsers deep-link this into
   the native Maps app; desktop opens Google Maps in a new tab. Falls back to a
   maps query built from the address so a blank field cannot produce a dead
   link. */
export const directionsHref =
  settings.directionsHref ||
  `https://maps.google.com/?q=${encodeURIComponent(addressLine)}`;

/* Typeform embed for the front-desk contact funnel — used on /contact/ and
   /emergency-dentistry/. Separate from the smile-consult Typeform inside
   <VirtualConsult>. */
export const contactTypeformId = settings.contactTypeformId;
/**
 * The virtual-consult form. NULLABLE, unlike its sibling above: the field was
 * added to Practice Settings after the settings page shipped, so an install that
 * has never saved it returns null. VirtualConsult.astro falls back to the id it
 * has always carried, which is what makes an empty box a no-op rather than a
 * blank form on the site's primary lead capture.
 */
export const consultTypeformId = settings.consultTypeformId;

/**
 * The review line every page repeats — "5.0 · 300+ reviews". Two Practice
 * Settings boxes; a blank box keeps the number the templates have always shown,
 * so the 48 routes build byte-identical until someone types a new figure.
 */
export const googleRating = settings.googleRating || "5.0";
export const googleReviewCount = settings.googleReviewCount || "300+";

/**
 * The membership plan's three facts — Practice Settings → Membership plan.
 * The fee is typed as it should read ("$500"); `membershipFeeDigits` is the
 * same number without the sign, for the markup that draws the "$" itself and
 * for the JSON-LD price. The join link was a literal in the page with NexHealth
 * attribution tokens nobody in wp-admin could repair; it stays the fallback.
 */
const feeRaw = (settings.membershipFee || "$500").trim();
export const membershipFeeDigits = feeRaw.replace(/[^\d.,]/g, "") || "500";
export const membershipFee = "$" + membershipFeeDigits;
export const membershipDiscount = (settings.membershipDiscount || "15%").trim();
export const membershipJoinHref = settings.membershipJoinHref || "https://app.nexhealth.com/appt/vivid-smiles?gei=SNOCZ9DYI4iz0PEP4pGiwAE&hl=en-US&lid=135014&rwg_token=AJKvS9UCpCLqLfgKhJQnz7rWoeDCbQ7_4jB31UUUncy7-eeuYYpuTjjp2NDySX2gPYqlQfi7W1lrikNOJ7pN5SiEvTSWaWjcYQ%3D%3D";

/**
 * The texting programme's support line, printed in Terms & Conditions §22 —
 * deliberately not the practice phone (terms-conditions/index.astro:31-33 kept
 * it separate for that reason). Typed as it should read; the tel: href is
 * derived from its digits, so a blank box keeps both literals exactly.
 */
export const smsHelpLabel = (settings.smsHelpPhone || "(303) 276-9932").trim();
const smsDigits = smsHelpLabel.replace(/\D/g, "");
export const smsHelpHref = "tel:+" + (smsDigits.length === 10 ? "1" + smsDigits : smsDigits);
