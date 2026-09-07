#!/usr/bin/env bash
#
# Authentication: a keyring token is accepted on the header flows only, and
# unknown, revoked and expired tokens fail identically.
#
# Usage: bash tests/e2e/measure/10-authx.sh
set -uo pipefail
. "$(dirname "$0")/lib.sh"

CONTACT_ID="${CONTACT_ID:-$(seed_subject)}"
KEY="$(site_key)"
V4='params=%7B%22select%22%3A%5B%22id%22%5D%2C%22limit%22%3A1%7D'
V3='entity=Contact&action=get&json=%7B%22sequential%22%3A1%2C%22return%22%3A%5B%22id%22%5D%7D'

echo "keyring e2e — authentication"
echo

echo "Listener priorities on civi.authx.checkCredential:"
cvx ev '
$d = \Civi::dispatcher();
foreach ($d->getListeners("civi.authx.checkCredential") as $l) {
  printf("  %6d\n", $d->getListenerPriority("civi.authx.checkCredential", $l));
}' | tee "$OUT_DIR/listeners.txt"
check "keyring listens at -250, above core's api_key resolver (-300)" \
  "$(grep -q -- '-250' "$OUT_DIR/listeners.txt" && echo 0 || echo 1)"

read -r TOKEN_ID TOKEN < <(mint_token "$CONTACT_ID" enforce "" \
  'access CiviCRM' 'access AJAX API' 'view all contacts')
export TOKEN

# The shape the add-in sends: the token in X-Civi-Auth.
code="$(hit xheader "$BASE_URL/civicrm/ajax/api4/Contact/get?$V4" "$(auth_header)" "$(site_key_header)")"
check "xheader (X-Civi-Auth: Bearer) authenticates" \
  "$([ "$code" = "200" ] && echo 0 || echo 1)" "http=$code $(head -c 160 "$OUT_DIR/xheader.body")"

code="$(hit header "$BASE_URL/civicrm/ajax/api4/Contact/get?$V4" "$(auth_header Authorization)" "$(site_key_header)")"
check "header (Authorization: Bearer) authenticates" \
  "$([ "$code" = "200" ] && echo 0 || echo 1)" "http=$code $(head -c 160 "$OUT_DIR/header.body")"

# Decision 1: a token in the URL is refused, and the error names the header.
code="$(hit legacyrest "$BASE_URL/civicrm/ajax/rest?$V3&api_key=$TOKEN&key=$KEY")"
check "legacyrest (api_key= in the URL) is refused" \
  "$(grep -qi 'must be sent as a request header' "$OUT_DIR/legacyrest.body" && echo 0 || echo 1)" \
  "http=$code $(head -c 200 "$OUT_DIR/legacyrest.body")"
check "the refusal names the header to use" \
  "$(grep -q 'X-Civi-Auth' "$OUT_DIR/legacyrest.body" && echo 0 || echo 1)" \
  "$(head -c 200 "$OUT_DIR/legacyrest.body")"

code="$(hit param "$BASE_URL/civicrm/ajax/api4/Contact/get?$V4&_authx=Bearer%20$TOKEN&_authxSiteKey=$KEY")"
check "param (&_authx=Bearer …) is refused" \
  "$(grep -qi 'must be sent as a request header' "$OUT_DIR/param.body" && echo 0 || echo 1)" \
  "http=$code $(head -c 200 "$OUT_DIR/param.body")"

# A matching header does not excuse a token that is ALSO in the URL: by then
# it is in the access log and the browser history. Fails closed.
code="$(hit header_and_param "$BASE_URL/civicrm/ajax/rest?$V3&api_key=$TOKEN&key=$KEY" "$(auth_header)")"
check "a token in the header AND in api_key= is refused" \
  "$(grep -qi 'must be sent as a request header' "$OUT_DIR/header_and_param.body" && echo 0 || echo 1)" \
  "http=$code $(head -c 200 "$OUT_DIR/header_and_param.body")"

# The same leak in array form: api_key[]=<token> is a parameter like any other.
code="$(hit header_and_array "$BASE_URL/civicrm/ajax/rest?$V3&api_key%5B%5D=$TOKEN&key=$KEY" "$(auth_header)")"
check "a token in the header AND in api_key[]= is refused" \
  "$(grep -qi 'must be sent as a request header' "$OUT_DIR/header_and_array.body" && echo 0 || echo 1)" \
  "http=$code $(head -c 200 "$OUT_DIR/header_and_array.body")"

# A repeated scalar key: PHP keeps only the last value, so the leaked token is
# gone from $_GET. The raw query string is scanned as well.
code="$(hit header_and_repeated "$BASE_URL/civicrm/ajax/rest?$V3&api_key=$TOKEN&api_key=harmless&key=$KEY" "$(auth_header)")"
check "a token in the header AND in a repeated api_key= is refused" \
  "$(grep -qi 'must be sent as a request header' "$OUT_DIR/header_and_repeated.body" && echo 0 || echo 1)" \
  "http=$code $(head -c 200 "$OUT_DIR/header_and_repeated.body")"

code="$(hit header_and_authx "$BASE_URL/civicrm/ajax/api4/Contact/get?$V4&_authx=Bearer%20$TOKEN&_authxSiteKey=$KEY" "$(auth_header)" "$(site_key_header)")"
check "a token in the header AND in _authx= is refused" \
  "$(grep -qi 'must be sent as a request header' "$OUT_DIR/header_and_authx.body" && echo 0 || echo 1)" \
  "http=$code $(head -c 200 "$OUT_DIR/header_and_authx.body")"

# The positive control for the two above: the same token, header only, works.
code="$(hit header_only "$BASE_URL/civicrm/ajax/api4/Contact/get?$V4" "$(auth_header)" "$(site_key_header)")"
check "the same token, header only, still authenticates" \
  "$([ "$code" = "200" ] && echo 0 || echo 1)" "http=$code $(head -c 160 "$OUT_DIR/header_only.body")"

# An unknown credential must fall through to core untouched.
TOKEN='not-a-keyring-token' hit unknown "$BASE_URL/civicrm/ajax/api4/Contact/get?$V4" \
  'header = "X-Civi-Auth: Bearer not-a-keyring-token"' >/dev/null
check "an unknown credential is left to core and rejected" \
  "$(grep -qi 'unauthorized\|401\|Invalid credential' "$OUT_DIR/unknown.body" && echo 0 || echo 1)" \
  "$(head -c 160 "$OUT_DIR/unknown.body")"

# A kr_ token that does not resolve must NOT fall through to core's api_key
# lookup: keyring rejects it, in the same words as a revoked one.
TOKEN='kr_zzzzzzzzzzzzzzzzzzzz' hit ghost "$BASE_URL/civicrm/ajax/api4/Contact/get?$V4" \
  'header = "X-Civi-Auth: Bearer kr_zzzzzzzzzzzzzzzzzzzz"' >/dev/null
check "an unknown kr_ token is rejected by keyring" \
  "$(grep -q 'Unknown keyring token' "$OUT_DIR/ghost.body" && echo 0 || echo 1)" \
  "$(head -c 160 "$OUT_DIR/ghost.body")"

# last_used_at is written on first use and throttled to once an hour.
used_after_first="$(sql "SELECT COALESCE(last_used_at, 'never') FROM civicrm_keyring_token WHERE id = $TOKEN_ID")"
check "last_used_at is written on first use" \
  "$([ "$used_after_first" != "never" ] && echo 0 || echo 1)" "got '$used_after_first'"
export TOKEN="$TOKEN"
hit xheader2 "$BASE_URL/civicrm/ajax/api4/Contact/get?$V4" "$(auth_header)" "$(site_key_header)" >/dev/null
used_after_second="$(sql "SELECT last_used_at FROM civicrm_keyring_token WHERE id = $TOKEN_ID")"
check "a second use inside the hour does not rewrite last_used_at" \
  "$([ "$used_after_first" = "$used_after_second" ] && echo 0 || echo 1)" \
  "first '$used_after_first', second '$used_after_second'"

# Revoked and expired fail exactly like unknown.
revoke_token "$TOKEN_ID"
hit revoked "$BASE_URL/civicrm/ajax/api4/Contact/get?$V4" "$(auth_header)" "$(site_key_header)" >/dev/null
check "a revoked token is refused" \
  "$(grep -q 'Unknown keyring token' "$OUT_DIR/revoked.body" && echo 0 || echo 1)" \
  "$(head -c 160 "$OUT_DIR/revoked.body")"

read -r EXPIRED_ID TOKEN < <(mint_token "$CONTACT_ID" enforce "$(date +%F)" 'access CiviCRM')
export TOKEN
check "expiresAt is a date, stored as the end of that day" \
  "$([ "$(sql "SELECT expires_at FROM civicrm_keyring_token WHERE id = $EXPIRED_ID")" = "$(date +%F) 23:59:59" ] && echo 0 || echo 1)" \
  "got '$(sql "SELECT expires_at FROM civicrm_keyring_token WHERE id = $EXPIRED_ID")'"
expire_token "$EXPIRED_ID"
hit expired "$BASE_URL/civicrm/ajax/api4/Contact/get?$V4" "$(auth_header)" "$(site_key_header)" >/dev/null
check "an expired token is refused" \
  "$(grep -q 'Unknown keyring token' "$OUT_DIR/expired.body" && echo 0 || echo 1)" \
  "$(head -c 160 "$OUT_DIR/expired.body")"
check "revoked and expired are indistinguishable from unknown" \
  "$(cmp -s "$OUT_DIR/revoked.body" "$OUT_DIR/expired.body" && cmp -s "$OUT_DIR/revoked.body" "$OUT_DIR/ghost.body" && echo 0 || echo 1)"

note "token ids used: $TOKEN_ID (revoked), $EXPIRED_ID (expired)"
finish
