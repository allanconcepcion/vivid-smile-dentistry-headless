# Deploying

The site is a static Astro build that reads content from WordPress **at build
time**. Nothing is fetched from WordPress by a visitor's browser.

That single fact drives everything below: **the build machine must be able to
reach WordPress over the public internet.** A Vercel build cannot reach
`localhost:8888`, which is why hosting WordPress was a prerequisite for
deploying at all rather than a follow-up task.

```
WordPress (1230613.us28.myftpupload.com)
      │  WPGraphQL, at build time only
      ▼
Vercel build ──► static HTML ──► visitors
```

## How to read the dates in this file

Every "Verified" line names the day the claim was checked against the live
system, not the day it was written. Most of the hosting facts below were
verified on 13–14 August 2026 and have not been re-checked against a dashboard
since; they are marked as such and nothing in the repository contradicts them.

The repository has moved a long way in the meantime. HEAD is `07c027d`
(31 August 2026) on branch `page-blocks`, thirty-three commits ahead of `main`,
and those commits added a whole second deploy surface — the WordPress-side block
system, whose runbook is [Deploying the CMS side](#deploying-the-cms-side).
Anything in this file re-verified against the working tree at `07c027d` says so.

---

## What is deployed today

Hosting rows verified live on 2026-08-13. Repository rows re-verified against
`07c027d` on 2026-08-31.

| Setting | Value |
| --- | --- |
| GitHub repository | `allanconcepcion/vivid-smile-dentistry-headless` — **still public; see [Outstanding before launch](#outstanding-before-launch)** |
| Branch that deploys | `main`. It is no longer the only branch — see [What is on `main`, and what is not](#what-is-on-main-and-what-is-not) |
| Vercel team | `allans-projects-cc55d7b7` ("Allan's projects", Hobby plan) |
| Vercel project | `vivid-smiles-headless` |
| Production URL | `https://vivid-smiles-headless.vercel.app` |
| **Root Directory** | `.` — the repository root, **not** `vivid-smiles-website` |
| Install command | `cd vivid-smiles-website && npm install` (from `vercel.json`) |
| Build command | `cd vivid-smiles-website && npm run build` (from `vercel.json`) |
| Output directory | `vivid-smiles-website/dist` (from `vercel.json`) |
| Environment variable | `WP_GRAPHQL_ENDPOINT` — Production, Preview and Development |
| WordPress | `https://1230613.us28.myftpupload.com` — GoDaddy Managed WordPress, temporary hostname |

The local CLI link lives in `vivid-smiles-website/.vercel/project.json`
(git-ignored) and records the same project and team ids.

### Root Directory is the repository root, and that is load-bearing

Vercel reads `vercel.json` from the project's Root Directory. `vercel.json` is
at the **repository root**, so the Root Directory must stay `.` for the file to
be read at all. Point it at `vivid-smiles-website` and the file becomes
invisible: no install command, no build command, no output directory, and the 65
legacy redirects, the security headers and `trailingSlash` all stop applying —
without any error saying so.

The repository root has no `package.json`. That is why `vercel.json` carries
explicit `installCommand`, `buildCommand` and `outputDirectory` values that `cd`
into `vivid-smiles-website/`, instead of relying on a framework preset. The
generator that writes the file says the same thing in a comment, so the two
cannot drift apart unnoticed.

Re-verified at `07c027d`: all four values parse out of `vercel.json` exactly as
tabled above, and the file is still at the repository root.

### The environment variable

| Name | Value | Environments |
| --- | --- | --- |
| `WP_GRAPHQL_ENDPOINT` | `https://1230613.us28.myftpupload.com/graphql` | Production, Preview, Development |

This is the only variable the build needs. Without it the build fails
immediately with a message naming the fix, rather than publishing an empty site.

It is set for Preview as well as Production, so a branch or pull-request preview
builds against the same live WordPress content. That matters more now than it
did when it was written: `page-blocks` is a long-lived branch whose previews are
the only way its 48 routes have ever been rendered against the real CMS.

A third copy exists for Development. Production and Preview are marked Sensitive,
so the dashboard will not display their values; only Development can be read
back. Verified in the dashboard on 2026-08-13.

Change it to `https://cms.vividsmilesdentistry.com/graphql` when the CMS moves to
its permanent hostname, and add that hostname to `image.remotePatterns` in
`astro.config.mjs` in the same change. `astro.config.mjs:73-80` already
authorizes `cms.vividsmilesdentistry.com`, `1230613.us28.myftpupload.com` and
`http://localhost:8888`, all scoped to `/wp-content/uploads/**`. A host that is
not on that list fails every image in the build.

### Node version is not pinned where Vercel looks

`vivid-smiles-website/package.json:6-8` declares `engines.node: ">=22.12.0"` and
`vivid-smiles-website/.nvmrc` says `22`. **Neither of those reaches Vercel.**
Vercel reads `.nvmrc` from the repository root, and there is none there;
`vercel.json` sets no `nodeVersion`. Re-verified at `07c027d`: still no root
`.nvmrc`, still no `nodeVersion` key. The build uses whatever the project's
dashboard Node setting is. Read back on 2026-08-13: **24.x**, which satisfies
`engines` — but nothing in the repository enforces that, a change made in the
dashboard would not appear in a diff, and this is the first thing to check if a
build ever fails on a Node API rather than on content.

### How a deploy happens

Push to `main`. The Vercel Git integration is connected and builds every push to
`main` as a Production deployment. There is no manual step and no CLI command in
the normal path.

```bash
git push origin main
```

Verified on 2026-08-13: a push to `main` produced a Ready production deployment
in 37 seconds.

### What is on `main`, and what is not

When this file was first written, `main` was the only branch and "push to `main`"
was the whole story. That is no longer true, and the gap is large enough that
following the line above from the wrong branch pushes nothing.

Measured at `07c027d` on 2026-08-31:

```
$ git rev-list --left-right --count main...page-blocks
0	33
```

Zero commits on `main` that are not on `page-blocks`; **thirty-three commits on
`page-blocks` that are not on `main`.** `git ls-tree main -- vivid-smiles-website/src/blocks/`
returns nothing at all: the entire block system — sixteen layout components,
`PageBlocks.astro`, `manifest.ts`, `registry.ts` — exists only on the branch.
Local branches are now `main`, `page-blocks` and `cms-editor-safety`, plus nine
that exist only on the remote.

Two consequences worth stating plainly:

- **Nothing described in [Deploying the CMS side](#deploying-the-cms-side) is
  live on the production URL.** The hosted WordPress may already carry the
  schema and the migrated `blocks` data; the front end that reads them does not
  exist in the deployed build. That asymmetry is safe by design — the un-migrated
  template branch still renders every page — but it means a correct-looking
  wp-admin and a correct-looking site can disagree about what has shipped.
- **This runbook has no step for getting branch work to production.** Whoever
  merges `page-blocks` decides the shape of that step (merge to
  `cms-editor-safety` first, or straight to `main`), and it belongs here once
  decided. It is deliberately not guessed at: writing a merge procedure nobody
  has run would be the same class of error as the predictions this file has had
  to correct elsewhere.

Until that merge happens, use a Preview deploy to see branch work rendered.
`WP_GRAPHQL_ENDPOINT` is set for Preview, so a branch build reads the same live
WordPress as Production.

---

## Deploying the CMS side

Everything above deploys the front end. The block system has a second half that
lives on the WordPress host and does **not** travel with a git push: the ACF
schema, the migration engine, and the map that drives it.

This section did not exist in earlier revisions of this file, and its absence
cost real time. The original text documented two ways to put `cms/mu-plugins/`
on the host, and **neither of them is the way it is actually done from this
environment.** What follows is the route that works, in the order it has to
happen.

### The order is not negotiable: PHP first, always

`cms/mu-plugins/vs-content-model.php` (the ACF field definitions) must be on the
host **before** `src/blocks/manifest.ts` selects any new field. Not the other
way round, and not in the same push.

The asymmetry is the whole reason there is a rule, and it is worth understanding
rather than memorising:

- **PHP ahead of Astro is a supported state.** A layout registered in WordPress
  that the Astro side does not know about renders as `UnknownBlock`.
  `scripts/check-block-schema.mjs:515-520` says so directly, and refuses to make
  that condition fatal: *"a gate that fires on normal states is a gate people
  learn to pass with `--force`."*
- **Astro ahead of PHP reddens every route.** The build's capability probe
  (`src/loaders/pages.ts`) sends the real selection set before querying. If the
  server rejects a field, `pages.ts:226` tests whether the rejection names
  `blocks` itself. An error naming `blocks` means the mu-plugin is simply not
  deployed and the build falls back to templates — harmless. **Any other
  "Cannot query field" throws**, at `pages.ts:228-233`:

  > WordPress has the page-sections field, but this build asked it for something
  > it does not have. That is a mismatch between the selection sets in
  > `src/blocks/manifest.ts` and the layouts in
  > `cms/mu-plugins/vs-content-model.php` — most often two layouts sharing a
  > repeater name, which makes WPGraphQL merge their types and drop one side's
  > fields.

  That throw fails the build outright. All 48 routes, not the one that changed.

The probe is deliberately opinion-free about *why* it failed —
`pages.ts:178-180`: *"ANY failure means 'no blocks'. The probe is not allowed to
have an opinion about why it failed"* — which is precisely what makes the
one exception above the dangerous direction.

So: **upload the PHP, confirm it, then push the manifest.**

### Confirming the PHP landed: `npm run check:blocks`

```bash
cd vivid-smiles-website && npm run check:blocks
```

`package.json:15` maps that to `node scripts/check-block-schema.mjs`. It sends
every registered fragment to the deployed schema and reports which ones the
server will not accept. It reads the endpoint the same way the build does
(through vite's `loadEnv`) so a pre-flight cannot pass against staging while the
build talks to production, and it resolves its own paths from `import.meta.url`
rather than `process.cwd()` so it is safe to run from a hook, from CI, or from
the repository root.

Exit codes, from `check-block-schema.mjs:59-63`:

| Code | Meaning |
| --- | --- |
| `0` | Every registered fragment validates. Safe to push the manifest. |
| `1` | At least one does not — **the build would die.** Fix the manifest, or deploy the PHP that backs it. |
| `2` | The check could not be run: no endpoint, CMS unreachable, or `blocks` not deployed yet. |

`2` is distinct from `1` on purpose. The script's own words: *"'your field names
are wrong' and 'the CMS is down' want different responses from whoever is
paged."*

**Decision: `check:blocks` is not chained into `prebuild`, and should not be.**
This is recorded here because it was not written down anywhere in the repository
before now — `grep -rn prebuild` across `docs/`, `scripts/`, `package.json` and
`cms/` finds only the media warmer. The reasoning: exit `2` fires on "cannot
reach the CMS", which is a normal condition for a laptop build, a fresh clone,
or a network blip, and wiring it into `prebuild` would fail builds for a reason
that has nothing to do with the build. It is the same argument
`check-block-schema.mjs:515-520` already makes about advisory findings, applied
one level up. `prebuild` stays exactly one thing:
`node scripts/warm-media-cache.mjs` (`package.json:17`).

Run it by hand after every PHP upload, before the next push. `manifest.ts:307-309`
states the obligation from the other side: the script *"is what confirms it once
the PHP is deployed, and it must be run before the next build."*

### Deploying mu-plugins

Nothing else in this repository puts `cms/mu-plugins/` on the host, and that gap
has cost real time: every CMS-side change stalled on someone hand-copying a file
through a file-manager plugin, and the repo and the host drifted apart quietly
enough that the handoff notes described `vs-deploy.php` as "never uploaded" a
full day after it was uploaded.

That sentence was written as a complaint about the absence of a script. A script
now exists — `cms/bin/deploy-mu-plugins.sh` — and the complaint turned out to be
about something else, because **the script cannot be driven from this working
environment.** `deploy-mu-plugins.sh:148-149`:

```
echo "==> Uploading (sftp will prompt for the password if no key is loaded)"
sftp -P "$PORT" -b "$BATCH" "$USER@$HOST"
```

The password is typed at `sftp`'s own prompt. That is a good security property —
it keeps the credential out of the script, the process list and shell history —
and it is exactly what makes the script unusable to an agent, which has no way to
answer an interactive prompt. With an SSH key loaded there is no prompt and the
script works; without one it hangs.

#### The route that is actually used: WP File Manager in wp-admin

Every CMS-side upload for the block system went through the **WP File Manager**
plugin's browser UI, not through SFTP.

Upload, and when it asks what to do about the existing file, **choose YES to
replace.**

> **Never choose BACKUP.** elFinder writes its backup copy *beside* the
> original, in the same directory. Any extra `.php` file in `wp-content/mu-plugins/`
> is auto-loaded by WordPress on every request, so a backup of a mu-plugin is a
> second copy of the same plugin running at the same time — redeclared functions,
> fatal error, whole site down. Git is the recoverable copy; the host does not
> need one.

Two practical notes from driving the UI:

- The file input does not exist in the DOM until the upload dialog is opened, and
  stale dialogs stack up. Confirm the destination path in the file manager's own
  path bar before confirming the replace — uploading a mu-plugin into the wrong
  directory is how the fatal above happens by accident.
- Directory navigation only works downward from a loaded parent. Walk
  `wp-content` → `vs-import` → `bin` rather than jumping.

**This route depends on a plugin two other documents in this repository
recommend deleting, and that conflict needs resolving by a human.**
`cms/README.md:133` lists WP File Manager 8.0.4 as *"Installed, **inactive**"*,
and `cms/README.md:151-153` argues for removing it: *"Deactivating WP File
Manager, as someone has since done, is not the same as removing it"*, on *"a
plugin with a long history of remote-code-execution bugs."*
`docs/SESSION-HANDOFF.md` puts the same decision on its next-steps list. (No line number:
that file was rewritten in the same pass as this one, so a line citation into it was stale
before either was committed. Cite it by section, and cite CODE by line.)
Both of those observations are stamped 13 August 2026; the uploads described
here happened after. Either the plugin was reactivated in the meantime or those
tables are stale — **the live plugin state cannot be verified from the
repository and is not asserted here.** What can be said is the operational
consequence: acting on `cms/README.md`'s advice and deleting the plugin would
remove the only CMS deploy path that works from this environment, and a
replacement (an SSH key, or host-side `curl`) has to be in place first.

#### The SFTP script, where a key is available

```bash
# NOTE the ssh. in the hostname. SFTP and SSH are NOT on the web hostname —
# 1230613.us28.myftpupload.com serves the site and refuses port 22.
export VS_SFTP_HOST=1230613.us28.ssh.myftpupload.com
export VS_SFTP_USER=<from the GoDaddy dashboard, Settings, SSH/SFTP>

DRY_RUN=1 bash cms/bin/deploy-mu-plugins.sh          # show the plan
bash cms/bin/deploy-mu-plugins.sh                    # every file but vs-config.php
bash cms/bin/deploy-mu-plugins.sh vs-content-model.php   # just one
```

**The script's own error message still disagrees with this.** Commit `de93357`
("Point SFTP at the ssh hostname, not the web one") changed one file — this one.
`deploy-mu-plugins.sh:56` still prints the web hostname in its usage text:

```
export VS_SFTP_HOST=1230613.us28.myftpupload.com
```

Anyone who reaches the script by running it without the variables set is handed
the hostname that refuses port 22. Trust this file, not that message, until the
script is corrected.

No credential is stored or read from this repository. The password is typed at
`sftp`'s own prompt, so it never reaches the script, the process list, or shell
history; with an SSH key loaded there is no prompt at all.

Two behaviours worth knowing:

- **`vs-config.php` is skipped by default** (`deploy-mu-plugins.sh:64-67`, `:83`,
  `:105`). The host's copy defines `VS_DEPLOY_HOOK_URL`, which this public
  repository deliberately does not carry. Overwriting it would delete the
  constant and publishing would silently stop rebuilding the front end —
  `vs-deploy.php` would keep loading and keep returning early. Send it only with
  `VS_OVERWRITE_CONFIG=1`, and re-add the constant afterwards.
- **Every file is read back and compared byte-for-byte after upload**
  (`deploy-mu-plugins.sh:33-39`). A truncated mu-plugin is a fatal error on every
  request to the CMS, so a mismatch fails the run loudly rather than leaving it
  to be discovered later. Nothing in the WP File Manager route does this; when
  uploading through the browser, the check has to be done by hand.

#### Pulling from GitHub on the host

If the host has SSH, pulling from GitHub on the host is shorter than pushing to
it — this repository is public, so no credential is involved at all:

```bash
ssh <user>@1230613.us28.ssh.myftpupload.com
cd ~/html/wp-content/mu-plugins
cp vs-content-model.php ~/vs-content-model.php.bak    # rollback copy, kept OUT of mu-plugins
curl -fsSL https://raw.githubusercontent.com/allanconcepcion/vivid-smile-dentistry-headless/main/cms/mu-plugins/vs-content-model.php -o vs-content-model.php
php -l vs-content-model.php && md5sum vs-content-model.php
```

Note `.bak` is kept **out of** `mu-plugins/` for the same reason BACKUP is
forbidden above: every top-level file in that directory auto-loads.

Note also the `/main/` in that URL. While the block system lives on
`page-blocks`, `main`'s copy of `vs-content-model.php` is the pre-blocks one —
change the ref, or use a different route, until the branch is merged.

Check the sum against the file in this repo before trusting it. This is how
`vs-content-model.php` was deployed on 13 August 2026.

mu-plugins are not activated. A file is live the moment it lands; confirm under
Plugins, Must-Use.

### The hosted files, and which two `deploy-mu-plugins.sh` does not carry

The script copies `mu-plugins/*.php` and nothing else. Of the three files the
block system needs on the host, it carries the first and **not** the other two,
which are therefore **manual uploads, and the runbook has never said so**:

| Repository path | Where it goes on the host |
| --- | --- |
| `cms/mu-plugins/vs-content-model.php` (253 KB) | `wp-content/mu-plugins/vs-content-model.php` |
| `cms/import/block-map.json` (844 KB) | `wp-content/vs-import/bin/block-map.json` |
| `cms/import/backfill-blocks.php` (41 KB) | `wp-content/vs-import/bin/backfill-blocks.php` |

The schema is a `.php` in `mu-plugins/` and the script does carry it. The map and
the engine are not, and `vs-migrate.php:199-201` says why in its own error text:
*"`cms/bin/deploy-mu-plugins.sh` copies `mu-plugins/*.php` only, so this is a
one-off manual upload."*

`vs-migrate.php:118-124` looks for the engine and the map in two directories, in
this preference order:

```
wp-content/mu-plugins/vs-migrate/{backfill-blocks.php,block-map.json}
wp-content/vs-import/bin/{backfill-blocks.php,block-map.json}
```

The first is checked first and is the preferred location; `vs-import/bin` is
what is in use. Either works. `vs-migrate.php:73-75` explains why a
subdirectory of `mu-plugins/` is safe even though the directory auto-loads: **only
top-level files are auto-loaded**, so a `.php` one level down does not start
running on every request.

Both directories are web-readable on this host. That is known and is not a leak:
requested directly, `backfill-blocks.php` defines its functions and stops, and
`block-map.json` holds page copy that is already published. Add a deny rule if
the host offers one.

### Running the back-fill: Tools → Page sections migration

The back-fill that turns a page's existing repeater rows into an ordered
`blocks` list **does not run through WP-CLI on this host.**
`cms/mu-plugins/vs-migrate.php:12-14` records why: the engine *"was written for
`wp eval-file`, and the hosted CMS (GoDaddy Managed WordPress) offers no SSH and
therefore no WP-CLI."* `vs-migrate.php` is the admin screen written to replace
it.

wp-admin → **Tools → Page sections migration** (`vs-migrate.php:520-522`,
`:751`). Administrators only, enforced three separate times.

**Dry run first, every time.** This is not convention — the plugin enforces it.
`vs-migrate.php:50-52`: *"Dry run is the default and a separate button. A request
that names no button, or an unrecognised one, plans and reports; only the button
called `vs_write` writes."* The screen renders two buttons
(`vs-migrate.php:855-857`): "Dry run — show me what it would write", and the
write button beside it.

A dry run reports how many sections would be written and names every repeater row
the map does not claim. Read that list before writing: an unclaimed row is data
that would be dropped.

One more guard, `vs-migrate.php:53-57`: a page whose `blocks` is **already
non-empty is refused** unless a separate checkbox is ticked in the same POST.
The reason is asymmetric reversibility — emptying `blocks` un-migrates a page
with no deploy and no code change, so almost everything here is reversible, *"an
editor's arrangement is the exception, because nothing anywhere records what the
order used to be."*

---

## Where the front-end URL is configured

**`cms/mu-plugins/vs-config.php`** — not `wp-config.php`. The managed host
rewrites `wp-config.php` during platform updates, silently dropping anything
added by hand. That failure is quiet: the constant disappears, the redirect
stops, and the raw WordPress theme starts answering on the CMS hostname
alongside the real site. `wp-content/mu-plugins/` survives those updates.

Locally the constant comes from `cms/.wp-env.json` instead, which loads first;
`vs-config.php` guards with `defined()` so the local value wins.

Verified live on 2026-08-13: `https://1230613.us28.myftpupload.com/` and
`https://1230613.us28.myftpupload.com/about-us/` both 302 to the matching path
on `https://vivid-smiles-headless.vercel.app`, and the CMS `robots.txt` is
`Disallow: /`.

**The redirect is deliberately skipped for signed-in users.** `redirect_frontend()`
in `vs-headless.php` returns early on `is_user_logged_in()` so an editor can
preview a draft. A browser logged into wp-admin therefore gets the WordPress theme
rather than a 302 — test anonymously, or in a private window, before concluding
the redirect has broken.

**`/sitemap_index.xml`, `/page-sitemap.xml` and `/post-sitemap.xml` are not
redirected** and return 200 on the CMS hostname. That is required — the Astro
build fetches them (see [Build-time failure modes](#build-time-failure-modes)).
They are not in the passthrough list in `vs-headless.php`; they survive because
Yoast emits and exits before the redirect hook runs. If a Yoast upgrade ever
breaks the sitemap step of a build, check this first.

---

## What is already handled

**`vercel.json` is generated, not hand-written.** `public/_headers` and
`public/_redirects` are Netlify/Cloudflare syntax and Vercel ignores both. Left
as they were, the site would deploy with no security headers, no immutable
caching on `/_assets/*`, and **65 dead legacy redirects** — every old WordPress
URL 404ing. Re-counted at `07c027d`: `vercel.json` carries exactly 65 redirect
entries.

Regenerate after editing either file:

```bash
cd vivid-smiles-website && npm run vercel:config
```

It is committed because Vercel reads `vercel.json` from the repository, before
any build step could produce it.

**`trailingSlash: true`** is set there to match Astro's `trailingSlash: 'always'`.
Without it the two disagree about the canonical form of every URL, producing
redirect chains and duplicate-content signals.

Confirmed on the production URL on 2026-08-13: the four sitewide security
headers are present, `/about-us` 308s to `/about-us/`, and `/before-and-afters`
resolves through to `/smile-gallery/`.

**Known gap:** `_redirects` and `_headers` are still copied into `dist/` by
Astro and are readable by anyone at `/_redirects/` and `/_headers/`. They leak
nothing secret — every rule in them is observable by requesting the URLs — but
they are build inputs, not site content, and they do not belong on the public
origin. This was raised in
[../vivid-smiles-website/VERCEL-DEPLOYMENT-NOTES.md](../vivid-smiles-website/VERCEL-DEPLOYMENT-NOTES.md)
and only half-fixed: the rules were migrated into `vercel.json`, the exposure
was not addressed.

---

## The CDN warm-up step, and why it exists

`npm run build` runs `scripts/warm-media-cache.mjs` first, via `prebuild`
(`package.json:17`).

Astro downloads every remote image at build time, at high concurrency. The CMS
sits behind Cloudflare, and a few hundred rapid requests come back **429 with
`cf-mitigated: challenge`** — bot protection, not rate limiting. It fires even on
files already cached at the edge, so caching alone does not avoid it, and a
cold build failed on a different image every run.

The warm-up requests every media item once, **three at a time**
(`warm-media-cache.mjs:48`, `CONCURRENCY = 3`), retrying on 429 over five
attempts with backoff (`:49`, `MAX_ATTEMPTS = 5`), then pauses three seconds
(`:52`, `SETTLE_MS = 3000`) before handing over so the build does not start
rendering inside a rate-limit window it opened moments earlier. By the time
Astro's high-concurrency phase runs, every URL is a cache HIT and the origin is
never touched.

It must run **inside** the build: Cloudflare caches per datacenter, so warming
from a laptop does nothing for the colo a CI build runs in. This is measured,
not assumed — the warmer reported every file as a MISS on Vercel where the
identical run reported HIT locally.

An earlier revision of this section gave that measurement as "the 131 media
files" and "`MISS=131`". **The count is not a constant and should not be read as
one.** `warm-media-cache.mjs:80` paginates the media library (`first: 100`, then
follows the cursor); nothing hardcodes a total. 131 was what the library held
when the measurement was taken on 13 August 2026. Re-measure from the warmer's
own output rather than copying that figure forward.

Failures there are non-fatal; Astro retries anything unwarmed.

If image builds start failing after a host change, check this first.

---

## Cloudflare bot protection — outstanding

The warm-up and the retry logic are workarounds, not the fix.

GoDaddy fronts the managed WordPress origin with Cloudflare bot protection that
answers sustained traffic from one IP with **429 and an HTML interstitial**. It
is triggered by cumulative request volume from the build IP and then applies to
whatever the build does next — which is why the failure moves around. Two
production deploys errored on it: once on an image, once on the footer-menu
GraphQL query while rendering the first page, after every earlier query had
already succeeded.

Two mitigations are in place and both are load-bearing:

- `scripts/warm-media-cache.mjs` pulls media at low concurrency before Astro's
  parallel phase.
- `src/lib/wp.ts:74-80` treats 429 as **retryable** — unlike other 4xx, which are
  permanent — honouring `Retry-After` when present and otherwise backing off
  quadratically (`:122-125`, `attempt * attempt * 1500`) over five attempts
  (`:21`, `MAX_ATTEMPTS = 5`). All re-verified at `07c027d`.

**The durable fix is still outstanding: relax the Cloudflare rules on the CMS
hostname for `/wp-content/uploads/` and `/graphql`.** Those two paths are the
build's entire surface area, they are read-only, and they are hit by a known
caller. Until that happens the build's reliability depends on staying under a
threshold nobody controls, and a larger media library or a slower colo can push
it back over.

---

## Build-time failure modes

The loaders fail loudly rather than publishing something that looks fine:

| Situation | What happens |
| --- | --- |
| `WP_GRAPHQL_ENDPOINT` unset | Build fails with setup instructions |
| WordPress unreachable, 5xx, or 429 | 5 attempts with backoff, then the build fails |
| Zero posts/reviews/pages returned | Build fails — an empty result is far more likely to be a broken query than deleted content |
| A GraphQL query returns errors | Build fails immediately; query errors are deterministic and retrying only wastes time |
| `manifest.ts` selects a field the deployed PHP does not have | **Build fails on every route.** See below — this is the newest way a build dies |
| `blocks` is absent from the schema entirely | Not a failure. The probe falls back to templates and logs it |
| A post is missing its hero image or alt text | Depends on the branch. See below |
| Yoast's sitemap lists a URL with no page | That entry is left out of the written sitemap, with a warning naming it |

### The manifest/PHP mismatch

This row is new and it is the loudest failure in the list. The capability probe
in `src/loaders/pages.ts` distinguishes two rejections that WPGraphQL words
identically:

- an error naming `blocks` itself → the mu-plugin is not deployed; fall back to
  templates, log it, build succeeds (`pages.ts:226`, `:239`);
- **any other** `Cannot query field` → throw, and the build stops
  (`pages.ts:228-233`).

The second is what a manifest pushed ahead of its PHP produces. It is not
recoverable at build time and it takes all 48 routes with it. The prevention is
[the ordering rule and `check:blocks`](#the-order-is-not-negotiable-php-first-always).

The probe cannot catch everything: a `blocks` row naming a layout the PHP no
longer registers resolves at execution rather than validation, so it is invisible
to the probe and the build fails. Only a developer deleting a layout can cause
that — an editor cannot author a row for a layout that is not offered — so it is
left to fail loudly rather than guessed around.

### Missing hero image or alt text: the two branches disagree

An earlier revision of this table said *"That post is skipped with a warning
naming it."* That is **still true of `main`, which is what is deployed** —
`git show main:…/src/loaders/blog.ts` has one ternary chain of seven conditions
feeding a single `logger.warn(...)` and `continue`.

It is **no longer true on `page-blocks`.** `src/loaders/blog.ts:18-19` at
`07c027d`: *"This loader never drops a post. Not for a missing hero, missing alt
text, an unexpected category, a missing date, or a missing title."* And
`:20-21`: *"It used to. Seven conditions each logged a warning and `continue`d."*

The change was deliberate and the reason is a deploy-pipeline reason, which is
why it belongs in this file: `vs-deploy.php` fires the deploy hook on
`transition_post_status`, so the person who causes a post to be dropped is an
editor who never sees the Vercel build log. A post could read "Published" in
wp-admin and simply not exist on the website, with the only trace in a log
nobody reads.

Current behaviour on the branch: a missing hero gets `HERO_PLACEHOLDER`, an
inline SVG data URI (`blog.ts:132-140`, `:376-378`); a missing alt emits
`warn({ code: "no_alt", … })` (`blog.ts:465-473`). The post publishes either way,
with the degradation visible on the page.

**Until `page-blocks` merges, production skips those posts.** Do not read the
branch's behaviour back onto the live site.

### The sitemap row

Dropping an unmatched entry is deliberate: a sitemap full of 404s spends crawl
budget and signals a broken site, so the entry is dropped instead of shipped
(`src/integrations/yoast-sitemap.ts:15-20`). Nothing is written until every entry
has been checked. It used to fail the build; it no longer can, because WordPress
now triggers builds itself and an editor creating a page must not be able to
break a deploy. Dropping the entry is not the same as fixing it — the page still
does not exist on the front end until someone adds a route for it, or marks it
noindex so WordPress stops listing it.

If a sitemap cannot be fetched at all — the CMS is down, or Cloudflare keeps
answering 429 after five attempts — that step warns and keeps the Astro-generated
sitemap rather than shipping none.

---

## Outstanding before launch

| Item | Status |
| --- | --- |
| Merge the block system to `main` | **Not done.** 33 commits on `page-blocks`, none on `main` — see [What is on `main`, and what is not](#what-is-on-main-and-what-is-not) |
| Make the GitHub repository private | **Not done.** See below |
| Move the CMS to `cms.vividsmilesdentistry.com` | Not done — see [Moving the CMS to its permanent hostname](#moving-the-cms-to-its-permanent-hostname) |
| Relax Cloudflare bot rules for `/wp-content/uploads/` and `/graphql` | Not done — see [Cloudflare bot protection](#cloudflare-bot-protection--outstanding) |
| Decide the fate of WP File Manager | **Blocked on a replacement deploy path.** `cms/README.md:151-153` says delete it; it is the route the CMS side is deployed through. See [The route that is actually used](#the-route-that-is-actually-used-wp-file-manager-in-wp-admin) |
| Correct the hostname in `deploy-mu-plugins.sh:56` | Not done — the script's usage text still names the web hostname, which refuses port 22 |
| Wire a Vercel deploy hook and fire it from WordPress | **Done, verified 13 August 2026** — see [Wire the deploy hook](#wire-the-deploy-hook) |
| Cut `vividsmilesdentistry.com` over to Vercel | Not done — DNS still points at the old host |
| Stop shipping `_redirects` and `_headers` into `dist/` | Not done |
| Confirm the Facebook URL | `facebook.com/VivdSmiles/` appears to be missing an `i`. It is stored in two places: `src/components/Footer.astro` and `cms/import/import-wp-settings.php`, plus the live WordPress option |

### Make the repository private

The repository is public and it should not be. Re-counted at `07c027d`: it
carries **661 files** under `cms/uploads/`, including identifiable patient
photographs, and a **2.3 MB** database dump of the client's entire site at
`cms/backup/database.sql`.

`cms/backup/database.sql` no longer contains `wp_users` or `wp_usermeta` —
`backup.sh` now excludes both at the table level — but **the password hash
remains in this repository's git history**, so making the repository private
does not retire it. Any install restored from an older dump needs its password
rotated.

The public repository is also load-bearing in one place, which is easy to forget
when flipping the switch: the [host-side `curl` route](#pulling-from-github-on-the-host)
fetches raw files from GitHub with no credential. Making the repository private
breaks that route, leaving WP File Manager and SFTP.

### Wire the deploy hook

The Vercel project has a deploy hook named **WordPress publish** pointing at
`main` (Settings, Git, Deploy Hooks), and `cms/mu-plugins/vs-deploy.php` calls it
when a post, page or testimonial becomes public, stops being public or is edited
while public, when a nav menu changes, and when Practice Settings are saved. It
debounces through WP-Cron: the first change schedules one event two minutes out
and later changes reuse it, so a session of editing produces one build rather
than a queue of builds that cancel each other.

**Both remaining steps are done, verified 13 August 2026.** `vs-deploy.php` is on
the host — wp-admin lists it under Plugins, Must-Use, as "Vivid Smiles — Deploy
trigger" — and `VS_DEPLOY_HOOK_URL` is defined in
`wp-content/mu-plugins/vs-config.php`. Publishing a page raised the plugin's own
"Front-end rebuild queued" notice, which only renders when the cron event is
scheduled, and `queue()` only schedules it when the constant is defined. Two
minutes later Vercel recorded a Production deployment labelled `Created: Deploy
Hook`. Trashing the page fired it again.

The two steps were:

1. Upload `cms/mu-plugins/vs-deploy.php` into `wp-content/mu-plugins/`. See
   [Deploying mu-plugins](#deploying-mu-plugins).
2. Add the hook URL to `wp-content/mu-plugins/vs-config.php`:

   ```php
   define( 'VS_DEPLOY_HOOK_URL', 'https://api.vercel.com/v1/integrations/deploy/...' );
   ```

   That line exists only on the host, which is why the deploy script refuses to
   overwrite `vs-config.php` unless explicitly told to.

Copy the value from Vercel with the Copy button next to the hook. It is a
credential — anyone holding it can start builds — so it does not belong in this
repository, which is public. With the constant undefined the plugin loads and
does nothing, which is what a local or staging copy should do.

Three caveats now, not two. Media library changes fire none of these hooks, so
replacing an image needs the page re-saved or a build started by hand. The
trigger relies on WP-Cron, so if the host ever disables it the event will sit
unfired; the recorded outcome of the last attempt is in the
`vs_deploy_last_result` option. And the hook points at `main`, so **an editor
saving a page today rebuilds the template-branch site**, not the block system —
another consequence of [the branch gap](#what-is-on-main-and-what-is-not), and
the reason a wp-admin edit can look like it did nothing.

---

## Moving the CMS to its permanent hostname

The current hostname is GoDaddy's temporary one. Moving to
`cms.vividsmilesdentistry.com`:

```bash
# On the host: restore the content
bash cms/bin/restore.sh https://cms.vividsmilesdentistry.com
```

Pass the target URL. The dump was taken against `http://localhost:8888`
(`cms/backup/SITEURL` records this), and `restore.sh` runs
`wp search-replace` rather than editing the SQL, because WordPress stores
serialized PHP in `wp_options` with string lengths encoded alongside the values.
A plain find/replace corrupts every serialized option whose URL changes length.
See [../cms/README.md](../cms/README.md).

- Copy `cms/uploads/` to the host's `wp-content/uploads/`.
- Deploy `cms/mu-plugins/` to `wp-content/mu-plugins/` — see
  [Deploying mu-plugins](#deploying-mu-plugins).
- Copy `cms/import/backfill-blocks.php` and `cms/import/block-map.json` to
  `wp-content/vs-import/bin/`. They are not `.php` files in `mu-plugins/`, so no
  script carries them — see
  [The hosted files, and which two `deploy-mu-plugins.sh` does not carry](#the-hosted-files-and-which-two-deploy-mu-pluginssh-does-not-carry).
- Run the equivalent of `cms/bin/setup.sh` to install the pinned plugins.
- Change `VS_FRONTEND_URL` in `cms/mu-plugins/vs-config.php` to
  `https://vividsmilesdentistry.com` and re-upload that one file.
- Update `WP_GRAPHQL_ENDPOINT` in the Vercel project and add the new hostname to
  `image.remotePatterns` in `astro.config.mjs`.

Confirm before moving on:

```bash
curl -sS -X POST https://cms.vividsmilesdentistry.com/graphql \
  -H 'Content-Type: application/json' \
  -d '{"query":"{ posts(first:1){ nodes { slug } } }"}'
```

Then confirm the block schema came across, before any build points at the new
host:

```bash
cd vivid-smiles-website && npm run check:blocks
```

Exit `0` means the migrated `blocks` data and the field definitions both
survived the move. Exit `2` means the check could not reach the CMS at all,
which at this point in the procedure is itself the answer.

---

## Cutting over the domain

Do this only after a production deployment renders correctly on the Vercel URL.

[CUTOVER-PROMPT.md](CUTOVER-PROMPT.md) is this section rewritten as a briefing to
hand a fresh session — same steps, ordered, with the three failure modes that are
silent pulled to the top. Use it rather than reciting this section from memory.

### DNS, and the records that must not be touched

`vividsmilesdentistry.com` is registered at GoDaddy. DNS is hosted at Cloudflare
on nameservers `beth.ns.cloudflare.com` and `dan.ns.cloudflare.com`. The apex
and `www` currently resolve to Cloudflare proxy addresses in front of the old
host.

**Email runs on Google Workspace through the same Cloudflare zone.** A DNS
migration or a tidy-up that drops any of the following will break the practice's
email. Verified present on 2026-08-13:

| Record | Value |
| --- | --- |
| `MX` | `1 smtp.google.com` |
| `TXT` (SPF) | `v=spf1 include:_spf.google.com -all` |
| `TXT` at `_dmarc` | `v=DMARC1; p=quarantine; adkim=r; aspf=r; rua=mailto:dmarc_rua@onsecureserver.net;` |
| `TXT` (Google Search Console) | `google-site-verification=BHnZIy4HsTfvGohSkKYzlNe0zSlg852c_ocprBdyTWY` |
| `TXT` (Apple) | `apple-domain-verification=zCeLhNWshJWildt7` |

Those last two are published DNS records, readable by anyone with `dig`. They
are verification tokens, not credentials, and they are reproduced here because a
migration needs to recreate them exactly.

**Attaching the site to Vercel never requires modifying any of them.** Only the
apex `A` record and the `www` record change. If a step in any runbook — this one
or a registrar's — asks you to delete records or replace the whole zone, stop.

### Pre-flight

1. `vercel.json` is committed with all redirects, headers and `trailingSlash`,
   and a production deploy has been made with it. Done.
2. Spot-check several legacy redirects on the `.vercel.app` URL.
3. Confirm the call-tracking vendor with the practice. The script at
   `s.ksrndkehqnwntyxlhgto.com/162233.js` is **WhatConverts dynamic number
   insertion, profile 162233** — identified in `BaseLayout.astro:106` and
   `LandingLayout.astro:128` and disclosed by name in the privacy policy. It
   rewrites the displayed phone number and POSTs visitor data offsite, so confirm
   the account is the practice's own before launch.
4. Note the current host's configuration so a rollback is possible.
5. Lower the TTL on the apex and `www` records about 24 hours in advance, so a
   rollback takes minutes rather than a day.
6. Decide whether the block system is cutting over with the domain. If
   `page-blocks` has not merged, the domain will point at the template render —
   which is correct and complete, but it is not what wp-admin's Page sections
   screens suggest is live.

### Cutover

1. In Vercel: project → Settings → Domains → Add Existing. Add
   `vividsmilesdentistry.com` and `www.vividsmilesdentistry.com`, connected to
   Production.
2. Vercel displays the exact DNS target values. **Use the values Vercel shows at
   that moment**, not any value written here — they change.
3. In Cloudflare, add or update **only** the apex and `www` records. Leave every
   record in the table above alone.
4. Set the Vercel records to **DNS only** (grey cloud) unless Cloudflare
   proxying has been deliberately configured to work with Vercel. Proxying on
   both sides causes redirect loops and certificate-issuance failures.
5. Decide whether the apex or `www` is canonical and set the other to redirect to
   it in Vercel's domain settings.
6. Wait for Vercel to show Valid Configuration and for the certificate to issue.
7. Update `VS_FRONTEND_URL` in `cms/mu-plugins/vs-config.php` to the real domain
   and re-upload it. Until you do, anyone landing on the CMS hostname is sent to
   the `vercel.app` address instead of the live site.
8. Verify: the homepage loads over HTTPS, several legacy redirect URLs land on
   the right pages, `/robots.txt` and `/sitemap_index.xml` resolve, and the four
   security headers are present.
9. Confirm `site` in `astro.config.mjs` and the emitted sitemap both say
   `https://vividsmilesdentistry.com/`.
10. Submit `https://vividsmilesdentistry.com/sitemap_index.xml` in Search Console
    and Bing Webmaster Tools. That is the same path the old WordPress site used,
    so an existing submission keeps working; the build also writes
    `sitemap-index.xml` (`yoast-sitemap.ts:226`) for anything referencing the
    hyphenated form.
11. Re-check the Microsoft Clarity and Google Ads tags. Both
    `clarity.ms/tag/vkjzesavnp` and the GTM-fired POST to
    `process.iconnode.com/google-ads/` returned 503 during the 2026-08-11 audit;
    both returned 200 when re-checked on 2026-08-13, so that looks transient
    rather than a domain rejection. Confirm again once the real domain is live.
12. Confirm `https://cms.vividsmilesdentistry.com/robots.txt` is `Disallow: /` —
    the CMS must never be indexed alongside the real site.

### Rollback

Revert the apex and `www` records in Cloudflare to the previous host's values.
Because only those two records change, email is unaffected either way.
