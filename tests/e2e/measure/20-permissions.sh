#!/usr/bin/env bash
#
# Scope enforcement: a permission outside the scope is taken away, a read
# outside it degrades to zero rows, and report mode records what it would
# have refused instead of refusing it.
#
# Usage: bash tests/e2e/measure/20-permissions.sh
set -uo pipefail
. "$(dirname "$0")/../lib.sh"

CONTACT_ID="${CONTACT_ID:-$(seed_subject)}"
KEY="$(site_key)"
V4='params=%7B%22select%22%3A%5B%22id%22%5D%2C%22limit%22%3A1%7D'

echo "keyring e2e — scope enforcement"
echo

# ── enforce: nothing but the bare minimum ────────────────────────────────
read -r NARROW_ID TOKEN < <(mint_token "$CONTACT_ID" enforce "" 'access CiviCRM' 'access AJAX API')
export TOKEN

code="$(hit narrow_v4 "$BASE_URL/civicrm/ajax/api4/Contact/get?$V4" "$(auth_header)" "$(site_key_header)")"
check "APIv4 Contact.get returns no rows outside the scope" \
  "$([ "$code" = "200" ] && grep -q '"count":0' "$OUT_DIR/narrow_v4.body" && echo 0 || echo 1)" \
  "http=$code $(head -c 160 "$OUT_DIR/narrow_v4.body")"

SUBJECT="keyring-scope-probe-$RANDOM"
code="$(hit narrow_create "$BASE_URL/civicrm/ajax/api4/Activity/create" "$(auth_header)" "$(site_key_header)" \
  'request = "POST"' \
  "data-urlencode = \"params={\\\"values\\\":{\\\"activity_type_id\\\":1,\\\"subject\\\":\\\"$SUBJECT\\\",\\\"source_contact_id\\\":$CONTACT_ID}}\"")"
check "APIv4 Activity.create is refused outside the scope" \
  "$([ "$code" = "403" ] && echo 0 || echo 1)" "http=$code $(head -c 200 "$OUT_DIR/narrow_create.body")"
check "and nothing was written" \
  "$([ "$(sql "SELECT COUNT(*) FROM civicrm_activity WHERE subject = '$SUBJECT'")" = "0" ] && echo 0 || echo 1)"

code="$(hit narrow_page "$BASE_URL/civicrm/admin/keyring/tokens?reset=1" "$(auth_header)" "$(site_key_header)")"
check "the extension's admin page is denied outside the scope" \
  "$([ "$code" != "200" ] && echo 0 || echo 1)" "http=$code"

# ── enforce: the same calls with the permissions in scope ────────────────
read -r WIDE_ID TOKEN < <(mint_token "$CONTACT_ID" enforce "" \
  'access CiviCRM' 'access AJAX API' 'view all contacts' \
  'administer keyring tokens' '@afform:afsearchKeyringTokens')
export TOKEN

code="$(hit wide_v4 "$BASE_URL/civicrm/ajax/api4/Contact/get?$V4" "$(auth_header)" "$(site_key_header)")"
check "APIv4 Contact.get returns rows once 'view all contacts' is in scope" \
  "$([ "$code" = "200" ] && grep -q '"count":1' "$OUT_DIR/wide_v4.body" && echo 0 || echo 1)" \
  "http=$code $(head -c 160 "$OUT_DIR/wide_v4.body")"

code="$(hit wide_page "$BASE_URL/civicrm/admin/keyring/tokens?reset=1" "$(auth_header)" "$(site_key_header)")"
check "the extension's admin page is served once its permission is in scope" \
  "$([ "$code" = "200" ] && echo 0 || echo 1)" "http=$code"

# The guard permission is checked BEFORE login and is always in scope, so a
# token works without the site key even though its scope never named it.
code="$(hit no_site_key "$BASE_URL/civicrm/ajax/api4/Contact/get?$V4" "$(auth_header)")"
check "'authenticate with api key' is implicitly in scope (no site key needed)" \
  "$([ "$code" = "200" ] && echo 0 || echo 1)" "http=$code $(head -c 160 "$OUT_DIR/no_site_key.body")"

# ── a superuser's token ──────────────────────────────────────────────────
# Standalone's admin holds every permission only through 'all CiviCRM permissions
# and ACLs', which is never in scope: the token still carries its scope, no more.
ADMIN_ID="$(cv ev 'echo \Civi\Api4\User::get(FALSE)->addWhere("username", "=", "admin")->addSelect("contact_id")->execute()->single()["contact_id"];')"
read -r ADMIN_WIDE_ID TOKEN < <(mint_token "$ADMIN_ID" enforce "" 'access CiviCRM' 'access AJAX API' 'view all contacts')
export TOKEN
code="$(hit admin_v4 "$BASE_URL/civicrm/ajax/api4/Contact/get?$V4" "$(auth_header)")"
check "a superuser's token authenticates without the site key and reads within its scope" \
  "$([ "$code" = "200" ] && grep -q '"count":1' "$OUT_DIR/admin_v4.body" && echo 0 || echo 1)" \
  "http=$code $(head -c 160 "$OUT_DIR/admin_v4.body")"

read -r ADMIN_NARROW_ID TOKEN < <(mint_token "$ADMIN_ID" enforce "" 'access CiviCRM' 'access AJAX API')
export TOKEN
SUBJECT="keyring-admin-probe-$RANDOM"
code="$(hit admin_create "$BASE_URL/civicrm/ajax/api4/Activity/create" "$(auth_header)" \
  'request = "POST"' \
  "data-urlencode = \"params={\\\"values\\\":{\\\"activity_type_id\\\":1,\\\"subject\\\":\\\"$SUBJECT\\\",\\\"source_contact_id\\\":$ADMIN_ID}}\"")"
check "a superuser's token is still refused outside its scope" \
  "$([ "$code" = "403" ] && echo 0 || echo 1)" "http=$code $(head -c 200 "$OUT_DIR/admin_create.body")"
check "and nothing was written" \
  "$([ "$(sql "SELECT COUNT(*) FROM civicrm_activity WHERE subject = '$SUBJECT'")" = "0" ] && echo 0 || echo 1)"

# ── report mode ──────────────────────────────────────────────────────────
read -r REPORT_ID TOKEN < <(mint_token "$CONTACT_ID" report "" 'access CiviCRM' 'access AJAX API')
export TOKEN
clear_denials "$REPORT_ID"

code="$(hit report_v4 "$BASE_URL/civicrm/ajax/api4/Contact/get?$V4" "$(auth_header)" "$(site_key_header)")"
check "report mode denies nothing: the same call returns rows" \
  "$([ "$code" = "200" ] && grep -q '"count":1' "$OUT_DIR/report_v4.body" && echo 0 || echo 1)" \
  "http=$code $(head -c 160 "$OUT_DIR/report_v4.body")"

denial_rows "$REPORT_ID" > "$OUT_DIR/denials.txt"
check "report mode recorded the permissions the scope would have refused" \
  "$([ -s "$OUT_DIR/denials.txt" ] && echo 0 || echo 1)"
check "'view all contacts' is among them" \
  "$(grep -q 'view all contacts' "$OUT_DIR/denials.txt" && echo 0 || echo 1)" \
  "$(head -3 "$OUT_DIR/denials.txt")"
# Aggregation only shows on a request that asks the same permission more than
# once. A single APIv4 call asks each permission once; a contact summary asks
# some of them a dozen times.
clear_denials "$REPORT_ID"
hit report_page "$BASE_URL/civicrm/contact/view?reset=1&cid=$CONTACT_ID" "$(auth_header)" "$(site_key_header)" >/dev/null
denial_rows "$REPORT_ID" > "$OUT_DIR/denials-page.txt"
check "denials are aggregated: one row per (permission, path) with a hit count" \
  "$(awk -F'\t' '$3 > 1 {found = 1} END {exit !found}' "$OUT_DIR/denials-page.txt" && echo 0 || echo 1)" \
  "no permission was counted more than once: $(head -3 "$OUT_DIR/denials-page.txt")"
denial_rows "$REPORT_ID" > "$OUT_DIR/denials.txt"
check "every denial carries a request path" \
  "$(awk -F'\t' '$2 == "" {bad = 1} END {exit bad}' "$OUT_DIR/denials.txt" && echo 0 || echo 1)"

rows_before="$(sql "SELECT COUNT(*) FROM civicrm_keyring_denial WHERE token_id = $REPORT_ID")"
hits_before="$(sql "SELECT COALESCE(SUM(hit_count), 0) FROM civicrm_keyring_denial WHERE token_id = $REPORT_ID")"
hit report_page_again "$BASE_URL/civicrm/contact/view?reset=1&cid=$CONTACT_ID" "$(auth_header)" "$(site_key_header)" >/dev/null
rows_after="$(sql "SELECT COUNT(*) FROM civicrm_keyring_denial WHERE token_id = $REPORT_ID")"
hits_after="$(sql "SELECT COALESCE(SUM(hit_count), 0) FROM civicrm_keyring_denial WHERE token_id = $REPORT_ID")"
check "a second identical request adds no rows" \
  "$([ "$rows_before" = "$rows_after" ] && echo 0 || echo 1)" "rows $rows_before → $rows_after"
check "but does raise the hit counts" \
  "$([ "$hits_after" -gt "$hits_before" ] && echo 0 || echo 1)" "hits $hits_before → $hits_after"

note "tokens used: $NARROW_ID (narrow), $WIDE_ID (wide), $REPORT_ID (report)"
note "denials recorded: $(wc -l < "$OUT_DIR/denials.txt" | tr -d ' ')"
finish
