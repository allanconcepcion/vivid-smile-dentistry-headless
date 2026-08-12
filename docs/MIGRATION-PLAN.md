# Vivid Smiles Dentistry — Headless WordPress Migration Plan

> ## Read this first — historical record, not a runbook
>
> **This file is the record of how the migration was planned and carried out.**
> It was written before Phase 1 and was never rewritten as decisions changed, so
> what is worth reading here is the reasoning: the forks that were considered,
> the options that were rejected and why, the traps that were found, and the
> risks that were weighed. That part is still accurate and is not reproduced
> anywhere else.
>
> **Operational instructions in it have been superseded in several places.**
> Where following a step would now do damage or waste time, an indented note
> immediately below it says what actually happened — look for *Superseded*,
> *Not executed*, *Partly implemented* or *Resolved*. Steps carrying no such
> note may still be stale; check them against the current docs before acting.
>
> **Current sources of truth:** [../README.md](../README.md) for what the system
> is, and [DEPLOYING.md](DEPLOYING.md) for how to deploy and operate it. Where
> this file disagrees with either, those two are right.
>
> Three divergences change the meaning of whole sections below:
>
> | This plan recommends | What actually shipped |
> |---|---|
> | WordPress REST API | **WPGraphQL**, plus WPGraphQL for ACF, Yoast and `add-wpgraphql-seo` |
> | v1 scope = blog + reviews only | **Full scope** — 14 posts, 20 reviews, 31 pages of structured copy, page images, navigation menus, SEO fields and practice settings |
> | CMS at `cms.vividsmilesdentistry.com` | `https://1230613.us28.myftpupload.com` — GoDaddy Managed WordPress on a **temporary** hostname. The permanent subdomain is still outstanding, so every command below naming `cms.vividsmilesdentistry.com` needs the temporary host substituted. |
>
> **WordPress is hosted, live and publicly reachable, and the front end is
> deployed** at `https://vivid-smiles-headless.vercel.app` from a Vercel Git
> integration that rebuilds on every push to `main`. Nothing in this file should
> be read as saying the CMS is local-only or that the site is undeployed.

**Repo:** `/Volumes/Concepcion Work/Vivid smiles/headless setup/vivid-smiles-website` — this is now the Astro app subdirectory; the git repository root is its parent, and `vercel.json` lives there.
**State at the time of writing (pre-migration):** Astro 6.1.9, fully static, no adapter, deployed to Vercel, `site: https://vividsmilesdentistry.com`, `trailingSlash: 'always'`, 35 routes, 14 blog posts + 20 reviews in `src/content/`, 149 local images (13.7 MiB). The markdown in `src/content/` is now inert — all three collections are WordPress-backed.
**Author:** Lead architect
**Status when written:** Decision-ready. Execute top to bottom.
**Status now:** Executed, with the divergences listed above and the corrections noted inline.

---

> **Correction (added after this plan was written).** The plan assumes ACF Pro
> is needed for the page content model, because Repeater and Flexible Content
> are ACF Pro fields. That is true of ACF, but not of **Secure Custom Fields** —
> WordPress.org's fork — which ships those field types free and GPL, works as a
> drop-in for ACF, and resolves through WPGraphQL for ACF unchanged. Verified on
> this install. Wherever this document says ACF Pro is required or recommends
> budgeting for it, no purchase is needed.
>
> The plan also recommends the REST API over WPGraphQL, and scoping v1 to blog +
> reviews rather than all 35 pages. This project uses WPGraphQL and the full
> scope; see the plan's own decision-fork section for what each choice changes.



## 1. Recommended architecture

Keep the Astro site exactly as it is — static output, no adapter, no SSR, deployed to Vercel — and replace only the two `glob()` content-collection loaders with a **custom Astro Content Layer loader that reads the WordPress REST API at build time**. WordPress runs on its own subdomain (`cms.vividsmilesdentistry.com`), serves no public front end, is locked behind Cloudflare Access on `/wp-admin`, and exists solely as an authoring surface. On publish, a WordPress mu-plugin POSTs to a Vercel Deploy Hook after a 90-second debounce; Vercel rebuilds the whole site, Astro downloads and re-encodes every WordPress image through sharp into hashed `/_assets/` files, and the deployed artefact is byte-for-byte the same *kind* of thing it is today: plain HTML and local images with no runtime dependency on WordPress. If WordPress is down, slow, hacked, or mid-update, the live site is unaffected — the last good deployment keeps serving. Scope for v1 is **blog + reviews only**; the 21,000 lines of hand-built service-page markup stay in Astro, because modelling ten single-use bespoke section layouts in ACF Flexible Content produces a worse CMS than no CMS.

### Data flow, in words

```
┌─ AUTHORING ────────────────────────────────────────────────────────────────┐
│                                                                            │
│  Editor writes in wp-admin at cms.vividsmilesdentistry.com                 │
│    • post (core)      → 14 blog posts, category taxonomy, featured image   │
│    • review (CPT)     → 20 reviews, ACF rating/source, review_tag taxonomy │
│    • media library    → 14 heroes + ~23 body images                        │
│                                                                            │
│  Clicks Publish                                                            │
│    └─ transition_post_status fires (NOT save_post)                         │
│         └─ mu-plugin schedules a single cron event, +90s, deduped          │
│              └─ POST https://api.vercel.com/v1/integrations/deploy/…       │
└────────────────────────────────────────────────────────────────────────────┘
                                     │
                                     ▼
┌─ BUILD (Vercel, ~2–4 min end to end) ──────────────────────────────────────┐
│                                                                            │
│  1. astro build starts. content.config.ts loads two custom loaders.        │
│                                                                            │
│  2. wordpressPostsLoader.load():                                           │
│       a. INDEX SWEEP — GET /wp-json/wp/v2/posts                            │
│            ?_fields=id,slug,modified_gmt&status=publish&per_page=100       │
│          (~40 bytes/post; one request for the whole blog)                  │
│       b. DIFF vs the JSON index persisted in the loader's `meta` store     │
│          (node_modules/.astro/data-store.json, restored by Vercel's        │
│           node_modules build cache)                                        │
│       c. FETCH FULL PAYLOAD only for slugs whose modified_gmt moved:       │
│            GET …/posts?include=<ids>&_embed=author,wp:featuredmedia,wp:term│
│       d. NORMALIZE  → strip entities, truncate description to 200,         │
│                       map WP category slug → closed 5-value enum,          │
│                       append Z to date_gmt, fall back heroAlt to title     │
│       e. SANITIZE + SLUG the HTML (ultrahtml sanitize + github-slugger),   │
│          assigning id="" on every h2/h3 and collecting the headings array  │
│       f. store.set({ id: <wp slug>, data, body: <plain text>,              │
│                      rendered: { html, metadata: { headings } }, digest }) │
│       g. DELETE store entries no longer present in the index               │
│       h. FLOOR ASSERTION — throw if entry count < 12. A build that         │
│          succeeds with an empty site is worse than a build that fails.     │
│                                                                            │
│  3. Zod schema validation runs via parseData(). A bad category, an         │
│     over-long excerpt, or a missing alt is a LOUD build failure.           │
│                                                                            │
│  4. Page rendering — UNCHANGED CODE PATHS:                                 │
│       getCollection("blog")  → src/lib/blog.ts (sort/related/prev-next)    │
│       render(post)           → { Content, headings } → TOC + <Content />   │
│       postUrl(post)          → /blog/<post.id>/  ← id IS the WP slug       │
│                                                                            │
│  5. astro:assets sees data.heroImage as a REMOTE https URL matching        │
│     image.remotePatterns → downloads the original, runs sharp, emits       │
│     dist/_assets/<name>.<hash>.webp with srcset variants.                  │
│     The shipped HTML never references cms.vividsmilesdentistry.com.        │
└────────────────────────────────────────────────────────────────────────────┘
                                     │
                                     ▼
┌─ DEPLOY ───────────────────────────────────────────────────────────────────┐
│  dist/ → Vercel static. vercel.json supplies trailingSlash, the ~65 legacy │
│  redirects, and the /_assets/* immutable cache header (none of which are    │
│  live today — see Phase 0). If the build fails, the previous deployment     │
│  stays live and a deployment.error webhook writes back to wp-admin.        │
└────────────────────────────────────────────────────────────────────────────┘
```

**One sentence to hold onto:** WordPress is only ever touched at build time. There is no request-time coupling anywhere in this design, and there must not be one.

> **That sentence held. Two details of the diagram above did not.**
>
> - **The publish → deploy hook link was never built.** No mu-plugin POSTs to a Vercel Deploy Hook, and there is no debounce, no admin notice and no status pill. Deploys are triggered by a push to `main` through the Vercel Git integration, which is connected and verified. An editor publishing in WordPress does **not** currently update the live site — someone has to push. Wiring the hook is still outstanding; see the note on Phase 7.
> - **The loaders do a full replace, not an incremental diff.** The index-sweep-and-diff design described in step 2 was not implemented; each build refetches everything and calls `store.clear()`. Correct, just not cheap.
>
> The rest of the diagram is what runs: build-time-only fetches, `astro:assets` downloading and re-encoding every WordPress image into hashed `/_assets/` files, no CMS hostname in the shipped HTML, and the previous deployment staying live when a build fails.

---

## 2. Decision forks

### Fork 1 — Framework: stay on Astro, or rewrite in Next.js?

**Recommendation: stay on Astro. This is not close.**

The audit found 164 `<Image>` call sites across 41 files with 40 distinct tuned `widths` arrays, ~35–40k words of bespoke copy, GSAP/Lenis animation with a hand-tuned draggable marquee that assumes an exact two-copy track, per-page CSS namespaces, and 75 hand-written JSON-LD blocks. A Next.js rewrite re-does *all* of that to solve a problem that is entirely in the data layer. The data layer is roughly 400 lines of work.

Concretely, what Astro gives you here that Next.js does not:
- `render()` returning `{ Content, headings }` from loader-supplied HTML metadata — this is what keeps the sticky TOC and `toc-spy.ts` working with zero changes to `[slug].astro`'s TOC block.
- Content Layer digest + `meta` caching, which is what makes incremental WP sync possible.
- `astro:assets` build-time download + sharp for remote images, producing same-origin hashed output.
- Zero-JS-by-default output, which is the actual competitive advantage of this site for local SEO.

**If they pick Next.js anyway:** budget 6–10 weeks, not 2–3. You lose the Content Layer loader entirely (replaced by `generateStaticParams` + fetch, with no digest caching, so every build refetches everything). `next/image` with remote WP URLs needs `images.remotePatterns` and, on a static export, `unoptimized: true` — meaning you lose image optimization completely unless you deploy as a server app. The 35 `.astro` pages become 35 `.tsx` pages with every `class` → `className`, every `set:html` → `dangerouslySetInnerHTML`, and every scoped `<style>` block manually extracted. The TOC/headings contract has no equivalent and must be re-derived. There is no upside for this site. The only honest reason to pick Next.js is if the client's future in-house team is a React shop — and that is a hiring decision, not an architecture one.

### Fork 2 — API: WPGraphQL or REST?

**Recommendation: WordPress REST API.**

At 14 posts and 20 reviews, over-fetching and request count are non-problems. `_fields` + `_embed` gets the entire blog in 2 HTTP requests and the entire review set in 1. The decision is therefore about **plugin count and failure mode**, and REST wins on both:

| | REST | WPGraphQL |
|---|---|---|
| Plugins required | **0** (core) | 3–5 (WPGraphQL, WPGraphQL for ACF, Yoast/RankMath bridge, +Smart Cache, +JWT) |
| Patched by | WordPress auto-updates | Independent plugin release cadences |
| ACF exposure | Native `show_in_rest` toggle on the field group | Requires WPGraphQL for ACF, plus per-field GraphQL names |
| Yoast SEO | `yoast_head_json` injected automatically, no addon | Requires `add-wpgraphql-seo` |
| Rank Math | Official headless endpoint | `AxeWP/wp-graphql-rank-math` is at v0.3.4 from **June 2025**, pre-1.0, self-documented as having breaking changes ahead |
| Application Passwords | Work out of the box | Need a custom `application_password_is_api_request` filter; known hook-ordering bug (wp-graphql#1689) |
| Failure mode | Core broke — you have bigger problems | A plugin auto-updated, wp-admin looks fine, **the Astro build fails silently and the site goes stale** |
| Debuggable by | Any WP dev with `curl` | Someone who knows this specific stack |

That last row is the real argument. Nobody at a dental practice watches CI.

**If they pick WPGraphQL anyway:** only the loader's fetch function changes — swap the two REST calls for one POST to `/graphql` with `posts(first:100, where:{status:PUBLISH})` returning `slug title excerpt content date modified categories{nodes{slug}} featuredImage{node{sourceUrl altText mediaDetails{width height}}}`. Everything downstream (normalization, sanitization, slugging, store.set) is identical. **Additional required work:** install and pin WPGraphQL ≥ 2.15.1 (CVE-2026-54768, unauthenticated author enumeration) and ≥ 2.5.4 (CVE-2025-68604, CSRF); disable GraphiQL, GraphQL debug mode, and public introspection in production; install WPGraphQL for ACF (which is why you'd then need **ACF PRO at $59/yr rather than free Secure Custom Fields** — SCF support is not officially documented by the GraphQL bridge); and give up the cheap `modified_gmt` index sweep, because WPGraphQL has no `modified_after`-equivalent filter, meaning every build refetches every post body. Verify the SEO plugin first: **if the practice runs Rank Math rather than Yoast, WPGraphQL is off the table.**

### Fork 3 — Scope: blog only / blog + reviews / + service page copy / everything

**Recommendation: blog + reviews. Ship that, then stop and reassess for 60 days.**

Reasoning per tier:

| Scope | Verdict | Why |
|---|---|---|
| **Blog only** | Too small | Reviews are the second-cheapest thing to migrate (no URLs, no images, no `render()`, no TOC, no image pipeline) and are the content the practice actually adds most often — a new Google review lands weekly. Migrating blog without reviews leaves the highest-frequency editing task still requiring a developer. |
| **Blog + reviews** ✅ | **Recommended** | Covers ~100% of the content that changes monthly. Touches 2 of 35 routes structurally. Zero new page templates. ~400 lines of loader + a WP content model that fits on one screen. |
| **+ service page copy** | Defer | The audit is unambiguous: FAQ arrays, `processSteps`, `tocLinks`, `whyCards` etc. are already clean JSON on 30 of 35 pages and map 1:1 to ACF repeaters — that's the cheap 60%. The other 40% is inline JSX prose inside bespoke markup with page-scoped CSS namespaces (`.cdlp-*`, `.vlp-*`, `.glp-*`) and a load-bearing `<em class="vs-italic-word">` convention. ACF Flexible Content assumes blocks are style-portable; here they are not. This is a genuine 2–3 week workstream *plus* a CSS unification pass that will produce visual regressions. Do it only after the blog+reviews pipeline has been stable for two months. |
| **Everything** | Reject | `/` has 10 sections, every one a unique layout including a `<dialog>` video modal and a deliberate marquee seam-duplication trick. `/privacy-policy/` and `/terms-conditions/` are 5,643 words of hand-numbered legal prose that arrives from counsel as a full replacement document ~annually. `/smile-gallery/` uses `import.meta.glob` where the filename prefix encodes order and the filename stem *is* the alt text — and `src/lib/smiles.ts:44-48` documents that as a **patient-privacy rail**: alt text must describe only the photograph, never claim a treatment outcome, because these are identifiable patients. A free-text ACF alt field removes that rail. The 3 paid LPs are noindex, sitemap-excluded, and iterated by forking the file per campaign; marketing does not edit those in a CMS. |

**If they pick "+ service pages" or "everything":** insert a new Phase 9 of 3–4 weeks. Build exactly 12 reusable layout blocks (hero, at-a-glance stats, why-cards, split-image-text, numbered process, compare-cards, stat-callout, pricing-tiers, local-trust, gallery-marquee, faq, closing-band) as an ACF Flexible Content field on a `service` CPT. Pilot on `/cosmetic-dentistry/gum-contouring/` (536 lines, the smallest service page). **Two hard prerequisites before any of it:** (a) normalize FAQ rendering — 17 pages use `set:html={f.a}`, 7 use escaped `{f.a}`, and `referral-program:231` uses a third form; a WYSIWYG FAQ field will render raw `<a href=...>` as visible text on 8 pages; (b) resolve the duplicated source data — the service card list exists three times with divergent copy, and the credential list three times with divergent `sub` values. Which variant is canonical is a **content-owner decision, not a developer one**, and must be settled before a line of ACF is built.

### Fork 4 — WordPress hosting: local dev now / existing install / managed host

**Recommendation: build on `@wordpress/env` locally first, then deploy to SpinupWP + Hetzner (~$18–24/mo). Do not reuse a legacy install.**

> **Half taken.** `@wordpress/env` on Docker is what the local CMS runs on, and `cms/.wp-env.json` is committed exactly as argued for. Production went to **GoDaddy Managed WordPress**, not SpinupWP — which brings the constraint that fills the rest of these docs: the platform rewrites `wp-config.php` during its own updates and silently drops hand-added lines, so per-environment constants live in `cms/mu-plugins/vs-config.php` instead. The managed platform also fronts the site with Cloudflare bot protection, which is the direct cause of the build-time 429s discussed under risk 3.

- **Local dev (`wp-env`)** — non-negotiable for the build phase, whatever production ends up being. `.wp-env.json` is committed to this repo, so the whole CMS is reproducible from source; `mappings` mounts `wp/mu-plugins` and `wp/acf-json` straight from the repo; and `wp-env run cli wp …` gives you WP-CLI, which the content importer depends on. **Use the Docker runtime.** The experimental `--runtime=playground` flag drops the Docker requirement but uses SQLite, has no test environment, and — decisively — **does not support `wp-env run`**, so WP-CLI is unavailable and the importer cannot run.
- **Production: SpinupWP Essentials ($12/mo) on a Hetzner CX22 (~€4/mo)** — ~$18–24 all-in, unlimited sites on the box (relevant if Concepcion.Work runs other client CMS installs), Nginx + PHP 8.3 + Redis + Let's Encrypt configured correctly by default, and you own the server so there are no visit caps or renewal cliffs.
- **Alternative if nobody wants a server to exist: Cloudways (~$11–14/mo)**, fully managed, Redis included, one-click staging. Slightly worse economics, materially less ops.
- **Explicitly reject:** Kinsta (Redis is a **$100/mo per-site add-on** — disqualifying), WP Engine Headless Platform ($49+/mo buys you Node front-end hosting you already get free from Vercel), and a bare self-managed droplet (saving $12/mo costs many hours on the first security incident, and this is an internet-facing WordPress admin).

**Reusing an existing install:** only if one exists, is on PHP 8.2+, and has a *clean* plugin list. The audit shows `public/_redirects` contains ~65 rules migrating legacy WordPress root slugs to `/blog/<slug>/`, and `content.config.ts:66-67` references a prior WordPress export — so a legacy install may still exist and may still contain the four retired veneers posts whose markdown was deliberately deleted (`public/_redirects:137-140`) and the archived `/before-and-afters/` page (`:152-153`, which carries an explicit in-file warning that a routed asset and a redirect at the same path is undefined behaviour). **If that install is resurrected as the CMS, those posts will regenerate at exactly the paths the redirect file claims.** Treat any legacy install as a content source to import *from*, not as the CMS to build *on*.

**If they insist on an existing shared/cPanel host:** verify `/wp-json/` is not blocked by a security plugin, that Application Passwords are not disabled, that the host doesn't 301 `http→https` on media URLs (Astro's `loadRemoteImage` uses `redirect: 'manual'` and **throws on any 3xx**, hard-failing the entire build), and that there is a way to set an `X-Robots-Tag` response header. If any of those fail, the plan doesn't work on that host.

---

## 3. Phased implementation plan

Ordering rule: **the site is deployable and correct at the end of every phase.** Reviews migrate before blog because reviews have no URLs, no images, no `render()`, and no TOC.

---

### Phase 0 — Prerequisites and baseline (no WordPress yet)

**Goal:** Fix the deployment layer that is currently inert, pin the toolchain, and capture a byte-level baseline to diff against later.

**Repo changes:**

1. **Create `vercel.json`** (does not exist; `VERCEL-DEPLOYMENT-NOTES.md` §5 says it is still missing). Today `public/_redirects` and `public/_headers` are Netlify/Cloudflare format and **Vercel ignores both** — so the ~65 legacy WP redirects are not firing, HSTS/X-Frame-Options/X-Content-Type-Options/Referrer-Policy are unapplied, the `/_assets/*` immutable cache is unapplied, and both files are publicly readable at `/_redirects` and `/_headers`.

> **Superseded — done, but differently.** `vercel.json` now exists, committed at the **repository root** (not in the app directory), carrying `trailingSlash: true`, 2 header groups and **65** redirects, plus the `installCommand` / `buildCommand` / `outputDirectory` that point the build into `vivid-smiles-website/`. It is **generated, not hand-written**: `vivid-smiles-website/scripts/build-vercel-config.mjs` reads `public/_headers` and `public/_redirects` and writes it. Regenerate with `cd vivid-smiles-website && npm run vercel:config` after editing either source file. See [DEPLOYING.md](DEPLOYING.md).

```json
{
  "trailingSlash": true,
  "redirects": [
    { "source": "/how-much-do-veneers-cost", "destination": "/blog/how-much-do-veneers-cost/", "permanent": true }
  ],
  "headers": [
    {
      "source": "/_assets/(.*)",
      "headers": [
        { "key": "Cache-Control", "value": "public, max-age=31536000, immutable" },
        { "key": "Accept-Ranges", "value": "bytes" }
      ]
    },
    {
      "source": "/(.*)",
      "headers": [
        { "key": "Strict-Transport-Security", "value": "max-age=31536000; includeSubDomains" },
        { "key": "X-Frame-Options", "value": "SAMEORIGIN" },
        { "key": "X-Content-Type-Options", "value": "nosniff" },
        { "key": "Referrer-Policy", "value": "strict-origin-when-cross-origin" }
      ]
    }
  ]
}
```
   Machine-translate all ~65 rules from `public/_redirects` (a one-off script; Vercel matches without needing the duplicated slash/no-slash pairs that file carries, since `trailingSlash: true` normalizes). **Then delete `public/_redirects` and `public/_headers`** so there is one source of truth and they stop being publicly readable.

> **Superseded — do not delete those two files.** The translation script was not made a one-off: `public/_headers` and `public/_redirects` are the *source* that `scripts/build-vercel-config.mjs` regenerates `vercel.json` from, so deleting them removes the input the generator needs. Both files still ship into `dist/` and so are still publicly readable at `/_redirects` and `/_headers` — that half of the concern is unresolved, and both files carry a banner explaining that Vercel does not read them.

2. **`.nvmrc`**: change `22` → `22.12.1` (both `DEPLOYMENT.md:9` and `VERCEL-DEPLOYMENT-NOTES.md:21` already *claim* it is pinned to 22.12+; it is not). Add `"packageManager": "npm@10.x"` to `package.json`. Set the Node version in Vercel project settings to match — **Vercel reads project settings, not `.nvmrc`**, and the build-cache key includes the Node version, so a silent drift throws away the image cache.

3. **Capture the baseline.** This is the single most valuable artefact in the whole migration:
   ```bash
   npm ci && npm run build
   cp -R dist ../dist-baseline-preWP
   ```
   Also write `scripts/diff-baseline.mjs` — normalizes hashed asset filenames (`/_assets/x.[hash].webp` → `/_assets/x.HASH.webp`) and diffs `dist/blog/**/index.html` against the baseline. Every later phase runs it.

4. **Fix three pre-existing defects now**, while content is still local and the diff is trivially verifiable:
   - `src/lib/blog.ts:96` — the regex has **zero capture groups**, so `$1` is inserted as the literal two-character string `$1`. 35 inline links across 10 posts each become a bogus one-word token. Fix: `.replace(/\[([^\]]*)\]\([^)]*\)/g, "$1")`.
   - `src/scripts/toc-spy.ts:22-24` — `document.querySelector(a.getAttribute('href'))` throws on a slug starting with a digit or containing a colon, killing the entire spy. Fix: `const el = document.getElementById(decodeURIComponent(href.slice(1)))`.
   - `src/lib/blog.ts:15-18, 28` — `includeDrafts` is declared, documented as "set false from the design-system route only", and never passed by any caller. Delete it, and correct the two false comments (`content.config.ts:64` claims the sitemap filter excludes drafts; `astro.config.mjs:14-37` has no draft logic at all).

**WordPress-side:** none.

**Verification:** `npm run check` clean. `node scripts/diff-baseline.mjs` shows only the expected reading-time deltas on the 10 posts containing inline links. Deploy to a Vercel preview and confirm with `curl -sI` that `/how-much-do-veneers-cost` 301s to `/blog/how-much-do-veneers-cost/` and that `/_assets/*` returns `Cache-Control: …immutable`.

---

### Phase 1 — Stand up WordPress (site untouched)

**Goal:** A running, locked-down, correctly modelled WordPress with zero content. The Astro site is not modified in this phase at all.

**Repo changes:** add committed CMS-as-code, none of it wired to the build yet.

```
.wp-env.json                       ← new
wp/mu-plugins/vivid-headless.php   ← CPT, taxonomy, lockdown, hardening
wp/mu-plugins/vivid-import.php     ← WP-CLI importer (Phase 4)
wp/acf-json/                       ← ACF Local JSON (field groups in git, not the DB)
```

`.wp-env.json`:
```json
{
  "core": null,
  "phpVersion": "8.3",
  "plugins": [
    "https://downloads.wordpress.org/plugin/advanced-custom-fields.latest-stable.zip",
    "https://downloads.wordpress.org/plugin/wordpress-seo.latest-stable.zip"
  ],
  "mappings": {
    "wp-content/mu-plugins": "./wp/mu-plugins",
    "wp-content/acf-json":   "./wp/acf-json",
    "wp-content/import-src": "./src/content",
    "wp-content/import-img": "./src/assets/images/blog"
  },
  "config": { "WP_DEBUG": true, "DISALLOW_FILE_EDIT": true },
  "port": 8888
}
```

**WordPress-side setup:**
1. `npm i -D @wordpress/env && npx wp-env start` → `http://localhost:8888`, signing in with the local account wp-env creates.
2. Register the content model (see §4 for the exact contract) via `wp/mu-plugins/vivid-headless.php`.
3. Create the 5 blog categories with **exact slugs**: `dental-tips`, `cosmetic-dentistry`, `implant-dentistry`, `general-dentistry`, `emergency-dentistry`.
4. Build the two ACF field groups in wp-admin, set each to **Show in REST API = Yes**, and confirm the JSON lands in `wp/acf-json/`. Commit it.
5. Create a dedicated **`astro-build`** user, role **Editor**, and issue an Application Password. Never use an admin account for the build.
6. Production hardening (in the mu-plugin + at the edge):

> **Partly implemented — do not assume `/wp-admin` is protected.** `cms/mu-plugins/vs-headless.php` ships the front-end bounce (a **302**, not a 301, with a passthrough list for `/graphql`, `/wp-admin`, `/wp-login.php`, `/wp-json`, `/wp-cron.php` and `/robots.txt`, plus logged-in users so previews work), the `X-Robots-Tag: noindex, nofollow` header on every response, a `robots.txt` of `Disallow: /` filtered at `PHP_INT_MAX` so Yoast cannot append a permissive block over it, and `xmlrpc_enabled → false`. **Cloudflare Access was never put in front of `/wp-admin` or `/wp-login.php`**, and there is no `astro-build` service user or service token — WPGraphQL is queried anonymously. The remaining items in this list (unsetting `/wp/v2/users`, `DISALLOW_FILE_EDIT`, 2FA, no user named `admin`) are unverified on the hosted install.
>
> One passthrough this list does not mention is load-bearing: the Yoast sitemap paths (`/sitemap_index.xml`, `/page-sitemap.xml`, `/post-sitemap.xml`) must stay reachable on the CMS host, because the Astro build fetches them. They are **not** in `vs-headless.php`'s passthrough array — they survive only because Yoast emits and exits on the `wp` hook, before `template_redirect` runs at priority 0.
   - `template_redirect` 301 of anything that isn't `/wp-admin`, `/wp-login.php`, `/wp-json` to `https://vividsmilesdentistry.com` + path. **This must be live before DNS points anywhere**, or `cms.vividsmilesdentistry.com/blog/how-much-do-veneers-cost/` competes with the canonical URL.
   - `X-Robots-Tag: noindex, nofollow, noarchive` as an **HTTP response header on the whole host**, set at Nginx/Cloudflare. The Settings → Reading checkbox is advisory only and Google routinely ignores `robots.txt` for URLs it discovers via links.
   - `robots.txt` on the CMS host returning `User-agent: *\nDisallow: /`.
   - Cloudflare Access (Zero Trust, free ≤50 users) in front of `/wp-admin*` and `/wp-login.php`, email-OTP or Google SSO. Issue a **service token** and exempt `/wp-json` for the build.
   - `xmlrpc_enabled → __return_false`; unset `/wp/v2/users` for unauthenticated callers; `DISALLOW_FILE_EDIT`, `DISALLOW_FILE_MODS`, `FORCE_SSL_ADMIN`; 2FA on every human account; no user named `admin`.
7. `big_image_size_threshold` stays at 2560 (**do not** `__return_false` it — that threshold is what caps `source_url`, and `source_url` is the file Astro downloads on every cold build). `jpeg_quality` → 92. **Do not** enable WP-side WebP/AVIF generation; sharp owns output format.

**Verification:**
```bash
curl -s "http://localhost:8888/wp-json/wp/v2/posts?_fields=id,slug&per_page=1" | jq
curl -s "http://localhost:8888/wp-json/wp/v2/review?per_page=1" | jq   # 200, empty array
curl -sI https://cms.vividsmilesdentistry.com/some-page/                # 301 → vividsmilesdentistry.com
curl -sI https://cms.vividsmilesdentistry.com/ | grep -i x-robots-tag   # noindex
curl -s  https://cms.vividsmilesdentistry.com/wp-json/wp/v2/users       # 404 unauthenticated
curl -sI https://cms.vividsmilesdentistry.com/wp-admin/                 # Cloudflare Access challenge
```
Astro site: unchanged, still deploying, still passing `diff-baseline`.

---

### Phase 2 — Repo-side hardening that is safe today

**Goal:** Make every change the WP migration will *require* while content is still local, so each one is independently verifiable against the baseline. **Nothing in this phase talks to WordPress.**

**Repo changes:**

1. **New `src/lib/reviews.ts`** — a single deterministic accessor, because seven consumers currently sort inline by date alone and the dataset has **six reviews sharing 2025-04-27 and three sharing 2025-12-27**. Ties resolve today only via JS sort stability falling back to glob's alphabetical-by-`id` order — i.e. the `NN-` filename prefix. A WP loader without an explicit secondary key reshuffles the marquee between builds.

```ts
// src/lib/reviews.ts
import { getCollection, type CollectionEntry } from "astro:content";
export type Review = CollectionEntry<"reviews">;

/** Newest-first, with an explicit deterministic tie-break on `order`
 *  (seeded from the NN- filename prefix; becomes WP menu_order). */
export async function getAllReviews(): Promise<Review[]> {
  const all = await getCollection("reviews");
  return all.sort(
    (a, b) => +b.data.date - +a.data.date || a.data.order - b.data.order,
  );
}
```

2. **Add `order: z.number().int().default(0)`** to the reviews schema in `content.config.ts`, and add `order: N` to all 20 markdown files (seeded from the existing `NN-` prefix). This makes the current build byte-identical while establishing the field the WP loader will populate from `menu_order`.

3. **Refactor all 7 review consumers** to `getAllReviews()`:
   `src/pages/index.astro:30`, `about-us/index.astro:257`, `new-patients/index.astro:22`, `patient-testimonials/index.astro:29`, `veneers-lp.astro:92`, `cosmetic-dentistry-lp.astro:95`, `general-lp.astro:78`.

4. **`src/pages/design-system.astro:35-36`** currently does `getCollection('reviews')` then `.slice(0, 3)` with **no sort** — it depends purely on collection iteration order. Change to `(await getAllReviews()).slice(0, 3)`. (This changes which three reviews show; that page is noindex + sitemap-excluded, so it is safe, but note it in the diff.)

5. **`src/components/BlogByline.astro:21`** — make `readingTime` HTML-tolerant now, because after migration `body` is derived plain text rather than markdown source and a stray tag would inflate the count:
```ts
export function readingTime(body: string): number {
  const stripped = body
    .replace(/<[^>]+>/g, " ")                  // NEW: tolerate HTML
    .replace(/!\[[^\]]*\]\([^)]*\)/g, "")
    .replace(/\[([^\]]*)\]\([^)]*\)/g, "$1")   // FIXED in Phase 0
    .replace(/[#*_`~]/g, " ");
  const words = stripped.trim().split(/\s+/).filter(Boolean).length;
  return Math.max(1, Math.round(words / 200));
}
```

**Verification:** `node scripts/diff-baseline.mjs` must show **zero diffs** on all `/blog/**` and all review-bearing pages except the intentional `/design-system/` change. That zero-diff result is the proof that the deterministic sort matches today's implicit order.

---

### Phase 3 — Reviews → WordPress

**Goal:** `reviews` sourced from WP. Lowest-risk possible first cut: no URLs, no images, no `render()`, no TOC, no image pipeline.

**Repo changes:**
- `src/loaders/wordpress.ts` — new (see §5).
- `src/content.config.ts` — `reviews` swaps `glob()` for `wordpressReviewsLoader({...})`, adds `order`. `blog` untouched.
- `.env.example`, `.gitignore` (`.env*` already ignored), Vercel env vars.
- `package.json` — promote `entities`, `github-slugger`, `ultrahtml` from transitive Astro deps to explicit `dependencies`.
- `src/content/reviews/` → move to `content-archive/reviews/` (**do not delete** — this is your rollback, and leaving them in `src/content/` guarantees someone edits the wrong copy within a month).

> **Not executed.** `content-archive/` was never created. The 20 review markdown files are still at `vivid-smiles-website/src/content/reviews/` and the 14 blog files at `src/content/blog/`. Nothing in the Astro build reads either directory — no `glob()` loader is instantiated anywhere in `src/` — so they are inert rollback copies and inputs to the one-time CMS importers (`cms/import/build-blog-payload.mjs`, `cms/import/import-reviews.php`). The "someone edits the wrong copy" hazard this step was meant to remove is still open.

**WordPress-side:** import the 20 reviews (see §6, `wp vivid import-reviews`).

**Verification:**
1. `npx wp-env run cli wp post list --post_type=review --format=count` → `20`.
2. `npm run build && node scripts/diff-baseline.mjs` — the review marquee HTML on `/`, `/about-us/`, `/new-patients/`, `/patient-testimonials/` and the three LPs must be **byte-identical** to baseline. Any diff is a data bug, not an expected change.
3. Manually confirm the three LP curation regexes still select the same 6 reviews (`veneers-lp.astro:93-97`, `cosmetic-dentistry-lp.astro:96-100`, `general-lp.astro:81-84`) — these run over `r.body`, so an HTML leak would silently flip the selection.
4. `grep -c '&#' dist/index.html` — confirm no raw HTML entities leaked into the escaped plain-text slot (`ReviewCard.astro:58`).

---

### Phase 4 — Content import of the 14 posts + images (site still on markdown)

**Goal:** Every blog post and every image lives correctly in WordPress. **The Astro site is still reading local markdown at the end of this phase.** Import and cutover are deliberately separate.

**Repo changes:** `wp/mu-plugins/vivid-import.php` (see §6).

**WordPress-side:**
1. `npx wp-env run cli wp vivid import-blog /var/www/html/wp-content/import-src/blog`
2. In wp-admin, for each of the 14 posts, open it and use the block editor's **"Convert to blocks"** on the imported Classic block. 14 clicks buys the practice a real Gutenberg editing experience instead of one opaque HTML blob.
3. Fill Yoast meta description on any post where the imported excerpt is close to 200 chars.
4. Spot-check every featured image has non-empty alt text (the importer seeds it from `heroAlt`, which is required in the current frontmatter, so this should be 14/14).

**Verification:**
```bash
npx wp-env run cli wp post list --post_type=post --post_status=publish --field=post_name | sort > /tmp/wp-slugs.txt
ls src/content/blog | sed 's/\.md$//' | sort > /tmp/md-slugs.txt
diff /tmp/wp-slugs.txt /tmp/md-slugs.txt      # MUST be empty
```
This is the single most important check in the migration. `postUrl()` derives `/blog/<id>/` purely from the filename stem, and two posts have titles that diverge substantially from their slugs (`hairline-cracks-in-tooth-what-are-your-treatment-options` → *"Hairline Cracks in Teeth: Causes, Symptoms, and Fixes"*; `the-best-type-of-dental-veneers-the-different-types-of-veneers` → *"Types of Veneers: Which One Is Right for You?"*). **If a single slug diverges, that indexed URL 404s.**

Also verify: 14 featured images attached; ~23 body images sideloaded and no `../../assets/` string remaining in any `post_content`:
```bash
npx wp-env run cli wp db query "SELECT post_name FROM wp_posts WHERE post_content LIKE '%../../assets/%'"  # empty
```

---

### Phase 5 — Blog → WordPress (the cutover)

**Goal:** `blog` sourced from WP. This is the risky phase; everything before it exists to de-risk it.

**Repo changes:**
1. `src/content.config.ts` — `blog` swaps `glob()` for `wordpressPostsLoader({...})`; `heroImage: image()` → `z.string().url()`; add `heroWidth` / `heroHeight`.
2. `astro.config.mjs` — add the `image` block with `remotePatterns` and a trimmed `breakpoints` array (**the default remote ladder is 15 entries up to 6016px vs 8 up to 2560px for local — leaving it costs ~2× the sharp encodes and ~2× the files in `dist`**).
3. `src/pages/blog/[slug].astro` — two edits only:
   - L51: `getImage({ src: data.heroImage, width: 1200, format: "webp" })` → add `height: 630`. **`verifyOptions` throws `MissingImageDimension` for any non-ESM src missing either dimension, regardless of the allowlist. This is a hard build failure.**
   - L93-99: `<Image>` gains `width={data.heroWidth} height={data.heroHeight}`, and add `fetchpriority="high"` — this is the only hero on the site without it (`index.astro:108`, `about-us/index.astro:307`, `smile-gallery/index.astro:86` all have it), and the gap gets materially worse once the source is remote.
4. `src/components/cards/BlogCard.astro:26-32` — same `width`/`height` addition.
5. `src/content/blog/` → `content-archive/blog/`. `src/assets/images/blog/` → **keep for now** (Phase 6 fallback); delete in Phase 7 once the pipeline is proven.

> **Not executed** — see the note in Phase 3. Both the markdown and `src/assets/images/blog/` are still in place. `src/assets/images/` as a whole is now roughly 97% orphaned: only four ESM image imports survive in the codebase, all in `src/pages/design-system.astro`. Nothing has been deleted, so the fallback is intact; nothing has been archived either.

**WordPress-side:** none (Phase 4 did it).

**Verification — this is the gate:**
```bash
npm run check                              # astro check must be clean
npm run build
node scripts/diff-baseline.mjs             # normalized-hash diff vs dist-baseline-preWP
```
Expected diffs and **only** these:
- Asset hashes on hero images (different source bytes from the media library round-trip).
- `dateModified` in BlogPosting JSON-LD, where WP `modified` now differs from `date` on posts that never had `updated:`. **Verify the loader only emits `updated` when `modified_gmt !== date_gmt`**, or every post starts showing a modified date.
- Body `<img src>` now pointing at `cms.vividsmilesdentistry.com` (fixed in Phase 6).

Anything else — a changed slug, a changed TOC, a missing category chip, a different reading time, a changed related-posts set — is a **stop-and-fix**.

Then, in a Vercel preview deployment:
- Every one of the 14 `/blog/<slug>/` URLs returns 200.
- TOC anchors resolve: `for h in $(...)` — or simply open three posts and click every TOC link. **WordPress does not emit heading IDs; the loader assigns them with `github-slugger` in the same pass that builds the `headings` array.** If they drift, the TOC renders dead links and `toc-spy.ts` silently no-ops with no console error and no build error.
- `/blog/?category=Cosmetic%20Dentistry` filters correctly — `BlogCard.astro:24` emits `data-category={data.category}` and `blog-filter.ts:29` does exact string equality against `chip.dataset.filter`. **Category label strings are a runtime contract, not display text.**

---

### Phase 6 — Body-image optimization + prose CSS for WP block markup

**Goal:** Close the two gaps Phase 5 knowingly left open.

**A. Body images.** After Phase 5, in-body images are absolute `wp-content` URLs inside `rendered.html`, bypassing `astro:assets` entirely. They carry WP's `width`/`height` attributes (so no CLS — `blog-post.css:295-302` sets `width:100%; height:auto` with **no `aspect-ratio`**, and relies entirely on those intrinsic attributes), but they are unoptimized, served from the CMS origin, and outside the `/_assets/* immutable` cache.

Run this **spike first, it decides the implementation**:
```bash
# Does astro:assets resolve inside a content loader in Astro 6.1.9?
node -e "0" # placeholder — actually: add `import { getImage } from 'astro:assets'`
            # to src/loaders/wordpress.ts, call it once, run `npm run build`.
```
- **If it resolves:** add `optimizeBodyImages()` to the loader's HTML pass (code in §5) and you are done — body images become hashed `/_assets/` files like everything else.
- **If it does not:** add `bodyHtml: z.string()` to the blog schema, have the loader store the sanitized HTML in `data.bodyHtml` as well as `rendered.html`, and in `[slug].astro` do `const html = await optimizeBodyImages(data.bodyHtml)` and render `<article class="prose" set:html={html} />`, keeping `render(post)` **only** for `headings`. Slightly more duplication in the data store (irrelevant at this scale), fully typed, guaranteed to work.

**B. Prose CSS.** `src/styles/pages/blog-post.css` targets bare semantic tags. WordPress block output adds `<div class="wp-block-*">` wrappers, `<figure>`/`<figcaption>`, and `alignwide`/`alignfull`. Critically, **L227 `.prose > * + *` sets all vertical rhythm via direct-child spacing** — any WP wrapper div collapses the article's spacing entirely. Add rules for `.prose .wp-block-image`, `.prose figure`, `.prose figcaption`, and either strip the wrappers in the loader's sanitize pass or add them to the `> * + *` selector chain. The stylesheet already has rules for tables and blockquotes that the markdown corpus never produced — those now become reachable.

**Verification:** `grep -c 'cms.vividsmilesdentistry.com' dist/blog/*/index.html` → `0`. Lighthouse on `/blog/how-cosmetic-dentistry-can-boost-your-confidence/` (6 body images) — CLS ≤ 0.01, no "properly size images" opportunity. Visual diff three posts against the baseline screenshots.

---

### Phase 7 — Editorial workflow: publish → rebuild → live

**Goal:** The office manager clicks Publish and the site updates, with visible status and real failure alerting.

**Repo changes:** none beyond `vercel.json`.

**WordPress-side:** `wp/mu-plugins/vivid-deploy.php` (see §7).

> **Not built, and one instruction below is now wrong.** No deploy-hook code exists in `cms/mu-plugins/`; publishing in WordPress does not yet trigger a rebuild. Deploys currently happen on a push to `main` via the Vercel Git integration. When this does get built, **`wp-config.php` is the wrong place for the hook URL**: GoDaddy's managed platform rewrites `wp-config.php` during platform updates and drops hand-added lines silently, which is exactly the failure that produced `cms/mu-plugins/vs-config.php`. Put the constant in a mu-plugin alongside `VS_FRONTEND_URL` instead. The rest of this phase — the `transition_post_status` hook, the debounce, the WP-Cron trap, the status pill, the three dead plugins — is unaffected and still applies.

**Vercel-side:**
- Create a Deploy Hook on `main`. Store the URL in `wp-config.php` as `VS_VERCEL_DEPLOY_HOOK` (**not** in the database — it's a write credential). *(See the note above: use a mu-plugin, not `wp-config.php`.)*
- **Upgrade to Vercel Pro ($20/mo).** Hobby gives 1 concurrent build; the entire editorial promise ("about two minutes") depends on builds starting promptly, and this is a revenue-generating asset.
- Configure a `deployment.error` webhook → Slack **and** back to WP. **The default "email the deployment creator" does not reliably reach a human when the build was fired by a hook.** Deliberately break a build once and confirm the alert lands.

**Verification:** publish a test post; the admin notice appears; the admin-bar pill goes Queued → Building → Live; total wall-clock is 2–4 minutes. Then set the WP app password to garbage and publish again — the build must **fail** (floor assertion + auth error), the previous deployment must stay live, Slack must fire, and wp-admin must show "Your last update didn't publish."

---

### Phase 8 (optional) — Draft preview

**Goal:** WordPress's Preview button renders unpublished content.

**Cost, stated plainly:** this requires `@astrojs/vercel` and one `prerender = false` route. That changes build output from a portable `dist/` to `.vercel/output/`, which invalidates `DEPLOYMENT.md:3` and `:17`'s "no backend / host anywhere / no host-specific config" claims. Every other route stays static.

**Repo changes:** `astro.config.mjs` adds `adapter: vercel()`; new `src/pages/preview/index.astro` with `export const prerender = false`, HMAC verification, and an authenticated WPGraphQL/REST fetch; `/preview/` added to the sitemap `excludeExact` array; `X-Robots-Tag: noindex` on the route.

**Honest alternative:** skip it. A dental practice publishing ~2 posts/month can accept "publish, wait two minutes, look at the live site." Say this out loud during handoff; a meaningful fraction of clients will take it and save you the adapter.

**Do not** use Vercel preview deployments for editorial preview — they sit behind Vercel Authentication by default (the office manager has no Vercel account and shouldn't), Shareable Links are capped at 1 concurrent on Hobby, and you'd spend a full build per draft.

---

### Phase 9 (deferred) — Scope expansion

> **Items 1 and 2 were done, not deferred.** Do not treat them as open work.
>
> - **Item 1 (contact + hours → Options page): done.** The options page is `Practice Settings`, registered in `cms/mu-plugins/vs-settings.php` and read through `src/lib/settings.ts`. `src/data/contact.ts` and `src/data/hours.ts` survive as thin adapters over it, with their export surface deliberately unchanged so the **38** importing files needed no edits. The atoms-only rule below was followed exactly: WordPress stores days, `opens` and `closes`; every display string and `openingHoursSpecification` is still derived in TypeScript. **What was not done:** the JSON-LD address literals. `streetAddress: '17167 Cedar Gulch Pkwy Ste 102'` is still hardcoded in the JSON-LD blocks on the pages listed below, and `LocalTrust.astro:113` still hardcodes `300+`.
> - **Item 2 (navigation → WP menus): done.** `cms/mu-plugins/vs-menus.php` registers the `primary` and `footer` locations plus the per-item appearance fields; `src/lib/menus.ts` flattens them. All six components — `Nav`, `MobileMenu`, the three mega panels and `Footer` — were migrated in one pass, as this item demanded.
> - **Item 3 (service page copy): done, and beyond what is described here.** 31 routes of structured copy, 213 sections, 187 cards and 200 image slots now live in WordPress. See §4's note.

Only after 60 days of stable operation. In priority order:
1. **`src/data/contact.ts` + `src/data/hours.ts` → one ACF Options page.** Highest ROI on the site: 12 constants consumed by `Nav:28`, `MobileMenu:26`, `Footer:6-17`, `LandingLayout:42-48`, `LocalTrust:12-20`, `FinalBand:21` and 20 of 35 pages. Store only the atoms (street/city/state/zip, phone_e164, opens/closes/days); keep `addressLine`, `emailHref`, `hoursLong`, `hoursShort`, `shortDays`, `openingHoursSpecification` and `isOpenNow()` as **derived code** — modelling the display strings as editable fields guarantees the footer pill and the JSON-LD disagree. Same pass fixes the 10 places that hardcode the practice address as string literals in JSON-LD (`porcelain-veneers:631-637`, `clear-aligners:658-664`, `teeth-whitening:802-808`, `all-on-4:840-846`, `bone-grafting:695-701`, `full-mouth-dental-implants:779-785`, `single-tooth:678-684`, `sinus-lift:651-657`, `about-us:700-704`, `dental-membership-plan:356`) and the hardcoded `300+` review count at `LocalTrust.astro:113-114`.
2. **Navigation → WP menus.** The link tree is maintained in **four** places today (`Nav.astro:76-82`, the three Mega components, `MobileMenu.astro:67-125`, `Footer.astro:50-69`) with five separate docblocks warning that parity is manual. **Migrate all four in one pass or none** — moving desktop megas to WP while leaving MobileMenu hardcoded makes drift strictly worse, because the WP side will change without anyone opening the repo. Prerequisite: unify the icon systems (desktop megas use FontAwesome class strings; `MobileMenu.astro:51-58` uses four raw inline SVG constants).
3. Service page copy (see Fork 3).

---

## 4. WordPress content model

> **Superseded as a specification; still useful as reasoning.** The shipped
> content model is declared in code under `cms/mu-plugins/` and is larger than
> what is described here. The differences that matter if you go looking for
> these objects:
>
> | This section says | What exists |
> |---|---|
> | `review` CPT, `public: false` | `vs_testimonial`, **`public: true`** — WPGraphQL gates public visibility on that flag and the build queries anonymously, so `public: false` makes the testimonials query return an empty list rather than an error. `rewrite` is off and `vs-headless.php` redirects front-end requests, so nothing is actually exposed. |
> | `review_tag` taxonomy | `vs_testimonial_tag`, `public: true` for the same reason |
> | Category matched by **slug** via a `categoryMap` | Matched by **name**. The five names are a public contract duplicated across `content.config.ts`, `src/loaders/blog.ts`, `src/lib/blog.ts` and `vs-content-model.php`, and they appear verbatim in shared `/blog/?category=<name>` URLs. |
> | Two ACF field groups (`Blog Post Extras`, `Review Details`) | Five groups — testimonial, post, page, menu item and practice settings — declared with `acf_add_local_field_group()`, so **editing them in the SCF/ACF admin UI does not persist** |
> | ACF Local JSON in `wp/acf-json/` | No `acf-json` directory; the model is PHP in `cms/mu-plugins/` |
> | `byline` ACF field | `author_name`, alongside a required `hero_alt` |
>
> The reasoning below — why the slug is the URL contract, why `menu_order` is
> the deterministic tie-break, why the category enum is closed, why display
> strings must stay derived — all still holds and is why the shipped model looks
> the way it does.

### 4.1 Post types

| WP object | Purpose | Registration |
|---|---|---|
| `post` (core) | The 14 blog posts | core |
| `review` (CPT) | The 20 reviews | `public: false`, `show_ui: true`, `show_in_rest: true`, `rest_base: 'review'`, `supports: ['title','editor','custom-fields','page-attributes']` |

`review` is `public: false` — it must never have a front-end URL. `page-attributes` is what exposes `menu_order`, which is the deterministic tie-break for the review sort.

### 4.2 Taxonomies

| Taxonomy | Attached to | Hierarchical | Notes |
|---|---|---|---|
| `category` (core) | `post` | yes | **Exactly 5 terms, fixed slugs.** Slug is the loader's map key; the term *name* is irrelevant to the build. |
| `review_tag` (custom) | `review` | no | `show_in_rest: true`. 31 distinct Title Case free-form strings. Term **name** is what renders. |

**The 5 category terms — slug → Astro enum value (this mapping is a hard contract):**

| WP slug | Astro `data.category` | posts |
|---|---|---|
| `dental-tips` | `"Dental Tips"` | 4 |
| `cosmetic-dentistry` | `"Cosmetic Dentistry"` | 4 |
| `implant-dentistry` | `"Implant Dentistry"` | 6 |
| `general-dentistry` | `"General Dentistry"` | 0 |
| `emergency-dentistry` | `"Emergency Dentistry"` | 0 |

`content.config.ts:78-84` is a **closed `z.enum`** and `src/lib/blog.ts:41-47` hardcodes the same five strings as display order. Adding a sixth category requires editing **both** files in the same commit. Until then, an unmapped WP term is a build failure — which is correct, but means an editor creating a category in wp-admin can break production. The loader's `defaultCategory` option softens this (see §5): unmapped → `"Dental Tips"` + a loud `logger.warn`. **Decide with the client whether unmapped should warn-and-default (site stays up, content is miscategorized) or throw (site stays on the last good build, editor is blocked).** Default in the code below is warn-and-default.

### 4.3 ACF field group: `Blog Post Extras`

Location: Post Type is equal to Post. **Show in REST API: Yes.**

| Field label | Field name | Type | Required | Default | Maps to |
|---|---|---|---|---|---|
| Byline | `byline` | Text | no | *(empty)* | `data.author`, falling back to WP author display name, falling back to `"Slate"` |

That is the whole group. Everything else on a blog post comes from core WordPress fields. Resist adding more.

> **Note on `author`:** `blog/[slug].astro:160-163` types the JSON-LD author as `{"@type": "Organization"}`. Today `author` is always the string `"Slate"`, which is arguably an organization. **The moment a real human name goes in that field, the structured data misrepresents a person as an organization.** Either keep `byline` as a brand name, or change L160 to `Person`. Flag this to the client (§9).

### 4.4 ACF field group: `Review Details`

Location: Post Type is equal to Review. **Show in REST API: Yes.**

| Field label | Field name | Type | Required | Config | Maps to |
|---|---|---|---|---|---|
| Rating | `rating` | Number | **yes** | min 1, max 5, step 1, default 5 | `data.rating` |
| Source | `source` | Select | **yes** | choices `Google : Google`, `Yelp : Yelp`, `Facebook : Facebook`, `Healthgrades : Healthgrades`; return **Value**; default `Google` | `data.source` |

`reviewer` is the WP **post title** (not an ACF field) so the admin list table is readable. `date` is `post_date_gmt`. `tags` is the `review_tag` taxonomy. `order` is `menu_order`.

### 4.5 Field-by-field contract — `blog`

Every row is load-bearing. Consumer citations are from the audit.

| Astro field | Type | WP source | Loader transform | Consumers |
|---|---|---|---|---|
| `id` | string | `post.slug` | none — **must equal the existing filename stem for all 14 posts** | `[slug].astro:31` getStaticPaths param; `lib/blog.ts:126` `postUrl()`; identity key at `lib/blog.ts:61` (related) and `:79` (prev/next); `blog/index.astro:145-162` JSON-LD URL |
| `data.title` | string | `title.rendered` | strip tags, decode entities | `[slug]:55` pageTitle, `:73` breadcrumb, `:85` H1, `:89`/`:132` share, `:153` JSON-LD headline, `:183` breadcrumb LD; `BlogCard:25,42`; `RelatedPosts:40,46` |
| `data.description` | string, **max 200** | Yoast `yoast_head_json.description` ?? `excerpt.rendered` | strip tags, decode entities, **truncate on a word boundary at 200** | `[slug]:60,156`; `BlogCard:44` (CSS-clamped to 3 lines at `:156-161`) |
| `data.date` | **Date** | `date_gmt` | **append `Z`** — WP `*_gmt` is ISO-8601 *without* a zone suffix, so `new Date()` parses it as local time and a UTC-7 build machine shifts every post by 7h | `lib/blog.ts:29` numeric sort; `.toISOString()` in **five** places (`[slug]:158`, `BaseLayout:86`, `BlogCard:39`, `blog/index.astro:151`); `formatDate()` `timeZone:'UTC'` |
| `data.updated` | Date, optional | `modified_gmt` | **emit only when `modified_gmt !== date_gmt`**, else `undefined` | `[slug]:159` `(data.updated ?? data.date).toISOString()` — its only use anywhere |
| `data.author` | string, default `"Slate"` | ACF `byline` ?? `_embedded.author[0].name` ?? `"Slate"` | none | `[slug]:63,87,162` |
| `data.category` | **closed enum, 5 values** | `_embedded['wp:term']` where `taxonomy === 'category'`, by **slug** | map via `categoryMap`; unmapped → `defaultCategory` + warn | `lib/blog.ts:39` (getCategories), `:62` (related); `[slug]:83,173`; **`BlogCard:24` `data-category` — the exact string `blog-filter.ts:29` compares against** |
| `data.heroImage` | **`z.string().url()`** (was `image()`) | `_embedded['wp:featuredmedia'][0].source_url` | none | `[slug]:51` `getImage()`, `:94` `<Image>`; `BlogCard:27` `<Image>` |
| `data.heroWidth` | int, optional | `media_details.width` | **NEW field.** Required by `<Image>` for remote src | `[slug]:94`, `BlogCard:27` |
| `data.heroHeight` | int, optional | `media_details.height` | **NEW field** | ditto |
| `data.heroAlt` | string, **required, no default** | `alt_text` | **`.trim() \|\| title`** — WP featured-image alt is empty on most posts and every such post is a build failure without this fallback | `[slug]:95`; `BlogCard:28` |
| `data.draft` | boolean, default false | `status !== 'publish'` | unauthenticated REST only returns `publish`, so this is always `false` unless you authenticate with `status=publish,draft&context=edit` | `lib/blog.ts:28`; `[slug]` getStaticPaths |
| `body` | string | derived | **plain text of the sanitized HTML** | `[slug]:42,87`; `BlogByline:21` `readingTime()` |
| `rendered.html` | string | `content.rendered` | sanitize (drop `script/style/iframe/object/embed/form/input`), assign heading `id`s, add `loading="lazy" decoding="async"` to `<img>`, rewrite CMS-absolute `<a href>` to site-relative | `<Content />` at `[slug]:129` — **note `<Content />` is `unescapeHTML(html)`, i.e. zero sanitization; the loader is the only sanitizer** |
| `rendered.metadata.headings` | `{depth,slug,text}[]` | derived, **same pass as the id assignment** | github-slugger, honouring any existing Gutenberg anchor | `render(post)` at `[slug]:43`; filtered to depth 2\|3 at `:47`; rendered at `:111-113`; **`toc-spy.ts:22-24` resolves `#${slug}` against the DOM** |

### 4.6 Field-by-field contract — `reviews`

| Astro field | Type | WP source | Loader transform | Consumers |
|---|---|---|---|---|
| `id` | string | `post.slug` | none (nothing references review ids, but keep it stable) | — |
| `data.reviewer` | string, required | `title.rendered` | strip tags, decode entities | `ReviewMarquee:35,48`; `ReviewCard:21` |
| `data.rating` | int 1–5, required | ACF `rating` | `Number()` | `ReviewCard:31` `'★'.repeat(clamp(round(r),0,5))` |
| `data.source` | string, required | ACF `source` | none (`ReviewCard:28` defaults to `"Google"` only when the **prop** is omitted, not when it's empty) | `ReviewCard` |
| `data.date` | **Date** | `date_gmt` | append `Z` | sorted in 7 places; `ReviewCard:34` `new Date(date)` then `en-US {year,month:'short',day, timeZone:'UTC'}` — **note this is a different format from blog's `month:'long'`** |
| `data.tags` | `string[]`, default `[]` | `_embedded['wp:term']` where `taxonomy === 'review_tag'`, term **name** | none | rendered as chips at `ReviewCard:63`; **regex-matched for curation on 3 LPs** |
| `data.order` | int, default 0 | `menu_order` | **NEW field.** Seeded from the `NN-` filename prefix | `lib/reviews.ts` tie-break |
| `body` | string | `content.rendered` | **strip tags + decode entities → plain text.** `ReviewCard:58` renders it as an escaped slot child inside a single `<p>`; any HTML displays as literal `<p>` markup on screen, and multi-paragraph content collapses with no breaks. It is also regex-matched against `r.body` at `veneers-lp:93-97`, `cosmetic-dentistry-lp:96-100`, `general-lp:81-84` — HTML in the body would put tag and class names into the match surface and silently flip which reviews get promoted. | `ReviewMarquee:40,53`; `ReviewCard:58`; the three LP regexes |

---

## 5. Code

> **Superseded — this code was not shipped. Do not copy it into the repo.**
> `src/loaders/wordpress.ts` does not exist. The build uses three WPGraphQL
> loaders — `src/loaders/blog.ts`, `src/loaders/pages.ts`,
> `src/loaders/reviews.ts` — over a shared client at `src/lib/wp.ts`. Read those
> files, not this listing.
>
> Several ideas below did survive intact and are worth recognising in the real
> code: appending `Z` to zoneless WordPress timestamps, sanitizing body HTML
> through `ultrahtml` as the only barrier between wp-admin and production,
> minting heading ids with `github-slugger` in the same pass that collects the
> `headings` array, and refusing to write a store that came back empty. Two
> ideas did not: the loaders call `store.clear()` and do a full replace on every
> build rather than diffing a persisted `meta` index, and `ultrahtml` /
> `github-slugger` were **never promoted to explicit `dependencies`** — they
> still resolve only as hoisted transitive dependencies of `astro`, which is the
> exact fragility §5.4 warns about.

### 5.1 `src/loaders/wordpress.ts` (new)

```ts
/**
 * WordPress REST loaders for the Astro Content Layer.
 *
 * Design notes that matter:
 *  - We do NOT call store.clear(). Clearing makes generateDigest() decorative,
 *    because store.set() only short-circuits when an entry with a matching
 *    digest already exists. The docs' own feed-loader example clears; don't
 *    copy it.
 *  - Incremental sync is driven by a cheap index sweep (~40 bytes/post)
 *    diffed against a JSON index persisted in `meta`. Full payloads are
 *    fetched only for slugs whose modified_gmt moved.
 *  - The `meta` store lives in .astro/data-store.json (dev) and
 *    node_modules/.astro/data-store.json (build). Vercel restores
 *    node_modules/** on a cache hit, so this usually survives between
 *    deploys. A cache miss degrades to a full sync — correct, just slow.
 *  - <Content /> is unescapeHTML(rendered.html). This file is the ONLY
 *    sanitizer between wp-admin and production HTML.
 */
import type { Loader, LoaderContext } from "astro/loaders";
import { z } from "astro/zod";
import { decodeHTML } from "entities/decode";
import GithubSlugger from "github-slugger";
import {
  ELEMENT_NODE,
  TEXT_NODE,
  parse,
  renderSync,
  walkSync,
  type Node,
} from "ultrahtml";
import sanitize from "ultrahtml/transformers/sanitize";

/* ───────────────────────────── shared schemas ──────────────────────────── */

export const BLOG_CATEGORIES = [
  "Dental Tips",
  "Cosmetic Dentistry",
  "Implant Dentistry",
  "General Dentistry",
  "Emergency Dentistry",
] as const;
export type BlogCategory = (typeof BLOG_CATEGORIES)[number];

/**
 * Mirrors the file-based blog schema with three deliberate differences:
 *  - heroImage is a remote URL string, not image(). The image() helper
 *    resolves through Vite against the entry's filePath; a remote loader
 *    has no filePath (see astro/dist/content/runtime-assets.js), so
 *    image() silently degrades to a plain string while TypeScript still
 *    reports ImageMetadata. `astro check` will NOT catch that.
 *  - heroWidth / heroHeight are carried because <Image> throws
 *    MissingImageDimension for a remote src without both.
 *  - Everything else is byte-identical, so src/lib/blog.ts needs no changes.
 */
export const wpBlogSchema = z.object({
  title: z.string(),
  description: z.string().max(200),
  date: z.coerce.date(),
  updated: z.coerce.date().optional(),
  author: z.string().default("Slate"),
  category: z.enum(BLOG_CATEGORIES),
  heroImage: z.string().url(),
  heroWidth: z.number().int().positive().optional(),
  heroHeight: z.number().int().positive().optional(),
  heroAlt: z.string(),
  draft: z.boolean().default(false),
});

export const wpReviewSchema = z.object({
  reviewer: z.string(),
  rating: z.number().int().min(1).max(5),
  source: z.string(),
  date: z.coerce.date(),
  tags: z.array(z.string()).default([]),
  order: z.number().int().default(0),
});

/* ───────────────────────────── WP payload types ────────────────────────── */

interface WpTerm { taxonomy?: string; name?: string; slug?: string }
interface WpMedia {
  code?: string;                       // present when REST returns an error object
  source_url?: string;
  alt_text?: string;
  media_details?: { width?: number; height?: number };
}
interface WpEntity {
  id: number;
  slug: string;
  status: string;
  menu_order?: number;
  date_gmt: string;
  modified_gmt: string;
  title: { rendered: string };
  content: { rendered: string; protected?: boolean };
  excerpt?: { rendered: string };
  acf?: Record<string, unknown>;
  yoast_head_json?: { description?: string };
  _embedded?: {
    author?: Array<{ name?: string }>;
    "wp:featuredmedia"?: WpMedia[];
    "wp:term"?: WpTerm[][];
  };
}
interface WpIndexRow { id: number; slug: string; modified_gmt: string }

/* ─────────────────────────────── helpers ───────────────────────────────── */

/** WP `*_gmt` fields are ISO-8601 WITHOUT a zone suffix. Without the Z,
 *  new Date() parses them as LOCAL time and a UTC-7 builder shifts every
 *  post back 7 hours, flipping the displayed day for evening publishes. */
function gmtToIso(v: string): string {
  return v.endsWith("Z") ? v : `${v}Z`;
}

/** WP renders entities into titles and excerpts ("Don&#8217;t", "&amp;",
 *  "&hellip;"). Ship those to <title> and you ship literal &#8217; to Google. */
function toPlainText(html: string): string {
  return decodeHTML(html.replace(/<[^>]*>/g, " ")).replace(/\s+/g, " ").trim();
}

/** description is z.string().max(200); parseData() THROWS on overflow and
 *  aborts the build. WP excerpts routinely exceed it. */
function truncate(text: string, max: number): string {
  if (text.length <= max) return text;
  const cut = text.slice(0, max - 1);
  const sp = cut.lastIndexOf(" ");
  return `${(sp > max * 0.6 ? cut.slice(0, sp) : cut).trimEnd()}…`;
}

export interface ProcessedContent {
  html: string;
  headings: Array<{ depth: number; slug: string; text: string }>;
}

/**
 * Sanitize WP content.rendered, assign a stable id to every heading, and
 * collect the headings array render() will hand back.
 *
 * WordPress emits NO heading ids and Astro's rehype-slug never runs on
 * loader-supplied HTML — without this, [slug].astro:112 renders anchors
 * that resolve to nothing and toc-spy.ts:22-24 silently dies with no
 * console error and no build error.
 *
 * github-slugger is the same slugger Astro's markdown pipeline uses, so
 * anchors stay consistent with the pre-migration file-based posts.
 */
export function processWpContent(rawHtml: string, cmsOrigin?: string): ProcessedContent {
  const slugger = new GithubSlugger();
  const headings: ProcessedContent["headings"] = [];

  const doc = sanitize({
    dropElements: ["script", "style", "iframe", "object", "embed", "form", "input"],
    allowComments: false,
    allowCustomElements: false,
  })(parse(rawHtml) as Node);

  walkSync(doc, (node) => {
    if (node.type !== ELEMENT_NODE) return;

    if (/^h[1-6]$/.test(node.name)) {
      let text = "";
      walkSync(node, (child) => { if (child.type === TEXT_NODE) text += child.value; });
      text = decodeHTML(text).replace(/\s+/g, " ").trim();
      const existing = node.attributes.id;                  // honour Gutenberg anchors
      const slug = slugger.slug(existing || text);
      node.attributes.id = slug;
      headings.push({ depth: Number(node.name[1]), slug, text });
      return;
    }

    if (node.name === "img") {
      node.attributes.loading ??= "lazy";
      node.attributes.decoding ??= "async";
      // WP's own srcset points back at wp-content; strip it so Phase 6
      // optimization is the only source of responsive candidates.
      delete node.attributes.srcset;
      delete node.attributes.sizes;
    }

    if (node.name === "a" && node.attributes.href) {
      const href = node.attributes.href;
      if (cmsOrigin && href.startsWith(cmsOrigin)) {
        node.attributes.href = href.slice(cmsOrigin.length) || "/";
      } else if (/^https?:\/\//.test(href)) {
        node.attributes.rel ??= "noopener";
      }
    }
  });

  return { html: renderSync(doc), headings };
}

/* ─────────────────────────── transport plumbing ────────────────────────── */

interface BaseOptions {
  /** WP origin, no trailing path. e.g. https://cms.vividsmilesdentistry.com */
  endpoint: string;
  /** Application Password for the dedicated `astro-build` Editor user. */
  auth?: { username: string; password: string };
  /** Refuse to write the store if fewer than this many entries came back.
   *  A build that SUCCEEDS with an empty site is far worse than one that fails. */
  minEntries: number;
  /** Ignore the persisted index and refetch everything. */
  fullSync?: boolean;
  perPage?: number;
}

function makeTransport(o: BaseOptions, restBase: string) {
  const origin = o.endpoint.replace(/\/+$/, "");
  const api = `${origin}/wp-json/wp/v2/${restBase}`;
  const perPage = Math.min(o.perPage ?? 100, 100);   // WP hard-caps per_page at 100

  const headers: Record<string, string> = { Accept: "application/json" };
  if (o.auth) {
    headers.Authorization =
      "Basic " + Buffer.from(`${o.auth.username}:${o.auth.password}`).toString("base64");
  }
  // Cloudflare Access service token, so /wp-json stays reachable while
  // /wp-admin is behind Zero Trust.
  const cfId = process.env.CF_ACCESS_CLIENT_ID;
  const cfSecret = process.env.CF_ACCESS_CLIENT_SECRET;
  if (cfId && cfSecret) {
    headers["CF-Access-Client-Id"] = cfId;
    headers["CF-Access-Client-Secret"] = cfSecret;
  }

  async function page<T>(params: URLSearchParams) {
    const res = await fetch(`${api}?${params}`, { headers });
    if (!res.ok) {
      throw new Error(
        `WordPress ${res.status} ${res.statusText} for ${api}?${params}. ` +
        `Refusing to build — the previous deployment stays live.`,
      );
    }
    return {
      rows: (await res.json()) as T[],
      totalPages: Number(res.headers.get("X-WP-TotalPages") ?? "1") || 1,
    };
  }

  async function all<T>(build: (p: number) => URLSearchParams) {
    const out: T[] = [];
    let p = 1, total = 1;
    do {
      const { rows, totalPages } = await page<T>(build(p));
      out.push(...rows);
      total = totalPages;
      p += 1;
    } while (p <= total);
    return out;
  }

  return { origin, perPage, page, all };
}

/** Index sweep + diff. Returns the ids that actually need a full fetch. */
async function syncIndex(
  t: ReturnType<typeof makeTransport>,
  ctx: LoaderContext,
  fullSync: boolean,
) {
  const index = await t.all<WpIndexRow>((p) =>
    new URLSearchParams({
      _fields: "id,slug,modified_gmt",
      status: "publish",
      per_page: String(t.perPage),
      page: String(p),
      orderby: "id",
      order: "asc",
    }),
  );

  const previous: Record<string, string> = fullSync
    ? {}
    : JSON.parse(ctx.meta.get("index") ?? "{}");
  const current: Record<string, string> = {};
  const stale: number[] = [];

  for (const row of index) {
    current[row.slug] = row.modified_gmt;
    if (previous[row.slug] !== row.modified_gmt || !ctx.store.has(row.slug)) {
      stale.push(row.id);
    }
  }

  // WP REST has no "deleted since" feed. The sweep is how deletions and slug
  // changes are detected: anything in the store that's gone from the index
  // is dropped. A slug change reads as delete + add, which is correct — and
  // a reminder that WP must emit a redirect for the old URL.
  let removed = 0;
  for (const id of ctx.store.keys()) {
    if (!(id in current)) { ctx.store.delete(id); removed += 1; }
  }

  return { index, current, stale, removed };
}

/* ──────────────────────────── blog posts loader ────────────────────────── */

export interface PostsLoaderOptions extends BaseOptions {
  /** WP category SLUG -> Astro enum value. */
  categoryMap: Record<string, BlogCategory>;
  /** Used when a WP term isn't in categoryMap. Omit to hard-fail instead. */
  defaultCategory?: BlogCategory;
  defaultAuthor?: string;
}

export function wordpressPostsLoader(o: PostsLoaderOptions): Loader {
  const t = makeTransport(o, "posts");

  return {
    name: "wordpress-posts",
    schema: wpBlogSchema,

    async load(ctx: LoaderContext) {
      const { store, meta, logger, parseData, generateDigest } = ctx;
      const { index, current, stale, removed } = await syncIndex(t, ctx, !!o.fullSync);

      if (index.length < o.minEntries) {
        throw new Error(
          `WordPress returned ${index.length} published posts, expected at least ` +
          `${o.minEntries}. Refusing to deploy a site with missing pages. ` +
          `Check WPGraphQL/REST availability and the astro-build credentials.`,
        );
      }

      if (stale.length === 0) {
        logger.info(`WP posts: ${index.length} posts, no changes (${removed} removed)`);
        meta.set("index", JSON.stringify(current));
        return;
      }
      logger.info(`WP posts: fetching ${stale.length} changed of ${index.length}`);

      const posts: WpEntity[] = [];
      for (let i = 0; i < stale.length; i += t.perPage) {
        const chunk = stale.slice(i, i + t.perPage);
        const { rows } = await t.page<WpEntity>(
          new URLSearchParams({
            include: chunk.join(","),
            per_page: String(chunk.length),
            _embed: "author,wp:featuredmedia,wp:term",
            status: "publish",
          }),
        );
        posts.push(...rows);
      }

      let written = 0;
      for (const post of posts) {
        if (post.content.protected) {
          logger.warn(`WP posts: skipping password-protected "${post.slug}"`);
          continue;
        }

        // _embedded media can come back as a REST ERROR OBJECT
        // ({ code: 'rest_forbidden' }) rather than a media record. TypeScript
        // won't save you — this is `any` off res.json().
        const media = post._embedded?.["wp:featuredmedia"]?.[0];
        if (!media || media.code || !media.source_url) {
          throw new Error(
            `WP posts: "${post.slug}" has no usable featured image. ` +
            `Every post needs one — heroImage is required and drives og:image.`,
          );
        }

        const terms = post._embedded?.["wp:term"]?.flat() ?? [];
        const wpCat = terms.find((t) => t.taxonomy === "category");
        const mapped = wpCat?.slug ? o.categoryMap[wpCat.slug] : undefined;
        if (!mapped) {
          if (!o.defaultCategory) {
            throw new Error(
              `WP posts: "${post.slug}" has category "${wpCat?.slug ?? "none"}", ` +
              `which is not in categoryMap. Add it to BOTH src/loaders/wordpress.ts ` +
              `and the display order in src/lib/blog.ts:41-47.`,
            );
          }
          logger.warn(
            `WP posts: "${post.slug}" category "${wpCat?.slug ?? "none"}" unmapped ` +
            `-> defaulting to "${o.defaultCategory}"`,
          );
        }
        const category = mapped ?? o.defaultCategory!;

        const title = toPlainText(post.title.rendered);
        const description = truncate(
          post.yoast_head_json?.description?.trim() ||
            toPlainText(post.excerpt?.rendered ?? ""),
          200,
        );

        const { html, headings } = processWpContent(post.content.rendered, t.origin);

        const published = gmtToIso(post.date_gmt);
        const modified = gmtToIso(post.modified_gmt);

        const raw = {
          title,
          description,
          date: published,
          // Emit `updated` ONLY when it genuinely differs, or every post
          // starts showing a dateModified it never had.
          updated: modified !== published ? modified : undefined,
          author:
            (typeof post.acf?.byline === "string" && post.acf.byline.trim()) ||
            post._embedded?.author?.[0]?.name ||
            o.defaultAuthor ||
            "Slate",
          category,
          heroImage: media.source_url,
          heroWidth: media.media_details?.width,
          heroHeight: media.media_details?.height,
          // heroAlt is required with NO default in the schema. WP featured-image
          // alt is empty on most posts; without this fallback every one of them
          // is a build failure.
          heroAlt: media.alt_text?.trim() || title,
          draft: post.status !== "publish",
        };

        // Digest covers the processed HTML too, so changing this loader's
        // transforms invalidates every entry — not just posts WP touched.
        const digest = generateDigest(JSON.stringify({ raw, html }));
        const data = await parseData({ id: post.slug, data: raw });

        const wrote = store.set({
          id: post.slug,                       // <- IS the public URL slug
          data,
          // readingTime() (src/lib/blog.ts:93) counts words in `body`.
          // WP gives HTML, not markdown source, so hand it plain text.
          body: toPlainText(html),
          rendered: { html, metadata: { headings } },
          digest,
        });
        if (wrote) written += 1;
      }

      meta.set("index", JSON.stringify(current));
      logger.info(`WP posts: ${written} written, ${removed} removed`);
    },
  };
}

/* ────────────────────────────── reviews loader ─────────────────────────── */

export function wordpressReviewsLoader(o: BaseOptions): Loader {
  const t = makeTransport(o, "review");

  return {
    name: "wordpress-reviews",
    schema: wpReviewSchema,

    async load(ctx: LoaderContext) {
      const { store, meta, logger, parseData, generateDigest } = ctx;
      const { index, current, stale, removed } = await syncIndex(t, ctx, !!o.fullSync);

      if (index.length < o.minEntries) {
        throw new Error(
          `WordPress returned ${index.length} reviews, expected at least ${o.minEntries}.`,
        );
      }
      if (stale.length === 0) {
        logger.info(`WP reviews: ${index.length} reviews, no changes (${removed} removed)`);
        meta.set("index", JSON.stringify(current));
        return;
      }

      const rows: WpEntity[] = [];
      for (let i = 0; i < stale.length; i += t.perPage) {
        const chunk = stale.slice(i, i + t.perPage);
        const { rows: r } = await t.page<WpEntity>(
          new URLSearchParams({
            include: chunk.join(","),
            per_page: String(chunk.length),
            _embed: "wp:term",
            status: "publish",
          }),
        );
        rows.push(...r);
      }

      let written = 0;
      for (const r of rows) {
        const tags = (r._embedded?.["wp:term"]?.flat() ?? [])
          .filter((t) => t.taxonomy === "review_tag")
          .map((t) => t.name!)
          .filter(Boolean);

        const raw = {
          reviewer: toPlainText(r.title.rendered),
          rating: Number(r.acf?.rating ?? 5),
          source: String(r.acf?.source ?? "Google"),
          date: gmtToIso(r.date_gmt),
          tags,
          // menu_order is the deterministic tie-break. Six reviews share
          // 2025-04-27 and three share 2025-12-27; without an explicit
          // secondary key the marquee reshuffles between builds.
          order: Number(r.menu_order ?? 0),
        };

        // ReviewCard:58 renders body as an ESCAPED plain-text slot child
        // inside a single <p>. Any HTML would display as literal markup,
        // and the three LP curation regexes match against this string.
        const body = toPlainText(r.content.rendered);

        const digest = generateDigest(JSON.stringify({ raw, body }));
        const data = await parseData({ id: r.slug, data: raw });
        if (store.set({ id: r.slug, data, body, digest })) written += 1;
      }

      meta.set("index", JSON.stringify(current));
      logger.info(`WP reviews: ${written} written, ${removed} removed`);
    },
  };
}
```

### 5.2 `src/content.config.ts` (replacement)

```ts
/**
 * Content collections, sourced from headless WordPress at build time.
 *
 * Content is authored at cms.vividsmilesdentistry.com. The markdown that
 * used to live in src/content/ is archived at content-archive/ — do not
 * edit it; it is a rollback artefact only.
 *
 * The Zod schemas below are no longer file validation. They are API-contract
 * validation: a bad category, an over-long excerpt, or a missing alt text
 * fails the build LOUDLY rather than silently rendering a broken page.
 * Keep them strict.
 */
import { defineCollection } from "astro:content";
import {
  wordpressPostsLoader,
  wordpressReviewsLoader,
  wpBlogSchema,
  wpReviewSchema,
} from "./loaders/wordpress";

const WP_ENDPOINT =
  import.meta.env.WP_ENDPOINT ??
  process.env.WP_ENDPOINT ??
  "http://localhost:8888";

const auth =
  (import.meta.env.WP_APP_USER ?? process.env.WP_APP_USER)
    ? {
        username: (import.meta.env.WP_APP_USER ?? process.env.WP_APP_USER)!,
        password: (import.meta.env.WP_APP_PASSWORD ?? process.env.WP_APP_PASSWORD)!,
      }
    : undefined;

const reviews = defineCollection({
  loader: wordpressReviewsLoader({
    endpoint: WP_ENDPOINT,
    auth,
    // 20 reviews today. Floor guards against a partial fetch shipping an
    // empty marquee. Raise as the set grows.
    minEntries: 18,
  }),
  schema: wpReviewSchema,
});

const blog = defineCollection({
  loader: wordpressPostsLoader({
    endpoint: WP_ENDPOINT,
    auth,
    minEntries: 12,          // 14 posts today
    // WP category SLUG -> the closed enum in wpBlogSchema. Adding a value
    // here ALSO requires editing the display order at src/lib/blog.ts:41-47
    // and BLOG_CATEGORIES in src/loaders/wordpress.ts.
    categoryMap: {
      "dental-tips": "Dental Tips",
      "cosmetic-dentistry": "Cosmetic Dentistry",
      "implant-dentistry": "Implant Dentistry",
      "general-dentistry": "General Dentistry",
      "emergency-dentistry": "Emergency Dentistry",
      uncategorized: "Dental Tips",
    },
    // Warn-and-default rather than hard-fail, so an editor creating a
    // category in wp-admin can't take the site's deploys down. Flip to
    // `undefined` to hard-fail instead — see §4.2.
    defaultCategory: "Dental Tips",
    defaultAuthor: "Slate",
  }),
  schema: wpBlogSchema,
});

export const collections = { reviews, blog };
```

### 5.3 `astro.config.mjs` — the added `image` block

```js
  // NEW. Without this, baseService.getURL() returns options.src unchanged
  // (astro/dist/assets/services/service.js:183) — no /_image route, no
  // transform, nothing emitted to /_assets/, and therefore nothing covered
  // by the one-year immutable cache. Worse: getSrcSet still runs and emits
  // `URL 640w, URL 960w, URL 1280w` where every candidate is the identical
  // full-size original. That looks correct in view-source and is worse than
  // having no srcset at all.
  image: {
    remotePatterns: [
      {
        protocol: 'https',
        hostname: 'cms.vividsmilesdentistry.com',
        // Scope to uploads, not the whole host: a wildcard host means any
        // URL that leaks into a post body becomes a build-time fetch.
        pathname: '/wp-content/uploads/**',
      },
    ],
    // Default REMOTE breakpoints are 15 entries up to 6016px (local is 8 up
    // to 2560). Trim, or pay ~2x the sharp encodes for widths nobody asks for.
    breakpoints: [640, 828, 1080, 1280, 1668, 2048],
  },
```

> **Also note:** the natural-width clamp in `getSrcSet` (`service.js:127-133`) is gated on `isESMImportedImage` and does **not** apply to remote sources. `widths={[640,960,1280,1600]}` against an 800px WP original will happily generate upscaled 1600w variants that are *larger in bytes than the source*. Clamp against `data.heroWidth` in `[slug].astro` or accept the waste.

### 5.4 Environment and config

> **Superseded.** The build reads **exactly one** environment variable:
> `WP_GRAPHQL_ENDPOINT`. None of the five below exist. There is no Application
> Password (WPGraphQL is queried anonymously), no Cloudflare Access service
> token (Zero Trust was never put in front of the CMS), and no preview secret
> (Phase 8 was not built). In the Vercel project the one variable is set for
> Production and Preview; `vivid-smiles-website/.env.example` documents the
> local, temporary-host and permanent-host values.
>
> One warning below is real and unaddressed: `ultrahtml` and `github-slugger`
> were never added to `package.json`, so `src/loaders/blog.ts` imports two
> packages that resolve only because Astro hoists them. `entities` is not used.
>
> A related trap the plan did not anticipate: `vivid-smiles-website/.gitignore`
> ends with `.env*`, which matches `.env.example` too. That file survives only
> because it was committed before the rule was added — untrack and re-add it and
> it disappears silently.

**`.env.example`** (commit this; `.env*` is already gitignored):
```bash
# WordPress origin — no trailing slash, no path.
WP_ENDPOINT=http://localhost:8888

# Application Password for the dedicated `astro-build` Editor user.
# NEVER prefix with PUBLIC_ — that ships it to the browser.
WP_APP_USER=astro-build
WP_APP_PASSWORD=xxxx xxxx xxxx xxxx xxxx xxxx

# Cloudflare Access service token, so /wp-json stays reachable while
# /wp-admin sits behind Zero Trust. Omit locally.
CF_ACCESS_CLIENT_ID=
CF_ACCESS_CLIENT_SECRET=

# Phase 8 only (draft preview).
PREVIEW_SECRET=
```

**Vercel project → Settings → Environment Variables:** all five, Production + Preview, **no `PUBLIC_` prefix**.

**`package.json` — promote transitive deps to explicit.** `ultrahtml`, `github-slugger` and `entities` currently resolve only because they are Astro's own transitive dependencies. That is not a stable API contract; an Astro patch release can drop or bump them.
```bash
npm i entities@^6 github-slugger@^2 ultrahtml@^1
```

**Documentation updates that are part of the work, not afterthoughts:**
- `DEPLOYMENT.md:13` — "Environment variables: None" is now false. Replace with the five above.
- `DEPLOYMENT.md:3` — "no backend/DB/SSR routes, host-anywhere" is false the moment Phase 8 adds an adapter. Qualify it.
- `DEPLOYMENT.md:37-44` — the third-party table omits the **WhatConverts** dynamic-number-insertion tag hardcoded at `BaseLayout.astro:90-106` and duplicated at `LandingLayout.astro:114-127`. It rewrites the practice's displayed phone number and POSTs visitor data offsite. Add it, and close out `VERCEL-DEPLOYMENT-NOTES.md:75-79`, which still lists that script as unidentified and requiring confirmation before launch.
- `VERCEL-DEPLOYMENT-NOTES.md` §4a/§5 — mark the `vercel.json` item done in Phase 0.

---

## 6. One-time content migration

> **Superseded — the importers below were not what shipped.** The `use WP-CLI,
> not REST` call was right and was followed; the single `vivid-import.php`
> command was not. What exists is `cms/import/`, run through the `import:*`
> scripts in `cms/package.json` in this order: `wp-settings` → `settings` →
> `gallery` → `reviews` → `blog` → `pages` → `sections` → `images` → `menus`
> (`npm run import:all` runs the chain). Two families of script: host-side
> `build-*.mjs` that read the Astro source and emit committed JSON payloads, and
> container-side `import-*.php` run via `wp eval-file`. Markdown is rendered
> with **Astro's own `@astrojs/markdown-remark`**, resolved out of the site's
> `package.json`, rather than Parsedown — anything else produces subtly
> different smart quotes, table markup and heading slugs.
>
> Two hard-won details from the real importers that this listing has no
> equivalent for: **ACF values must be written by field KEY, not name**, or the
> rows are invisible to both the admin UI and WPGraphQL; and `wp eval-file` runs
> the script through `eval()`, so none of the `import-*.php` files may carry
> `declare(strict_types=1)` or a `namespace`.

**Use WP-CLI, not the REST API.** WP-CLI runs as an authenticated superuser with no HTTP round trip, no auth handshake, and no rate limiting. Decisively: **Application Passwords are refused over plain HTTP**, so a REST-based importer against `http://localhost:8888` needs an SSL-bypass filter you'd then have to remember to remove. WP-CLI also gives you `media_handle_sideload`, which handles attachment metadata and thumbnail generation in one call.

### What's being moved

| | count | notes |
|---|---|---|
| Blog posts | 14 | 0 H1s, H2s in every post, H3s in 8, exactly one H4, **zero tables, zero blockquotes, zero raw HTML blocks**. All `.md`; no MDX, no `@astrojs/mdx`. |
| Hero images | 14 | `src/assets/images/blog/<slug>/hero.webp` or `00-hero.webp` |
| Body images | ~23 | across 10 posts (6 posts have zero); `![alt](../../assets/images/blog/<slug>/NN-desc.webp)` |
| Reviews | 20 | rating 5, source Google, single-paragraph plain text, no markdown syntax |
| Review tags | 31 distinct | Title Case free-form strings |

### Frontmatter parsing gotcha, confirmed in the files

Blog dates are **unquoted** (`date: 2025-08-05`, `updated: 2026-07-23`) — YAML parses those as Date objects. Review dates are **quoted** (`date: "2026-03-27"`) — parsed as String. `z.coerce.date()` papers over this today; a naive parser will not. The importer below normalizes both through `strtotime()`.

### `wp/mu-plugins/vivid-import.php`

Install Parsedown first: `composer require erusev/parsedown` in the wp-content dir, or vendor the single `Parsedown.php` file.

```php
<?php
/** WP-CLI importer. Idempotent: keyed on the markdown filename, which is
 *  already the production URL slug. Safe to re-run against dev/staging/prod. */
if (!defined('WP_CLI') || !WP_CLI) return;

require_once __DIR__ . '/vendor/Parsedown.php';

class Vivid_Import_Command {

    /** Minimal frontmatter parser. The corpus uses only scalars plus a
     *  `tags:` sequence — deliberately not pulling in a YAML dependency. */
    private function parse(string $file): array {
        $raw = file_get_contents($file);
        if (!preg_match("/^---\R(.*?)\R---\R(.*)$/s", $raw, $m)) {
            WP_CLI::error("No frontmatter in $file");
        }
        $fm = ['tags' => []];
        $key = null;
        foreach (preg_split("/\R/", $m[1]) as $line) {
            if (preg_match('/^\s*-\s*"?(.*?)"?\s*$/', $line, $t) && $key === 'tags') {
                $fm['tags'][] = $t[1];
                continue;
            }
            if (preg_match('/^([a-zA-Z]+):\s*(.*)$/', $line, $kv)) {
                $key = $kv[1];
                $val = trim($kv[2]);
                if ($val === '') { continue; }                 // sequence follows
                $fm[$key] = trim($val, "\"'");
            }
        }
        return [$fm, $m[2]];
    }

    private function find(string $slug, string $type): ?int {
        $q = get_posts([
            'name' => $slug, 'post_type' => $type,
            'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids',
        ]);
        return $q ? (int) $q[0] : null;
    }

    private function sideload(string $path, int $parent, string $alt = ''): ?int {
        if (!file_exists($path)) { WP_CLI::warning("missing image: $path"); return null; }
        $tmp = wp_tempnam(basename($path));
        copy($path, $tmp);
        $att = media_handle_sideload(
            ['name' => basename($path), 'tmp_name' => $tmp], $parent
        );
        if (is_wp_error($att)) { WP_CLI::warning($att->get_error_message()); return null; }
        if ($alt !== '') update_post_meta($att, '_wp_attachment_image_alt', $alt);
        return (int) $att;
    }

    /**
     * ## OPTIONS
     * <dir>  : /var/www/html/wp-content/import-src/blog
     * [--images=<dir>] : defaults to /var/www/html/wp-content/import-img
     *
     * @subcommand import-blog
     */
    public function import_blog($args, $assoc) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $imgRoot = $assoc['images'] ?? '/var/www/html/wp-content/import-img';
        $md = new Parsedown();

        // Astro enum value -> WP category slug. The slug is what the loader
        // maps on; the term name is irrelevant to the build.
        $catSlug = [
            'Dental Tips'         => 'dental-tips',
            'Cosmetic Dentistry'  => 'cosmetic-dentistry',
            'Implant Dentistry'   => 'implant-dentistry',
            'General Dentistry'   => 'general-dentistry',
            'Emergency Dentistry' => 'emergency-dentistry',
        ];

        foreach (glob(rtrim($args[0], '/') . '/*.md') as $file) {
            [$fm, $body] = $this->parse($file);
            $slug = basename($file, '.md');          // <- IS the production URL
            $id   = $this->find($slug, 'post');

            $data = [
                'post_type'     => 'post',
                'post_name'     => $slug,
                'post_title'    => $fm['title'],
                'post_excerpt'  => $fm['description'] ?? '',
                'post_status'   => (($fm['draft'] ?? 'false') === 'true') ? 'draft' : 'publish',
                'post_date_gmt' => gmdate('Y-m-d H:i:s', strtotime($fm['date'])),
                'post_content'  => '',                // filled after images resolve
            ];
            if ($id) $data['ID'] = $id;

            $post_id = $id ? wp_update_post($data, true) : wp_insert_post($data, true);
            if (is_wp_error($post_id)) WP_CLI::error($post_id->get_error_message());

            // `updated:` -> post_modified. Only three posts carry it
            // (2026-07-23); leaving the rest alone keeps modified == date so
            // the loader correctly omits dateModified.
            if (!empty($fm['updated'])) {
                wp_update_post([
                    'ID' => $post_id,
                    'post_modified_gmt' => gmdate('Y-m-d H:i:s', strtotime($fm['updated'])),
                ]);
            }

            wp_set_object_terms($post_id, [$catSlug[$fm['category']]], 'category', false);
            if (!empty($fm['author'])) update_field('byline', $fm['author'], $post_id);

            // Hero -> featured image, alt from heroAlt (required in frontmatter,
            // so this is always populated).
            $hero = $imgRoot . '/' . $slug . '/' . basename($fm['heroImage']);
            if ($att = $this->sideload($hero, $post_id, $fm['heroAlt'] ?? $fm['title'])) {
                set_post_thumbnail($post_id, $att);
            }

            // Body images: ![alt](../../assets/images/blog/<slug>/NN-x.webp)
            // -> media library URL. Alt from the markdown alt text.
            $body = preg_replace_callback(
                '#!\[([^\]]*)\]\((?:\.\./)+assets/images/blog/([^/]+)/([^)]+)\)#',
                function ($m) use ($imgRoot, $post_id) {
                    $att = $this->sideload("$imgRoot/{$m[2]}/{$m[3]}", $post_id, $m[1]);
                    return $att
                        ? '![' . $m[1] . '](' . wp_get_attachment_url($att) . ')'
                        : $m[0];
                },
                $body
            );

            // Imported as one Classic block. In wp-admin, open each post and
            // use "Convert to blocks" — 14 clicks buys the practice a real
            // Gutenberg editing experience instead of one opaque HTML blob.
            wp_update_post(['ID' => $post_id, 'post_content' => $md->text($body)]);

            WP_CLI::log(($id ? 'Updated ' : 'Created ') . $slug);
        }
        WP_CLI::success('Blog import complete. Now run "Convert to blocks" on each post.');
    }

    /** @subcommand import-reviews */
    public function import_reviews($args) {
        foreach (glob(rtrim($args[0], '/') . '/*.md') as $file) {
            [$fm, $body] = $this->parse($file);
            $slug  = basename($file, '.md');            // e.g. 01-steve-olds
            $order = (int) substr($slug, 0, 2);         // NN- prefix -> menu_order
            $id    = $this->find($slug, 'review');

            $data = [
                'post_type'     => 'review',
                'post_name'     => $slug,
                'post_title'    => $fm['reviewer'],
                'post_content'  => trim($body),          // plain text, no markdown
                'post_status'   => 'publish',
                'menu_order'    => $order,
                'post_date_gmt' => gmdate('Y-m-d H:i:s', strtotime($fm['date'])),
            ];
            if ($id) $data['ID'] = $id;

            $post_id = $id ? wp_update_post($data, true) : wp_insert_post($data, true);
            if (is_wp_error($post_id)) WP_CLI::error($post_id->get_error_message());

            update_field('rating', (int) $fm['rating'], $post_id);
            update_field('source', $fm['source'], $post_id);
            wp_set_object_terms($post_id, $fm['tags'], 'review_tag', false);

            WP_CLI::log(($id ? 'Updated ' : 'Created ') . $slug);
        }
        WP_CLI::success('Reviews import complete.');
    }
}
WP_CLI::add_command('vivid', 'Vivid_Import_Command');
```

### Post-import verification checklist

```bash
# 1. THE critical check — slugs must match filename stems exactly.
npx wp-env run cli wp post list --post_type=post --post_status=publish \
  --field=post_name --posts_per_page=-1 | sort > /tmp/wp.txt
ls src/content/blog | sed 's/\.md$//' | sort > /tmp/md.txt
diff /tmp/wp.txt /tmp/md.txt && echo "SLUGS OK"

# 2. No unrewritten relative image paths survived.
npx wp-env run cli wp db query \
  "SELECT post_name FROM wp_posts WHERE post_content LIKE '%../../assets/%'"

# 3. Every post has a featured image with alt text.
npx wp-env run cli wp post list --post_type=post --field=ID | while read id; do
  t=$(npx wp-env run cli wp post meta get "$id" _thumbnail_id 2>/dev/null)
  [ -z "$t" ] && echo "NO THUMBNAIL: $id"
done

# 4. Reviews: 20 items, menu_order 1..20, ratings all 5.
npx wp-env run cli wp post list --post_type=review --fields=post_name,menu_order --format=csv

# 5. Round-trip the API the loader will actually call.
curl -s "http://localhost:8888/wp-json/wp/v2/posts?per_page=100&_fields=slug,date_gmt,modified_gmt" \
  | jq -r '.[] | [.slug, .date_gmt, .modified_gmt] | @tsv'
```

### Migrating to production WordPress

> **Superseded — use the committed scripts.** `cms/bin/backup.sh` and
> `cms/bin/restore.sh` do this, and `restore.sh` takes the target URL as an
> argument: `bash cms/bin/restore.sh https://cms.vividsmilesdentistry.com`. The
> dump lives at `cms/backup/database.sql` and the origin it was taken from at
> `cms/backup/SITEURL`, which is what makes the rewrite decidable. Two things are
> deliberately **not** in the dump. Media: `wp-content/uploads` is mapped to
> `cms/uploads/` and committed with the repo, so dump plus uploads is a complete
> copy of the content. Accounts: `backup.sh` passes
> `--exclude_tables=wp_users,wp_usermeta`, so restoring onto a real host moves
> content and leaves that host's logins untouched — the export uses
> `--add-drop-table`, so a dump that merely *emptied* those tables would drop and
> recreate them, wiping the target's accounts. The `--skip-columns=guid` flag below is wrong for this
> setup: `restore.sh` rewrites all tables precisely, because WordPress stores
> serialized PHP in `wp_options` with string lengths encoded alongside the
> values, so the rewrite must go through `wp search-replace` and must not skip
> the columns that carry it. The field groups do **not** travel in
> `wp/acf-json/`; they are PHP in `cms/mu-plugins/`, deployed with the code.

Export locally, import to prod (WP's XML exporter mangles custom fields; use SQL):
```bash
npx wp-env run cli wp db export /var/www/html/seed.sql
# then on prod: wp db import seed.sql && wp search-replace 'http://localhost:8888' \
#   'https://cms.vividsmilesdentistry.com' --precise --skip-columns=guid
```
Copy `wp-content/uploads/` across. Then re-run the verification checklist against production. **ACF field groups travel in `wp/acf-json/` (git), not in the database** — that is the only sane way to version the content model.

---

## 7. Editorial workflow

### Publish → live

```
Editor clicks Publish
  └─ transition_post_status fires
       ├─ Rejected if: revision, autosave, wrong post_type, or neither
       │  $new nor $old is 'publish'.
       │  (save_post is the WRONG hook — it fires on autosaves, revisions,
       │   and draft-to-draft saves, triggering builds for invisible content.)
       └─ wp_next_scheduled() guard + wp_schedule_single_event(time()+90)
            ├─ Admin notice: "Saved. Your website will update in about 2 minutes."
            └─ +90s → POST to the Vercel Deploy Hook
                 └─ Queue ~10s → Build 40–80s → Propagate ~10s
                      └─ LIVE. Typical 2–3 min, worst case ~4.
```

**Why a 90-second debounce and not Vercel's own dedupe.** Vercel *does* cancel superseded builds (same hook, same branch, 60 hook triggers/hr/project), which is good for cost — but each new save cancels the in-flight build and restarts the clock, so "when is it live" keeps sliding indefinitely while someone keeps typing. A fixed window lets you make a **promise**. Pair it with a "Publish now" button that skips the window.

**The WP-Cron trap.** `wp_schedule_single_event` fires only when someone hits the site — and a dental practice at 9pm has no traffic. The manager publishes and nothing happens for hours. **Required fix:** set `define('DISABLE_WP_CRON', true)` and add a real system cron hitting `wp-cron.php` every minute. On SpinupWP this is a checkbox.

`wp/mu-plugins/vivid-deploy.php`:
```php
<?php
const VS_HOOK   = 'vs_fire_vercel_deploy';
const VS_WINDOW = 90;

add_action('transition_post_status', function ($new, $old, $post) {
    if (wp_is_post_revision($post) || wp_is_post_autosave($post)) return;
    if (!in_array($post->post_type, ['post', 'review'], true)) return;
    if ($new !== 'publish' && $old !== 'publish') return;   // covers publish,
    vs_queue_deploy();                                       // edit, unpublish, trash
}, 10, 3);
add_action('wp_trash_post',  'vs_queue_deploy');
add_action('deleted_post',   'vs_queue_deploy');
// Category renames and review_tag edits change rendered output too.
add_action('edited_term',    'vs_queue_deploy');

function vs_queue_deploy(bool $now = false): void {
    if ($now) { wp_schedule_single_event(time(), VS_HOOK); spawn_cron(); return; }
    if (wp_next_scheduled(VS_HOOK)) return;                  // <- the debounce
    wp_schedule_single_event(time() + VS_WINDOW, VS_HOOK);
    set_transient('vs_deploy_pending', time() + VS_WINDOW, VS_WINDOW + 60);
}

add_action(VS_HOOK, function () {
    if (!defined('VS_VERCEL_DEPLOY_HOOK')) return;           // set in a mu-plugin,
                                                             // NOT wp-config.php —
                                                             // the managed host
                                                             // rewrites that file
    $res = wp_remote_post(VS_VERCEL_DEPLOY_HOOK, ['timeout' => 15]);
    delete_transient('vs_deploy_pending');
    if (is_wp_error($res)) {
        set_transient('vs_deploy_error', $res->get_error_message(), DAY_IN_SECONDS);
    }
});

add_action('admin_notices', function () {
    if ($err = get_transient('vs_deploy_error')) {
        echo '<div class="notice notice-error"><p><strong>Your last update didn\'t '
           . 'publish.</strong> We\'ve been notified. The website is still showing '
           . 'the previous version.</p></div>';
    }
    if (get_transient('vs_deploy_pending')) {
        echo '<div class="notice notice-info"><p><strong>Saved.</strong> '
           . 'Your website will update in about 2 minutes.</p></div>';
    }
});
```

**Ship the admin-bar status pill.** A small JS polling `GET https://api.vercel.com/v6/deployments?projectId=…&limit=1` (proxied through an authenticated WP AJAX endpoint so the Vercel token never reaches the browser), showing **Queued / Building / Live / Failed**. Without it the manager refreshes the live site, sees no change, and calls you — every single time.

### Do not build these off-the-shelf plugins in — all three are dead

- `vercel-deploy-hooks` on wordpress.org was **permanently closed 20 Oct 2024** and is no longer downloadable.
- `aderaaij/wp-vercel-deploy-hooks` was **archived 6 Jul 2026**.
- `wp-jamstack-deployments` was **last updated 30 Nov 2020**, tested to WP 5.5.

Owning ~90 lines of PHP is strictly less risk than depending on abandoned code with write access to your deploy hook on a client's production CMS.

### Draft preview (Phase 8)

WordPress Preview button → your production domain → one SSR route.

1. `preview_post_link` filter rewrites the button to `https://vividsmilesdentistry.com/preview/?post_id=…&exp=…&sig=…`, HMAC-SHA256 signed with a 15-minute expiry.
2. `src/pages/preview/index.astro` with `export const prerender = false` verifies the signature with `timingSafeEqual`, returns 403 on expiry, sends `X-Robots-Tag: noindex`, and fetches the draft with the `astro-build` Application Password.
3. Optionally install **HWP Previews** (WP Engine's `hwptoolkit`, actively maintained, framework-agnostic, per-post-type URL templates, renders in an iframe inside the editor) so it feels like native WordPress.

**Three things that will bite:**
- `/preview/` must be added to the `excludeExact` array in `astro.config.mjs` — otherwise a public URL that renders unpublished content lands in your sitemap.
- A brand-new draft has **no revision**, so an `asPreview`-style query returns differently than for edits-to-published-posts. Handle both paths or first-time drafts preview as blank.
- The Application Password grants full REST access as that user. It is `astro-build` (Editor), never an admin, and it is a server-only env var.

---

## 8. Risks, and what gets **worse**

I'll do the honest part first.

### Things that are strictly worse after this migration

1. **The build stops being hermetic.** Today `astro build` reads 34 files off disk and cannot fail for network reasons. After migration it has a hard network dependency on a $20/mo WordPress box. Vercel auto-deploys on every push to `main`, so an unrelated commit — a CSS tweak, a typo fix — can fail because the CMS was down, mid-update, or rate-limiting. The floor assertion and fail-loud design make this *safe* (the previous deployment stays live), but it is a new class of interruption that does not exist today.

2. **Content leaves git.** You lose PR review on copy changes, `git blame` on a claim about veneer pricing, offline builds, and the ability to reconstruct any past version of the site from a commit hash alone. For a dental practice making medical-adjacent claims, "who changed this sentence and when" is a real question, and the answer degrades from a git log to WordPress revisions in a database you now have to back up.

3. **Type safety on images is lost, silently.** `heroImage: image()` today produces a real `ImageMetadata` object that TypeScript proves exists. After migration it is a string that TypeScript can only prove is a string. If someone later reverts the schema to `image()` while feeding WP URLs, **`astro check` passes and the runtime value is a plain string** — because `imageSrcToImportId` returns `undefined` for remote paths and `updateImageReferencesInData` falls through to `ctx.update(src)`. The build then fails at `verifyOptions` with `MissingImageDimension`, pointing at the wrong file.

4. **Body images get worse before they get better.** Between Phase 5 and Phase 6 the ~23 in-body images are hotlinked, unoptimized, and outside the `/_assets/* immutable` cache. Even after Phase 6, the intrinsic-dimension guarantee is now something you maintain rather than something Astro's markdown pipeline gives you for free.

5. **Reading time gets approximate.** `readingTime()` today operates on real markdown source. After migration it operates on plain text derived from HTML, which is close but not identical (list markers, code fences, and emphasis characters no longer count). The number on the byline will shift by a minute on some posts.

6. **Schema violations move from author-time to build-time, and the author is no longer a developer.** Today a bad frontmatter value fails a developer's local build before it is ever committed. After migration, a non-technical editor can break production deploys by renaming a category, clearing an alt text, or writing an excerpt over 200 characters. The loader's defensive coercion (truncate, alt fallback, category default) covers the common cases — but that is exactly the point: **you are now relying on defensive coercion rather than Zod rejection**, which means some classes of bad content will silently render rather than loudly fail.

7. **Publish is no longer instant, and preview is no longer free.** A markdown edit is visible in `astro dev` in milliseconds. A WordPress publish is visible in 2–4 minutes, and only after you build and maintain the debounce, the status pill, and the alerting. Preview requires an adapter and a server route that did not previously exist.

8. **New recurring cost and new attack surface.** ~$20–35/mo hosting + $20/mo Vercel Pro + optionally $59/yr ACF PRO, plus an internet-facing WordPress admin that now needs 2FA, Cloudflare Access, and an update discipline nobody at the practice will maintain unattended.

9. **Build time grows.** 48 routes and 14 posts hide the curve. Once heroes are downloaded and re-encoded on a cold cache, plus `npm run check` running `astro check && astro build` back to back (two full content loads), the fast static build becomes a network-bound multi-minute one on any cache miss.

**Bottom line, said plainly to the client:** if Concepcion.Work is going to keep writing the blog posts, git is the better CMS and this migration is net-negative. It pays for itself only if the practice has genuinely committed to publishing content themselves. Ask that question before Phase 1, not after Phase 5.

### Ranked risk register

| # | Risk | Severity | Mitigation in this plan |
|---|---|---|---|
| 1 | **Slug drift → 404s on indexed URLs.** `postUrl()` derives `/blog/<id>/` purely from the slug. Two posts have titles that diverge sharply from their slugs. | Critical | Phase 4 slug diff is a hard gate. Re-run it in CI. |
| 2 | **A build that SUCCEEDS with missing pages.** A 200 response with a partial result set produces a successful build that silently deletes live URLs, regenerates the sitemap without them, and turns redirect destinations into 301→404 chains. | Critical | `minEntries` floor assertion throws. A *failed* fetch is the safe case. |
| 3 | **A media URL redirects → whole build fails.** `loadRemoteImage` uses `redirect: 'manual'` and throws on **any** 3xx. WP media redirects for routine reasons: http→https canonicalization, a trailing-slash rule, Jetpack/Photon, a CDN rewrite. | High | Verify every `source_url` returns 200 on first hit with zero hops. Put a plain caching CDN in front of `/wp-content/uploads/`. **See the note below — the CDN turned out to be the hazard, not the fix.** |
| 4 | **Dead TOC anchors.** WP emits no heading ids; `toc-spy.ts` fails with **no console error and no build error**. | High | Loader assigns ids and builds the headings array in the same pass. Phase 5 verification clicks every TOC link on 3 posts. |
| 5 | **Redirect/route collision at paths WP can resurrect.** `public/_redirects:150-151` explicitly warns that a routed asset and a redirect at the same path is undefined. The four `/blog/<veneers-slug>/` rules (`:137-140`) and the `/before-and-afters/` pair (`:152-153`) sit at paths a WP-sourced build regenerates if that content still exists in the install. | High | Phase 0 translates redirects into `vercel.json`; Phase 4 slug diff surfaces any resurrection; delete both rule sets in the same commit if the content returns. |
| 6 | **Stored XSS.** `public/_headers:5-7` deliberately omits CSP so GTM can inject tags. `<Content />` is `unescapeHTML()` with zero sanitization. An editor account — or a compromised WP install — can execute script on a production dental site. | High | Loader sanitizes with `ultrahtml`, dropping `script/style/iframe/object/embed/form/input`. Revisit the CSP decision. |
| 7 | **Unauthorized-domain pass-through emits an actively harmful srcset.** If `remotePatterns` is misconfigured or the hostname later changes, `getURL` returns the src unchanged with **no warning**, but `getSrcSet` still emits N candidates that are all the identical full-size original. Looks correct in view-source; worse than no srcset. | High | Phase 5 verification greps `dist/` for the CMS hostname. |
| 8 | **Non-deterministic review order.** Six reviews share 2025-04-27, three share 2025-12-27; today ties resolve via glob's alphabetical `NN-` order. | Medium | `order` field from `menu_order` + `src/lib/reviews.ts` explicit tie-break, established in Phase 2 with a zero-diff proof. |
| 9 | **Build cache doesn't survive CI.** The remote-asset and content-layer caches live in `node_modules/.astro/`, which is gitignored. Vercel restores `node_modules/**` on a cache hit, but the key includes Node version, package manager, and **git branch** — a `.nvmrc` bump or a new feature branch throws it all away. Cache is capped at 1 GB and expires after one month. | Medium | Pin `.nvmrc` to `22.12.1` and set Vercel's Node version to match. Watch the loader's `logger.info` line in build output to confirm incremental sync is engaging. **Verify empirically; do not assume.** |
| 10 | **Prose CSS gap.** `.prose > * + *` sets all vertical rhythm via direct-child spacing; any WP wrapper div collapses it. | Medium | Phase 6B, with visual diffs. |
| 11 | **Timezone off-by-one.** WP `*_gmt` has no `Z`; current frontmatter dates are bare `YYYY-MM-DD` → UTC midnight; `formatDate` and `ReviewCard:34` both force `timeZone:'UTC'`. | Medium | `gmtToIso()` appends `Z`; `date_gmt` (not `date`) is the mapped field. |
| 12 | **Duplicate/conflicting JSON-LD.** 76 hand-rolled blocks across 24 page files. If Yoast's `schema.raw` @graph is ever emitted alongside them you get two BreadcrumbLists on nearly every page and two Article nodes on every post. | Medium | **Decision: keep the bespoke blocks, disable Yoast schema output entirely.** Yoast is used only for `yoast_head_json.description`. Do not half-do this. |
| 13 | **WP origin gets indexed**, creating a full duplicate-content competitor. | Medium | `X-Robots-Tag` header + `robots.txt` + front-end 301 + never linking to `cms.` from anywhere public. |
| 14 | **Sitemap exclusion list drifts.** `astro.config.mjs:17-32` is six literal strings and cannot import `astro:content`. | Low *at this scope* (route set is unchanged) | Becomes High the moment scope expands to page copy — then it must become a data-driven manifest written by a prebuild step. |
| 15 | **DNS/email blast radius.** Google Workspace MX, SPF, `_dmarc`, and two verification TXT records share the Cloudflare zone. | Low probability, catastrophic impact | Only apex, `www`, and the new `cms` record ever change. Nothing else in the zone is touched. Document this in the runbook. |

> **What actually bit, and it was risk 3 from an unexpected direction.** The
> managed host sits behind Cloudflare bot protection that returns **429 with
> `cf-mitigated: challenge`** under burst load. Astro downloads remote images at
> high concurrency, so a cold cache produced exactly the "whole build fails"
> outcome this risk predicted — twice, on production deploys — but through rate
> limiting rather than a 3xx hop. Two mitigations shipped:
> `vivid-smiles-website/scripts/warm-media-cache.mjs` runs as `prebuild` and
> GETs every media URL at concurrency 3 with backoff so the edge is warm before
> Astro touches it, and `src/lib/wp.ts` now treats 429 as retryable, honouring
> `Retry-After` across 5 attempts. Deploys have succeeded since. **The durable
> fix — relaxing the host's Cloudflare rules for `/wp-content/uploads/` and
> `/graphql` — is still outstanding.**

---

## 9. Open questions — things I need from the client before Phase 1

> **Several of these were answered by what shipped. Do not re-ask them.**
>
> | # | Settled as |
> |---|---|
> | 3 | **Yoast.** Pinned at `wordpress-seo:28.2` with `add-wpgraphql-seo:5.1.0`, so WPGraphQL stayed on the table. |
> | 4 | **Full scope, not blog + reviews.** 31 routes of page copy, images, menus and settings all moved. |
> | 5 | Partly. **No ACF Pro purchase** — Secure Custom Fields, free and GPL. Hosting is GoDaddy Managed WordPress; Vercel is on Hobby, not Pro. Who pays and who owns the accounts is not recorded here. |
> | 10 | `import-blog.php` writes the rendered HTML straight into `post_content` and performs **no block conversion**, so an imported post opens as a single Classic block. Posts keep their editor; pages have it removed entirely, because nothing renders a page's `post_content`. Whether anyone has since run "Convert to blocks" in wp-admin is not recorded. |
> | 14, 18 | Resolved — see the struck-through entries below. |
>
> Questions 1, 2, 6, 7, 8, 11, 12, 13, 15, 16 and 17 have no recorded answer and
> should be treated as still open. Question 9 was answered by the code in a
> third way neither option anticipated; see its entry.

**Blocking (cannot start without answers):**

1. **Who is actually going to write the blog posts after this ships — the practice, or Concepcion.Work?** If the honest answer is Concepcion.Work, this migration is net-negative and we should not do it. I need a straight answer, not an aspirational one.
2. **Does a legacy WordPress install still exist?** `public/_redirects:22-59` migrates ~14 legacy WP root slugs and `content.config.ts:66-67` references a prior export. If it exists: what host, what PHP version, what plugins, and **does it still contain the four retired veneers posts and the archived `/before-and-afters/` page?** Those regenerate at exactly the paths the redirect file warns about.
3. **Yoast or Rank Math?** If Rank Math, WPGraphQL is off the table (its bridge plugin is 14 months stale and pre-1.0) and the REST recommendation becomes mandatory rather than preferred.
4. **Scope confirmation: blog + reviews only for v1?** If they want service page copy in v1, the timeline goes from ~2 weeks to ~6 and needs two content-owner decisions resolved first (FAQ rendering normalization, and which of the three divergent service/credential lists is canonical).
5. **Hosting budget approval:** ~$18–24/mo WordPress + $20/mo Vercel Pro + $59/yr ACF PRO if WPGraphQL is chosen. Who pays, and who is the account owner of record?
6. **Who owns the WordPress install operationally** — updates, backups, security patching, incident response? If the answer is "nobody," pick the managed host (Cloudways or Rocket.net), not SpinupWP.

**Needed before Phase 4 (content import):**

7. **Confirm all 14 slugs are frozen forever.** Editors must be told they can change a post *title* freely but must never change a *slug*. Is there an editorial policy we can put in writing, or do we need to lock the slug field in wp-admin?
8. **The `author` / Organization problem.** `blog/[slug].astro:160-163` types the JSON-LD author as `Organization` while the field is called `author` and currently contains `"Slate"`. If real human bylines are coming, we change that node to `Person`. Which is it?
9. **Unmapped category behaviour:** should an editor creating a new category (a) warn-and-default to "Dental Tips" so the site stays current, or (b) hard-fail the build so nothing miscategorized ships? ~~Current code does (a).~~ **The shipped loader does neither.** `src/loaders/blog.ts` **skips** any post whose category is outside the five-name allowlist, logging a warning, and throws only if *every* post is skipped. So a miscategorized post does not get defaulted and does not stop the build — it silently vanishes from the blog hub and the sitemap while the deploy reports success. Two guards soften it: `vs-content-model.php` seeds the five terms and repoints `default_category` to Dental Tips, so a new post never lands on Uncategorized. This question is still worth putting to the client.
10. **Do the 14 posts need to be Gutenberg-editable, or is a Classic HTML block acceptable?** Gutenberg means 14 manual "Convert to blocks" clicks and a real editing experience; Classic means one opaque blob nobody can safely edit.

**Needed before Phase 8 / cutover:**

11. **Is draft preview actually required?** It costs an adapter and invalidates the "host anywhere / no backend" clauses in `DEPLOYMENT.md`. Many practices are fine with "publish, wait two minutes, look at the site."
12. **Who receives build-failure alerts?** A Slack channel, an email distribution, or a person. And who is on the hook to act at 6pm on a Friday?
13. **`cms.vividsmilesdentistry.com` DNS:** confirm we may add one A/CNAME record at Cloudflare and that **nothing else in the zone is touched** — MX (`smtp.google.com`), SPF, `_dmarc`, and the two verification TXT records are all off-limits.

**Should be raised now even though it isn't blocking:**

14. ~~**`src/components/SmileGallery.astro` is entirely orphaned**~~ — **Resolved.** Deleted in commit `180270c`, confirmed absent from all 48 built pages. The live gallery is `src/lib/smiles.ts`, which now reads Practice Settings → Smile gallery rather than globbing `src/assets/images/smiles/`.
15. **The "300+ five-star reviews" claim is hardcoded** at `LocalTrust.astro:113-114` **and** duplicated in at least four page meta descriptions and body copy. Centralizing only the component leaves the meta descriptions stale. Also: despite 20 structured reviews existing, there is **zero `Review` or `AggregateRating` schema anywhere on the site** — that is an unclaimed rich-result opportunity, but it needs a real, defensible source number before we emit it.
16. **`src/assets/videos/hero.mp4` is 6.6 MB of unreferenced dead weight**, along with `brand/hero-poster.jpg` and `_missing.webp`. Confirm before deletion.
17. **The WhatConverts dynamic-number-insertion tag** (`BaseLayout.astro:90-106`, duplicated at `LandingLayout.astro:114-127`) rewrites the practice's displayed phone number and POSTs visitor data offsite. It is absent from `DEPLOYMENT.md`'s third-party table and `VERCEL-DEPLOYMENT-NOTES.md:75-79` still flags it as unverified. Confirm it is intentional and authorized.
18. ~~**Currently every page is live at two URLs**~~ — **Resolved.** `vercel.json` sets `trailingSlash: true`, matching Astro's `trailingSlash: 'always'`, so the no-slash form now redirects rather than returning 200. The caveat still stands historically: any SEO comparison drawn against a baseline measured before this landed is measuring an unrepresentative state.