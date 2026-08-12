/*
 * Vivid Smiles — TOC scroll-spy.
 *
 * Wires up a sticky in-page TOC for service detail pages: highlights the
 * active section as the user scrolls, and on mobile (where the TOC chip rail
 * is horizontally scrollable) auto-centers the active pill so the highlight
 * is visible at all. Click-to-scroll falls back to native anchors +
 * scroll-margin-top — the global Lenis instance is not exposed on window,
 * only the library namespace, so we don't drive smooth scroll from here.
 *
 * Each service page is namespaced under its own wrapper class (`.pveneers`,
 * `.caligners`, etc.); pass that selector in.
 */

export function initTocSpy(wrapperSelector: string) {
  const tocLinks = Array.from(
    document.querySelectorAll(`${wrapperSelector} .toc-list a`)
  ) as HTMLAnchorElement[];
  if (tocLinks.length === 0) return;

  const tocList = document.querySelector(`${wrapperSelector} .toc-list`) as HTMLElement | null;
  const sections = tocLinks
    .map((a) => document.querySelector(a.getAttribute("href") || ""))
    .filter((el): el is HTMLElement => el !== null);

  // Measure the stacked sticky bars at runtime and write the values back to
  // CSS custom properties on the page wrapper. That way the scroll-margin-top
  // declared in CSS (calc(var(--nav-h) + var(--toc-h) + 12px)) lands sections
  // at exactly the same y-coordinate the spy logic below tests against.
  const navEl = document.querySelector(".vs-nav-shell, header") as HTMLElement | null;
  const tocEl = document.querySelector(`${wrapperSelector} .toc`) as HTMLElement | null;
  const wrapper = document.querySelector(wrapperSelector) as HTMLElement | null;
  const syncBarHeights = () => {
    const navH = navEl?.getBoundingClientRect().height ?? 77;
    const tocH = tocEl?.getBoundingClientRect().height ?? 68;
    wrapper?.style.setProperty("--nav-h", `${navH}px`);
    wrapper?.style.setProperty("--toc-h", `${tocH}px`);
    return navH + tocH + 12;
  };
  let currentSpyLine = syncBarHeights();

  const linkByHash = new Map<string, HTMLAnchorElement>(
    tocLinks.map((a) => [a.getAttribute("href") || "", a]),
  );
  let lastActiveId: string | null = null;
  const centerPill = (pill: HTMLElement, smooth: boolean) => {
    if (!tocList) return;
    if (tocList.scrollWidth <= tocList.clientWidth) return;
    const target = pill.offsetLeft - (tocList.clientWidth - pill.offsetWidth) / 2;
    const clamped = Math.max(0, Math.min(tocList.scrollWidth - tocList.clientWidth, target));
    tocList.scrollTo({ left: clamped, behavior: smooth ? "smooth" : "auto" });
  };

  const setActive = (id: string | null) => {
    if (id === lastActiveId) return;
    if (lastActiveId !== null) linkByHash.get(`#${lastActiveId}`)?.classList.remove("active");
    const next = id !== null ? linkByHash.get(`#${id}`) : null;
    if (next) {
      next.classList.add("active");
      centerPill(next, true);
    }
    lastActiveId = id;
  };

  const updateActive = () => {
    let activeId: string | null = null;
    for (const s of sections) {
      // Small tolerance covers subpixel landing when scroll-margin-top
      // places the section exactly at the spy line.
      if (s.getBoundingClientRect().top - currentSpyLine <= 4) {
        activeId = s.id;
      }
    }
    setActive(activeId);
  };
  updateActive();

  let rafId = 0;
  const scheduleUpdate = () => {
    if (rafId) return;
    rafId = requestAnimationFrame(() => { rafId = 0; updateActive(); });
  };
  window.addEventListener("scroll", scheduleUpdate, { passive: true });
  window.addEventListener("resize", () => {
    currentSpyLine = syncBarHeights();
    scheduleUpdate();
  }, { passive: true });
}
