/*
 * Vivid Smiles — GSAP animation library.
 * Source-of-truth: the design system reference § "Motion Intent" table.
 *
 * Wiring categories (declarative, attribute-driven):
 *   [data-vs-reveal]         — fade + rise on enter (default 24px y, 0.6s, power2.out)
 *   [data-vs-reveal-lines]   — masked line-by-line reveal (headings)
 *   [data-vs-reveal-batch]   — children reveal as a stagger batch (cards on a row)
 *   [data-vs-parallax]       — y drift parallax on scroll (desktop only, opt out: data-vs-no-parallax)
 *   [data-vs-counter]        — count-up from 0 to data-target on enter
 *   [data-vs-arrow]          — circled-arrow "knock-up + swap" hover on any host
 *                              with a descendant <ArrowBadge>. Flags space-separated
 *                              in the attribute value: "magnetic" (mousemove translate
 *                              + mousedown squish) and "ripple" (click ripple on the
 *                              .btn-ripple element). Buttons use both; cards use neither.
 *
 * All matchMedia-gated to honor prefers-reduced-motion.
 */

import { gsap } from "gsap";
import { ScrollTrigger } from "gsap/ScrollTrigger";
import { Draggable } from "gsap/Draggable";
import { InertiaPlugin } from "gsap/InertiaPlugin";

gsap.registerPlugin(ScrollTrigger, Draggable, InertiaPlugin);
gsap.defaults({ ease: "power3.out", duration: 1.4 });

/* ──────────────────────────────────────────────────────────────────────────
 *  PRE-HIDE — set [data-anim] before paint so reveal targets start hidden
 *  ────────────────────────────────────────────────────────────────────── */
const REVEAL_SELECTORS = [
  "[data-vs-reveal]",
  "[data-vs-reveal-lines]",
  "[data-vs-reveal-batch] > *",
];

function preHide() {
  REVEAL_SELECTORS.forEach((sel) => {
    document.querySelectorAll(sel).forEach((el) => {
      el.setAttribute("data-anim", "");
    });
  });
  document.documentElement.classList.add("gsap-ready");
}

/* ──────────────────────────────────────────────────────────────────────────
 *  In-viewport check used at boot to decide whether to wait for a
 *  ScrollTrigger or fire the reveal immediately. ScrollTrigger's default
 *  "top 85%" line sits ~15% above the viewport's bottom edge, so a trigger
 *  element whose top lands in the bottom 15% of the viewport waits for
 *  scroll even though it's visibly in view. This bypasses that for the
 *  load case while preserving "top 85%" semantics for below-fold sections.
 *  ────────────────────────────────────────────────────────────────────── */
function isInViewport(el) {
  const r = el.getBoundingClientRect();
  return r.top < window.innerHeight && r.bottom > 0;
}

/* ──────────────────────────────────────────────────────────────────────────
 *  Transition suppression. Cards across the site set
 *  `transition: transform 240ms` for their hover lift. During a GSAP reveal
 *  the CSS transition chases every per-frame transform write, creating a
 *  visible ~240ms lag where opacity (not transitioned) outruns transform —
 *  the cards fade in, then visibly "jump" upward as the lagged transform
 *  catches up. Suppress inline before the tween's immediateRender writes
 *  the from-state, restore in onComplete so hover smoothing still works.
 *  ────────────────────────────────────────────────────────────────────── */
function suppressTransitions(els) {
  els.forEach((el) => el.style.setProperty("transition", "none", "important"));
}
function restoreTransitions(els) {
  els.forEach((el) => el.style.removeProperty("transition"));
}

/* ──────────────────────────────────────────────────────────────────────────
 *  LINE SPLITTER for [data-vs-reveal-lines]
 *  Splits visible text into <span class="gx-line"><span class="gx-inner">…
 *  groups by offsetTop. Walks childNodes so inline <em> emphasis (the green
 *  italic phrase in vs-h2/vs-h1) survives the split — each word carries its
 *  emphasized flag through line grouping and is rewrapped in
 *  <em class="vs-italic-word"> per contiguous run in the final DOM.
 *  ────────────────────────────────────────────────────────────────────── */
function splitLines(el) {
  if (el.dataset.vsLinesPrepared) return;

  // 1. Tokenize: walk childNodes, capturing each word + whether it sits
  //    inside an emphasis element. EM/I tags inherit emphasis to children.
  const tokens = []; // [{ text: string, em: boolean }]
  (function walk(node, isEm) {
    node.childNodes.forEach((child) => {
      if (child.nodeType === Node.TEXT_NODE) {
        (child.textContent || "")
          .split(/\s+/)
          .filter((w) => w.length)
          .forEach((w) => tokens.push({ text: w, em: isEm }));
      } else if (child.nodeType === Node.ELEMENT_NODE) {
        const tag = child.tagName;
        walk(child, isEm || tag === "EM" || tag === "I");
      }
    });
  })(el, false);

  if (!tokens.length) return;
  el.dataset.vsLinesPrepared = "1";

  // 2. Render each token as a flat .gx-word span (with .gx-em marker for
  //    emphasized words) so we can read offsetTop after browser layout.
  el.textContent = "";
  const wordEls = tokens.map((tok, i) => {
    const span = document.createElement("span");
    span.className = tok.em ? "gx-word gx-em" : "gx-word";
    span.style.display = "inline-block";
    span.textContent = tok.text;
    el.appendChild(span);
    if (i < tokens.length - 1) el.appendChild(document.createTextNode(" "));
    return span;
  });

  // 3. Group adjacent words by offsetTop into visual lines.
  const lines = []; // [[{ text, em }, ...], ...]
  let currentTop = null;
  let currentLine = [];
  wordEls.forEach((w, i) => {
    const top = w.offsetTop;
    if (currentTop === null) currentTop = top;
    if (Math.abs(top - currentTop) < 4) {
      currentLine.push(tokens[i]);
    } else {
      lines.push(currentLine);
      currentLine = [tokens[i]];
      currentTop = top;
    }
  });
  if (currentLine.length) lines.push(currentLine);

  // 4. Rebuild masked structure, wrapping contiguous emphasized words in
  //    <em class="vs-italic-word"> within each line's .gx-inner.
  el.textContent = "";
  lines.forEach((lineToks, i) => {
    const lineEl = document.createElement("span");
    lineEl.className = "gx-line";
    const innerEl = document.createElement("span");
    innerEl.className = "gx-inner";

    let openEm = null;
    lineToks.forEach((tok, idx) => {
      const text = (idx > 0 ? " " : "") + tok.text;
      if (tok.em) {
        if (!openEm) {
          openEm = document.createElement("em");
          openEm.className = "vs-italic-word";
          innerEl.appendChild(openEm);
        }
        openEm.appendChild(document.createTextNode(text));
      } else {
        openEm = null;
        innerEl.appendChild(document.createTextNode(text));
      }
    });

    lineEl.appendChild(innerEl);
    el.appendChild(lineEl);
    if (i < lines.length - 1) el.appendChild(document.createTextNode(" "));
  });
}

/* ──────────────────────────────────────────────────────────────────────────
 *  CIRCLED-ARROW HOVER — single source of truth for buttons, cards, mega
 *  menus, and any future surface that uses <ArrowBadge>.
 *
 *  Hosts opt in via [data-vs-arrow]. Optional flags in the attribute value:
 *    "magnetic" — mousemove translate + mousedown squish (button feel)
 *    "ripple"   — click ripple emanating from the badge (button only)
 *
 *  Colors are read off the .vs-arrow descendant via the
 *  --vs-arrow-{bg,fg,border,hover-bg,hover-fg,hover-border} custom
 *  properties (defined by the variant in ArrowBadge.astro, overridable by
 *  any ancestor — see Button.astro for the per-variant override pattern).
 *  ────────────────────────────────────────────────────────────────────── */
function wireArrowSwap(host) {
  if (host.__arrowBound) return;
  host.__arrowBound = true;

  const arrow = host.querySelector(".vs-arrow");
  const main = arrow && arrow.querySelector(".a-glyph.main");
  const ghost = arrow && arrow.querySelector(".a-glyph.ghost");
  if (!arrow || !main || !ghost) return;

  const flags = (host.dataset.vsArrow || "").split(/\s+/).filter(Boolean);
  const magnetic = flags.includes("magnetic");
  const ripple = flags.includes("ripple");

  // Optional button label slide. Auto-detected; cards skip this.
  const l1 = host.querySelector(".btn-label .l1");
  const l2 = host.querySelector(".btn-label .l2");
  const rippleEl = ripple ? host.querySelector(".btn-ripple") : null;

  // Resolve rest + hover colors from the badge's computed style.
  // ArrowBadge sets defaults via `--vs-arrow-*`; ancestors can override.
  const cs = getComputedStyle(arrow);
  const prop = (name, fallback) => cs.getPropertyValue(name).trim() || fallback;
  const restBg = prop("--vs-arrow-bg", cs.backgroundColor);
  const restFg = prop("--vs-arrow-fg", cs.color);
  const restBorder = prop("--vs-arrow-border", cs.borderColor);
  const hoverBg = prop("--vs-arrow-hover-bg", restBg);
  const hoverFg = prop("--vs-arrow-hover-fg", restFg);
  const hoverBorder = prop("--vs-arrow-hover-border", restBorder);

  gsap.set(main, { x: 0, y: 0, opacity: 1 });
  gsap.set(ghost, { x: "-130%", y: "130%", opacity: 0 });
  if (l2) gsap.set(l2, { y: "100%" });

  const enter = () => {
    if (magnetic) gsap.to(host, { scale: 1.025, duration: 0.35, ease: "power3.out" });
    gsap.to(arrow, {
      scale: 1.08,
      rotate: -45,
      duration: 0.55,
      ease: "expo.out",
      overwrite: "auto",
      backgroundColor: hoverBg,
      color: hoverFg,
      borderColor: hoverBorder,
    });
    gsap.to(main, { x: "130%", y: "-130%", opacity: 0, duration: 0.45, ease: "power3.in" });
    gsap.fromTo(
      ghost,
      { x: "-130%", y: "130%", opacity: 0 },
      { x: 0, y: 0, opacity: 1, duration: 0.55, ease: "expo.out", delay: 0.08 }
    );
    if (l1) gsap.to(l1, { y: "-100%", duration: 0.5, ease: "expo.out" });
    if (l2) gsap.fromTo(l2, { y: "100%" }, { y: 0, duration: 0.5, ease: "expo.out" });
  };

  const leave = () => {
    if (magnetic) gsap.to(host, { scale: 1, x: 0, y: 0, duration: 0.55, ease: "expo.out" });
    gsap.to(arrow, {
      scale: 1,
      rotate: 0,
      x: 0,
      y: 0,
      duration: 0.55,
      ease: "expo.out",
      overwrite: "auto",
      backgroundColor: restBg,
      color: restFg,
      borderColor: restBorder,
    });
    gsap.to(main, { x: 0, y: 0, opacity: 1, duration: 0.55, ease: "expo.out", delay: 0.05 });
    gsap.to(ghost, { x: "-130%", y: "130%", opacity: 0, duration: 0.35, ease: "power3.in" });
    if (l1) gsap.to(l1, { y: 0, duration: 0.55, ease: "expo.out" });
    if (l2) gsap.to(l2, { y: "100%", duration: 0.55, ease: "expo.out" });
  };

  host.addEventListener("mouseenter", enter);
  host.addEventListener("mouseleave", leave);
  host.addEventListener("focusin", enter);
  host.addEventListener("focusout", leave);

  if (magnetic) {
    host.addEventListener("mousemove", (e) => {
      const r = host.getBoundingClientRect();
      const dx = (e.clientX - (r.left + r.width / 2)) / (r.width / 2);
      const dy = (e.clientY - (r.top + r.height / 2)) / (r.height / 2);
      gsap.to(host, { x: dx * 6, y: dy * 4, duration: 0.5, ease: "power3.out" });
      gsap.to(arrow, {
        x: dx * 4,
        y: dy * 3,
        duration: 0.5,
        ease: "power3.out",
        overwrite: "auto",
      });
    });
    host.addEventListener("mousedown", () => {
      gsap.to(host, { scale: 0.965, duration: 0.15, ease: "power2.out" });
    });
    host.addEventListener("mouseup", () => {
      gsap.to(host, { scale: 1.025, duration: 0.35, ease: "expo.out" });
    });
  }

  if (rippleEl) {
    host.addEventListener("click", () => {
      const r = host.getBoundingClientRect();
      const ar = arrow.getBoundingClientRect();
      gsap.set(rippleEl, {
        left: ar.left + ar.width / 2 - r.left - 7,
        top: ar.top + ar.height / 2 - r.top - 7,
        scale: 0,
        opacity: 0.9,
      });
      gsap.to(rippleEl, { scale: 26, opacity: 0, duration: 0.9, ease: "power3.out" });
      gsap.fromTo(
        arrow,
        { x: 0 },
        { x: 8, duration: 0.12, ease: "power2.out", yoyo: true, repeat: 1 }
      );
    });
  }
}

/* ──────────────────────────────────────────────────────────────────────────
 *  COUNTER COUNT-UP — [data-vs-counter] with data-target / data-suffix / data-prefix
 *  ────────────────────────────────────────────────────────────────────── */
function wireCounters() {
  document.querySelectorAll("[data-vs-counter]").forEach((el) => {
    const target = parseFloat(el.dataset.target || el.textContent || "0");
    const suffix = el.dataset.suffix || "";
    const prefix = el.dataset.prefix || "";
    const obj = { v: 0 };
    el.textContent = `${prefix}0${suffix}`;
    ScrollTrigger.create({
      trigger: el,
      start: "top 92%",
      once: true,
      onEnter: () => {
        gsap.to(obj, {
          v: target,
          duration: 2,
          ease: "expo.out",
          onUpdate: () => {
            const value = Math.round(obj.v);
            el.textContent = `${prefix}${value}${suffix}`;
          },
        });
      },
    });
  });
}

/* ──────────────────────────────────────────────────────────────────────────
 *  SWIPE-DRAGGABLE AUTO-RESUMING MARQUEE — [data-vs-marquee]
 *
 *  The track auto-scrolls continuously (driven by gsap.ticker, not the CSS
 *  keyframe), but the visitor can grab and flick it. While pressed/throwing,
 *  the auto-scroll pauses; once the inertia settles it resumes from wherever
 *  the user left off — no jump, seamless wrap.
 *
 *  All widths (no-preference): initialized inside a (prefers-reduced-motion:
 *  no-preference) matchMedia block, mobile included. Draggable type:"x" coexists
 *  with the marquee's touch-action: pan-y — the browser keeps vertical
 *  page-scroll while Draggable owns horizontal, so there's no drag-vs-scroll
 *  conflict. Under reduced motion this never runs and the CSS falls back to a
 *  native horizontal scroll-snap container (see the page CSS).
 *
 *  The track markup is a duplicated set ([...tiles, ...tiles]); we shift by
 *  exactly one copy's width (measured to the first tile of the second copy,
 *  gap included) and wrap, so the seam is invisible.
 *
 *  Returns a cleanup fn (matchMedia calls it when the query stops matching):
 *  removes the ticker, kills Draggable, restores the CSS marquee/native scroll.
 *  ────────────────────────────────────────────────────────────────────── */
const SECONDS_PER_COPY = 40; // slower than the CSS keyframe — premium, readable

function initDraggableMarquee() {
  const marquees = gsap.utils.toArray("[data-vs-marquee]");
  const cleanups = [];

  marquees.forEach((marquee) => {
    const track = marquee.querySelector("[data-vs-marquee-track]");
    if (!track) return;
    const tiles = track.children;
    if (tiles.length < 2) return;

    // Per-marquee auto-scroll speed (seconds per copy). Defaults to the global
    // constant; a marquee can read slower/faster by setting --vs-marquee-drag-speed
    // on its [data-vs-marquee] container (e.g. ReviewMarquee scrolls slower).
    const secsPerCopy =
      parseFloat(getComputedStyle(marquee).getPropertyValue("--vs-marquee-drag-speed")) ||
      SECONDS_PER_COPY;

    // One copy's width. The track holds exactly two identical copies, but some
    // galleries nest the clone half in a display:contents wrapper (to hide it
    // from assistive tech), so child-index math (tiles[half].offsetLeft) isn't
    // reliable across shapes. Measure from the rendered content box instead:
    // period = (full content width + one gap) / 2. Exact for both flat doubled
    // tracks (B&A) and wrapper-nested ones (reviews); transforms don't affect
    // scrollWidth, so it stays stable once GSAP is driving the loop.
    const measureHalf = () => {
      const cs = getComputedStyle(track);
      const gap = parseFloat(cs.columnGap || cs.gap) || 0;
      const padL = parseFloat(cs.paddingLeft) || 0;
      const padR = parseFloat(cs.paddingRight) || 0;
      return (track.scrollWidth - padL - padR + gap) / 2;
    };
    let half = measureHalf();
    if (!half || half < 1) return;

    let wrapX = gsap.utils.wrap(-half, 0);
    const state = { x: 0 };
    // quickSetter caches the property setter so the per-frame ticker loop
    // doesn't re-parse a vars object on every tick.
    const setX = gsap.quickSetter(track, "x", "px");
    const render = () => setX(wrapX(state.x));

    // Take over from the CSS keyframe.
    track.classList.add("is-gsap-marquee");
    render();

    let paused = false;
    const tick = (time, deltaMS) => {
      if (paused) return;
      state.x -= (half / (secsPerCopy * 1000)) * deltaMS;
      render();
    };
    gsap.ticker.add(tick);

    // Recompute the copy width when the viewport (and the vw-based tile
    // widths) change, so the wrap stays seamless.
    let resizeRAF = 0;
    const onResize = () => {
      cancelAnimationFrame(resizeRAF);
      resizeRAF = requestAnimationFrame(() => {
        const next = measureHalf();
        if (next && next > 1) {
          half = next;
          wrapX = gsap.utils.wrap(-half, 0);
          render();
        }
      });
    };
    window.addEventListener("resize", onResize);

    // Invisible proxy drives the drag; we map its delta onto state.x.
    const proxy = document.createElement("div");
    let pressProxyX = 0;
    let baseX = 0;

    // Shared by onDrag + onThrowUpdate (inertia phase) — `this` is the
    // Draggable instance in both. Map the proxy's delta onto state.x.
    function applyDragDelta() {
      state.x = baseX + (this.x - pressProxyX);
      render();
    }

    const drags = Draggable.create(proxy, {
      type: "x",
      trigger: marquee,
      inertia: true,
      throwResistance: 2600,
      edgeResistance: 0,
      dragClickables: true,
      onPress() {
        paused = true;
        marquee.classList.add("is-dragging");
        pressProxyX = this.x;
        baseX = state.x;
      },
      onDrag: applyDragDelta,
      onThrowUpdate: applyDragDelta,
      onRelease() {
        marquee.classList.remove("is-dragging");
        // If no inertia throw took over, resume the auto-scroll now.
        if (!this.isThrowing) paused = false;
      },
      onThrowComplete() {
        paused = false;
      },
    });

    cleanups.push(() => {
      gsap.ticker.remove(tick);
      window.removeEventListener("resize", onResize);
      cancelAnimationFrame(resizeRAF);
      drags.forEach((d) => d.kill());
      track.classList.remove("is-gsap-marquee");
      gsap.set(track, { clearProps: "transform" });
    });
  });

  return () => cleanups.forEach((fn) => fn());
}

/* ──────────────────────────────────────────────────────────────────────────
 *  MAIN BOOT
 *  ────────────────────────────────────────────────────────────────────── */
function boot() {
  preHide();

  const mm = gsap.matchMedia();

  // === ANIMATIONS ENABLED (no-preference) ====================================
  mm.add("(prefers-reduced-motion: no-preference)", () => {
    const reveals = gsap.utils.toArray("[data-vs-reveal]");
    const lines = gsap.utils.toArray("[data-vs-reveal-lines]");
    const batches = gsap.utils.toArray("[data-vs-reveal-batch]");

    // Batch all viewport reads before any animation writes — otherwise each
    // iteration's getBoundingClientRect flushes the layout dirtied by the
    // previous iteration's transition-suppress / immediateRender writes.
    const inView = new WeakSet();
    [...reveals, ...lines, ...batches].forEach((el) => {
      if (isInViewport(el)) inView.add(el);
    });

    // 1. Universal scroll reveal
    reveals.forEach((el) => {
      suppressTransitions([el]);
      const toVars = {
        autoAlpha: 1,
        y: 0,
        duration: 0.6,
        ease: "power2.out",
        onComplete() { restoreTransitions([el]); },
      };
      if (!inView.has(el)) {
        toVars.scrollTrigger = { trigger: el, start: "top 85%", once: true };
      }
      gsap.fromTo(el, { autoAlpha: 0, y: 24 }, toVars);
    });

    // 2. Heading line mask reveal
    lines.forEach((el) => {
      splitLines(el);
      gsap.set(el, { autoAlpha: 1, visibility: "visible" });
      const inners = el.querySelectorAll(".gx-inner");
      const toVars = {
        yPercent: 0,
        filter: "blur(0px)",
        duration: 1.6,
        ease: "expo.out",
        stagger: 0.12,
      };
      if (!inView.has(el)) {
        toVars.scrollTrigger = { trigger: el, start: "top 86%", once: true };
      }
      gsap.fromTo(inners, { yPercent: 110, filter: "blur(10px)" }, toVars);
    });

    // 3. Batched reveal — children of [data-vs-reveal-batch]
    batches.forEach((parent) => {
      const kids = Array.from(parent.children);
      suppressTransitions(kids);
      const toVars = {
        autoAlpha: 1,
        y: 0,
        scale: 1,
        filter: "blur(0px)",
        duration: 1.4,
        ease: "expo.out",
        stagger: 0.14,
        onComplete() { restoreTransitions(kids); },
      };
      if (!inView.has(parent)) {
        toVars.scrollTrigger = { trigger: parent, start: "top 85%", once: true };
      }
      gsap.fromTo(
        kids,
        { autoAlpha: 0, y: 50, scale: 0.97, filter: "blur(6px)" },
        toVars
      );
    });

    // 4. Counters
    wireCounters();

    // 5. Circled-arrow hover — single boot pass over every [data-vs-arrow]
    //    host. Per-surface colors live on the descendant <ArrowBadge> via
    //    its variant CSS; the JS just reads them and runs the animation.
    document.querySelectorAll("[data-vs-arrow]").forEach(wireArrowSwap);
  });

  // === DESKTOP ONLY (>=992px) — parallax =====================================
  mm.add("(min-width: 992px) and (prefers-reduced-motion: no-preference)", () => {
    gsap.utils.toArray("[data-vs-parallax]").forEach((el) => {
      if (el.hasAttribute("data-vs-no-parallax")) return;
      const distance = parseFloat(el.dataset.parallax || "-40");
      gsap.fromTo(
        el,
        { y: -distance / 2 },
        {
          y: distance / 2,
          ease: "none",
          scrollTrigger: {
            trigger: el,
            start: "top bottom",
            end: "bottom top",
            scrub: 0.5,
          },
        }
      );
    });
  });

  // === ALL WIDTHS (no-preference) — swipe-draggable B&A + reviews marquee =====
  // Runs on mobile too: GSAP Draggable type:"x" coexists with the marquee's
  // touch-action: pan-y (browser keeps vertical page-scroll, Draggable owns
  // horizontal), so phones get the same auto-scroll + flick-to-explore as
  // desktop instead of a frozen gallery. Returns its own cleanup so matchMedia
  // tears down the ticker + Draggable if the query stops matching.
  mm.add("(prefers-reduced-motion: no-preference)", () => initDraggableMarquee());

  // === REDUCED MOTION — kill everything, restore visibility =================
  mm.add("(prefers-reduced-motion: reduce)", () => {
    ScrollTrigger.getAll().forEach((t) => t.kill());
    gsap.globalTimeline.clear();
    document.querySelectorAll("[data-anim]").forEach((el) => {
      gsap.set(el, { clearProps: "all", autoAlpha: 1, visibility: "visible" });
      // Clear any in-flight transition suppression so hover smoothing returns.
      el.style.removeProperty("transition");
    });
  });
}

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", boot);
} else {
  boot();
}
