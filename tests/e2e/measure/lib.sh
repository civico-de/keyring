# shellcheck shell=bash
# Shared helpers for the keyring regression suites. Source right after
# `set -uo pipefail`:
#
#   . "$(dirname "$0")/lib.sh"
#
# Provides the env knobs, PASS/FAIL bookkeeping (check/finish), the cv and
# curl plumbing, and token minting. Tokens never reach a command line: curl
# reads its whole configuration, headers included, from stdin.

COMPOSE_DIR="${COMPOSE_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.docker" && pwd)}"
BASE_URL="${BASE_URL:-http://localhost:8100}"
OUT_DIR="${OUT_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/out}"
mkdir -p "$OUT_DIR"

PASS=0
FAIL=0

check() { # check <name> <condition-result(0/1)> [detail]
  if [ "$2" = "0" ]; then
    echo "  ✔ $1"; PASS=$((PASS + 1))
  else
    echo "  ✘ $1  — ${3:-}"; FAIL=$((FAIL + 1))
  fi
}

note() { echo "  · $*"; }

finish() {
  echo
  echo "Result: $PASS passed, $FAIL failed"
  [ "$FAIL" = 0 ]
}

cvx() { # cvx <cv args...>
  (cd "$COMPOSE_DIR" && docker compose exec -T app cv "$@" 2>/dev/null)
}

appsh() { # appsh <shell code> — run in the app container
  (cd "$COMPOSE_DIR" && docker compose exec -T app bash -c "$1")
}

sql() { # sql <query> — PII-free queries only: ids, counts, metadata
  (cd "$COMPOSE_DIR" && docker compose exec -T db mysql -N -B -ucivicrm -pcivicrm civicrm -e "$1" 2>/dev/null)
}

site_key() {
  cvx ev 'echo CIVICRM_SITE_KEY;'
}

# ── requests ─────────────────────────────────────────────────────────────
# hit <label> <url> [extra curl config lines...]
#
# The token is passed through $TOKEN in the environment and written into
# curl's stdin config, so it appears in no process list.
hit() {
  local label="$1" url="$2"; shift 2
  {
    printf 'url = "%s"\n' "$url"
    printf 'output = "%s"\n' "$OUT_DIR/$label.body"
    printf 'write-out = "%%{http_code}"\n'
    printf 'silent\ngloboff\n'
    printf '%s\n' "$@"
  } | curl -K -
}

auth_header() { # auth_header [header-name] — a curl config line carrying the token
  printf 'header = "%s: Bearer %s"' "${1:-X-Civi-Auth}" "$TOKEN"
}

site_key_header() {
  printf 'header = "X-Civi-Key: %s"' "$KEY"
}

# ── tokens ───────────────────────────────────────────────────────────────
# mint_token <contactId> <mode> <expiresAt|""> <permission>...
# Prints "<id> <plaintext>".
mint_token() {
  local contact="$1" mode="$2" expires="$3"; shift 3
  local scope exp
  scope="$(printf '%s\n' "$@" | python3 -c 'import json,sys; print(json.dumps([l.rstrip("\n") for l in sys.stdin if l.strip()]))')"
  exp='null'
  [ -n "$expires" ] && exp="\"$expires\""
  cvx api4 KeyringToken.create \
    "{\"contactId\":$contact,\"label\":\"keyring-e2e\",\"scope\":$scope,\"mode\":\"$mode\",\"expiresAt\":$exp,\"checkPermissions\":false}" \
    | python3 -c 'import json,sys; row = json.load(sys.stdin)[0]; print(row["id"], row["token"])'
}

# expire_token <id> — fixture setup only: the API refuses to issue a token that
# is already expired, so the stored date is aged directly.
expire_token() {
  sql "UPDATE civicrm_keyring_token SET expires_at = '2020-01-01 23:59:59' WHERE id = $1"
}

revoke_token() { # revoke_token <id>
  cvx api4 KeyringToken.revoke "{\"where\":[[\"id\",\"=\",$1]],\"checkPermissions\":false}" >/dev/null
}

denial_rows() { # denial_rows <tokenId> — "permission<TAB>path<TAB>hits" per line
  sql "SELECT permission, request_path, hit_count FROM civicrm_keyring_denial WHERE token_id = $1 ORDER BY hit_count DESC"
}

clear_denials() { # clear_denials <tokenId>
  sql "DELETE FROM civicrm_keyring_denial WHERE token_id = $1"
}

# The regression subject: a NON-superuser contact with a realistic role.
# Standalone's admin holds 'all CiviCRM permissions and ACLs', which
# short-circuits CRM_Core_Permission::check before the specific permission is
# ever asked — useless for exercising a scope.
seed_subject() {
  appsh 'cat > /tmp/keyring-seed.php && cv scr /tmp/keyring-seed.php' < "$(dirname "${BASH_SOURCE[0]}")/seed.php" \
    | sed -n 's/^contactId=//p' | tail -1
}
