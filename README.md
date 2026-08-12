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
| http://localhost:8888/wp-admin | WordPress editor |
| http://localhost:8888/graphql | GraphQL endpoint |

The local WordPress uses the throwaway account `wp-env` creates on first start —
see the [@wordpress/env docs](https://www.npmjs.com/package/@wordpress/env) for
its defaults. No credentials are stored in this repository, and none of them are
valid against the hosted CMS.

See [`cms/README.md`](cms/README.md) for the content model, plugin notes, and
the import scripts.

## Migration status

| Content | Where it's edited |
| --- | --- |
| Reviews / testimonials (20) | wp-admin → Testimonials |
| Blog posts (14) + 173 media | wp-admin → Posts |
| Page copy — 738 rows across 31 pages | wp-admin → Pages |
| Phone, email, address, hours, booking URL | wp-admin → Practice Settings |

**738 editable rows** moved into WordPress: 213 section headings and intros,
187 cards and list items, 166 table-of-contents links, 122 FAQ entries and 50
process steps — plus every practice detail that appears in the nav, footer,
CTAs and structured data.

Verified by comparing the rendered visible text of every route against a
pre-migration snapshot: **47/47 identical**, 0 references to the CMS host, and
an edit made in wp-admin confirmed appearing on the built page.

### What is still in code, and why

- **Layout and structure.** WordPress changes the words, not the design. There
  is no section ordering and no free-form block list — each page's CSS assumes a
  fixed structure, and a page builder would let an editor produce pages the
  stylesheet was never written for.
- **26 image-bearing arrays** (team photos, service icons, trust-bar logos,
  before/after cases). Their rows reference imported image assets, so the copy
  and the asset are one unit; splitting them would leave an editor able to
  change a caption but not the picture it describes.
- **16 duplicated eyebrow labels.** The same short label appears twice inside
  one section, so the migration could not tell which to replace and skipped both
  rather than guess. Listed in the output of `rewire-sections.mjs`.
- **Fine-grained inline copy** — button labels, captions and short spans woven
  into bespoke markup.

Everything above is reported by the import scripts rather than silently
omitted.

## This runs entirely on your machine

Nothing here depends on a hosted service. WordPress runs in Docker locally,
Astro reads it over `localhost` at build time, and the built site is plain
static files. You can work offline, and no client content leaves your machine.

Content is kept portable so that going online later is a move, not a rebuild:

| What | Where | Survives `wp-env destroy` |
| --- | --- | --- |
| Posts, reviews, settings | `cms/backup/database.sql` (`npm run backup`) | Yes |
| Media uploads | `cms/uploads/` — mapped to the host, not Docker | Yes |
| Content model, plugins | `cms/mu-plugins/`, `cms/bin/setup.sh` | Yes — declared in code |

### Going online later

Nothing needs rewriting. The sequence is:

1. Provision WordPress at `cms.vividsmilesdentistry.com`.
2. Copy `cms/uploads/` to the host and restore the database against it:
   `bash cms/bin/restore.sh https://cms.vividsmilesdentistry.com`
3. Deploy the `mu-plugins/` directory and run `bin/setup.sh` against the host.
4. Set `WP_GRAPHQL_ENDPOINT` in the Vercel project to the new hostname.
5. Add `image.remotePatterns` for that hostname to `astro.config.mjs` so Astro
   downloads and optimizes WordPress media at build time.
6. Add a Vercel deploy hook and fire it from WordPress on publish.

Until step 1 exists, a hosted preview is not possible: the Astro build fetches
content at build time, so a deployment cannot reach a `localhost` CMS.

## Known constraints

**No paid plugins.** The page model needs repeater fields, which ACF charges
for — so this uses Secure Custom Fields, WordPress.org's fork of ACF, which
ships repeater, flexible content, gallery, clone and options pages free and GPL.
It is a drop-in for ACF: same function surface, same storage format, and
WPGraphQL for ACF resolves against it unchanged. See
[`cms/README.md`](cms/README.md#secure-custom-fields-not-acf).

**A hosted preview needs a publicly reachable WordPress.** The Astro build
fetches content at build time, so a Vercel deployment cannot resolve a
`localhost` CMS. Until WordPress is hosted, previews are local only.

**Structured data stays in Astro.** 29 pages carry hand-written JSON-LD
(`Dentist`, `MedicalProcedure`, `FAQPage`, `BreadcrumbList`) that is more
specific than Yoast's generated output. Schema is generated in Astro from
WordPress field data rather than delegated to Yoast — so it remains a developer
concern, not an editor-facing one.
