/*
 * Vivid Smiles — contact constants. Single source for the practice phone and
 * the canonical online booking URL (NexHealth scheduling — opens off-site).
 * Imported by Nav, MobileMenu, Footer, and every page that renders a Book
 * Online or Call CTA. <Button> auto-detects the external host and opens it
 * in a new tab.
 */

export const phoneLabel = "(303) 841-5313";
export const phoneHref = "tel:+13038415313";
export const phoneE164 = "+1-303-841-5313";
export const bookNowHref = "https://app.nexhealth.com/appt/vivid-smiles?lid=135014";

export const emailAddress = "info@vividsmilesdentistry.com";
export const emailHref = `mailto:${emailAddress}`;

/* Practice street address — single source for any "17167 Cedar Gulch…" copy
   that we may want to template later. The directionsHref below is what every
   "Get Directions" CTA should link to; mobile browsers deep-link this into
   the native Maps app, desktop opens Google Maps in a new tab. */
export const addressStreet = "17167 Cedar Gulch Pkwy Ste 102";
export const addressCity = "Parker";
export const addressState = "CO";
export const addressZip = "80134";
export const addressLine = `${addressStreet}, ${addressCity}, ${addressState} ${addressZip}`;
export const directionsHref =
  "https://maps.google.com/?q=17167+Cedar+Gulch+Pkwy+Ste+102,+Parker,+CO+80134";

/* Canonical Typeform embed ID for the practice's front-desk contact funnel —
   used on /contact/ and /emergency-dentistry/. Separate from the smile-consult
   Typeform inside <VirtualConsult>. If this ever changes, update here once. */
export const contactTypeformId = "01KQVS3YDH3E0TNG9N208Y6FBC";
