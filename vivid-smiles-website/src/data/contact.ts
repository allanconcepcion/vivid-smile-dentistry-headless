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
