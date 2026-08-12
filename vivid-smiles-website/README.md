# Vivid Smiles Dentistry

Marketing site for Vivid Smiles Dentistry, a cosmetic, implant, general, and emergency dental practice in Parker, Colorado. Built with [Astro](https://astro.build).

Build commands, redirects, DNS, and third-party tags are in [DEPLOYMENT.md](./DEPLOYMENT.md).

## Stack

- **Astro 6**, static output, file-based routing
- **Vanilla CSS** with custom properties. No Tailwind. Design tokens live in `src/styles/tokens.css`
- **GSAP + ScrollTrigger** for scroll reveals and parallax, **Lenis** for smooth scrolling
- **Astro content collections** (markdown) for blog posts and patient reviews

## Getting started

Requires Node 22.12 or newer.

```bash
npm install
npm run dev        # http://localhost:4321
```

| Script | What it does |
|---|---|
| `npm run dev` | Dev server at `http://localhost:4321` |
| `npm run build` | Build to `dist/` |
| `npm run preview` | Serve the production build locally |
| `npm run check` | Type-check, then build |

## Layout

- `src/pages/` — routes. Trailing slashes are always on
- `src/layouts/BaseLayout.astro` — head, fonts, global scripts, shared nav and footer
- `src/layouts/LandingLayout.astro` — layout for the paid landing pages
- `src/components/` — shared components, with `cards/` and `nav/` subfolders
- `src/styles/tokens.css` — the `--vs-*` custom properties
- `src/styles/pages/` — one stylesheet per page, namespaced under a page-level class
- `src/scripts/animations.js` — the GSAP animation library
- `src/content/` — markdown blog posts and reviews, schema in `src/content.config.ts`
- `src/data/` — contact details and office hours, used by the footer, contact page, and structured data
- `public/` — served as-is: redirects, headers, robots.txt, favicons

`/design-system` renders every component, token, and type style on one page. It is noindex and excluded from the sitemap.
