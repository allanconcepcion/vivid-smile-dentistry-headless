/**
 * Visual-regression screenshots — the pixel half of the Phase 0 safety net.
 *
 *   node scripts/vr-screens.mjs snapshot     # record the baseline from dist/
 *   node scripts/vr-screens.mjs compare      # rebuild, then diff against it
 *   node scripts/vr-screens.mjs --help
 *
 * WHY THIS EXISTS
 *
 * The CSS work in docs/PAGE-BLOCKS.md claims to be visually inert. The HTML
 * harness proves the markup did not move; it cannot prove the cascade did not.
 * Wrapping the 34 page sheets in `@layer page` changes zero declarations and
 * still inverts the cascade wherever a page sheet currently beats a component's
 * unlayered Astro <style> on specificity — an unlayered rule outranks every
 * layered rule regardless of specificity, so the identical HTML can paint
 * differently. Only pixels catch that. This is the instrument that decides
 * whether a phase shipped or gets reverted, so it is built to be believed:
 * every source of run-to-run variance below is closed deliberately, because a
 * harness that cries wolf gets ignored and then the real regression ships.
 *
 * WHAT IT DOES NOT COVER — state this honestly rather than discover it later.
 *
 *  - Captures are taken under prefers-reduced-motion: reduce (see SETTLING).
 *    That is the site's own deterministic render path; it is not the path a
 *    default visitor sees. A regression that exists ONLY in the animated
 *    state — a GSAP tween's start values, a hover transition — is invisible
 *    here. That is an accepted trade: the migration moves declarations between
 *    cascade layers, and the finished, at-rest paint is what that changes.
 *  - No hover, focus or :active states. The mouse is never moved, so nothing
 *    is captured mid-hover; equally, nothing hover-only is verified.
 *  - Third-party surfaces are blocked and the Google Maps embed is masked
 *    (see NETWORK). Their internals are not ours to regress.
 *
 * DEPENDENCIES
 *
 * Playwright is deliberately NOT in package.json. It is a ~200MB browser
 * download that only this script needs, and this is a live client repo whose
 * production build should not grow a dependency for a dev-time instrument.
 * Install it transiently instead — see PLAYWRIGHT_INSTALL below. The import is
 * lazy so this file can be read, linted and syntax-checked without it present.
 *
 * OUTPUT
 *
 *   .vr/screens/baseline/<route>@<width>.png   recorded by `snapshot`
 *   .vr/screens/current/<route>@<width>.png    recorded by `compare`
 *   .vr/screens/diff/<route>@<width>.png       written for every failure
 *   .vr/screens/manifest.json                  the settings the baseline used
 *
 * .vr/ is NOT currently in .gitignore. 144 full-page PNGs must never land in
 * a client repo's history; this script warns loudly if the directory is
 * tracked, but adding the ignore rule is the repo owner's job, not this
 * script's.
 *
 * Exit codes: 0 = every capture matched, 1 = at least one differed,
 * 2 = the harness could not run (no Playwright, no dist, settings mismatch).
 * 2 is distinct from 1 on purpose: "the harness is broken" must never be
 * mistaken by CI for "the site changed".
 */

import { createServer } from "node:http";
import { readFile, readdir, mkdir, writeFile, stat } from "node:fs/promises";
import { existsSync } from "node:fs";
import { execFileSync } from "node:child_process";
import { deflateSync, inflateSync } from "node:zlib";
import path from "node:path";
import { fileURLToPath } from "node:url";

const APP_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const DIST_DIR = path.join(APP_ROOT, "dist");
const DEFAULT_OUT_DIR = path.join(APP_ROOT, ".vr", "screens");

/**
 * The exact commands that make this script runnable, quoted verbatim in the
 * error path. `--no-save` is not cosmetic: it installs into node_modules
 * without touching package.json or the lockfile, so running the harness
 * cannot smuggle a dependency into a production build.
 */
const PLAYWRIGHT_INSTALL = "npm install --no-save playwright";
const BROWSER_INSTALL = "npx playwright install chromium";

/* ──────────────────────────────────────────────────────────────────────────
 *  CAPTURE SETTINGS
 *
 *  Everything here is recorded into manifest.json and re-checked on compare.
 *  A baseline captured under different settings is not a baseline, it is a
 *  different picture of a different site, and comparing against it produces
 *  confident nonsense.
 *  ────────────────────────────────────────────────────────────────────── */

/**
 * The three widths from docs/PAGE-BLOCKS.md §3.5.
 *
 * hasTouch is set at 390 and nowhere else. The corpus contains a
 * `(hover: none)` media query; in Chromium that resolves from the emulated
 * pointer type, not from the viewport width, so a 390px capture without touch
 * would render a layout no phone ever sees. isMobile is deliberately left off:
 * it additionally engages Chromium's mobile viewport-meta reflow path, which
 * is emulation machinery unrelated to anything this migration touches and one
 * more thing that can differ between Playwright releases.
 */
/**
 * Ceiling on any in-page settle step (image decode, font readiness).
 *
 * These wait on promises that can stay pending indefinitely rather than
 * rejecting, so they need a bound that does not come from Playwright.
 */
const SETTLE_TIMEOUT_MS = 10_000;

const WIDTHS = [
  { width: 1440, height: 900, hasTouch: false },
  { width: 768, height: 1024, hasTouch: false },
  { width: 390, height: 844, hasTouch: true },
];

/**
 * Wednesday 11:00 in America/Denver (17:00Z; Denver is UTC-6 in June).
 *
 * The footer's Open Now pill is client-rendered from the wall clock via
 * src/lib/open-now.ts, and the built markup ships it `hidden` — the script
 * unhides it and writes "Open Now" or "Closed". Left alone, the same commit
 * captures a green pill at 11am and a grey one at 5pm, and every page fails
 * every evening. Wednesday 11:00 sits inside the published 08:00–17:00
 * Mon–Wed window, so the pill is deterministically in its OPEN state — the
 * state most of the site's traffic sees, and the one whose styling is worth
 * regression-testing.
 *
 * Pinned by patching Date in an init script rather than with page.clock:
 * page.clock also fakes timers, and the site drives Lenis and the marquee off
 * gsap.ticker. Freezing Date alone is the smallest intervention that fixes the
 * pill, and it works on every Playwright version rather than only >= 1.45.
 */
const FIXED_CLOCK_ISO = "2025-06-11T17:00:00.000Z";

/**
 * Pinned so nothing renders off the host machine's settings. TIMEZONE matches
 * the practice's own timezone, which is what open-now.ts formats in anyway;
 * LOCALE fixes Intl number and date output (ReviewCard formats review dates
 * with toLocaleDateString).
 */
const TIMEZONE = "America/Denver";
const LOCALE = "en-US";

/**
 * 1 CSS pixel per device pixel. A 2x capture quadruples file size and, more to
 * the point, quadruples the number of sub-pixel rasterisation differences the
 * comparator has to forgive. Layout regressions are visible at 1x.
 */
const DEVICE_SCALE_FACTOR = 1;

/**
 * Elements painted over with a solid box before capture.
 *
 * .location-map is the Google Maps embed on /our-office/ and /contact/. Its
 * contents are a third-party render that changes without us — and with the
 * network blocked it becomes a browser error page, whose wording changes
 * between Chromium builds. Masking keeps the element's BOX in the capture, so
 * a change to the map container's size still shifts everything below it and is
 * still caught; only the pixels inside are ignored.
 */
const MASK_SELECTORS = [".location-map"];

/* ──────────────────────────────────────────────────────────────────────────
 *  TOLERANCE
 *
 *  Two knobs, because the two failure modes are different. Per-pixel colour
 *  distance forgives rounding; a whole-image budget forgives a stray speck.
 *  Neither is allowed to forgive a real change.
 *  ────────────────────────────────────────────────────────────────────── */

/**
 * Per-pixel colour distance (YIQ-weighted, normalised so black-vs-white is
 * ~0.97) below which two pixels are called identical.
 *
 * Calibrated against measured deltas from colorDelta() rather than copied from
 * a library default, because the units are not intuitive and a guess here is
 * the difference between a harness and a rubber stamp:
 *
 *     1 grey level  (255 -> 254)  0.0038      3 grey levels   0.0114
 *     2 grey levels (255 -> 253)  0.0076      5 grey levels   0.0189
 *     1 level on one channel      0.0027     16 grey levels   0.0606
 *
 * pixelmatch's usual 0.1 is ~26 grey levels — calibrated for baselines shot on
 * one machine and compared on another, where font hinting and GPU
 * rasterisation genuinely differ. Here both captures come from the same
 * Chromium build on the same machine minutes apart, so the honest expectation
 * is zero drift. 0.010 forgives up to 2 grey levels (or 3 on a single channel)
 * of rounding on an antialiased glyph edge and catches everything past that —
 * including the ~5% tonal shift a wrongly-resolved cascade layer produces,
 * which anything above ~0.019 would wave through. Raise it only with evidence
 * that same-machine captures actually drift, never to turn a red run green.
 */
const DEFAULT_PIXEL_THRESHOLD = 0.01;

/**
 * Fraction of an image's pixels allowed to differ before the route fails.
 *
 * 0.005%. On a 1440-wide page 8,000px tall that is ~575 pixels — well under a
 * 1px border changing colour around a single 300x100 button (~800 pixels),
 * which is precisely the class of cascade-inversion bug §3.5 exists to catch.
 * A ratio alone is unsafe on short pages, so a small absolute floor sits under
 * it: a handful of isolated pixels is the classic false alarm and is not worth
 * a red build.
 *
 * Every difference is reported and a diff image written even when the count is
 * under budget, so nothing is ever silently swallowed — the budget decides the
 * exit code, not what you get told.
 */
const DEFAULT_FAIL_RATIO = 0.00005;
const FAIL_PIXELS_FLOOR = 120;

/**
 * Sequential by default.
 *
 * Chromium throttles timers and requestAnimationFrame in pages it considers
 * backgrounded, and every settle step below waits on exactly those. Parallel
 * pages would make the settle nondeterministic, which turns into intermittent
 * failures, which turns into a harness nobody trusts. 144 captures at ~3s is
 * roughly seven minutes; that is a cheap price for a result that means
 * something. --concurrency exists for anyone who measures otherwise.
 */
const DEFAULT_CONCURRENCY = 1;

/* ──────────────────────────────────────────────────────────────────────────
 *  CLI
 *  ────────────────────────────────────────────────────────────────────── */

const USAGE = `
vr-screens — full-page visual regression for the built site

  node scripts/vr-screens.mjs snapshot [dir] [options]   record dist/ as the baseline
  node scripts/vr-screens.mjs compare  [dir] [options]   diff dist/ against the baseline

The positional <dir> mirrors scripts/vr-html.mjs, so the two halves of the
harness are driven the same way:

  node scripts/vr-html.mjs    snapshot .vr/html
  node scripts/vr-screens.mjs snapshot .vr/screens

Options
  --out <dir>            output root, same as the positional (default .vr/screens)
  --force                overwrite an existing baseline on snapshot
  --routes <substr,...>  only routes containing one of these substrings
  --widths <n,...>       only these widths (default ${WIDTHS.map((w) => w.width).join(",")})
  --tolerance <ratio>    failing-pixel budget as a fraction (default ${DEFAULT_FAIL_RATIO})
  --pixel-threshold <n>  per-pixel colour distance 0..1 (default ${DEFAULT_PIXEL_THRESHOLD})
  --concurrency <n>      pages captured in parallel (default ${DEFAULT_CONCURRENCY})
  --help

Requires Playwright. The package IS a devDependency (it is small); its
  browser binaries are NOT, because they are ~150MB and would land on every
  npm install for a tool most runs never invoke. Install them once:
  ${PLAYWRIGHT_INSTALL}
  ${BROWSER_INSTALL}
`.trim();

function parseArgs(argv) {
  const opts = {
    mode: null,
    outDir: DEFAULT_OUT_DIR,
    routeFilters: [],
    widths: WIDTHS.map((w) => w.width),
    failRatio: DEFAULT_FAIL_RATIO,
    pixelThreshold: DEFAULT_PIXEL_THRESHOLD,
    concurrency: DEFAULT_CONCURRENCY,
    force: false,
  };
  let sawPositionalDir = false;

  for (let i = 0; i < argv.length; i++) {
    const arg = argv[i];
    const next = () => {
      const v = argv[++i];
      if (v === undefined) die(`${arg} needs a value`);
      return v;
    };
    switch (arg) {
      case "snapshot":
      case "compare":
        opts.mode = arg;
        break;
      case "--help":
      case "-h":
        console.log(USAGE);
        process.exit(0);
        break;
      case "--out":
        opts.outDir = path.resolve(next());
        sawPositionalDir = true;
        break;
      case "--force":
        opts.force = true;
        break;
      case "--routes":
        opts.routeFilters = next().split(",").map((s) => s.trim()).filter(Boolean);
        break;
      case "--widths":
        opts.widths = next().split(",").map((s) => Number(s.trim())).filter(Number.isFinite);
        break;
      case "--tolerance":
        opts.failRatio = Number(next());
        break;
      case "--pixel-threshold":
        opts.pixelThreshold = Number(next());
        break;
      case "--concurrency":
        opts.concurrency = Math.max(1, Number(next()) || 1);
        break;
      default:
        // A bare token after the mode is the output root, matching
        // vr-html.mjs's `snapshot <dir>` shape. Anything starting with a dash
        // is a typo, not a directory, and must not be silently swallowed.
        if (!arg.startsWith("-") && opts.mode && !sawPositionalDir) {
          opts.outDir = path.resolve(arg);
          sawPositionalDir = true;
          break;
        }
        die(`unknown argument: ${arg}\n\n${USAGE}`);
    }
  }

  if (!opts.mode) die(`expected "snapshot" or "compare"\n\n${USAGE}`);

  const unknownWidths = opts.widths.filter((w) => !WIDTHS.some((c) => c.width === w));
  if (unknownWidths.length) {
    die(`--widths must be a subset of ${WIDTHS.map((w) => w.width).join(", ")} (got ${unknownWidths.join(", ")})`);
  }
  return opts;
}

/** Harness could not run. Exit 2, never 1 — see the header. */
function die(message) {
  console.error(`\n[vr-screens] ${message}\n`);
  process.exit(2);
}

/* ──────────────────────────────────────────────────────────────────────────
 *  ROUTES — derived from the build, never hardcoded
 *  ────────────────────────────────────────────────────────────────────── */

/**
 * Every route the build actually emitted.
 *
 * Derived by walking dist/ rather than reading a list, for two reasons. A page
 * added in a later phase is covered the moment it builds, with no edit here —
 * a hardcoded list silently stops covering the site the first time someone
 * forgets it. And a page that DISAPPEARS shows up on compare as a baseline
 * with no capture, which is reported as a failure rather than skipped.
 *
 * The sitemap is not the source: it filters out /design-system/, the three
 * landing pages, /thank-you/ and /404/ (astro.config.mjs), and those are
 * exactly the pages carrying the most duplicated CSS.
 *
 * trailingSlash is 'always', so directory routes end in "/". 404.html is a
 * bare file at the root and keeps its extension.
 */
async function enumerateRoutes(distDir) {
  const routes = [];

  async function walk(dir, urlPrefix) {
    const entries = await readdir(dir, { withFileTypes: true });
    for (const entry of entries) {
      if (entry.isDirectory()) {
        if (entry.name === "_assets") continue;
        await walk(path.join(dir, entry.name), `${urlPrefix}${entry.name}/`);
      } else if (entry.name === "index.html") {
        routes.push(urlPrefix);
      } else if (entry.name.endsWith(".html")) {
        routes.push(`${urlPrefix}${entry.name}`);
      }
    }
  }

  await walk(distDir, "/");
  return routes.sort();
}

/**
 * Route to a filename stem.
 *
 * Collisions are asserted rather than assumed away: two routes mapping to one
 * filename would silently overwrite each other's baseline, and the harness
 * would then compare a page against a different page and report a confident,
 * meaningless failure. Better to refuse to start.
 */
function slugForRoute(route) {
  const trimmed = route.replace(/^\/+/, "").replace(/\/+$/, "");
  if (!trimmed) return "__root";
  return trimmed.replace(/[^a-zA-Z0-9._-]+/g, "__");
}

function assertUniqueSlugs(routes) {
  const seen = new Map();
  for (const route of routes) {
    const slug = slugForRoute(route);
    if (seen.has(slug)) {
      die(`routes "${seen.get(slug)}" and "${route}" both map to the file stem "${slug}"; add a disambiguator to slugForRoute()`);
    }
    seen.set(slug, route);
  }
}

/* ──────────────────────────────────────────────────────────────────────────
 *  SERVER
 *
 *  dist/ is a static artefact and something has to serve it over http:// —
 *  file:// would break the module scripts (CORS), relative asset URLs and any
 *  absolute /_assets/ path, which is most of the page.
 *
 *  A ~40-line node:http server rather than `astro preview`, deliberately. The
 *  point of this harness is to test the artefact that ships. `astro preview`
 *  goes through the configured adapter and is a second moving part whose
 *  behaviour can change under a dependency bump; serving the bytes in dist/
 *  cannot. It also means the harness has no npm-script dependency and cannot
 *  be affected by a dev server someone left running.
 *
 *  Port 0: the OS picks a free port. A hardcoded 4321 collides with `astro
 *  dev` and would silently screenshot the DEV site — same URLs, unbundled CSS,
 *  completely different pixels.
 *  ────────────────────────────────────────────────────────────────────── */

const CONTENT_TYPES = {
  ".html": "text/html; charset=utf-8",
  ".css": "text/css; charset=utf-8",
  ".js": "text/javascript; charset=utf-8",
  ".mjs": "text/javascript; charset=utf-8",
  ".json": "application/json; charset=utf-8",
  ".xml": "application/xml; charset=utf-8",
  ".txt": "text/plain; charset=utf-8",
  ".svg": "image/svg+xml",
  ".png": "image/png",
  ".jpg": "image/jpeg",
  ".jpeg": "image/jpeg",
  ".webp": "image/webp",
  ".avif": "image/avif",
  ".gif": "image/gif",
  ".ico": "image/x-icon",
  ".woff": "font/woff",
  ".woff2": "font/woff2",
  ".ttf": "font/ttf",
};

async function serveDist(distDir) {
  const server = createServer(async (req, res) => {
    try {
      const pathname = decodeURIComponent(new URL(req.url, "http://127.0.0.1").pathname);

      // Refuse traversal outright. The harness only ever requests its own
      // routes, but a server that can read outside dist/ is not one to leave
      // lying in a client repo.
      const resolved = path.resolve(distDir, `.${pathname}`);
      if (resolved !== distDir && !resolved.startsWith(distDir + path.sep)) {
        res.writeHead(403).end("forbidden");
        return;
      }

      let filePath = resolved;
      const info = await stat(filePath).catch(() => null);
      if (info?.isDirectory()) filePath = path.join(filePath, "index.html");
      else if (!info) {
        // trailingSlash is 'always', so /foo is not a route the build emits;
        // resolve it anyway so a stray in-page link does not 404 mid-capture.
        const asDir = path.join(resolved, "index.html");
        if (existsSync(asDir)) filePath = asDir;
      }

      const body = await readFile(filePath);
      res.writeHead(200, {
        "Content-Type": CONTENT_TYPES[path.extname(filePath)] ?? "application/octet-stream",
        "Cache-Control": "no-store",
      });
      res.end(body);
    } catch {
      res.writeHead(404, { "Content-Type": "text/plain" }).end("not found");
    }
  });

  await new Promise((resolve, reject) => {
    server.once("error", reject);
    server.listen(0, "127.0.0.1", resolve);
  });

  const { port } = server.address();
  return { origin: `http://127.0.0.1:${port}`, close: () => new Promise((r) => server.close(r)) };
}

/* ──────────────────────────────────────────────────────────────────────────
 *  PLAYWRIGHT — lazily imported, with the install command in every failure
 *  ────────────────────────────────────────────────────────────────────── */

async function loadPlaywright() {
  try {
    return await import("playwright");
  } catch (error) {
    if (error?.code !== "ERR_MODULE_NOT_FOUND" && !/Cannot find (module|package)/.test(String(error?.message))) {
      throw error;
    }
    die(
      [
        "Playwright is not installed, and this script cannot run without it.",
        "",
        "It is intentionally absent from package.json: it is a ~200MB browser",
        "download that only this harness needs, and this is a live client repo.",
        "Install it transiently, from the app root:",
        "",
        `    ${PLAYWRIGHT_INSTALL}`,
        `    ${BROWSER_INSTALL}`,
        "",
        `(--no-save keeps package.json and package-lock.json untouched, so the`,
        " production build never inherits a dev-time dependency.)",
      ].join("\n"),
    );
  }
}

async function launchChromium(playwright) {
  try {
    return await playwright.chromium.launch();
  } catch (error) {
    const message = String(error?.message ?? error);
    // The playwright package installs without its browsers. This is the second
    // half of the same problem and deserves the same actionable message rather
    // than Playwright's wall of text.
    if (/Executable doesn't exist|browserType\.launch|Please run the following/i.test(message)) {
      die(
        [
          "Playwright is installed but its Chromium build is missing.",
          "",
          `    ${BROWSER_INSTALL}`,
          "",
          "Original error:",
          message.split("\n").slice(0, 3).join("\n"),
        ].join("\n"),
      );
    }
    throw error;
  }
}

/* ──────────────────────────────────────────────────────────────────────────
 *  NETWORK
 *
 *  Everything off-origin is aborted. dist/ references app.nexhealth.com,
 *  googletagmanager.com, embed.typeform.com, maps.google.com and a session
 *  analytics host; GTM alone can inject a chat widget or a consent banner that
 *  appears in some runs and not others, and Typeform's loader can mount a
 *  popup. None of that is the site's CSS, all of it depends on someone else's
 *  uptime, and any of it can move a page's pixels between two runs of the same
 *  commit. Blocking makes the capture hermetic: it depends on dist/ and
 *  nothing else, so the harness works offline and gives the same answer today
 *  and in six months.
 *
 *  All fonts and images are self-hosted under /_assets/, so blocking costs the
 *  capture nothing real.
 *  ────────────────────────────────────────────────────────────────────── */

async function blockOffOrigin(context, origin) {
  await context.route("**/*", (route) => {
    const url = route.request().url();
    if (url.startsWith(origin) || url.startsWith("data:") || url.startsWith("blob:")) {
      return route.continue();
    }
    return route.abort();
  });
}

/* ──────────────────────────────────────────────────────────────────────────
 *  SETTLING — how a full-page capture is made stable
 *
 *  reducedMotion: 'reduce' is the load-bearing decision, and it is not a
 *  blunt instrument here: the site has a first-class reduced-motion path that
 *  this harness borrows. animations.js's `mm.add("(prefers-reduced-motion:
 *  reduce)")` branch kills every ScrollTrigger, clears the global timeline and
 *  restores every [data-anim] element to autoAlpha:1 — so the scroll-triggered
 *  GSAP reveals are not "not yet run", they are resolved to their final state
 *  on load. marquee.css stops the auto-scrolling track (which is otherwise
 *  driven off gsap.ticker and would be at a different offset in every single
 *  capture — the single most likely source of false failures on this site).
 *  lenis.js skips smooth scroll entirely, which is what makes the scroll pass
 *  below land exactly where it is told. animations.css collapses every
 *  remaining animation and transition to 0.01ms.
 *
 *  Lazy images are a separate problem that reduced motion does not solve:
 *  Chromium's full-page capture grabs beyond the viewport without scrolling,
 *  so 19 loading="lazy" images would photograph as empty boxes. The scroll
 *  pass below walks the page to the bottom to trip them, then returns to the
 *  top, and the capture waits for every image to be decoded — not merely
 *  loaded, since an undecoded image still paints blank.
 *  ────────────────────────────────────────────────────────────────────── */

/**
 * Injected after load. Every rule here is a guard against a specific way a
 * capture can differ from an identical one taken a second later.
 */
const STABILISER_CSS = `
  /* A smooth-scrolling root would still be gliding when the shot is taken. */
  html, body { scroll-behavior: auto !important; }
  /* marquee.css already stops this under reduced motion; belt and braces,
     because a track frozen one frame further along fails every marquee page
     and there is no legitimate transform on it in this mode. */
  [data-vs-marquee-track] { animation: none !important; transform: none !important; }
  /* A blinking caret in a focused field is a two-state pixel. */
  *, *::before, *::after { caret-color: transparent !important; }
`;

async function settle(page) {
  await page.waitForLoadState("load");
  // Bounded: with off-origin traffic aborted this settles immediately, but
  // networkidle is a heuristic and must never be able to hang the run.
  await page.waitForLoadState("networkidle", { timeout: 5000 }).catch(() => {});

  await page.addStyleTag({ content: STABILISER_CSS });

  // Fonts are self-hosted and subset; capturing before they swap in changes
  // every text pixel on the page and every metric that depends on them.
  await page.evaluate(() => document.fonts?.ready).catch(() => {});

  // Walk the page to trip loading="lazy" and any IntersectionObserver, then
  // return to the top so a sticky header is photographed in its at-rest state.
  await page.evaluate(async () => {
    const pause = (ms) => new Promise((r) => setTimeout(r, ms));
    const fullHeight = () => document.documentElement.scrollHeight;
    const step = Math.max(200, Math.floor(window.innerHeight * 0.8));
    for (let y = 0; y < fullHeight(); y += step) {
      window.scrollTo(0, y);
      await pause(40);
    }
    window.scrollTo(0, fullHeight());
    await pause(150);
    window.scrollTo(0, 0);
    await pause(150);
  });

  await page
    .waitForFunction(() => Array.from(document.images).every((img) => img.complete), null, { timeout: 15000 })
    .catch(() => {});
  // complete === true only means the fetch finished. decode() is what
  // guarantees the bitmap is ready to paint.
  //
  // Both of these are raced against a timer IN THE PAGE, because
  // page.evaluate() takes no timeout and an unbounded settle step can hang the
  // whole run with no output — which is exactly what happened the first time
  // this was run. An off-origin image request aborted by the route handler
  // above leaves its decode() pending forever rather than rejecting, so the
  // .catch() never fires and there is nothing to time out against.
  //
  // A settle step that gives up is correct here: the screenshot is taken
  // either way, and a picture that never decoded will differ from the baseline
  // and be reported. Failing loudly on a slow image would make the harness the
  // flakiest thing in the pipeline.
  await page
    .evaluate(
      (ms) =>
        Promise.race([
          Promise.all(Array.from(document.images).map((img) => img.decode().catch(() => {}))),
          new Promise((resolve) => setTimeout(resolve, ms)),
        ]),
      SETTLE_TIMEOUT_MS,
    )
    .catch(() => {});

  // Fonts again: the scroll pass can pull in text that requests a face not
  // needed above the fold.
  await page
    .evaluate(
      (ms) =>
        Promise.race([
          document.fonts?.ready ?? Promise.resolve(),
          new Promise((resolve) => setTimeout(resolve, ms)),
        ]),
      SETTLE_TIMEOUT_MS,
    )
    .catch(() => {});
  await page.waitForFunction(() => window.scrollY === 0, null, { timeout: 5000 }).catch(() => {});
}

/**
 * Freeze Date before any page script runs. See FIXED_CLOCK_ISO.
 */
function pinClock(context, iso) {
  return context.addInitScript((fixedIso) => {
    const fixed = new Date(fixedIso).getTime();
    const RealDate = Date;
    function FakeDate(...args) {
      if (!(this instanceof FakeDate)) return new RealDate(fixed).toString();
      return args.length === 0 ? new RealDate(fixed) : new RealDate(...args);
    }
    FakeDate.prototype = RealDate.prototype;
    FakeDate.now = () => fixed;
    FakeDate.parse = RealDate.parse;
    FakeDate.UTC = RealDate.UTC;
    Object.defineProperty(FakeDate, "name", { value: "Date" });
    globalThis.Date = FakeDate;
  }, iso);
}

async function captureRoute(browser, origin, route, widthConfig) {
  const context = await browser.newContext({
    viewport: { width: widthConfig.width, height: widthConfig.height },
    deviceScaleFactor: DEVICE_SCALE_FACTOR,
    hasTouch: widthConfig.hasTouch,
    isMobile: false,
    reducedMotion: "reduce",
    // The site has no prefers-color-scheme rules today, but an unpinned
    // colour scheme is a baseline that changes when the host machine flips to
    // dark mode at sunset.
    colorScheme: "light",
    locale: LOCALE,
    timezoneId: TIMEZONE,
  });

  try {
    await pinClock(context, FIXED_CLOCK_ISO);
    await blockOffOrigin(context, origin);

    const page = await context.newPage();
    await page.goto(`${origin}${route}`, { waitUntil: "load", timeout: 30000 });
    await settle(page);

    return await page.screenshot({
      fullPage: true,
      type: "png",
      // scale: 'css' keeps the output in CSS pixels regardless of
      // deviceScaleFactor, so a future change to that setting cannot silently
      // resize every baseline.
      scale: "css",
      // Finishes any CSS animation/transition still in flight and freezes it,
      // on top of the reduced-motion path. Playwright's own guarantee, cheap.
      animations: "disabled",
      caret: "hide",
      mask: MASK_SELECTORS.map((selector) => page.locator(selector)),
      maskColor: "#ff00ff",
      timeout: 60000,
    });
  } finally {
    await context.close();
  }
}

/* ──────────────────────────────────────────────────────────────────────────
 *  PNG
 *
 *  Decoded and encoded here rather than with pngjs/pixelmatch. Adding two npm
 *  packages to a live client repo to run a dev-time instrument is exactly the
 *  cost this script avoids for Playwright, and it would be inconsistent to
 *  avoid it there and accept it here. Chromium emits 8-bit non-interlaced
 *  RGBA; anything else is refused loudly rather than decoded wrongly, because
 *  a comparator that misreads its inputs reports differences that are not
 *  there.
 *  ────────────────────────────────────────────────────────────────────── */

const PNG_SIGNATURE = Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]);

const CRC_TABLE = (() => {
  const table = new Int32Array(256);
  for (let n = 0; n < 256; n++) {
    let c = n;
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    table[n] = c;
  }
  return table;
})();

function crc32(buf) {
  let c = -1;
  for (let i = 0; i < buf.length; i++) c = CRC_TABLE[(c ^ buf[i]) & 0xff] ^ (c >>> 8);
  return (c ^ -1) >>> 0;
}

function paeth(a, b, c) {
  const p = a + b - c;
  const pa = Math.abs(p - a);
  const pb = Math.abs(p - b);
  const pc = Math.abs(p - c);
  if (pa <= pb && pa <= pc) return a;
  return pb <= pc ? b : c;
}

/** Decode a PNG buffer to { width, height, data } where data is RGBA. */
function decodePng(buffer, label) {
  if (!buffer.subarray(0, 8).equals(PNG_SIGNATURE)) throw new Error(`${label}: not a PNG`);

  let width = 0;
  let height = 0;
  let colorType = -1;
  const idat = [];

  let offset = 8;
  while (offset < buffer.length) {
    const length = buffer.readUInt32BE(offset);
    const type = buffer.toString("ascii", offset + 4, offset + 8);
    const data = buffer.subarray(offset + 8, offset + 8 + length);
    offset += 12 + length;

    if (type === "IHDR") {
      width = data.readUInt32BE(0);
      height = data.readUInt32BE(4);
      const bitDepth = data[8];
      colorType = data[9];
      const interlace = data[12];
      if (bitDepth !== 8 || interlace !== 0 || (colorType !== 2 && colorType !== 6)) {
        throw new Error(
          `${label}: unsupported PNG (bitDepth ${bitDepth}, colorType ${colorType}, interlace ${interlace}); expected 8-bit non-interlaced RGB or RGBA`,
        );
      }
    } else if (type === "IDAT") {
      idat.push(data);
    } else if (type === "IEND") {
      break;
    }
  }

  const channels = colorType === 6 ? 4 : 3;
  const raw = inflateSync(Buffer.concat(idat));
  const stride = width * channels;
  const out = Buffer.alloc(width * height * 4);
  let prev = Buffer.alloc(stride);

  for (let y = 0; y < height; y++) {
    const rowStart = y * (stride + 1);
    const filter = raw[rowStart];
    const row = Buffer.from(raw.subarray(rowStart + 1, rowStart + 1 + stride));

    for (let i = 0; i < stride; i++) {
      const a = i >= channels ? row[i - channels] : 0;
      const b = prev[i];
      const c = i >= channels ? prev[i - channels] : 0;
      switch (filter) {
        case 0: break;
        case 1: row[i] = (row[i] + a) & 0xff; break;
        case 2: row[i] = (row[i] + b) & 0xff; break;
        case 3: row[i] = (row[i] + ((a + b) >> 1)) & 0xff; break;
        case 4: row[i] = (row[i] + paeth(a, b, c)) & 0xff; break;
        default: throw new Error(`${label}: unknown PNG row filter ${filter}`);
      }
    }

    for (let x = 0; x < width; x++) {
      const src = x * channels;
      const dst = (y * width + x) * 4;
      out[dst] = row[src];
      out[dst + 1] = row[src + 1];
      out[dst + 2] = row[src + 2];
      out[dst + 3] = channels === 4 ? row[src + 3] : 255;
    }
    prev = row;
  }

  return { width, height, data: out };
}

function pngChunk(type, data) {
  const head = Buffer.alloc(8);
  head.writeUInt32BE(data.length, 0);
  head.write(type, 4, "ascii");
  const crc = Buffer.alloc(4);
  crc.writeUInt32BE(crc32(Buffer.concat([head.subarray(4), data])), 0);
  return Buffer.concat([head, data, crc]);
}

/** Encode an RGB buffer (3 bytes per pixel) as a PNG. Filter 0 throughout. */
function encodePngRgb(width, height, rgb) {
  const stride = width * 3;
  const raw = Buffer.alloc((stride + 1) * height);
  for (let y = 0; y < height; y++) {
    raw[y * (stride + 1)] = 0;
    rgb.copy(raw, y * (stride + 1) + 1, y * stride, y * stride + stride);
  }
  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(width, 0);
  ihdr.writeUInt32BE(height, 4);
  ihdr[8] = 8;
  ihdr[9] = 2;
  return Buffer.concat([
    PNG_SIGNATURE,
    pngChunk("IHDR", ihdr),
    pngChunk("IDAT", deflateSync(raw, { level: 6 })),
    pngChunk("IEND", Buffer.alloc(0)),
  ]);
}

/* ──────────────────────────────────────────────────────────────────────────
 *  COMPARE
 *  ────────────────────────────────────────────────────────────────────── */

/**
 * YIQ-weighted colour distance, normalised so black-vs-white is ~0.97.
 *
 * Plain RGB euclidean distance treats a blue shift the eye barely registers as
 * equal to a luminance shift it cannot miss, so a threshold tuned to catch the
 * second cries wolf on the first. YIQ weights luminance the way perception
 * does, which is what makes a single threshold usable across a page of text,
 * photography and flat brand colour.
 */
function colorDelta(a, b, i, j) {
  const r1 = a[i], g1 = a[i + 1], b1 = a[i + 2], a1 = a[i + 3];
  const r2 = b[j], g2 = b[j + 1], b2 = b[j + 2], a2 = b[j + 3];
  if (r1 === r2 && g1 === g2 && b1 === b2 && a1 === a2) return 0;

  // Composite over white so a transparency change is not invisible. Full-page
  // screenshots are opaque, but a decoded RGB source is padded to alpha 255
  // and this keeps the two paths comparable.
  const f1 = a1 / 255, f2 = a2 / 255;
  const R1 = 255 + (r1 - 255) * f1, G1 = 255 + (g1 - 255) * f1, B1 = 255 + (b1 - 255) * f1;
  const R2 = 255 + (r2 - 255) * f2, G2 = 255 + (g2 - 255) * f2, B2 = 255 + (b2 - 255) * f2;

  const y = 0.29889531 * (R1 - R2) + 0.58662247 * (G1 - G2) + 0.11448223 * (B1 - B2);
  const i2 = 0.59597799 * (R1 - R2) - 0.2741761 * (G1 - G2) - 0.32180189 * (B1 - B2);
  const q = 0.21147017 * (R1 - R2) - 0.52261711 * (G1 - G2) + 0.31114694 * (B1 - B2);

  // 35215 is the conventional normalising constant for this expression;
  // dividing by it puts the result on a ~0..1 scale (black vs white measures
  // 0.966) so DEFAULT_PIXEL_THRESHOLD is a number with a meaning.
  return Math.sqrt(0.5053 * y * y + 0.299 * i2 * i2 + 0.1957 * q * q) / Math.sqrt(35215);
}

/**
 * Compare two decoded images.
 *
 * A size mismatch is not an error — it is the most valuable single result the
 * harness produces, because "the page got 40px taller" localises a layout
 * regression instantly. The overlapping region is still compared and the
 * non-overlapping strip is painted so the diff image shows WHERE the page
 * changed length.
 */
function compareImages(baseline, current, pixelThreshold) {
  const width = Math.max(baseline.width, current.width);
  const height = Math.max(baseline.height, current.height);
  const diff = Buffer.alloc(width * height * 3);

  let differing = 0;
  const overlapWidth = Math.min(baseline.width, current.width);
  const overlapHeight = Math.min(baseline.height, current.height);

  for (let y = 0; y < height; y++) {
    for (let x = 0; x < width; x++) {
      const out = (y * width + x) * 3;

      if (x >= overlapWidth || y >= overlapHeight) {
        // Blue: this pixel exists in only one of the two images.
        diff[out] = 0x22;
        diff[out + 1] = 0x66;
        diff[out + 2] = 0xff;
        differing++;
        continue;
      }

      const bi = (y * baseline.width + x) * 4;
      const ci = (y * current.width + x) * 4;

      if (colorDelta(baseline.data, current.data, bi, ci) > pixelThreshold) {
        // Red: a real difference.
        diff[out] = 0xff;
        diff[out + 1] = 0x00;
        diff[out + 2] = 0x40;
        differing++;
      } else {
        // Unchanged pixels are kept as a washed-out greyscale of the baseline,
        // so the diff image is readable as a page rather than as red confetti
        // on black — you can see which section moved without opening the
        // baseline alongside it.
        const lum =
          0.299 * baseline.data[bi] + 0.587 * baseline.data[bi + 1] + 0.114 * baseline.data[bi + 2];
        const washed = 255 - (255 - lum) * 0.12;
        diff[out] = diff[out + 1] = diff[out + 2] = washed;
      }
    }
  }

  return {
    width,
    height,
    diff,
    differing,
    totalPixels: width * height,
    sizeMismatch:
      baseline.width !== current.width || baseline.height !== current.height
        ? { baseline: [baseline.width, baseline.height], current: [current.width, current.height] }
        : null,
  };
}

/* ──────────────────────────────────────────────────────────────────────────
 *  MANIFEST
 *
 *  Comparing a baseline captured under one set of settings against a run using
 *  another produces differences that have nothing to do with the code. That is
 *  the worst outcome available to this script: a confident, entirely wrong
 *  answer during exactly the phase everyone has agreed to trust it. So the
 *  settings that move pixels are recorded and re-checked.
 *  ────────────────────────────────────────────────────────────────────── */

function pixelAffectingSettings() {
  return {
    widths: WIDTHS,
    deviceScaleFactor: DEVICE_SCALE_FACTOR,
    fixedClock: FIXED_CLOCK_ISO,
    timezone: TIMEZONE,
    locale: LOCALE,
    reducedMotion: "reduce",
    colorScheme: "light",
    maskSelectors: MASK_SELECTORS,
    networkBlocked: "off-origin",
  };
}

function checkManifest(baselineManifest, browserVersion, playwrightVersion) {
  const expected = JSON.stringify(pixelAffectingSettings());
  const recorded = JSON.stringify(baselineManifest.settings ?? {});
  if (expected !== recorded) {
    die(
      [
        "the baseline was captured with different capture settings, so comparing",
        "against it would report differences this code did not cause.",
        "",
        `  baseline: ${recorded}`,
        `  current:  ${expected}`,
        "",
        "Re-run `snapshot` on the unmodified build before comparing.",
      ].join("\n"),
    );
  }

  // A browser bump changes font rasterisation, which is real drift the
  // tolerance was NOT sized for. Warn rather than refuse: the operator may
  // legitimately be re-baselining, and they need to know why the run is noisy.
  if (baselineManifest.chromiumVersion && baselineManifest.chromiumVersion !== browserVersion) {
    console.warn(
      `[vr-screens] WARNING: baseline captured with Chromium ${baselineManifest.chromiumVersion}, running ${browserVersion}.\n` +
        `             Font rasterisation differs between builds; treat small diffs with suspicion and re-snapshot.`,
    );
  }
  if (baselineManifest.playwrightVersion && baselineManifest.playwrightVersion !== playwrightVersion) {
    console.warn(
      `[vr-screens] WARNING: baseline captured with Playwright ${baselineManifest.playwrightVersion}, running ${playwrightVersion}.`,
    );
  }
}

async function playwrightVersion() {
  try {
    const pkg = await readFile(path.join(APP_ROOT, "node_modules", "playwright", "package.json"), "utf8");
    return JSON.parse(pkg).version ?? "unknown";
  } catch {
    return "unknown";
  }
}

/**
 * .vr/ holds 144 full-page PNGs. If it is not ignored, the next `git add -A`
 * puts hundreds of megabytes of screenshots into a live client repo's history,
 * which is not undoable without a rewrite. Read-only check, non-fatal.
 */
function warnIfTracked(outDir) {
  try {
    execFileSync("git", ["check-ignore", "-q", outDir], { cwd: APP_ROOT, stdio: "ignore" });
  } catch {
    console.warn(
      `[vr-screens] WARNING: ${path.relative(APP_ROOT, outDir)} is not gitignored.\n` +
        `             These are full-page PNGs; add the directory to .gitignore before committing anything.`,
    );
  }
}

/* ──────────────────────────────────────────────────────────────────────────
 *  MAIN
 *  ────────────────────────────────────────────────────────────────────── */

function formatCount(n) {
  return n.toLocaleString("en-US");
}

async function main() {
  const opts = parseArgs(process.argv.slice(2));

  // Playwright first: it is the likeliest thing to be missing and its message
  // is the one the operator needs, unobscured by anything else.
  const playwright = await loadPlaywright();

  if (!existsSync(DIST_DIR)) {
    die(`no build found at ${DIST_DIR}. The harness captures the built site, not the dev server — build first.`);
  }

  let routes = await enumerateRoutes(DIST_DIR);
  assertUniqueSlugs(routes);
  const allRoutesCount = routes.length;
  if (opts.routeFilters.length) {
    routes = routes.filter((r) => opts.routeFilters.some((f) => r.includes(f)));
  }
  if (!routes.length) die("no routes matched --routes");

  const widths = WIDTHS.filter((w) => opts.widths.includes(w.width));

  const baselineDir = path.join(opts.outDir, "baseline");
  const currentDir = path.join(opts.outDir, "current");
  const diffDir = path.join(opts.outDir, "diff");
  const manifestPath = path.join(opts.outDir, "manifest.json");
  const targetDir = opts.mode === "snapshot" ? baselineDir : currentDir;

  // Re-baselining is how a harness gets quietly disarmed: run `snapshot`
  // instead of `compare` by mistake and the reference you were about to check
  // against is replaced by the very build you were checking. --force is the
  // deliberate act, matching vr-html.mjs.
  if (opts.mode === "snapshot" && !opts.force && !opts.routeFilters.length && existsSync(manifestPath)) {
    die(
      `a baseline already exists at ${path.relative(APP_ROOT, baselineDir)}.\n` +
        "Overwriting it discards the reference this phase is verified against.\n" +
        "  - to verify the current build:   node scripts/vr-screens.mjs compare\n" +
        "  - to deliberately re-baseline:   node scripts/vr-screens.mjs snapshot --force",
    );
  }

  // Checked BEFORE capturing, not after. The same refusal used to fire at the
  // end, having already spent a full capture run on captures it then declined
  // to record — the most annoying possible ordering.
  const isPartialRun = opts.routeFilters.length > 0 || widths.length !== WIDTHS.length;

  if (opts.mode === "snapshot" && isPartialRun && !existsSync(manifestPath) && !opts.force) {
    die(
      "a filtered snapshot cannot establish a baseline — the routes it skips would have no\n" +
        "reference at all, and compare would report them as failures.\n\n" +
        "  - full baseline:              node scripts/vr-screens.mjs snapshot\n" +
        "  - deliberately scoped one:    add --force, and compare with the same filters\n\n" +
        "A scoped baseline is honest when the change provably cannot reach the other routes —\n" +
        "vr-html.mjs is what proves that. It is recorded in the manifest so compare can enforce it.",
    );
  }

  await mkdir(targetDir, { recursive: true });
  if (opts.mode === "compare") await mkdir(diffDir, { recursive: true });
  warnIfTracked(opts.outDir);

  let baselineManifest = null;
  if (opts.mode === "compare") {
    if (!existsSync(manifestPath)) {
      die(`no baseline at ${baselineDir}. Run \`node scripts/vr-screens.mjs snapshot\` against the unmodified build first.`);
    }
    baselineManifest = JSON.parse(await readFile(manifestPath, "utf8"));

    if (baselineManifest.scope) {
      const covered = new Set(baselineManifest.scope.routes ?? []);
      const uncovered = routes.filter((r) => !covered.has(r));
      if (uncovered.length) {
        die(
          `this baseline is SCOPED to ${covered.size} route(s) and has no reference for ` +
            `${uncovered.length} of the routes you asked to compare.\n` +
            `First uncovered: ${uncovered.slice(0, 3).join(", ")}\n\n` +
            "Use the same --routes filter the scoped snapshot used, or record a full baseline.",
        );
      }
    }
  }

  const pwVersion = await playwrightVersion();
  const browser = await launchChromium(playwright);
  const chromiumVersion = browser.version();
  if (baselineManifest) checkManifest(baselineManifest, chromiumVersion, pwVersion);

  const server = await serveDist(DIST_DIR);

  console.log(
    `[vr-screens] ${opts.mode}: ${routes.length} of ${allRoutesCount} built routes x ${widths.length} widths = ${routes.length * widths.length} captures`,
  );
  console.log(`[vr-screens] serving ${path.relative(APP_ROOT, DIST_DIR)} at ${server.origin}`);
  console.log(`[vr-screens] chromium ${chromiumVersion} · playwright ${pwVersion} · clock pinned to ${FIXED_CLOCK_ISO}\n`);

  const jobs = [];
  for (const route of routes) {
    for (const widthConfig of widths) jobs.push({ route, widthConfig });
  }

  const failures = [];
  let passed = 0;
  let cursor = 0;

  async function worker() {
    while (cursor < jobs.length) {
      const { route, widthConfig } = jobs[cursor++];
      const name = `${slugForRoute(route)}@${widthConfig.width}.png`;
      const label = `${route.padEnd(64)} @${String(widthConfig.width).padEnd(4)}`;

      let png;
      try {
        png = await captureRoute(browser, server.origin, route, widthConfig);
      } catch (error) {
        failures.push({ route, width: widthConfig.width, reason: `capture failed: ${error?.message ?? error}` });
        console.log(`FAIL  ${label}  capture failed: ${String(error?.message ?? error).split("\n")[0]}`);
        continue;
      }

      await writeFile(path.join(targetDir, name), png);

      if (opts.mode === "snapshot") {
        passed++;
        console.log(`REC   ${label}  ${formatCount(png.length)} bytes`);
        continue;
      }

      const baselinePath = path.join(baselineDir, name);
      if (!existsSync(baselinePath)) {
        // Not a pass. A route with no baseline is a route this run cannot
        // vouch for, and silently skipping it is how a new page ships
        // unverified.
        failures.push({ route, width: widthConfig.width, reason: "no baseline for this route; re-snapshot" });
        console.log(`FAIL  ${label}  no baseline (new route?) — re-run snapshot on the unmodified build`);
        continue;
      }

      let result;
      try {
        result = compareImages(
          decodePng(await readFile(baselinePath), `baseline ${name}`),
          decodePng(png, `current ${name}`),
          opts.pixelThreshold,
        );
      } catch (error) {
        failures.push({ route, width: widthConfig.width, reason: `compare failed: ${error?.message ?? error}` });
        console.log(`FAIL  ${label}  ${error?.message ?? error}`);
        continue;
      }

      const budget = Math.max(FAIL_PIXELS_FLOOR, Math.round(result.totalPixels * opts.failRatio));
      const ratio = result.differing / result.totalPixels;
      const failed = result.sizeMismatch !== null || result.differing > budget;

      // A diff image is written whenever anything differs at all, even under
      // budget, so a sub-threshold change is inspectable rather than hidden.
      let diffPath = null;
      if (result.differing > 0) {
        diffPath = path.join(diffDir, name);
        await writeFile(diffPath, encodePngRgb(result.width, result.height, result.diff));
      }

      const detail =
        `${formatCount(result.differing)} px (${(ratio * 100).toFixed(4)}%)` +
        (result.sizeMismatch
          ? `  SIZE ${result.sizeMismatch.baseline.join("x")} -> ${result.sizeMismatch.current.join("x")}`
          : "");

      if (failed) {
        failures.push({
          route,
          width: widthConfig.width,
          reason: detail,
          diffPath,
        });
        console.log(`FAIL  ${label}  ${detail}\n      -> ${path.relative(APP_ROOT, diffPath)}`);
      } else {
        passed++;
        const note = result.differing > 0 ? `  ${detail} (under budget of ${formatCount(budget)} px)` : "";
        console.log(`PASS  ${label}  ${result.differing === 0 ? "identical" : "within tolerance"}${note}`);
      }
    }
  }

  try {
    await Promise.all(Array.from({ length: Math.min(opts.concurrency, jobs.length) }, worker));
  } finally {
    await browser.close();
    await server.close();
  }

  if (opts.mode === "snapshot") {
    // A filtered snapshot re-records SOME routes. Letting it write a fresh
    // manifest would stamp a full-baseline record onto a partial one, and the
    // next compare would vouch for 48 routes on the strength of having shot
    // three. A partial run therefore amends the existing manifest instead of
    // replacing it, and cannot establish a baseline where none exists.
    const isPartial = isPartialRun;

    // A scoped baseline established with --force records WHAT it covers, so a
    // later compare cannot quietly read it as a full one.
    if (isPartial && !existsSync(manifestPath)) {
      await writeFile(
        manifestPath,
        JSON.stringify(
          {
            chromiumVersion,
            playwrightVersion: pwVersion,
            buildYear: new Date().getUTCFullYear(),
            scope: { routes, widths: widths.map((w) => w.width) },
            scopedAt: new Date().toISOString(),
          },
          null,
          2,
        ) + "\n",
        "utf8",
      );
      console.log(
        `\n[vr-screens] SCOPED baseline: ${routes.length} route(s) only. compare must use the same\n` +
          "             filters; the other routes have no reference and are not vouched for.",
      );
      process.exit(0);
    }
    if (isPartial) {
      const existing = JSON.parse(await readFile(manifestPath, "utf8"));
      existing.partialReBaselines = [
        ...(existing.partialReBaselines ?? []),
        {
          at: new Date().toISOString(),
          routes,
          widths: widths.map((w) => w.width),
          chromiumVersion,
        },
      ];
      await writeFile(manifestPath, `${JSON.stringify(existing, null, 2)}\n`);
      console.log(`\n[vr-screens] re-recorded ${formatCount(passed)} captures (partial baseline).`);
      console.log(
        `[vr-screens] NOTE: the baseline now mixes builds — ${routes.length} route(s) from this build,\n` +
          `             the rest from ${existing.capturedAt}. Recorded in the manifest.`,
      );
      process.exit(failures.length ? 1 : 0);
    }

    await writeFile(
      manifestPath,
      `${JSON.stringify(
        {
          capturedAt: new Date().toISOString(),
          // The copyright year in Footer.astro and LandingLayout.astro is
          // baked in at BUILD time, not read in the browser, so the pinned
          // clock cannot fix it. A baseline recorded in December and compared
          // in January will flag the footer on all 48 pages. Recording the
          // year makes that diagnosable in one line instead of an afternoon.
          buildYear: new Date().getFullYear(),
          routes,
          widths: widths.map((w) => w.width),
          chromiumVersion,
          playwrightVersion: pwVersion,
          settings: pixelAffectingSettings(),
        },
        null,
        2,
      )}\n`,
    );
    console.log(`\n[vr-screens] recorded ${formatCount(passed)} captures to ${path.relative(APP_ROOT, baselineDir)}`);
    console.log(`[vr-screens] manifest: ${path.relative(APP_ROOT, manifestPath)}`);
    if (failures.length) {
      console.log(`[vr-screens] ${failures.length} capture(s) FAILED to record — the baseline is incomplete.`);
      process.exit(1);
    }
    process.exit(0);
  }

  const total = passed + failures.length;
  console.log(`\n[vr-screens] ${formatCount(total)} captures · ${formatCount(passed)} pass · ${formatCount(failures.length)} fail`);

  if (baselineManifest?.buildYear && baselineManifest.buildYear !== new Date().getFullYear()) {
    console.log(
      `[vr-screens] NOTE: baseline was built in ${baselineManifest.buildYear}, it is now ${new Date().getFullYear()}.\n` +
        `             The footer copyright year is server-rendered, so every page's footer will differ. Re-snapshot.`,
    );
  }

  if (failures.length) {
    console.log("\n[vr-screens] failures:");
    for (const failure of failures) {
      console.log(`  ${failure.route} @${failure.width}  ${failure.reason}`);
      if (failure.diffPath) console.log(`      ${path.relative(APP_ROOT, failure.diffPath)}`);
    }
    process.exit(1);
  }

  console.log("[vr-screens] no visual change.");
  process.exit(0);
}

main().catch((error) => {
  console.error(`\n[vr-screens] unexpected failure:\n${error?.stack ?? error}\n`);
  process.exit(2);
});
