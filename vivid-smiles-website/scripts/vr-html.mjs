/**
 * HTML half of the visual-regression harness: prove a CSS refactor changed no markup.
 *
 *   node scripts/vr-html.mjs snapshot <dir>            copy dist/**\/*.html into <dir>
 *   node scripts/vr-html.mjs compare  <dir> [current]  diff current build against <dir>
 *
 * `current` defaults to dist/. Exit 0 when nothing differs, 1 when something
 * does, 2 on a usage or I/O problem. The non-zero exit is the point: this is
 * meant to gate a script, not to be read optimistically.
 *
 * WHY THIS IS NEEDED
 *
 * The page-blocks migration moves CSS around underneath a live site. The only
 * claim that matters at each step is "the markup is unchanged", and the only
 * way to make that claim cheaply is to diff the built HTML before and after.
 *
 * A naive diff cannot make that claim. Built pages link content-hashed bundles
 * (`/_assets/index_astro.bsRSD1t0.css`), so editing one declaration in one
 * stylesheet changes the hash, and the diff reports all 48 pages as changed —
 * loudest precisely when the change is the kind this harness exists to clear.
 * So the hash segment is replaced with a stable token before comparing.
 *
 * WHAT IS NORMALISED, AND WHAT DELIBERATELY IS NOT
 *
 * Every normalisation is a blind spot, so the list is short and each entry is
 * justified against a failure it would otherwise cause:
 *
 *   - Content hashes in /_assets/<name>.<hash>.css|js, and only those. The
 *     `<name>` is preserved, so if veneers-lp and cosmetic-dentistry-lp ever
 *     stop being separate bundles the diff still shows it: the link goes from
 *     veneers-lp_astro.[hash].css to cosmetic-dentistry-lp_astro.[hash].css.
 *     Sixteen routes share the base name `index_astro`, though, and for those
 *     the hash was the only thing telling one route's page sheet from
 *     another's. That hole is closed outside the per-route diff, by the ASSET
 *     LINKS check (see assetGroups), rather than by weakening the
 *     normalisation: it compares which routes share a built file, a fact that
 *     survives hash churn and moves only when bundles really merge or swap.
 *
 * Not normalised, each for a measured reason:
 *
 *   - Image URLs (`/_assets/name_ZD3220.webp`). Astro derives that hash from
 *     the source image and the transform, not from any stylesheet, so a CSS
 *     edit cannot move it. If it moves, the image or its props really changed.
 *   - `data-astro-cid-*` scope tokens. The compiler hashes the component's
 *     root-relative path (core/compile/compile.js passes `normalizedFilename`),
 *     not its contents, so editing a component's <style> block leaves the token
 *     alone. A token that does move means a component moved or was renamed.
 *   - Whitespace. Equality is decided on the exact normalised bytes, because
 *     whitespace between inline elements is rendered whitespace and collapsing
 *     it would hide a real visual change. For readability the text is split
 *     into display lines only at zero-width `><` and post-newline boundaries,
 *     which adds and removes nothing: the lines rejoin to the original string
 *     exactly. A difference that is only whitespace is labelled as such rather
 *     than hidden, since it is otherwise invisible on a terminal.
 *   - Inline <style> blocks. 46 of 48 pages carry one: Astro inlines small
 *     scoped stylesheets straight into the HTML. CSS work therefore does show
 *     up in this diff, by design — an inlined sheet is render-blocking markup,
 *     and a sheet crossing the inline size threshold silently changes how the
 *     page loads. That is exactly the kind of thing worth failing on.
 *
 * Snapshots store raw HTML, not normalised HTML. A build costs ~3 minutes
 * against a rate-limited CMS, so a baseline is expensive to recreate and must
 * survive a later fix to the normalisation rules: normalising at compare time
 * means both sides can be re-read under new rules without rebuilding anything.
 */

import { readdir, readFile, mkdir, rm, writeFile, stat } from "node:fs/promises";
import path from "node:path";
import process from "node:process";

const DEFAULT_CURRENT_DIR = "dist";

// Display caps. A report nobody reads to the end is a report that does not
// gate anything, so the default output stays short and --full opts into all of
// it. These bound presentation only; the pass/fail verdict is never truncated.
const MAX_ROUTES_SHOWN = 12;
const MAX_HUNKS_PER_ROUTE = 8;
const MAX_DIFF_LINES_PER_ROUTE = 60;
const MAX_LINE_CHARS = 200;
const CHAR_WINDOW = 60;

// Rollup emits an 8-character base64url hash as the second-to-last segment,
// per the assetFileNames override in astro.config.mjs. The leading group is
// greedy so the hash matches the LAST such segment: bundle names contain dots
// of their own (BaseLayout.astro_astro_type_script_index_0_lang.JsBA7PuL.js).
const ASSET_HASH = /(\/_assets\/[^"'\s>]*)\.[A-Za-z0-9_-]{8}\.(css|js)\b/g;
const HASH_TOKEN = "$1.[hash].$2";

function normalise(html) {
  return html.replace(ASSET_HASH, HASH_TOKEN);
}

/** Split at zero-width boundaries only, so the pieces rejoin to the input. */
function displayLines(text) {
  return text.split(/(?<=\n)|(?<=>)(?=<)/);
}

function routeOf(relPath) {
  const posix = relPath.split(path.sep).join("/");
  if (posix.endsWith("/index.html")) return "/" + posix.slice(0, -"index.html".length);
  if (posix === "index.html") return "/";
  return "/" + posix;
}

async function collectHtml(dir) {
  const files = new Map();
  async function walk(current) {
    let entries;
    try {
      entries = await readdir(current, { withFileTypes: true });
    } catch (err) {
      if (err.code === "ENOENT") return;
      throw err;
    }
    for (const entry of entries) {
      const full = path.join(current, entry.name);
      if (entry.isDirectory()) await walk(full);
      else if (entry.name.endsWith(".html")) {
        files.set(path.relative(dir, full), await readFile(full, "utf8"));
      }
    }
  }
  await walk(dir);
  return files;
}

/**
 * How the routes partition across bundles: one entry per distinct built file.
 *
 * This is what keeps the hash normalisation honest, and it has to be built from
 * the RAW hashed URLs to do its job. Sixteen routes each link a different page
 * sheet that is nonetheless named `index_astro.<hash>.css`, so the hash is the
 * only thing telling them apart; group by the normalised name and /about-us/
 * quietly picking up /our-office/'s sheet reads as no change at all.
 *
 * Grouping by the raw URL and labelling the group with the normalised name
 * keeps the one property that survives hash churn: which routes share a file.
 * A pure stylesheet refactor rewrites every hash and leaves that partition
 * alone. A merge, a swap, or a sheet that stopped being emitted moves it.
 */
function assetGroups(pages, sharedRoutes) {
  const byFile = new Map();
  for (const [route, html] of pages) {
    if (!sharedRoutes.has(route)) continue;
    for (const match of html.matchAll(/\/_assets\/[^"'\s>]*\.(?:css|js)\b/g)) {
      if (!byFile.has(match[0])) byFile.set(match[0], new Set());
      byFile.get(match[0]).add(route);
    }
  }
  return [...byFile].map(([url, routes]) => ({ name: normalise(url), routes: [...routes].sort() }));
}

/** Multiset difference, so a bundle splitting or merging shows on both sides. */
function compareAssetGroups(before, after) {
  const key = (group) => `${group.name}\u0000${group.routes.join(", ")}`;
  const counts = new Map();
  for (const group of before) counts.set(key(group), (counts.get(key(group)) ?? 0) + 1);
  for (const group of after) counts.set(key(group), (counts.get(key(group)) ?? 0) - 1);

  const findings = [];
  for (const [k, delta] of counts) {
    if (delta === 0) continue;
    const [name, routes] = k.split("\u0000");
    for (let n = 0; n < Math.abs(delta); n++) findings.push({ gone: delta > 0, name, routes });
  }
  return findings.sort((a, b) => a.name.localeCompare(b.name) || a.routes.localeCompare(b.routes));
}

/**
 * Longest-common-subsequence diff over display lines.
 *
 * Common prefix and suffix are trimmed first, which is what makes this cheap in
 * the expected case of a near-identical page. The largest page in this build is
 * 716 display lines, so the quadratic table is small; the guard below is only
 * there so a pathological input degrades into a coarse report instead of
 * exhausting memory and taking the whole check down with it.
 */
function diffLines(aLines, bLines) {
  let start = 0;
  while (start < aLines.length && start < bLines.length && aLines[start] === bLines[start]) start++;
  let endA = aLines.length;
  let endB = bLines.length;
  while (endA > start && endB > start && aLines[endA - 1] === bLines[endB - 1]) {
    endA--;
    endB--;
  }
  const a = aLines.slice(start, endA);
  const b = bLines.slice(start, endB);
  if (a.length === 0 && b.length === 0) return [];

  const ops = [];
  const n = a.length;
  const m = b.length;

  if (n * m > 4_000_000) {
    for (let i = 0; i < n; i++) ops.push({ type: "del", text: a[i], a: start + i, b: start });
    for (let j = 0; j < m; j++) ops.push({ type: "ins", text: b[j], a: start + n, b: start + j });
    return ops;
  }

  const width = m + 1;
  const dp = new Uint32Array((n + 1) * width);
  for (let i = n - 1; i >= 0; i--) {
    for (let j = m - 1; j >= 0; j--) {
      dp[i * width + j] =
        a[i] === b[j]
          ? dp[(i + 1) * width + (j + 1)] + 1
          : Math.max(dp[(i + 1) * width + j], dp[i * width + (j + 1)]);
    }
  }

  let i = 0;
  let j = 0;
  while (i < n && j < m) {
    if (a[i] === b[j]) {
      ops.push({ type: "eq", text: a[i], a: start + i, b: start + j });
      i++;
      j++;
    } else if (dp[(i + 1) * width + j] >= dp[i * width + (j + 1)]) {
      ops.push({ type: "del", text: a[i], a: start + i, b: start + j });
      i++;
    } else {
      ops.push({ type: "ins", text: b[j], a: start + i, b: start + j });
      j++;
    }
  }
  while (i < n) ops.push({ type: "del", text: a[i], a: start + i, b: start + j, i: i++ });
  while (j < m) ops.push({ type: "ins", text: b[j], a: start + i, b: start + j, j: j++ });
  return ops;
}

function toHunks(ops, context) {
  const changed = ops.map((op) => op.type !== "eq");
  const hunks = [];
  let cursor = 0;
  while (cursor < ops.length) {
    if (!changed[cursor]) {
      cursor++;
      continue;
    }
    let end = cursor;
    while (end < ops.length) {
      // Absorb a short run of equal lines rather than splitting one edit into
      // two hunks the reader has to mentally rejoin.
      let gap = end;
      while (gap < ops.length && !changed[gap]) gap++;
      if (gap < ops.length && gap - end <= context * 2) end = gap + 1;
      else break;
    }
    while (end < ops.length && changed[end]) end++;
    const from = Math.max(0, cursor - context);
    const to = Math.min(ops.length, end + context);
    hunks.push(ops.slice(from, to));
    cursor = to;
  }
  return hunks;
}

// Whitespace removed outright, not collapsed to a single space: a DELETED space
// is the case that needs labelling, and collapsing runs would still read the
// two sides as different and leave the reader staring at two identical-looking
// lines. Removing a space between two inline elements also changes the display
// line split, so this has to compare the whole hunk, not line for line.
const withoutSpace = (text) => text.replace(/\s+/g, "");

function clip(text) {
  const flat = text.replace(/\n/g, "\\n");
  return flat.length <= MAX_LINE_CHARS ? flat : flat.slice(0, MAX_LINE_CHARS) + " …[" + flat.length + " chars]";
}

/**
 * Point at where two long lines actually diverge.
 *
 * Astro inlines minified CSS as single lines of several thousand characters, so
 * a one-declaration change otherwise prints as two walls of near-identical text
 * with no indication of what moved.
 */
function charFocus(before, after) {
  let head = 0;
  const limit = Math.min(before.length, after.length);
  while (head < limit && before[head] === after[head]) head++;
  let tail = 0;
  while (tail < limit - head && before[before.length - 1 - tail] === after[after.length - 1 - tail]) tail++;
  const window = (text) => {
    const from = Math.max(0, head - CHAR_WINDOW);
    const to = Math.min(text.length, text.length - tail + CHAR_WINDOW);
    const mid = text.slice(head, text.length - tail);
    return (
      (from > 0 ? "…" : "") +
      text.slice(from, head) +
      ">>>" + (mid.length > 400 ? mid.slice(0, 400) + " …[" + mid.length + " chars]" : mid) + "<<<" +
      text.slice(text.length - tail, to) +
      (to < text.length ? "…" : "")
    ).replace(/\n/g, "\\n");
  };
  return { at: head, before: window(before), after: window(after) };
}

function renderHunk(hunk, out, full) {
  const first = hunk[0];
  out.push(`  @@ line ${first.a + 1} @@`);

  const dels = hunk.filter((op) => op.type === "del").map((op) => op.text);
  const inss = hunk.filter((op) => op.type === "ins").map((op) => op.text);
  if (dels.length && inss.length && withoutSpace(dels.join("")) === withoutSpace(inss.join(""))) {
    out.push("     WHITESPACE ONLY — same tags, attributes and text. Not automatically");
    out.push("     harmless: whitespace between two inline elements is a rendered space.");
  }

  if (dels.length === 1 && inss.length === 1 && (dels[0].length > MAX_LINE_CHARS || inss[0].length > MAX_LINE_CHARS)) {
    const focus = charFocus(dels[0], inss[0]);
    out.push(`     one long line differs from character ${focus.at + 1}:`);
    out.push(`  -  ${focus.before}`);
    out.push(`  +  ${focus.after}`);
    return;
  }

  for (const op of hunk) {
    const mark = op.type === "del" ? "-" : op.type === "ins" ? "+" : " ";
    out.push(`  ${mark}  ${full ? op.text.replace(/\n/g, "\\n") : clip(op.text)}`);
  }
}

function pad(text, width) {
  return text.length >= width ? text : text + " ".repeat(width - text.length);
}

async function snapshot(destDir, force) {
  const source = DEFAULT_CURRENT_DIR;
  const pages = await collectHtml(source);
  if (pages.size === 0) {
    fail(`No HTML found in ${source}/. Build first — an empty snapshot would silently pass every later compare.`);
  }

  let existing = 0;
  try {
    existing = (await collectHtml(destDir)).size;
  } catch {
    existing = 0;
  }
  if (existing > 0 && !force) {
    // A baseline costs a three-minute build against a rate-limited CMS. Losing
    // one to an accidental re-snapshot of a dirty dist is expensive and silent,
    // because the overwritten baseline still compares clean afterwards.
    fail(
      `${destDir} already holds ${existing} HTML files. Pass --force to replace it.\n` +
        `A baseline is expensive to rebuild and an overwritten one compares clean against anything.`,
    );
  }
  if (existing > 0) await rm(destDir, { recursive: true, force: true });

  for (const [rel, html] of pages) {
    const target = path.join(destDir, rel);
    await mkdir(path.dirname(target), { recursive: true });
    await writeFile(target, html);
  }

  console.log(`Snapshot: ${pages.size} HTML files from ${source}/ into ${destDir}`);
  console.log("Stored raw, not normalised, so the normalisation rules can change without a rebuild.");
  return 0;
}

async function compare(baselineDir, currentDir, options) {
  const baseInfo = await stat(baselineDir).catch(() => null);
  if (!baseInfo) fail(`Baseline directory not found: ${baselineDir}`);

  const baseFiles = await collectHtml(baselineDir);
  const currFiles = await collectHtml(currentDir);
  if (baseFiles.size === 0) fail(`Baseline ${baselineDir} contains no HTML files.`);
  if (currFiles.size === 0) {
    fail(`No HTML found in ${currentDir}/. Build first — comparing against an empty build is not a pass.`);
  }

  const basePages = new Map([...baseFiles].map(([rel, html]) => [routeOf(rel), html]));
  const currPages = new Map([...currFiles].map(([rel, html]) => [routeOf(rel), html]));
  const routes = [...new Set([...basePages.keys(), ...currPages.keys()])].sort();
  const shared = new Set(routes.filter((r) => basePages.has(r) && currPages.has(r)));

  const verdicts = [];
  const diffs = [];
  const added = [];
  const removed = [];

  for (const route of routes) {
    if (!basePages.has(route)) {
      added.push(route);
      verdicts.push([route, "ADDED"]);
      continue;
    }
    if (!currPages.has(route)) {
      removed.push(route);
      verdicts.push([route, "REMOVED"]);
      continue;
    }
    const before = normalise(basePages.get(route));
    const after = normalise(currPages.get(route));
    if (before === after) {
      verdicts.push([route, "same"]);
      continue;
    }
    const ops = diffLines(displayLines(before), displayLines(after));
    const plus = ops.filter((op) => op.type === "ins").length;
    const minus = ops.filter((op) => op.type === "del").length;
    diffs.push({ route, hunks: toHunks(ops, options.context), plus, minus });
    verdicts.push([route, `CHANGED +${plus} -${minus}`]);
  }

  const graphFindings = compareAssetGroups(
    assetGroups(basePages, shared),
    assetGroups(currPages, shared),
  );

  const changedCount = diffs.length;
  const structural = graphFindings.length;
  const failed = changedCount > 0 || added.length > 0 || removed.length > 0 || structural > 0;

  const out = [];
  const rule = "─".repeat(72);
  out.push(rule);
  out.push(
    failed
      ? `HTML regression: FAIL — ${changedCount} of ${shared.size} routes changed` +
          (structural ? `, ${structural} asset-link change${structural === 1 ? "" : "s"}` : "") +
          (added.length ? `, ${added.length} added` : "") +
          (removed.length ? `, ${removed.length} removed` : "")
      : `HTML regression: PASS — all ${shared.size} routes identical`,
  );
  out.push(rule);
  out.push(`baseline  ${baselineDir}  (${basePages.size} routes)`);
  out.push(`current   ${currentDir}  (${currPages.size} routes)`);
  out.push("");
  out.push("Content hashes in /_assets/*.css and *.js were replaced with [hash] on");
  out.push("both sides, so a stylesheet-only refactor is expected to read as PASS.");
  if (!failed) {
    out.push("Nothing else was normalised: whitespace, inline <style> blocks, image");
    out.push("URLs and astro-cid tokens all compare byte for byte.");
  }
  out.push("");

  // Capped: one 110-character blog slug should not pad every other row off the
  // right edge of the terminal.
  const width = Math.min(48, Math.max(...verdicts.map(([route]) => route.length)) + 2);
  const cells = verdicts.map(([route, verdict]) => pad(`${verdict === "same" ? "ok  " : "DIFF"} ${route}`, width + 6));
  const columns = Math.max(1, Math.floor(96 / (width + 8)));
  out.push("PER-ROUTE VERDICT");
  for (let i = 0; i < cells.length; i += columns) {
    out.push("  " + cells.slice(i, i + columns).join("").trimEnd());
  }
  out.push("");

  if (removed.length) {
    out.push(`GONE — in the baseline, not in this build (${removed.length})`);
    for (const route of removed) out.push(`  ${route}`);
    out.push("");
  }
  if (added.length) {
    out.push(`NEW — in this build, not in the baseline (${added.length})`);
    for (const route of added) out.push(`  ${route}`);
    out.push("");
  }

  if (structural) {
    out.push(`ASSET LINKS — which routes share which bundle (${structural})`);
    out.push("  One line per built CSS/JS file and the routes linking it. The hash");
    out.push("  is normalised away in the per-route diff above, so this is what");
    out.push("  catches two same-named bundles merging or swapping.");
    for (const finding of graphFindings) {
      out.push(`  ${finding.gone ? "gone" : "new "}  ${finding.name}`);
      out.push(`        linked by ${finding.routes}`);
    }
    out.push("");
  }

  if (changedCount) {
    out.push(`CHANGED ROUTES (${changedCount})`);
    for (const diff of diffs) out.push(`  ${pad(diff.route, width)} +${diff.plus} -${diff.minus}`);
    out.push("");
    out.push("Lines below are HTML split at tag boundaries, not source lines.");
    out.push("");

    const shown = options.full ? diffs : diffs.slice(0, MAX_ROUTES_SHOWN);
    for (const diff of shown) {
      out.push(`══ ${diff.route}  (+${diff.plus} -${diff.minus}) ${"═".repeat(Math.max(0, 60 - diff.route.length))}`);
      const hunks = options.full ? diff.hunks : diff.hunks.slice(0, MAX_HUNKS_PER_ROUTE);
      const body = [];
      for (const hunk of hunks) {
        renderHunk(hunk, body, options.full);
        if (!options.full && body.length > MAX_DIFF_LINES_PER_ROUTE) break;
      }
      const trimmed = options.full ? body : body.slice(0, MAX_DIFF_LINES_PER_ROUTE);
      out.push(...trimmed);
      if (trimmed.length < body.length || hunks.length < diff.hunks.length) {
        out.push(`  … output truncated (${diff.hunks.length} hunks total). Re-run with --full.`);
      }
      out.push("");
    }
    if (shown.length < diffs.length) {
      out.push(`… and ${diffs.length - shown.length} more changed routes. Re-run with --full.`);
      out.push("");
    }
  }

  out.push(rule);
  out.push(
    failed
      ? "VERDICT: something changed. Do not ship until every line above is explained."
      : "VERDICT: the built HTML is unchanged. Any CSS work in this step was markup-neutral.",
  );
  out.push(rule);

  console.log(out.join("\n"));
  return failed ? 1 : 0;
}

function fail(message) {
  console.error(`vr-html: ${message}`);
  process.exit(2);
}

function usage() {
  console.error(
    [
      "usage:",
      "  node scripts/vr-html.mjs snapshot <dir> [--force]",
      "  node scripts/vr-html.mjs compare  <dir> [current-dir] [--full] [--context=N]",
    ].join("\n"),
  );
  process.exit(2);
}

async function main() {
  const argv = process.argv.slice(2);
  const flags = argv.filter((arg) => arg.startsWith("--"));
  const positional = argv.filter((arg) => !arg.startsWith("--"));
  const [mode, first, second] = positional;

  const contextFlag = flags.find((flag) => flag.startsWith("--context="));
  const options = {
    full: flags.includes("--full"),
    context: contextFlag ? Math.max(0, Number.parseInt(contextFlag.slice("--context=".length), 10) || 0) : 2,
  };

  const unknown = flags.filter((flag) => flag !== "--full" && flag !== "--force" && !flag.startsWith("--context="));
  if (unknown.length) fail(`unknown flag ${unknown[0]}`);

  if (mode === "snapshot") {
    if (!first) usage();
    process.exit(await snapshot(first, flags.includes("--force")));
  } else if (mode === "compare") {
    if (!first) usage();
    process.exit(await compare(first, second ?? DEFAULT_CURRENT_DIR, options));
  } else {
    usage();
  }
}

main().catch((err) => fail(err.stack ?? String(err)));
