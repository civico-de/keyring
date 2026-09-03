#!/usr/bin/env bash
#
# Attribution: with a request authenticated by the keyring subscriber, does the
# person land as source_contact_id / creator / log_user_id? The token is not a
# shadow identity — every write must look exactly like an interactive one.
#
# Usage: bash tests/e2e/measure/30-attribution.sh
set -uo pipefail
. "$(dirname "$0")/lib.sh"

CONTACT_ID="${CONTACT_ID:-$(seed_subject)}"
KEY="$(site_key)"
TAG="keyring-attr-$RANDOM"

echo "keyring e2e — attribution"
echo
note "subject contact id: $CONTACT_ID"
note "logging setting: $(cvx ev 'echo Civi::settings()->get("logging") ? "on" : "off";')"
note "user id of subject: $(cvx ev "echo (int) CRM_Core_BAO_UFMatch::getUFId($CONTACT_ID);")"

read -r TOKEN_ID TOKEN < <(mint_token "$CONTACT_ID" enforce "" \
  'access CiviCRM' 'access AJAX API' 'view all contacts' 'edit all contacts' \
  'view all activities' 'edit all activities' 'add contacts' 'access all custom data')
export TOKEN

# Writes must be POST: core refuses a database-modifying GET outright.
hit v3 "$BASE_URL/civicrm/ajax/rest" "$(auth_header)" "$(site_key_header)" 'request = "POST"' \
  'data-urlencode = "entity=Activity"' 'data-urlencode = "action=create"' \
  "data-urlencode = \"json={\\\"activity_type_id\\\":1,\\\"subject\\\":\\\"$TAG-v3\\\",\\\"target_contact_id\\\":$CONTACT_ID}\"" >/dev/null
note "APIv3 Activity.create: $(head -c 200 "$OUT_DIR/v3.body")"

hit v4 "$BASE_URL/civicrm/ajax/api4/Activity/create" "$(auth_header)" "$(site_key_header)" 'request = "POST"' \
  "data-urlencode = \"params={\\\"values\\\":{\\\"activity_type_id\\\":1,\\\"subject\\\":\\\"$TAG-v4\\\",\\\"source_contact_id\\\":$CONTACT_ID}}\"" >/dev/null
note "APIv4 Activity.create: $(head -c 200 "$OUT_DIR/v4.body")"

hit note "$BASE_URL/civicrm/ajax/api4/Note/create" "$(auth_header)" "$(site_key_header)" 'request = "POST"' \
  "data-urlencode = \"params={\\\"values\\\":{\\\"entity_table\\\":\\\"civicrm_contact\\\",\\\"entity_id\\\":$CONTACT_ID,\\\"subject\\\":\\\"$TAG-note\\\",\\\"note\\\":\\\"keyring attribution\\\"}}\"" >/dev/null
note "APIv4 Note.create: $(head -c 200 "$OUT_DIR/note.body")"

# ── verdicts, by id only ────────────────────────────────────────────────
src_v3="$(sql "SELECT ac.contact_id FROM civicrm_activity a
  JOIN civicrm_activity_contact ac ON ac.activity_id = a.id AND ac.record_type_id = 2
  WHERE a.subject = '$TAG-v3' LIMIT 1")"
check "APIv3 Activity source_contact_id is the person" \
  "$([ "$src_v3" = "$CONTACT_ID" ] && echo 0 || echo 1)" "got '$src_v3', want '$CONTACT_ID'"

src_v4="$(sql "SELECT ac.contact_id FROM civicrm_activity a
  JOIN civicrm_activity_contact ac ON ac.activity_id = a.id AND ac.record_type_id = 2
  WHERE a.subject = '$TAG-v4' LIMIT 1")"
check "APIv4 Activity source_contact_id is the person" \
  "$([ "$src_v4" = "$CONTACT_ID" ] && echo 0 || echo 1)" "got '$src_v4', want '$CONTACT_ID'"

note_creator="$(sql "SELECT contact_id FROM civicrm_note WHERE subject = '$TAG-note' LIMIT 1")"
# contact_id was NOT sent: this measures whether APIv4 fills the creator
# from the authenticated session.
check "APIv4 Note contact_id (creator) defaults to the person" \
  "$([ "$note_creator" = "$CONTACT_ID" ] && echo 0 || echo 1)" "got '$note_creator', want '$CONTACT_ID'"

# @civicrm_user_id is set from the session's 'userID', which in CiviCRM is
# the CONTACT id, not the CMS user id (CRM/Core/Config.php:210).
log_user="$(sql "SELECT log_user_id FROM log_civicrm_activity WHERE subject = '$TAG-v4' ORDER BY id DESC LIMIT 1")"
check "log_civicrm_activity.log_user_id is the person's contact id" \
  "$([ "$log_user" = "$CONTACT_ID" ] && echo 0 || echo 1)" "got '$log_user', want '$CONTACT_ID'"

log_note="$(sql "SELECT log_user_id FROM log_civicrm_note WHERE subject = '$TAG-note' ORDER BY id DESC LIMIT 1")"
check "log_civicrm_note.log_user_id is the person's contact id" \
  "$([ "$log_note" = "$CONTACT_ID" ] && echo 0 || echo 1)" "got '$log_note', want '$CONTACT_ID'"

# The token itself never becomes an identity: created_id stays whoever issued
# it, and no shadow contact is created for the token.
issued_by="$(sql "SELECT COALESCE(created_id, 0) FROM civicrm_keyring_token WHERE id = $TOKEN_ID")"
note "token $TOKEN_ID issued by contact '$issued_by' (0 = issued from the CLI)"
finish
