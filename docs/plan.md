# keyring — architecture, phase 2 onwards

Every decision below names the measurement it rests on. Read
[`measurements.md`](measurements.md) first; this document does not repeat the
evidence.

## What keyring is

Several revocable API tokens per contact. Each token carries a permission
allowlist, an expiry and a last-use timestamp. The person's role is never
touched, and every write stays attributed to the person — measured: APIv3 and
APIv4 `source_contact_id`, APIv4 `Note.contact_id` and `log_user_id` are all
the subject's contact id when a `civi.authx.checkCredential` subscriber
supplies the principal.

First deployment: a client running the Outlook 365 add-in with seven personal
API keys. keyring replaces those seven keys with seven scoped tokens; a forked
add-in sends the token in the `X-Civi-Auth` header (see Decisions).

## Schema

One table, `civicrm_keyring_token`, in `schema/KeyringToken.entityType.php`.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | int | |
| `contact_id` | FK `civicrm_contact` | `ON DELETE CASCADE`; the person the token acts as |
| `label` | varchar(128) | required; "Outlook laptop", shown in the UI |
| `token_hash` | varchar(64) | `hash('sha256', $plaintext)`, unique index |
| `token_prefix` | varchar(16) | `kr_` + first 6 random chars, for display and for the audit log |
| `scope` | text (JSON) | allowlist of permission strings |
| `scope_profile` | varchar(64), null | name of the profile the scope came from, or NULL for a hand-built scope |
| `mode` | varchar(16) | `report` or `enforce` |
| `expires_at` | datetime, null | end of the last valid day; NULL = no expiry |
| `last_used_at` | datetime, null | |
| `revoked_at` | datetime, null | |
| `created_id` | FK `civicrm_contact`, null | who issued it |
| `created_date` | timestamp | |

**Hash, not encryption.** The plaintext is shown once, at create, and never
stored. A stolen database yields no usable token. SHA-256 without a salt is
deliberate: the token is 32 bytes from `random_bytes()`, so there is nothing to
brute-force, and an unsalted digest allows the single indexed lookup the hot
path needs. A per-row salt would force a table scan on every request.

Second table, `civicrm_keyring_denial`, in
`schema/KeyringDenial.entityType.php` — the report-mode audit trail, its own
entity because a sync/report history has to be browsable, not a log file:

| Column | Type | Notes |
| --- | --- | --- |
| `id` | int | |
| `token_id` | FK `civicrm_keyring_token` | `ON DELETE CASCADE` |
| `permission` | varchar(255) | |
| `request_path` | varchar(255) | e.g. `civicrm/ajax/rest` |
| `hit_count` | int | |
| `first_seen`, `last_seen` | datetime | |

Aggregated, not one row per check: measured, a single `civicrm/contact/view`
asks 690 permission checks and one permission was asked 208 times in that one
request. Per-check rows would be unusable. The unique key is
`(token_id, permission, request_path)`, and the writer reads that key through
APIv4 and then creates or increments the row. Not an atomic upsert: two
requests denying the same permission at the same instant can lose one
increment, or one row on the very first denial of a key. Accepted — the counter
ranks permissions for deriving a scope, it is not an audit of record, and the
same page produced only 7 distinct keys, so the buffer needs no cap.
Denials are buffered in memory for the request and flushed once at
`civi.api.respond`/shutdown, so a request writes at most one statement per
distinct permission.

## Recognising a token

`Civi\Keyring\Authx\TokenSubscriber`, an `AutoSubscriber` on
`civi.authx.checkCredential` at **priority -250**.

Measured: core's listeners sit at `PRIORITY_BASIC_USER = -200`,
`PRIORITY_BEARER_API_KEY = -300`, `PRIORITY_BEARER_JWT = -400`. -250 is above
core's api_key resolver, so keyring owns its tokens, and below core's Basic
listener, so username/password keeps precedence. `accept()` calls
`stopPropagation()`, so core never sees a token keyring claimed.

The listener returns immediately unless `str_starts_with($e->credValue, 'kr_')`
— measured: the credential reaches the listener verbatim on every flow,
including `legacyrest`, where authx builds
`'Bearer ' . $_REQUEST['api_key']`. Anything else falls through to core
untouched (measured: an unknown credential traversed all six probe priorities
and was rejected by core).

On a match it looks up `token_hash`, and:

- no row → `reject('Unknown keyring token')`. Rejection stops propagation, so a
  mistyped keyring token never falls back to being tried as a contact api_key.
- `revoked_at IS NOT NULL` → reject.
- `expires_at < now` → reject.

Revocation and expiry are enforced **here**, at authentication, not at
permission time: one check per request instead of hundreds, and a revoked
token then fails identically to a wrong one.

Accepted principal: `['credType' => 'api_key', 'contactId' => $row->contact_id]`.
`credType` must be `api_key` because `checkPolicy()` rejects any credType
outside `authx_<flow>_cred`, which ships as `["jwt","api_key"]` on all four
flows.

## Marking the request

`Civi\Keyring\TokenContext`, a request-scoped service holding the active
token's id, scope array and mode. Set by the subscriber, read by the permission
listener.

Not the session: the flows the add-in uses are stateless
(`useSession = FALSE`), so a session flag would be written and never read, and
on the `login`/`auto` flows it would outlive the token. The context object dies
with the request, which is exactly the lifetime of the authentication.

## Enforcing the scope

`Civi\Keyring\Permission\ScopeSubscriber`, an `AutoSubscriber` on
`hook_civicrm_permission_check` at `PHP_INT_MIN`, so it sees the verdict every
other listener has already produced and has the last word.

```
if (no active token)            return;
if ($granted === FALSE)         return;   // never widen
if (permission in scope)        return;
if (mode === 'report')          record the denial; return;
$granted = FALSE;
```

Measured: this gates everything that matters. Every one of 757 observed checks
entered through `CRM_Core_Permission::check`, including
`CRM_Core_Permission::checkMenu` → `checkMenuItem` for page access, APIv4
entity permissions via `PermissionCheckSubscriber::onApiAuthorize`, and the
coarse ACL clauses (`CRM_Contact_BAO_Contact_Permission::cacheClause` /
`cacheSubquery`) that decide whether a contact query returns rows.

**The scope is always an in-memory array.** Measured: the hook is not
memoised — one permission was asked 208 times in one request and fired the
listener 208 times. The listener does an `isset()` on a flipped array and
nothing else; the token row is loaded once, during authentication.

### Two permissions are always in scope

- `authenticate with api key` — measured: `Authenticator::checkPolicy()` runs
  `CRM_Core_Permission::check('authenticate with api key', $contactId)` before
  login, and `authx_guards` ships as `["site_key","perm"]` (OR'd). With that
  permission denied and no site key, authentication fails with
  `Login not permitted. Must satisfy guard (site_key, perm).` Denying it would
  make every token unusable on any site not sending the site key. keyring adds
  it to every scope implicitly, and the UI does not offer it.
- `all CiviCRM permissions and ACLs` — the inverse: it is **never** grantable
  through a token, and is stripped from any scope on save. `CRM_Core_Permission::check`
  tests it first and short-circuits, so leaving it in would make the scope a
  no-op for a superuser.

### What stays outside the hook — accepted and documented

- **Per-record ACL** via `CoreUtil::checkAccessRecord`
  (`AbstractCreateAction.php:49`) and row-level `civicrm_acl` rules. These
  narrow access further; they never widen it. A token can therefore never see
  more than its person, which is the security property that matters. Accepted.
- **Pre-login checks.** Measured: `access CiviCRM` is checked once via
  `CRM_Core_Resources_Common::coreResourceList` before authx logs the contact
  in, then again via `checkMenuItem` after. The listener returns early when
  there is no active token, so the early pass is simply unscoped. Accepted; it
  runs before any data is touched.
- **Direct SQL and `CRM_Core_DAO` writes** in extensions that skip the API.
  Nothing in CiviCRM can gate those. Documented, not guarded. Server-side DAO
  and raw-SQL writes are a trusted boundary: code running there is already
  inside the application and can do anything, whatever guard is placed in
  front of it.
- **`userPermissionTemp`.** `CRM_Core_Permission::check` runs the
  `permission_check` hook and *then* one more test: a permission still denied
  is granted anyway if `CRM_Core_Config::singleton()->userPermissionTemp` — a
  `CRM_Core_Permission_Temp` — grants it. This sits after the hook, so
  keyring's listener at `PHP_INT_MIN` cannot see or veto it: a `FALSE` the
  scope wrote is silently turned back into a `TRUE`. Measured on 6.17.3: the
  only occurrences of `userPermissionTemp` in core are the config key's own
  declaration (`CRM_Core_Config_MagicMerge`, `CRM_Core_BAO_ConfigSetting`) and
  the read above — nothing in core ever assigns one, so on a stock install the
  fallback is inert. A third-party extension that assigned one and left it in
  place would hand a token-authenticated request a permission outside its
  scope. Documented, not guarded: a guard here means a second enforcement layer
  for a variable no supported install sets.

No additional guard is built for any of these. Adding a second enforcement
layer would double the surface that has to stay correct, for cases that only
ever restrict.

## Report mode

`mode = 'report'` denies nothing and records every permission the token was
refused-by-scope. This is how a client's allowlist is derived from real
traffic: issue the token in report mode, let the add-in run for a week, read
`KeyringDenial` grouped by permission, promote the list to a profile, switch
the token to `enforce`.

Measured why this is not optional: a read outside the scope returns
`"count":0`, not an error. A too-narrow scope looks to the client like empty
data. Guessing a scope and shipping it would produce silent, plausible-looking
wrong answers.

## Expiry

`expires_at` is a **date**, not an instant. The input is `YYYY-MM-DD` and the
stored value is that day's `23:59:59` in the site timezone, so a token issued
"until 2026-12-31" works for the whole of that day. `create` validates:
a datetime input is refused rather than silently truncated, a past date is an
error, and an unparseable one is an error. An administrator's `update` obeys
the same rule, in `TokenWriteSubscriber` on `hook_civicrm_pre`, so the column
has one definition and not two. One deviation is forced by core and measured on
6.17.3: the DAO rewrites every date to `YmdHis` **before** the pre hook runs, so
at that point a date-only input is indistinguishable from midnight. The hook
therefore accepts `00:00:00` (what a date arrives as) and `23:59:59` (the stored
value being resent) and refuses any other time of day. Re-sending the stored
value is a no-op, so a token whose day has already passed can still be
relabelled. The site timezone is the one MySQL's
`NOW()` and `CRM_Utils_Time` already work in, so no conversion happens anywhere.

The comparison is server-side, in two places that agree by construction:
`Civi\Keyring\Expiry::isExpired` at authentication, and a computed
`KeyringToken.state` field (`active` | `expired` | `revoked`) on `get`.
`state` is a read-only APIv4 field added by a `SpecProvider` with a
`setSqlRenderer` — the mechanism core itself uses for `Contact.age_years` and
`Contribution.balance_amount` — rendering an `IF()` over `revoked_at` and
`expires_at` against `CRM_Utils_Time::time()`, so a test that freezes the clock
moves the field with it. Nothing derives the state client-side: a browser would
have to guess the site timezone and would disagree with the authentication path
that actually refuses the token.

## `last_used_at`

Written at most once per token per hour, and never on a revoked row. The
throttle is the `UPDATE`'s own `WHERE` clause
(`last_used_at IS NULL OR last_used_at < now - 1 hour`), not a prior read:
two requests arriving together would both pass a read-decide-write and both
write. The column answers "is this token still in use, can I revoke it" — an
hour of resolution is enough, and the add-in polls often enough that a write
per request would be one extra write on every single call.

## APIv4 surface

`KeyringToken` (generic CRUD via the entity type) plus:

- `KeyringToken::create` — override. Generates `kr_` + 32 bytes of
  `random_bytes()` base32-encoded, stores the hash and the prefix, and returns
  the plaintext in the result **once**, in a field that `get` does not have.
  Permission: `administer keyring tokens`, or `manage own keyring tokens` when
  `contact_id` is the caller's own.
- `KeyringToken::revoke` — sets `revoked_at` through an unchecked APIv4
  `update` whose `WHERE` repeats `revoked_at IS NULL`, so a second revocation
  cannot overwrite the first timestamp. Which rows the caller reaches was
  already decided by the `get` the batch action runs, and `revoked_at` is
  closed to the generic `update`.
- `KeyringToken::get` — never exposes `token_hash`; the field is declared with
  `'permission' => ['*always deny*']`.

**Nothing deletes a token.** `delete` is denied to every permission on both
entities: the row anchors the denial history and the audit trail, and with
`actions` enabled the SearchKit display would otherwise offer core's stock
Delete task and cascade that history away. Contact deletion still removes the
rows through the foreign key, which is intended — the person is gone. Revoke is
the only end of a token's life.

The generic `update` and `save` need `administer keyring tokens`, and `replace`
is always denied. A `manage own keyring tokens` holder's whole write surface is
`create` and `revoke`. This is not tidiness: core's `AbstractUpdateAction` has a
fast path for a single-id update that calls `CoreUtil::checkAccessRecord`
directly and never builds a select clause, so `hook_civicrm_selectWhereClause`
does not see it. With `update` open to holders, one of them could move a token's
`contact_id` onto an administrator and then authenticate as that administrator.

Two further layers close the same door from the other side:

- a `civi.api4.authorizeRecord` listener resolves a named row back to its owning
  contact and refuses a non-administrator any row that is not theirs — this is
  the check the single-id fast path and `AbstractSaveAction` actually consult;
- `hook_civicrm_pre` (`TokenWriteSubscriber`, with the field rule in
  `ImmutableFields`) makes the issuance fields immutable for *everybody*,
  administrators and `checkPermissions => FALSE` callers included:
  `contact_id`, because it is the identity the token authenticates as, and
  `token_hash`, `token_prefix`, `created_id`, `created_date`, because they are
  the audit trail. The same fields carry `readonly` in the schema, but APIv4
  reads that as metadata for form builders and still writes them — measured on
  6.17.3, an `update` naming a `readonly` column succeeded — so the hook is the
  enforcement and `readonly` only keeps the fields off generated forms.
  Everything else on the row (`label`, `scope`, `mode`, `expires_at`) stays
  editable by an administrator.

The `civi.api4.authorizeRecord` listener judges `AuthorizeRecordEvent::getUserID()`,
the user the event names, for both the `administer` test and the ownership
comparison — not whoever happens to hold the session.

`KeyringDenial` is read-only through the API (`get` only); rows are written by
the service.

## Scope profiles

A setting `keyring_scope_profiles`, a map of name → list of permissions,
editable through a SearchKit/Afform screen. Shipped with **one** entry,
`empty`, holding no permissions. No guessed profile for the add-in: the
measurement showed one APIv3 `Contact.get` touching ten distinct permissions,
several of them incidental (`skip IDS check`, `edit api keys`), and a profile
invented from that list would be wrong in a way nobody would notice. The
add-in's profile is the first artefact report mode produces on the client's
own traffic.

## Contact tab

An Afform screen on a contact tab, `keyring tokens`, listing that contact's
tokens: label, prefix, scope profile or permission count, mode, expiry (the
date, not the stored end-of-day timestamp), last use, and the server-computed
`state`. Actions: create (plaintext shown once, in a dismissible block with
a copy button) and revoke (confirm dialog).

Accessibility requirements, checked before merge:

- The one-time plaintext block is an `aria-live="polite"` region and receives
  focus when it appears; it is not a colour-only highlight.
- Revoke is a `<button>` in the tab order with an accessible name that includes
  the token label ("Revoke token Outlook laptop"), never a bare icon.
- Expired and revoked rows are marked by text and an icon with a text
  alternative, not by row colour alone. Contrast is checked against the
  inherited CiviCRM theme colours, set explicitly rather than inherited.
- The copy button reports success in the live region, not only by changing its
  own label.

## Tests

**phpunit, headless** (`phpunit.xml.dist`):

- token generation: prefix shape, plaintext returned exactly once, hash stored,
  plaintext absent from `get`
- lookup: unknown hash, revoked token, expired token — each rejects, and each
  rejects *identically* from the outside
- scope: in-scope grant, out-of-scope deny, `authenticate with api key` always
  in scope, `all CiviCRM permissions and ACLs` stripped on save
- report mode: nothing denied, denial rows aggregated by
  `(token, permission, path)` with `hit_count`
- `last_used_at` throttle: a second touch inside the hour leaves the stored
  value untouched even though it offers a newer one; a revoked row is never
  touched
- expiry: a date is stored as that day's `23:59:59`, a datetime input and a
  past date are refused, and `state` turns from `active` to `expired` as a
  frozen `CRM_Utils_Time` passes the end of the day
- issuance metadata: an administrator's `update` of `token_prefix` is refused
  and the stored value is unchanged; `delete` is refused for everybody
- expiry on update: a date is stored as that day's `23:59:59`, a time of day and
  a past date are refused with the stored value unchanged, resending the stored
  value alongside a new label is not a change, and NULL clears the expiry
- lifecycle: install, upgrade, disable, uninstall; `ON DELETE CASCADE` from
  contact and from token

**phpunit, unit** (`phpunit-unit.xml.dist`): the scope matcher and the token
generator, no database.

**e2e on civikitchen** (`tests/e2e/`): the measurement suites become the
regression suite. A real `curl` shaped like the add-in —
`GET /civicrm/ajax/rest?entity=…&api_key=<token>&key=<sitekey>&json=…` — for
in-scope success, out-of-scope zero rows, revoked-token failure and
expired-token failure, plus the attribution assertions from
`30-attribution.sh` (`source_contact_id`, `log_user_id`) kept verbatim.

## Minimum size

| Area | Files | LOC (est.) |
| --- | --- | --- |
| schema + DAOs | 4 | 240 (mostly generated) |
| `Civi\Keyring\*` services (TokenService, TokenContext, ScopeService, DenialRecorder) | 4 | 320 |
| subscribers (authx, permission) | 2 | 120 |
| APIv4 entities + actions | 5 | 200 |
| settings + managed (profiles, nav, SearchKit, Afform) | 6 | 260 |
| contact tab (Afform + tab registration) | 3 | 140 |
| `keyring.php`, `info.xml`, upgrader | 3 | 90 |
| tests (unit + headless + e2e) | 10 | 700 |
| docs | 4 | — |
| **Total** | **~41** | **~2 070** |

The instrumentation in `Civi/Keyring/Measure/` (3 files, ~200 LOC), the
measurement page and its menu entry are removed in the same commit that lands
the real subscribers; `tests/e2e/measure/` is kept and repointed at the
product code.

## Decisions

1. **No token in the URL.** keyring tokens are accepted only on the header
   flows (`X-Civi-Auth: Bearer kr_…` via `xheader`, `Authorization: Bearer`
   via `header`). A `kr_` token arriving through `legacyrest` or `param` is
   rejected with an explicit error naming the header to use, so a token never
   lands in browser history, proxy logs or the access log. The test is both
   ways round: a matching header is required *and* no `_authx` or `api_key`
   parameter may carry a `kr_` credential. Proving a header exists says nothing
   about what the URL still holds, and a token that also rode in the query
   string has already been logged whatever the header says. Consequence: the
   Outlook add-in is forked (own repo, name and GitHub creation on Johannes'
   go) and changed to send the token in `X-Civi-Auth` instead of `api_key=`.
   The add-in fork also drops the `com.cividesk.apikey` dependency, which does
   not run on current CiviCRM. The e2e suite's add-in-shaped curl therefore
   sends the header, and one negative test proves the URL form is refused.
2. **Scope granularity** is permission strings only. No per-entity allowlist.
   A profile name is at most 64 characters — the width of the `scope_profile`
   column — its permissions are non-empty strings, and repetitions are
   normalised away on parse.
3. **Who may issue tokens:** both. `manage own keyring tokens` for the person
   themselves, `administer keyring tokens` for administrators.

## Deviations, with the evidence that forced them

Phase 2 implemented everything above. Five points changed; each was measured in
the running stack, not reasoned about.

1. **The flow is not on the event.** Decision 1 said to read `$e->flow` in the
   `civi.authx.checkCredential` subscriber. `CheckCredentialEvent` (read from
   `core/ext/authx/Civi/Authx/CheckCredentialEvent.php` on 6.17.3) carries
   `credFormat`, `credValue`, `requestPath`, `principal` and `rejection` — and
   no flow. The flow lives on `AuthenticatorTarget`, which only the later
   `civi.authx.checkPolicy` event exposes. `Civi\Keyring\Authx\CredentialSource`
   therefore establishes the source directly: the credential is accepted only if
   `credFormat . ' ' . credValue` is byte-identical to `X-Civi-Auth` or
   `Authorization`. That is a stronger test than a flow name — it asserts where
   the bytes came from — and the e2e suite proves all four cases (header and
   xheader accepted, legacyrest and param refused with the header named).

2. **The scope profile screen is core's settings form, not Afform.** Afform has
   no `Setting` entity (`cv api4 Entity.get +w name=Setting` returns an
   `AbstractEntity` with no Afform binding), so a name→permissions map cannot be
   bound to an `af-field`. The setting is rendered by core's
   `CRM_Admin_Form_Generic` at `civicrm/admin/setting/keyring`, with a
   `validate_callback` that refuses malformed JSON with its own message. No form
   code is written by this extension.

3. **The contact tab is a custom Angular component, not a SearchKit display.**
   The plan's accessibility requirement is a per-row `<button>` whose accessible
   name contains the token label. SearchKit tables offer row actions only
   through the bulk task menu — search_kit's display modules contain no per-row
   task link — so that button cannot exist in a stock display. `ang/keyringTokens`
   renders the list, the issue form, the one-time plaintext block (an
   `aria-live` region that takes focus) and the named revoke button. SearchKit
   is used where it fits: the two admin lists, plus a `keyring_revoke` bulk task
   built from core's `apiBatch` mechanism, which supplies the confirmation
   dialog.

4. **`TokenContext` is static state, not a container service.** It is read once
   per permission check and a single contact summary asks 690 of them; a
   container round-trip per check buys nothing and a memoised service instance
   would outlive a headless test's container rebuild. Static properties have
   exactly the request lifetime the design asks for, and `reset()` makes them
   testable.

5. **The denial flush is `register_shutdown_function`, not `civi.api.respond`.**
   Page requests never reach `civi.api.respond`, and `civicrm/contact/view` is
   where the denial volume actually is. The shutdown function is registered on
   the first buffered denial, so a request that denies nothing registers nothing.

Two smaller findings worth recording:

- **Afform routes are gated twice.** Measured in report mode against
  `civicrm/admin/keyring/tokens`: the request was refused
  `administer keyring tokens` (twice) *and* `@afform:afsearchKeyringTokens`. A
  scope that is to reach an Afform page needs the synthetic permission verbatim.
- **`CRM_Core_Permission::check()` ANDs its top level and ORs a nested array.**
  An APIv4 `permissions()` map meaning "either of these" is
  `[[A, B]]`, not `[[A], [B]]` — the latter is an AND and locked out every
  `manage own keyring tokens` holder until a headless test caught it.
