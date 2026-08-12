/*
 * Blog hub — URL-driven category filter.
 *
 * Reads ?category=X from the URL on mount and re-applies it whenever the user
 * clicks a chip. Updates history without a page reload so back/forward works.
 *
 * Card visibility is toggled via the .is-hidden class on each .bc element.
 * (.bc carries `data-category` from BlogCard.astro.) The filter logic mirrors
 * the /before-and-afters/ pattern — same script shape, same chip semantics.
 */

interface FilterState {
  active: string;
}

const grid = document.getElementById("cardGrid");
const chips = Array.from(document.querySelectorAll<HTMLButtonElement>("#filterList .chip"));
const cards = grid ? Array.from(grid.querySelectorAll<HTMLElement>(".bc")) : [];
const empty = document.getElementById("gridEmpty");
const visibleCount = document.getElementById("visibleCount");

const state: FilterState = { active: "all" };

function applyFilter(value: string) {
  state.active = value;
  let shown = 0;
  cards.forEach((card) => {
    const match = value === "all" || card.dataset.category === value;
    card.classList.toggle("is-hidden", !match);
    if (match) shown++;
  });
  if (visibleCount) visibleCount.textContent = String(shown);
  if (empty) empty.classList.toggle("show", shown === 0);

  chips.forEach((chip) => {
    const isActive = (chip.dataset.filter || "all") === value;
    chip.classList.toggle("active", isActive);
    chip.setAttribute("aria-selected", isActive ? "true" : "false");
  });
}

function syncFromUrl() {
  const params = new URLSearchParams(window.location.search);
  const cat = params.get("category");
  // Tolerate either the exact category label or the lowercased/url-encoded form.
  const target = cat
    ? chips.find((c) => (c.dataset.filter || "").toLowerCase() === cat.toLowerCase())?.dataset.filter
    : null;
  applyFilter(target || "all");
}

function setUrl(value: string) {
  const url = new URL(window.location.href);
  if (value === "all") {
    url.searchParams.delete("category");
  } else {
    url.searchParams.set("category", value);
  }
  window.history.replaceState({}, "", url.toString());
}

chips.forEach((chip) => {
  chip.addEventListener("click", () => {
    const value = chip.dataset.filter || "all";
    if (value === state.active) return;
    applyFilter(value);
    setUrl(value);
  });
});

// Re-sync when the user navigates back/forward — handles the "click category
// badge on a post → back button → hub filter resets" case.
window.addEventListener("popstate", syncFromUrl);

// Initial paint
syncFromUrl();
