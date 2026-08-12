# Vivid Smiles — Headless WordPress + Astro

Conversion of the Vivid Smiles Dentistry site to a headless setup: WordPress as
the CMS, Astro as a statically-built front end.

This is a **new repository**. It does not push to
`allanconcepcion/vivid-smiles-website` — that repo is untouched, and the
pre-conversion history of the site is preserved locally in
`.original-git-backup/` (git-ignored).

```
                        edit                     build-time fetch
   editors ──────────► WordPress ──── WPGraphQL ────────────────┐
                       (cms/)                                    │
                                                                 ▼
   visitors ◄────── Vercel (static HTML) ◄──── Astro build ──── loaders
                                               (vivid-smiles-website/)
```

Visitors never reach WordPress. Content is pulled at build time and baked into
static HTML, so the site keeps its current performance profile.

| Directory | What |
| --- | --- |
| `vivid-smiles-website/` | The Astro front end — 35 routes, 48 built pages |
| `cms/` | Local headless WordPress (wp-env + Docker) |

## Quick start

Requires Docker Desktop (running) and Node 22+.

```bash
cd cms && npm install && npm start
```

That boots WordPress and provisions it. Then, in a second shell:

```bash
cd vivid-smiles-website && npm install && cp .env.example .env && npm run dev
```

| URL | What |
| --- | --- |
| http://localhost:4321 | The Astro site |
| http://localhost:8888/wp-admin | WordPress editor — `admin` / `password` |
| http://localhost:8888/graphql | GraphQL endpoint |

See [`cms/README.md`](cms/README.md) for the content model, plugin notes, and
the import scripts.

## Migration status

| Content | Source | Status |
| --- | --- | --- |
| Reviews / testimonials (20) | WordPress | **Migrated** — verified rendering identically |
| Blog posts (14) | `src/content/blog/*.md` | Not yet migrated |
| Page copy (35 routes) | Astro markup | Not yet migrated |

The `reviews` collection is fully WordPress-backed: edited in wp-admin under
**Testimonials**, fetched by `src/loaders/reviews.ts`, and validated against the
unchanged Zod schema in `src/content.config.ts`. No consuming component changed.

## Known constraints

**ACF Pro is required to model page content.** Repeater, Flexible Content,
Options Pages and Gallery are Pro-only. Testimonials and posts use only free
field types; the page-level model (hero / process steps / FAQ) needs Repeater
and Flexible Content. $49/yr for one site, $149/yr for ten; dev and staging
installs are not counted.

**A hosted preview needs a publicly reachable WordPress.** The Astro build
fetches content at build time, so a Vercel deployment cannot resolve a
`localhost` CMS. Until WordPress is hosted, previews are local only.

**Structured data stays in Astro.** 29 pages carry hand-written JSON-LD
(`Dentist`, `MedicalProcedure`, `FAQPage`, `BreadcrumbList`) that is more
specific than Yoast's generated output. Schema is generated in Astro from
WordPress field data rather than delegated to Yoast — so it remains a developer
concern, not an editor-facing one.
