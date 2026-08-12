/**
 * "Is the practice open right now?" — pure, dependency-free.
 *
 * This module is imported by BROWSER code (the footer's Open Now pill), so it
 * must never reach into anything build-time. It takes the schedule as an
 * argument rather than importing it.
 *
 * That separation is load-bearing. src/data/hours.ts now fetches from
 * WordPress with a top-level await; importing it from a client <script> pulls
 * the whole WPGraphQL client — fetch logic, retries, the query text — into the
 * browser bundle, where WP_GRAPHQL_ENDPOINT does not exist and the module
 * throws on evaluation. The pill would silently never appear.
 */

/** The minimum a client needs to answer the question. */
export type OpenSchedule = Array<{
  /** Short day names matching Intl({weekday:"short"}) output: "Mon", "Tue"… */
  shortDays: string[];
  /** 24-hour open time, e.g. "08:00". null = closed that day. */
  opens: string | null;
  /** 24-hour close time, e.g. "17:00". null = closed that day. */
  closes: string | null;
}>;

const denverFmt = new Intl.DateTimeFormat("en-US", {
  timeZone: "America/Denver",
  weekday: "short",
  hour: "numeric",
  minute: "numeric",
  hour12: false,
});

const parseHM = (hm: string): number => {
  const [h, m] = hm.split(":").map(Number);
  return (h ?? 0) + (m ?? 0) / 60;
};

/**
 * True if the practice is open at `now`, evaluated in America/Denver
 * regardless of the visitor's own timezone.
 *
 * Boundaries: opens-inclusive, closes-exclusive (8:00 → open, 17:00 → closed).
 */
export function isOpenNow(schedule: OpenSchedule, now: Date = new Date()): boolean {
  const parts = denverFmt.formatToParts(now);
  const day = parts.find((p) => p.type === "weekday")?.value ?? "";
  const hour = Number(parts.find((p) => p.type === "hour")?.value ?? 0);
  const minute = Number(parts.find((p) => p.type === "minute")?.value ?? 0);
  const t = hour + minute / 60;

  const range = schedule.find((r) => r.shortDays.includes(day));
  if (!range?.opens || !range.closes) return false;

  return t >= parseHM(range.opens) && t < parseHM(range.closes);
}
