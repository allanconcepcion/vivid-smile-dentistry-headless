#!/usr/bin/env bash
#
# Copy cms/mu-plugins/ to the host's wp-content/mu-plugins/, over SFTP.
#
#   bash cms/bin/deploy-mu-plugins.sh                 # everything
#   bash cms/bin/deploy-mu-plugins.sh vs-config.php   # one file
#   DRY_RUN=1 bash cms/bin/deploy-mu-plugins.sh       # say what would happen
#
# WHY THIS EXISTS
#
# Nothing else in this repository puts mu-plugins on the host. Every CMS-side
# change therefore stalled at the same place: someone had to hand-copy a file
# through a file-manager plugin, which is slow, unrepeatable, and leaves the
# repo and the host quietly disagreeing about what is running. That gap is how
# vs-deploy.php came to be described in the docs as "never uploaded" when it had
# in fact been on the host for a day.
#
# The domain cutover in docs/DEPLOYING.md already lists "Deploy cms/mu-plugins/
# to wp-content/mu-plugins/" as a manual step. This is that step.
#
# CREDENTIALS
#
# None are stored, and none are read from this repository. Host and user come
# from the environment; the password is typed at sftp's own prompt, so it never
# reaches this script, the process list, or your shell history. Use an SSH key
# instead if the host offers one — sftp will simply not prompt.
#
#   export VS_SFTP_HOST=... VS_SFTP_USER=...
#
# For GoDaddy Managed WordPress these are in the hosting dashboard under the
# site's Settings, SFTP. VS_SFTP_PATH defaults to the layout that host uses.
#
# WHAT IT VERIFIES
#
# Uploading is not the same as having uploaded. After the transfer each file is
# read back off the host and compared byte-for-byte with the local copy. A
# mismatch is a failure, loudly, listing the files that differ — a partial or
# silently-truncated upload of a mu-plugin is a fatal error on every request to
# the CMS, so it is not something to discover later.

set -euo pipefail

cd "$(dirname "$0")/.."

SRC="mu-plugins"
HOST="${VS_SFTP_HOST:-}"
USER="${VS_SFTP_USER:-}"
PORT="${VS_SFTP_PORT:-22}"
DEST="${VS_SFTP_PATH:-./html/wp-content/mu-plugins}"
DRY_RUN="${DRY_RUN:-0}"

if [[ -z "$HOST" || -z "$USER" ]]; then
  cat >&2 <<'MSG'
ERROR: VS_SFTP_HOST and VS_SFTP_USER must be set.

  # GoDaddy's SFTP endpoint — infrastructure, deliberately NOT the CMS domain.
# cms.vividsmilesdentistry.com serves the site and refuses port 22; only the
# ssh. hostname answers SFTP. (This line used to print the web hostname — the
# exact mistake docs/DEPLOYING.md warned about — corrected 2026-09-01.)
export VS_SFTP_HOST=1230613.us28.ssh.myftpupload.com
  export VS_SFTP_USER=<your sftp user>
  bash cms/bin/deploy-mu-plugins.sh

Optional: VS_SFTP_PORT (default 22), VS_SFTP_PATH (default ./html/wp-content/mu-plugins).
MSG
  exit 1
fi

# vs-config.php is deliberately NOT in the default set.
#
# The host's copy carries VS_DEPLOY_HOOK_URL, which is a credential and is
# therefore absent from this public repository on purpose — see that file's own
# comments. Copying the repo version over it would delete the constant, and
# nothing would appear to break: vs-deploy.php would still load, still return
# early from queue(), and publishing would silently stop rebuilding the front
# end. That is the exact bug this project already spent a session chasing.
PROTECTED="vs-config.php"

# Which files. Named arguments must exist; with none, every .php but PROTECTED.
FILES=()
if [[ $# -gt 0 ]]; then
  for name in "$@"; do
    if [[ ! -f "$SRC/$name" ]]; then
      echo "ERROR: $SRC/$name does not exist." >&2
      exit 1
    fi
    if [[ "$name" == "$PROTECTED" && "${VS_OVERWRITE_CONFIG:-0}" == "0" ]]; then
      cat >&2 <<MSG
ERROR: refusing to overwrite $PROTECTED.

The host's copy defines VS_DEPLOY_HOOK_URL and this repository's copy does not,
because it is a credential and this repository is public. Overwriting it stops
WordPress rebuilding the front end, and does so silently.

If you mean it, plan to re-add the constant on the host afterwards, then:

  VS_OVERWRITE_CONFIG=1 bash cms/bin/deploy-mu-plugins.sh $PROTECTED
MSG
      exit 1
    fi
    FILES+=("$name")
  done
else
  while IFS= read -r path; do
    name="$(basename "$path")"
    [[ "$name" == "$PROTECTED" ]] && continue
    FILES+=("$name")
  done < <(find "$SRC" -maxdepth 1 -name '*.php' | sort)
  echo "==> Skipping $PROTECTED — the host copy holds VS_DEPLOY_HOOK_URL"
fi

if [[ ${#FILES[@]} -eq 0 ]]; then
  echo "ERROR: no .php files found in $SRC." >&2
  exit 1
fi

echo "==> ${#FILES[@]} file(s) to $USER@$HOST:$DEST"
for name in "${FILES[@]}"; do
  printf '    %-28s %6s bytes\n' "$name" "$(wc -c < "$SRC/$name")"
done

if [[ "$DRY_RUN" != "0" ]]; then
  echo "==> DRY_RUN set; nothing sent."
  exit 0
fi

# Checked here rather than at the top so a dry run still works on a machine
# with no SSH client — the point of a dry run is to see the plan.
if ! command -v sftp >/dev/null 2>&1; then
  echo "ERROR: sftp not found. Install an OpenSSH client." >&2
  exit 1
fi

# A mu-plugin that half-arrives takes the site down, so the readback below is
# not optional. Keep it next to the upload rather than as a separate command
# someone can forget to run.
VERIFY_DIR="$(mktemp -d)"
trap 'rm -rf "$VERIFY_DIR"' EXIT

BATCH="$(mktemp)"
{
  echo "cd $DEST"
  for name in "${FILES[@]}"; do
    echo "put $SRC/$name $name"
  done
  for name in "${FILES[@]}"; do
    echo "get $name $VERIFY_DIR/$name"
  done
  echo "bye"
} > "$BATCH"

echo "==> Uploading (sftp will prompt for the password if no key is loaded)"
sftp -P "$PORT" -b "$BATCH" "$USER@$HOST"
rm -f "$BATCH"

echo "==> Verifying by reading each file back"
FAILED=()
for name in "${FILES[@]}"; do
  if [[ ! -f "$VERIFY_DIR/$name" ]]; then
    FAILED+=("$name (not readable on the host)")
  elif ! cmp -s "$SRC/$name" "$VERIFY_DIR/$name"; then
    FAILED+=("$name (differs: local $(wc -c < "$SRC/$name") bytes, host $(wc -c < "$VERIFY_DIR/$name") bytes)")
  else
    printf '    OK  %s\n' "$name"
  fi
done

if [[ ${#FAILED[@]} -gt 0 ]]; then
  echo >&2
  echo "ERROR: ${#FAILED[@]} file(s) did not land intact:" >&2
  printf '    %s\n' "${FAILED[@]}" >&2
  echo >&2
  echo "The CMS may be broken right now. Re-run this script, or restore the" >&2
  echo "affected file from git and upload that." >&2
  exit 1
fi

echo "==> ${#FILES[@]} file(s) deployed and verified."
echo
echo "mu-plugins are not activated — they are live already. Confirm in wp-admin"
echo "under Plugins, Must-Use."
