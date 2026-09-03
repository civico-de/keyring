#!/usr/bin/env bash
#
# Token labels are free text. The API stores and returns them byte-for-byte,
# markup included — escaping is the caller's job, and the contact tab does it
# with _.escape() before the label reaches CRM.confirm's .html().
#
# Usage: bash tests/e2e/measure/40-escaping.sh
set -uo pipefail
. "$(dirname "$0")/lib.sh"

CONTACT_ID="${CONTACT_ID:-$(seed_subject)}"
KEY="$(site_key)"

echo "keyring e2e — label handling"
echo

PAYLOAD='<img src=x onerror="alert(1)">'

# 'view all contacts' is in the scope because KeyringToken.contact_id is an FK
# to Contact: core adds the contact ACL sub-query before keyring's own clause,
# so a caller who cannot see the contact sees none of its tokens either.
read -r TOKEN_ID TOKEN < <(mint_token "$CONTACT_ID" enforce "" \
  'access CiviCRM' 'access AJAX API' 'administer keyring tokens' 'view all contacts')
export TOKEN

# A write has to be POSTed; the params ride in the body, read from a file so
# no shell quoting can alter the payload on the way.
PAYLOAD="$PAYLOAD" CONTACT_ID="$CONTACT_ID" python3 -c '
import json, os, sys
json.dump({
    "contactId": int(os.environ["CONTACT_ID"]),
    "label": os.environ["PAYLOAD"],
    "scope": [],
    "mode": "report",
}, open(sys.argv[1], "w"))' "$OUT_DIR/label_create.params"

code="$(hit label_create "$BASE_URL/civicrm/ajax/api4/KeyringToken/create" \
  "$(auth_header)" "$(site_key_header)" \
  "data-urlencode = \"params@$OUT_DIR/label_create.params\"")"
check "a token can be issued with a label full of markup" \
  "$([ "$code" = "200" ] && echo 0 || echo 1)" "http=$code $(head -c 200 "$OUT_DIR/label_create.body")"

NEW_ID="$(python3 -c '
import json, sys
print(json.load(open(sys.argv[1]))["values"][0]["id"])' "$OUT_DIR/label_create.body" 2>/dev/null)"
check "the create returned an id" \
  "$([ -n "$NEW_ID" ] && echo 0 || echo 1)" "$(head -c 200 "$OUT_DIR/label_create.body")"

if [ -n "$NEW_ID" ]; then
  get_params="$(NEW_ID="$NEW_ID" python3 -c '
import json, os, urllib.parse
print(urllib.parse.quote(json.dumps({
    "select": ["label"],
    "where": [["id", "=", int(os.environ["NEW_ID"])]],
})))')"

  hit label_get "$BASE_URL/civicrm/ajax/api4/KeyringToken/get?params=$get_params" \
    "$(auth_header)" "$(site_key_header)" >/dev/null

  stored="$(python3 -c '
import json, sys
print(json.load(open(sys.argv[1]))["values"][0]["label"])' "$OUT_DIR/label_get.body" 2>/dev/null)"
  check "get returns the label byte-for-byte, nothing escaped or stripped" \
    "$([ "$stored" = "$PAYLOAD" ] && echo 0 || echo 1)" \
    "expected '$PAYLOAD', got '$stored'"

  revoke_token "$NEW_ID"
fi

revoke_token "$TOKEN_ID"
note "the UI-side escaping this justifies has no browser test; see README."
finish
