/*
 * Vivid Smiles — office hours, single source of truth.
 *
 * Drives the footer hours table, the footer "Open Now / Closed" pill (via
 * isOpenNow at runtime in America/Denver), the contact page hours table +
 * "skip the form" copy, and the contact page's schema.org
 * openingHoursSpecification JSON-LD.
 *
 * Edit a schedule once here; every consumer follows.
 */

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

export const officeHours: HoursRange[] = [
  {
    label: "Mon–Wed",
    days: ["Monday", "Tuesday", "Wednesday"],
    shortDays: ["Mon", "Tue", "Wed"],
    opens: "08:00",
    closes: "17:00",
    hoursLong: "8:00am – 5:00pm",
    hoursShort: "8a–5p",
  },
  {
    label: "Thu–Fri",
    days: ["Thursday", "Friday"],
    shortDays: ["Thu", "Fri"],
    opens: "08:00",
    closes: "13:00",
    hoursLong: "8:00am – 1:00pm",
    hoursShort: "8a–1p",
  },
  {
    label: "Sat–Sun",
    days: ["Saturday", "Sunday"],
    shortDays: ["Sat", "Sun"],
    opens: null,
    closes: null,
    hoursLong: "Closed",
    hoursShort: "Closed",
  },
];

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

const denverFmt = new Intl.DateTimeFormat("en-US", {
  timeZone: "America/Denver",
  weekday: "short",
  hour: "numeric",
  minute: "numeric",
  hour12: false,
});

const parseHM = (hm: string) => {
  const [h, m] = hm.split(":").map(Number);
  return h + m / 60;
};

/**
 * True if the practice is currently open in America/Denver.
 * Boundaries: opens-inclusive, closes-exclusive (8:00 → open, 17:00 → closed).
 */
export function isOpenNow(now: Date = new Date()): boolean {
  const parts = denverFmt.formatToParts(now);
  const day = parts.find((p) => p.type === "weekday")?.value ?? "";
  const hour = Number(parts.find((p) => p.type === "hour")?.value ?? 0);
  const minute = Number(parts.find((p) => p.type === "minute")?.value ?? 0);
  const t = hour + minute / 60;

  const range = officeHours.find((r) =>
    (r.shortDays as readonly string[]).includes(day),
  );
  if (!range || !range.opens || !range.closes) return false;
  return t >= parseHM(range.opens) && t < parseHM(range.closes);
}
