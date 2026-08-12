/**
 * Practice settings, fetched from the WordPress options page at build time.
 *
 * Backs src/data/contact.ts and src/data/hours.ts, which still export the same
 * plain constants they always did — so the 41 files importing them are
 * unchanged. Those modules use top-level await, which is safe here because
 * nothing in src/scripts/ imports them: they are evaluated in Node during the
 * build, never shipped to a browser.
 *
 * Fetched exactly once per build. Every page imports contact.ts, and a request
 * per page would turn one query into forty.
 */

import { wpQuery, WordPressError } from "./wp";

const SETTINGS_QUERY = /* GraphQL */ `
  query PracticeSettings {
    practiceSettings {
      practiceSettingsFields {
        phoneLabel
        phoneE164
        emailAddress
        bookNowHref
        addressStreet
        addressCity
        addressState
        addressZip
        directionsHref
        contactTypeformId
        officeHours {
          label
          days
          closed
          opens
          closes
        }
        smileGallery {
          nodes {
            sourceUrl
            altText
            mediaDetails {
              width
              height
            }
          }
        }
        logoLightBg {
          node { sourceUrl altText mediaDetails { width height } }
        }
        logoDarkBg {
          node { sourceUrl altText mediaDetails { width height } }
        }
      }
    }
  }
`;

export type WpHoursRow = {
  label: string;
  days: string[] | null;
  closed: boolean | null;
  opens: string | null;
  closes: string | null;
};

export type WpMedia = {
  sourceUrl: string | null;
  altText: string | null;
  mediaDetails: { width: number | null; height: number | null } | null;
};

export type PracticeSettings = {
  phoneLabel: string;
  phoneE164: string;
  emailAddress: string;
  bookNowHref: string;
  addressStreet: string;
  addressCity: string;
  addressState: string;
  addressZip: string;
  directionsHref: string;
  contactTypeformId: string;
  officeHours: WpHoursRow[];
  smileGallery: { nodes: WpMedia[] } | null;
  logoLightBg: { node: WpMedia | null } | null;
  logoDarkBg: { node: WpMedia | null } | null;
};

/** Fields without which the site would ship visibly broken contact details. */
const REQUIRED = [
  "phoneLabel",
  "phoneE164",
  "emailAddress",
  "bookNowHref",
  "addressStreet",
  "addressCity",
  "addressState",
  "addressZip",
] as const;

let cached: Promise<PracticeSettings> | null = null;

async function fetchSettings(): Promise<PracticeSettings> {
  const data = await wpQuery<{
    practiceSettings: { practiceSettingsFields: PracticeSettings | null } | null;
  }>(SETTINGS_QUERY, {}, "practice settings");

  const fields = data.practiceSettings?.practiceSettingsFields;

  if (!fields) {
    throw new WordPressError(
      "WordPress returned no Practice Settings. Seed them with:\n" +
        "  cd cms && npm run import:settings",
    );
  }

  const missing = REQUIRED.filter((k) => !fields[k]);

  if (missing.length) {
    throw new WordPressError(
      `Practice Settings is missing required field(s): ${missing.join(", ")}.\n` +
        `Fill them in at wp-admin -> Practice Settings. These appear on every page, ` +
        `so publishing without them would ship a site with no working phone number or address.`,
    );
  }

  return fields;
}

export function getSettings(): Promise<PracticeSettings> {
  cached ??= fetchSettings();
  return cached;
}

/* ---------------------------------------------------------------------------
 * Display formatting.
 *
 * Derived here rather than stored in WordPress. Storing "8:00am – 5:00pm"
 * next to "08:00" invites the two to drift, and the one an editor fixes is
 * rarely the one the structured data reads.
 * ------------------------------------------------------------------------ */

function parts(hm: string): { h: number; m: number } {
  const [h, m] = hm.split(":").map(Number);
  return { h: h ?? 0, m: m ?? 0 };
}

/** "08:00" -> "8:00am" */
export function formatLong(hm: string): string {
  const { h, m } = parts(hm);
  const period = h < 12 ? "am" : "pm";
  const h12 = h % 12 || 12;
  return `${h12}:${String(m).padStart(2, "0")}${period}`;
}

/** "08:00" -> "8a"; "08:30" -> "8:30a" */
export function formatShort(hm: string): string {
  const { h, m } = parts(hm);
  const period = h < 12 ? "a" : "p";
  const h12 = h % 12 || 12;
  return m === 0 ? `${h12}${period}` : `${h12}:${String(m).padStart(2, "0")}${period}`;
}

export type BrandLogo = { src: string; width: number; height: number; alt: string };

/**
 * A brand logo from Practice Settings.
 *
 * `variant` names the BACKGROUND it sits on, not the logo's own colour — the
 * dark-background logo is the light-coloured one, and naming it by its own
 * colour is how the wrong file ends up in the footer.
 *
 * Throws rather than returning null: the logo is in the header and footer of
 * every page, so a missing one is a site-wide visual break that should stop the
 * build, not render as a gap.
 */
export async function getLogo(variant: "lightBg" | "darkBg"): Promise<BrandLogo> {
  const settings = await getSettings();
  const node = variant === "lightBg" ? settings.logoLightBg?.node : settings.logoDarkBg?.node;

  if (!node?.sourceUrl || !node.mediaDetails?.width || !node.mediaDetails?.height) {
    throw new WordPressError(
      `No "${variant}" logo in Practice Settings, or it has no dimensions.\n` +
        `Set it at wp-admin -> Practice Settings -> Logos, or re-run:\n` +
        `  cd cms && npm run import:gallery`,
    );
  }

  return {
    src: node.sourceUrl,
    width: node.mediaDetails.width,
    height: node.mediaDetails.height,
    alt: node.altText?.trim() || "Vivid Smiles Dentistry",
  };
}
