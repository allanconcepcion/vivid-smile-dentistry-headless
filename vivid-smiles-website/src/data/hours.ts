/*
 * Vivid Smiles — office hours, sourced from WordPress.
 *
 * Edited at wp-admin -> Practice Settings -> Opening hours.
 *
 * Drives the footer hours table, the footer "Open Now / Closed" pill (via
 * isOpenNow at runtime in America/Denver), the contact page hours table +
 * "skip the form" copy, and the contact page's schema.org
 * openingHoursSpecification JSON-LD.
 *
 * WordPress stores only the facts: which days, and the open/close times. Every
 * display string below is derived from those, so an editor cannot leave the
 * printed hours saying one thing while the structured data Google reads says
 * another.
 *
 * Top-level await is build-time only — see the note in contact.ts.
 */

import { getSettings, formatLong, formatShort } from "../lib/settings";
import { isOpenNow as evaluateSchedule, type OpenSchedule } from "../lib/open-now";

type ShortDay = "Mon" | "Tue" | "Wed" | "Thu" | "Fri" | "Sat" | "Sun";
type LongDay =
  | "Monday"
  | "Tuesday"
  | "Wednesday"
  | "Thursday"
  | "Friday"
  | "Saturday"
  | "Sunday";

export type HoursRange = {
  /** Display label like "Mon–Wed". */
  label: string;
  /** Full day names (schema.org-shaped). */
  days: LongDay[];
  /** Short day names matching Intl({weekday:"short"}) output ("Mon", "Tue"…). */
  shortDays: ShortDay[];
  /** 24-hour open time, e.g. "08:00". null = closed. */
  opens: string | null;
  /** 24-hour close time, e.g. "17:00". null = closed. */
  closes: string | null;
  /** Long display string, e.g. "8:00am – 5:00pm" or "Closed". */
  hoursLong: string;
  /** Short display string, e.g. "8a–5p" or "Closed". */
  hoursShort: string;
};

const settings = await getSettings();

export const officeHours: HoursRange[] = settings.officeHours.map((row) => {
  const days = (row.days ?? []) as LongDay[];
  const closed = Boolean(row.closed) || !row.opens || !row.closes;

  return {
    label: row.label,
    days,
    shortDays: days.map((d) => d.slice(0, 3) as ShortDay),
    opens: closed ? null : row.opens,
    closes: closed ? null : row.closes,
    hoursLong: closed ? "Closed" : `${formatLong(row.opens!)} – ${formatLong(row.closes!)}`,
    hoursShort: closed ? "Closed" : `${formatShort(row.opens!)}–${formatShort(row.closes!)}`,
  };
});

/** schema.org OpeningHoursSpecification entries, ready to drop into JSON-LD. */
export const openingHoursSpecification = officeHours
  .filter((r) => r.opens && r.closes)
  .map((r) => ({
    "@type": "OpeningHoursSpecification" as const,
    dayOfWeek: r.days,
    opens: r.opens!,
    closes: r.closes!,
  }));

/** Brief copy like "Mon–Wed 8a–5p, Thu–Fri 8a–1p" — used in inline prose. */
export const hoursCopyShort = officeHours
  .filter((r) => r.opens && r.closes)
  .map((r) => `${r.label} ${r.hoursShort}`)
  .join(", ");

/**
 * The subset the browser needs for the footer's Open Now pill.
 *
 * Serialized into the DOM by Footer.astro. A client <script> must NOT import
 * this module — it fetches from WordPress at build time, and that whole client
 * would end up in the browser bundle. See src/lib/open-now.ts.
 */
export const openSchedule: OpenSchedule = officeHours.map((r) => ({
  shortDays: r.shortDays,
  opens: r.opens,
  closes: r.closes,
}));

/**
 * True if the practice is currently open in America/Denver.
 * Boundaries: opens-inclusive, closes-exclusive (8:00 → open, 17:00 → closed).
 */
export function isOpenNow(now: Date = new Date()): boolean {
  return evaluateSchedule(openSchedule, now);
}
