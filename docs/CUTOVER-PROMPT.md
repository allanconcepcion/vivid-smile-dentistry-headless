# Claude-in-Chrome prompt — Vivid Smiles domain cutover

Paste everything below the line into a fresh Claude-in-Chrome session when you're
ready to go live. Do **not** paste any password, SSH credential or deploy-hook URL
into it — if a step needs one, Claude should stop and ask you to run that step.

---

I'm cutting a headless WordPress + Astro + Vercel site over to its live domain.
Read this file first and treat it as the source of truth for current state:

https://github.com/allanconcepcion/vivid-smile-dentistry-headless/blob/main/docs/SESSION-HANDOFF.md

Then read `docs/DEPLOYING.md` in the same repo, specifically the sections
"Deploying mu-plugins", "Moving the CMS to its permanent hostname" and
"Cutting over the domain".

## What's moving

| Piece | From | To |
| --- | --- | --- |
| Front end | `vivid-smiles-headless.vercel.app` | `vividsmilesdentistry.com` |
| CMS | `1230613.us28.myftpupload.com` | `cms.vividsmilesdentistry.com` — **DONE 2026-09-01**: the CMS answers here, its own settings and media URLs emit this domain, and the repo points at it |

Repo: `github.com/allanconcepcion/vivid-smile-dentistry-headless`
Vercel project: `vercel.com/allans-projects-cc55d7b7/vivid-smiles-headless`
Host SSH/SFTP: `1230613.us28.ssh.myftpupload.com` (note the `ssh.` — the web
hostname refuses port 22)

## Ground rules

- Never merge a PR, push to `main`, or delete anything without asking me first.
  Code changes go to a feature branch and a PR.
- Never handle passwords, SSH credentials or deploy-hook URLs. If a step needs
  one, stop and tell me to run it.
- When a Vercel build fails, open the deployment and read the actual log before
  proposing a fix. Don't guess.
- Always report the actual value you observed alongside any pass/fail verdict,
  and say plainly what you couldn't verify and why.
- Ask before any irreversible or externally visible action: DNS changes,
  submitting sitemaps, adding domains in Vercel.

## Three things that will break this if you get them wrong

**1. Do not overwrite `vs-config.php` on the host.**
The host's copy defines `VS_DEPLOY_HOOK_URL`. The repo's copy does not — it's a
credential and the repo is public. `DEPLOYING.md`'s cutover step 7 says to update
`VS_FRONTEND_URL` in that file and "re-upload it". Uploading the repo version
deletes the hook constant, and nothing appears to break: `vs-deploy.php` keeps
loading, keeps returning early from `queue()`, and publishing silently stops
rebuilding the front end.

Edit that one constant **in place on the host** instead, and confirm afterwards
that `VS_DEPLOY_HOOK_URL` is still defined. `cms/bin/deploy-mu-plugins.sh` already
refuses this file unless `VS_OVERWRITE_CONFIG=1`; don't set that flag.

**2. Do not touch the email DNS records.**
`vividsmilesdentistry.com` DNS is at Cloudflare, and the practice's Google
Workspace email runs through the same zone. `DEPLOYING.md` lists the MX, SPF,
DMARC and verification TXT records that must survive. Only the apex `A` record
and the `www` record change. If any runbook step asks you to delete records or
replace the zone, stop and tell me.

**3. `robots.txt` flips at cutover.**
`src/integrations/robots.ts` writes `Disallow: /` with no `Sitemap:` line on a
`.vercel.app` host, and `Allow: /` plus two `Sitemap:` lines on a real domain.
Every mistake becomes indexable the moment the custom domain goes live, so
verify before announcing anything.

## Order of operations

Do not start until all three are true. Check and report each:

1. Production renders correctly on the `.vercel.app` URL.
2. The two outstanding credential rotations are done — the WordPress password
   whose hash is in this repo's git history before commit `9f41107`, and the
   host's SSH/SFTP password. Ask me; don't try to verify these yourself.
3. TTL on the apex and `www` records was lowered ~24h ago.

Then:

1. **Pin the canonical host explicitly.** `astro.config.mjs` derives `site` from
   `SITE_URL` → `VERCEL_PROJECT_PRODUCTION_URL` → `FRONT_END_URL`. Set
   `SITE_URL=https://vividsmilesdentistry.com` in the Vercel project's
   environment variables for Production so nothing depends on how Vercel reports
   the production URL mid-cutover. Ask before saving it.
2. **Move the CMS** to `cms.vividsmilesdentistry.com` per "Moving the CMS to its
   permanent hostname". `restore.sh` uses `wp search-replace` rather than editing
   SQL — WordPress stores serialized PHP in `wp_options` with string lengths
   encoded, so a plain find/replace corrupts it.
3. **Update `WP_GRAPHQL_ENDPOINT`** in Vercel to the new CMS hostname, and add
   that hostname to `image.remotePatterns` in `astro.config.mjs` (feature branch
   + PR).
4. **Confirm GraphQL answers on the new CMS host** before going further.
5. **Add the domains in Vercel**, set DNS in Cloudflare using the values Vercel
   displays at that moment, records set to **DNS only** (grey cloud). Proxying on
   both sides causes redirect loops and certificate failures.
6. **Edit `VS_FRONTEND_URL` in place on the host** (see hazard 1).
7. **Redeploy production** from the Vercel dashboard with "Use existing Build
   Cache" unchecked.

## Verification — report the observed value for every line

Run against `https://vividsmilesdentistry.com`:

1. `/` renders, over HTTPS, valid certificate.
2. `<link rel="canonical">` host equals the host being browsed, on `/`,
   `/cosmetic-dentistry/` and `/blog/`.
3. `/robots.txt` says `Allow: /` plus **two** `Sitemap:` lines. If it still says
   `Disallow: /`, `site` resolved wrong — stop and fix before anything is
   crawled.
4. `/sitemap_index.xml` and `/page-sitemap.xml` — every `<loc>` host equals the
   live domain.
5. Several legacy redirect URLs from `vercel.json` land on the right pages.
6. The four security headers are present.
7. **Publish-to-rebuild still works.** Save any page in wp-admin; the admin
   notice "Front-end rebuild queued" should appear, and ~2 minutes later Vercel
   should show a Production deployment labelled `Created: Deploy Hook`. If the
   notice doesn't appear, `VS_DEPLOY_HOOK_URL` was lost — see hazard 1.

The fastest way to run 1–4 is a same-origin `fetch()` loop from the site's own
origin, regexing out `<link rel="canonical">`, `<title>` and `<h1>`. Cross-origin
fetch is blocked.

## Only after all of the above passes

Submit `https://vividsmilesdentistry.com/sitemap_index.xml` to Search Console and
Bing Webmaster Tools. Ask me first.

## Known gotchas in this codebase

- Pages have no `post_content`; a page **is** its structured fields. Read them
  through `getCollection("pages")`.
- `getStaticPaths` is hoisted out of Astro frontmatter — only imports travel with
  it. Anything else it needs must be declared inside it.
- Yoast's sitemap is generated on the CMS and rewritten by the Astro build.
  Rewriting the hostname inside WordPress does not work; Yoast validates entries
  against its own host and silently drops foreign ones.
- A sitemap URL with no built page is dropped with a warning, not a build
  failure. Deliberate — an editor can't break a deploy.
- Cloudflare in front of the CMS answers cold bursts with 429s.
  `warm-media-cache.mjs` and retry logic in `src/lib/wp.ts` paper over it.
  Relaxing the bot rules for `/wp-content/uploads/` and `/graphql` is the durable
  fix and is still outstanding.
- GraphQL introspection is disabled for public requests. The structured field
  group is exposed as `pageFields` on `Page`; `vsSeo` and `vsRoute` are separate.

## Rollback

Keep the old host's configuration noted before you start. With a lowered TTL,
reverting the apex and `www` records restores the previous site in minutes.
