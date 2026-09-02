# Vivid Smiles headless — read before doing anything

This repo is a headless WordPress + Astro site for a dental practice. The
owner's goal, verbatim: **"everything easily edited on WordPress and reflect to
actual Astro frontend, from pages to posts and menus, everything"** — and the
editing screens must make sense to a non-technical staff member.

**Start with `docs/SESSION-HANDOFF.md`.** It is the state of the project as of
the commit that last touched it, written so a fresh session of ANY model does
not start over. Trust its `git log` pointer over its prose if they disagree.

## Non-negotiables (each one cost something real)

1. **No credentials in the repo, ever.** It is public. Env var NAMES are fine;
   values are not. A WordPress password hash is already in git history and has
   not been rotated — do not add to that.
2. **PHP ships before the manifest selects.** `cms/mu-plugins/vs-content-model.php`
   must be on the host before `src/blocks/manifest.ts` or `src/loaders/pages.ts`
   asks for a new field, or all 48 routes fail to build. New page-level groups
   are gated on a capability probe so order is safe — keep it that way.
3. **`php -l` before any mu-plugin upload.** A parse error in `mu-plugins/`
   takes down wp-admin. Deploy through WP File Manager in wp-admin, choose
   **YES** to replace, **never BACKUP** (the backup lands beside the original
   and auto-loads).
4. **The bar for any change to a live route:** all 48 built routes byte-identical
   unless the change is the point — measured with the four sweeps in the
   handoff (words, section classes, head/eyebrow modifiers, raw `<body>`) plus
   computed styles in a browser. Reasoning about the cascade has been wrong
   here four times; measuring never has.
5. **A field declared, selected, and read by nothing is this project's #1
   defect** — and so is a registered select VALUE with no branch in the
   component. Ship the consumer in the same commit, and prove it with a
   forced-value smoke into `dist/`.
6. **The un-migrated `else` branch in every template is the rollback path.**
   Emptying `blocks` in wp-admin must always render the page from its sheet.
   Do not delete page CSS that branch still needs.
7. **Whitespace is a real diff.** Astro keeps text nodes between expressions;
   new conditionals inside an existing run go flush (`}{`).
8. **Commit messages are the engineering record.** Merge with merge commits,
   never squash. Say what was MEASURED, cite `file:line`, record what
   diverged from the plan.
9. **The CMS domain is `cms.vividsmilesdentistry.com`.** SFTP is
   `1230613.us28.ssh.myftpupload.com` — GoDaddy infrastructure, not the CMS
   domain; the web host refuses port 22.
10. **Newbie language in wp-admin.** Labels/instructions/messages must make
    sense to a dental receptionist. Teach `<em>` with visible entities. Never
    rename a stored field NAME — the GraphQL contract must not move.

## Tooling caveats for agents

- Chrome MCP: several browsers are connected; when asked, choose "send a
  Connect prompt to all" — it lands on the one named **work**, which is signed
  into wp-admin and Vercel.
- The `rtk` Bash hook rewrites/summarises commands. Two subagents saw `diff`
  report "identical" on differing files. Measurements here are done in
  `python3` reading files directly for that reason; the `/opt/homebrew/bin/git`
  binary is used to bypass aliasing where exactness matters.
- Subagents that write to the shared tree collide. One writer per file;
  verifiers stash/restore and must leave the tree byte-exact.
- `vivid-smiles-website/.env` is untracked. Copy `.env.example`.
