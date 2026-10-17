# shellcheck shell=bash
# keyring's own e2e helpers, loaded by the managed lib.sh. Tokens never reach
# a command line: hit() hands curl its whole configuration on stdin.

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
  cv api4 KeyringToken.create \
    "{\"contactId\":$contact,\"label\":\"keyring-e2e\",\"scope\":$scope,\"mode\":\"$mode\",\"expiresAt\":$exp,\"checkPermissions\":false}" \
    | python3 -c 'import json,sys; row = json.load(sys.stdin)[0]; print(row["id"], row["token"])'
}

# expire_token <id> — fixture setup only: the API refuses to issue a token that
# is already expired, so the stored date is aged directly.
expire_token() {
  sql "UPDATE civicrm_keyring_token SET expires_at = '2020-01-01 23:59:59' WHERE id = $1"
}

revoke_token() { # revoke_token <id>
  cv api4 KeyringToken.revoke "{\"where\":[[\"id\",\"=\",$1]],\"checkPermissions\":false}" >/dev/null
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
  cv_scr "$CK_E2E_DIR/measure/seed.php" | sed -n 's/^contactId=//p' | tail -1
}
