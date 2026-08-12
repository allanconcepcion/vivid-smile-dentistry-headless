/*
 * Lenis smooth scroll (disabled under prefers-reduced-motion).
 *
 * Cross-origin Typeform iframes swallow wheel events that land on them. Lenis
 * sets `lenis-scrolling` on <html> while animating; we also flip
 * `vs-wheel-active` for ~350ms on any wheel/touchmove the parent window sees,
 * so the first event of a fresh scroll (before Lenis starts animating) still
 * passes through. The matching CSS in global.css drops iframe pointer-events
 * under either class.
 */

import Lenis from "lenis";
import { gsap } from "gsap";
import { ScrollTrigger } from "gsap/ScrollTrigger";

const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

if (!reduceMotion) {
  const lenis = new Lenis({
    duration: 1.1,
    easing: (t) => Math.min(1, 1.001 - Math.pow(2, -10 * t)),
  });

  lenis.on("scroll", ScrollTrigger.update);

  gsap.ticker.add((time) => {
    lenis.raf(time * 1000);
  });
  gsap.ticker.lagSmoothing(0);
}

// Wheel/touchmove state-tracker runs in both motion modes — native scroll
// also relies on iframe pointer-events:none to pass wheels through.
const WHEEL_HOLD_MS = 350;
let wheelEndTimer = null;

const markWheelActive = () => {
  if (!wheelEndTimer) {
    document.documentElement.classList.add("vs-wheel-active");
  } else {
    clearTimeout(wheelEndTimer);
  }
  wheelEndTimer = setTimeout(() => {
    document.documentElement.classList.remove("vs-wheel-active");
    wheelEndTimer = null;
  }, WHEEL_HOLD_MS);
};

window.addEventListener("wheel", markWheelActive, { passive: true, capture: true });
window.addEventListener("touchmove", markWheelActive, { passive: true, capture: true });
