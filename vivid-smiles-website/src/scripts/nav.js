/*
 * Vivid Smiles — global navigation controller.
 *
 * Drives the desktop mega menus, mobile drawer, and scroll-aware solid
 * background. The circled-arrow hover (formerly [data-hover-swap] here)
 * lives in animations.js as wireArrowSwap on [data-vs-arrow] hosts.
 *
 * Single auto-initializing module imported by BaseLayout.astro alongside
 * lenis.js and animations.js. No exports.
 *
 * Reduced-motion is honored: the script still wires structural behavior
 * (open/close/route changes), but skips GSAP transforms.
 */

import { gsap } from "gsap";

const HOVER_OPEN_DELAY = 120;
const HOVER_CLOSE_DELAY = 200;
const SCROLL_SOLID_AT = 80;

const reduceMotion =
  typeof window !== "undefined" &&
  window.matchMedia("(prefers-reduced-motion: reduce)").matches;

function boot() {
  const shell = document.querySelector("[data-vs-nav]");
  if (!shell) return;

  initNavHeightVar(shell);
  initMegaMenus(shell);
  initMobileDrawer(shell);
  initScrollBehavior(shell);
}

/* ====================================================================
 * --vs-nav-h — live measurement of the nav bar height. The mobile drawer
 * drops down from `top: var(--vs-nav-h)`, so the var has to track the
 * actual rendered height across breakpoints (padding + tallest child
 * vary). Debounced to a single rAF per resize burst.
 * ================================================================== */

function initNavHeightVar(shell) {
  const navEl = shell.querySelector(".nav");
  if (!navEl) return;

  let raf = 0;
  const update = () => {
    const h = Math.round(navEl.getBoundingClientRect().height);
    document.documentElement.style.setProperty("--vs-nav-h", `${h}px`);
  };
  const schedule = () => {
    if (raf) return;
    raf = requestAnimationFrame(() => {
      raf = 0;
      update();
    });
  };

  update();
  window.addEventListener("resize", schedule, { passive: true });
}

/* ====================================================================
 * Desktop mega menus — hover intent + click + outside-click + ESC
 * ================================================================== */

function initMegaMenus(shell) {
  const items = shell.querySelectorAll("[data-mega-trigger]");
  const wrap = shell.querySelector("[data-mega-wrap]");
  if (!wrap || items.length === 0) return;

  const panels = new Map();
  shell.querySelectorAll("[data-mega-panel]").forEach((panel) => {
    panels.set(panel.dataset.megaPanel, panel);
  });

  let openId = null;
  let openTimer = null;
  let closeTimer = null;

  const clearTimers = () => {
    if (openTimer) {
      clearTimeout(openTimer);
      openTimer = null;
    }
    if (closeTimer) {
      clearTimeout(closeTimer);
      closeTimer = null;
    }
  };

  const setOpen = (id) => {
    if (openId === id) return;
    if (openId) {
      const prev = panels.get(openId);
      if (prev) prev.dataset.open = "false";
      const prevTrigger = shell.querySelector(`[data-mega-trigger="${openId}"]`);
      if (prevTrigger) {
        prevTrigger.setAttribute("aria-expanded", "false");
        prevTrigger.parentElement?.classList.remove("open");
      }
    }
    openId = id;
    if (id) {
      const next = panels.get(id);
      if (next) next.dataset.open = "true";
      const nextTrigger = shell.querySelector(`[data-mega-trigger="${id}"]`);
      if (nextTrigger) {
        nextTrigger.setAttribute("aria-expanded", "true");
        nextTrigger.parentElement?.classList.add("open");
      }
      wrap.setAttribute("aria-hidden", "false");
    } else {
      wrap.setAttribute("aria-hidden", "true");
    }
  };

  const scheduleOpen = (id) => {
    clearTimers();
    openTimer = setTimeout(() => setOpen(id), HOVER_OPEN_DELAY);
  };

  const scheduleClose = () => {
    clearTimers();
    closeTimer = setTimeout(() => setOpen(null), HOVER_CLOSE_DELAY);
  };

  items.forEach((trigger) => {
    const id = trigger.dataset.megaTrigger;
    const li = trigger.parentElement;

    li.addEventListener("mouseenter", () => scheduleOpen(id));
    li.addEventListener("mouseleave", () => scheduleClose());

    // Click on a desktop trigger toggles the mega rather than navigating
    // immediately when the panel is closed (UX convention for items that
    // both lead to a hub page AND open a sub-tree). Second click navigates.
    trigger.addEventListener("click", (e) => {
      if (window.matchMedia("(max-width: 991px)").matches) return; // mobile uses drawer
      if (openId !== id) {
        e.preventDefault();
        clearTimers();
        setOpen(id);
      }
    });

    trigger.addEventListener("focus", () => scheduleOpen(id));
  });

  // Outside-click closes
  document.addEventListener("click", (e) => {
    if (!openId) return;
    if (shell.contains(e.target)) return;
    setOpen(null);
  });

  // ESC closes
  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && openId) {
      setOpen(null);
      const trigger = shell.querySelector(`[data-mega-trigger="${openId}"]`);
      if (trigger instanceof HTMLElement) trigger.focus();
    }
  });

  // Keep open while hovering the panel itself
  wrap.addEventListener("mouseenter", clearTimers);
  wrap.addEventListener("mouseleave", scheduleClose);
}

/* ====================================================================
 * Mobile drawer — burger toggle, body scroll lock, accordion submenus
 * ================================================================== */

function initMobileDrawer(shell) {
  const drawer = document.getElementById("vs-mobile-menu");
  const openBtn = shell.querySelector("[data-mm-open]");
  if (!drawer || !openBtn) return;

  const links = drawer.querySelectorAll("a[href]");
  const toggles = drawer.querySelectorAll("[data-mm-toggle]");
  const staggerItems = drawer.querySelectorAll("[data-mm-stagger]");

  let open = false;
  let lastFocus = null;
  let tl = null;

  // Paused reveal timeline — built once. play opens, reverse closes, so
  // toggling mid-animation just flips direction cleanly with no overwrite
  // bookkeeping. Reduced-motion users skip the timeline entirely; the open /
  // close path snaps clip-path with gsap.set and items stay placed.
  if (!reduceMotion) {
    tl = gsap.timeline({ paused: true, defaults: { ease: "power3.inOut" } });
    tl.fromTo(
      drawer,
      { clipPath: "inset(0 0 100% 0)" },
      { clipPath: "inset(0 0 0% 0)", duration: 0.55 },
      0
    );
    tl.fromTo(
      staggerItems,
      { opacity: 0, y: 18 },
      {
        opacity: 1,
        y: 0,
        duration: 0.5,
        stagger: 0.045,
        ease: "power3.out",
      },
      0.18
    );
  } else {
    gsap.set(staggerItems, { opacity: 1, y: 0 });
  }

  const setOpen = (next) => {
    if (next === open) return;
    open = next;
    drawer.setAttribute("aria-hidden", next ? "false" : "true");
    if (next) drawer.removeAttribute("hidden");
    openBtn.setAttribute("aria-expanded", next ? "true" : "false");
    openBtn.setAttribute("aria-label", next ? "Close navigation" : "Open navigation");
    document.body.classList.toggle("vs-nav-locked", next);

    // If the user opens the menu while the nav is hidden by hide-on-scroll,
    // strip the hidden state so the drawer doesn't dangle below an off-screen
    // header. (No-op when the class isn't present.)
    if (next) shell.classList.remove("hidden");

    if (tl) {
      // Animated path — play forward to open, reverse to close. On reverse-
      // complete we set `hidden` so AT users skip the empty container.
      if (next) {
        tl.eventCallback("onReverseComplete", null);
        tl.play();
      } else {
        tl.eventCallback("onReverseComplete", () => {
          if (!open) drawer.setAttribute("hidden", "");
        });
        tl.reverse();
      }
    } else {
      // Reduced-motion path — snap clip-path with no animation.
      gsap.set(drawer, {
        clipPath: next ? "inset(0 0 0% 0)" : "inset(0 0 100% 0)",
      });
      if (!next) drawer.setAttribute("hidden", "");
    }

    if (next) {
      lastFocus = document.activeElement;
      // Focus first interactive item once the panel has begun revealing.
      requestAnimationFrame(() => {
        const firstFocusable = drawer.querySelector(
          'a[href], button:not([disabled])'
        );
        if (firstFocusable instanceof HTMLElement) firstFocusable.focus();
      });
    } else if (lastFocus instanceof HTMLElement) {
      lastFocus.focus();
    }
  };

  openBtn.addEventListener("click", () => setOpen(!open));

  // Close drawer when a link is clicked (so anchor nav works)
  links.forEach((link) => {
    link.addEventListener("click", () => {
      if (open) setOpen(false);
    });
  });

  // Accordion toggles
  toggles.forEach((toggle) => {
    toggle.addEventListener("click", () => {
      const expanded = toggle.getAttribute("aria-expanded") === "true";
      toggle.setAttribute("aria-expanded", expanded ? "false" : "true");
    });
  });

  // ESC closes drawer
  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && open) {
      e.stopPropagation();
      setOpen(false);
    }
  });

  // Focus trap (cheap version — keeps focus inside while open)
  drawer.addEventListener("keydown", (e) => {
    if (!open || e.key !== "Tab") return;
    const focusables = drawer.querySelectorAll(
      'a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])'
    );
    if (focusables.length === 0) return;
    const first = focusables[0];
    const last = focusables[focusables.length - 1];
    if (e.shiftKey && document.activeElement === first) {
      e.preventDefault();
      last.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
      e.preventDefault();
      first.focus();
    }
  });
}

/* ====================================================================
 * Scroll-aware solid background
 * ================================================================== */

function initScrollBehavior(shell) {
  let ticking = false;

  const update = () => {
    shell.classList.toggle("scrolled", window.scrollY > SCROLL_SOLID_AT);
    ticking = false;
  };

  window.addEventListener(
    "scroll",
    () => {
      if (!ticking) {
        requestAnimationFrame(update);
        ticking = true;
      }
    },
    { passive: true }
  );

  update();
}

/* ====================================================================
 * Boot
 * ================================================================== */

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", boot);
} else {
  boot();
}
