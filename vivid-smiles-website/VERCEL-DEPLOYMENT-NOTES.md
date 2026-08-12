# Vercel Deployment Notes

Working notes for the Vivid Smiles Dentistry site on Vercel.
Last updated: 2026-08-11. Status: deployed to a temporary .vercel.app URL, real domain NOT yet cut over.

---

## 1. What is deployed

| Item | Value |
| --- | --- |
| Repo | allanconcepcion/vivid-smiles-website |
| Branch | main (commit de7926d) |
| Vercel team | Allan's projects (Hobby plan) |
| Vercel project | vivid-smiles-website |
| Framework preset | Astro (auto-detected) |
| Build command | `npm run build` (Vercel default for the preset) |
| Output directory | `dist` |
| Root directory | `./` |
| Install command | default |
| Node version | 22.12+ (pinned in `.nvmrc`) |
| Environment variables | none |

No build settings were overridden. The first build succeeded with one non-fatal warning.
Pushes to `main` now auto-deploy to production.

## 2. Live URLs

- Primary: https://vivid-smiles.vercel.app
- Also attached (original auto-generated alias): https://vivid-smiles-website.vercel.app
- Immutable deployment alias: vivid-smiles-website-fau5a5pl7-allans-projects-cc55d7b7.vercel.app

Both domains are connected to the Production environment and serve the same build.
Remove the older one if you only want a single URL, or rename the project to `vivid-smiles` so the auto-generated alias matches.

Vercel dashboard: https://vercel.com/allans-projects-cc55d7b7/vivid-smiles-website

## 3. Page audit result: all passing

Enumerated 48 routes = 42 from the deployed sitemap + 6 noindex pages excluded from it
(`/design-system/`, `/cosmetic-dentistry-lp/`, `/veneers-lp/`, `/general-lp/`, `/thank-you/`, `/404/`).

- All 47 real pages returned HTTP 200 with a populated title tag and full HTML payloads (37 KB to 151 KB).
- `/404/` correctly returns a 404 status.
- Coverage: homepage, 15 top-level sections, 6 cosmetic-dentistry sub-pages, 5 implant-dentistry sub-pages, blog index + 14 posts, plus the 6 noindex pages.
- Rendering verified on a service page, a blog post, the smile gallery, contact, and a landing page.
- All `/_assets/*` files, fonts and stylesheets returned 200. Zero broken images (44 checked on one landing page).
- GSAP scroll reveals and Lenis smooth scrolling work. Typeform embeds initialize on contact and the landing pages.
- No JavaScript console errors on any page checked.
- Re-verified 10 representative routes after adding the new domain: all 200.

## 4. Outstanding issues to fix before the real domain goes live

### 4a. Redirects and headers are inactive (highest priority)

`public/_redirects` and `public/_headers` use Netlify / Cloudflare format. Vercel ignores them, so right now:

- About 65 legacy WordPress redirects are NOT firing. DEPLOYMENT.md says these carry real search traffic.
- Security and cache headers are NOT applied: HSTS, X-Frame-Options, X-Content-Type-Options, referrer policy, and the one-year immutable cache on `/_assets/*`.
- Both files are also being served publicly as readable text at `/_redirects` and `/_headers`.

Fix: translate them into a `vercel.json` at the repo root. See section 5.

### 4b. Trailing slashes are not enforced

`astro.config.mjs` forces trailing slashes on every route, but Vercel serves `/about-us` with a 200 instead of redirecting to `/about-us/`. Every page is therefore reachable at two URLs. Fix with the trailingSlash option in `vercel.json`.

### 4c. Two third-party requests returning 503

- https://www.clarity.ms/tag/vkjzesavnp (Microsoft Clarity) returned 503.
- https://process.iconnode.com/google-ads/ (POST, fired from the GTM container) returned 503.

These may simply be rejecting an unrecognised domain. Re-check after the real domain is attached.

### 4d. Undocumented tracking script: CONFIRM THIS IS YOURS

A script from s.ksrndkehqnwntyxlhgto.com/162233.js loads early on every page and POSTs to p.ksrndkehqnwntyxlhgto.com/keyword/. It swaps the displayed phone number from (303) 841-5313 to (720) 617-0331, which is behaviour typical of a call-tracking or dynamic number insertion product.

It is NOT among the services listed in DEPLOYMENT.md, which documents GTM GTM-W5FBTHCQ, Clarity vkjzesavnp, Ahrefs via GTM, Google Ads conversions, Typeform, and Bing verification. Verify this vendor is authorised before launch.

## 5. vercel.json still needs to be written

Not created yet. It should cover, at minimum:

- trailingSlash set to true
- every rule from `public/_redirects` converted into the redirects array, using source, destination, and permanent true for 301s
- every rule from `public/_headers` converted into the headers array, including the `/_assets/*` immutable cache rule
- optionally block public access to `/_redirects` and `/_headers`, or delete them once migrated

Vercel reference: https://vercel.com/docs/project-configuration

## 6. Runbook: cutting over to the real domain

Domain: vividsmilesdentistry.com. Registered at GoDaddy. DNS hosted at Cloudflare on nameservers beth.ns.cloudflare.com and dan.ns.cloudflare.com.

### DO NOT TOUCH these DNS records

Email runs on Google Workspace through the same Cloudflare zone. A DNS migration or cleanup that drops any of these will break email:

- MX record pointing at smtp.google.com
- SPF TXT record: v=spf1 include:_spf.google.com -all
- the _dmarc TXT record
- two verification TXT records, one for Google Search Console and one for Apple

Website changes never require modifying any of these.

### Pre-flight checklist

1. vercel.json is committed with all redirects, headers and trailingSlash. See section 5.
2. A production deploy has been made with that file, and the redirects have been spot-checked on the .vercel.app URL.
3. Confirm the tracking vendor in section 4d.
4. Note the current host's configuration so a rollback is possible.
5. Lower the TTL on the existing DNS records about 24 hours in advance to make rollback fast.

### Cutover steps

1. In Vercel: project, then Settings, then Domains, then Add Existing. Enter vividsmilesdentistry.com and www.vividsmilesdentistry.com, connected to Production.
2. Vercel will display the exact DNS target values to use. Use the values Vercel shows at that moment rather than any value written here.
3. In Cloudflare, add or update ONLY the apex and www records to point at Vercel's targets. Leave every record in the DO NOT TOUCH list alone.
4. Cloudflare proxy status: set the Vercel records to DNS only (grey cloud) unless you have deliberately configured Cloudflare proxying to work with Vercel. Proxying on both sides can cause redirect loops and certificate issues.
5. Decide whether the apex or www is canonical, and set the other to redirect to it in Vercel's domain settings.
6. Wait for Vercel to show Valid Configuration and for the SSL certificate to issue.
7. Verify: homepage loads over HTTPS, several legacy redirect URLs land on the right pages, /sitemap-0.xml and /robots.txt resolve, and the security headers are present.
8. Confirm astro.config.mjs canonical URL and the sitemap still emit https://vividsmilesdentistry.com/.
9. Re-submit the sitemap in Google Search Console and Bing Webmaster Tools, and re-check the Clarity and Google Ads tags from section 4c.

### Rollback

Revert the apex and www DNS records in Cloudflare to the previous host's values. Because only those two records change, email is unaffected either way.

## 7. Not done yet

- No vercel.json written.
- Real domain not attached. DNS untouched.
- The older vivid-smiles-website.vercel.app domain is still attached alongside the new one.
- Redirects and headers not migrated.
