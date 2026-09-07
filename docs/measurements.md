# Measurements

Every statement here was produced by running the suites in
`tests/e2e/measure/` against the `.docker` stack, not by reading source. Raw
evidence stays in `tests/e2e/measure/out/` (gitignored; regenerate with
`bash tests/e2e/measure/run.sh`).

## Environment

| Item | Value |
| --- | --- |
| CiviCRM | 6.17.3 (`cv ev 'echo CRM_Utils_System::version();'`) |
| CMS | Standalone (`standaloneusers`) |
| PHP | 8.4.25 |
| Image | `ghcr.io/jfilter/civikitchen:v1` |
| Compose project | `keyring`, app on `http://localhost:8100` |
| Extensions enabled | `authx`, `keyring` |
| Measurement subject | a non-superuser contact + Standalone user + role, seeded by `tests/e2e/measure/seed.php` |

The subject deliberately is **not** the demo admin: Standalone's admin role
holds `all CiviCRM permissions and ACLs`, which `CRM_Core_Permission::check()`
tests first and short-circuits on. Measured against admin, the hook only ever
sees that one wildcard permission and never the permission actually asked for
— the measurement would have been meaningless.

## Q1 — Does an api_key request reach `civi.authx.checkCredential` first?

Yes, for every flow, and the raw credential arrives verbatim.

**Priorities.** Core's listeners are constants in
`core/ext/authx/Civi/Authx/CheckCredential.php`:

```
29:  const PRIORITY_BASIC_USER = -200;
35:  const PRIORITY_BEARER_API_KEY = -300;
41:  const PRIORITY_BEARER_JWT = -400;
```

Probes registered at `1000, 0, -199, -201, -301, -401` were observed running in
that order (`out/q1-listeners.txt`). **A keyring listener must sit above -300**
(core's api_key resolver). keyring uses `-250`: above core's api_key and
JWT listeners, below core's Basic-auth listener, which serves username/password
and must keep precedence.

**Accepting stops the chain.** `CheckCredentialEvent::accept()` calls
`stopPropagation()` (`core/ext/authx/Civi/Authx/CheckCredentialEvent.php`), so
core never sees a credential keyring owns. Measured: with a `kr_` token, only
the priority-1000 probe logged; with an unknown credential, all six probes
logged and core rejected it:

```
request 9182a8c8 /civicrm/ajax/rest
  prio  1000  format=Bearer prefix='not' ... acceptedAlready=False
  prio     0  ...
  prio  -199  ...
  prio  -201  ...
  prio  -301  ...
  prio  -401  ...
```

**The credential is verbatim.** `CheckCredentialEvent` splits the string on the
first space into `credFormat` and public `credValue`; the probe logged
`credFormat=Bearer prefix='kr_'`. For the legacy REST endpoint the event is
built as `'Bearer ' . $_REQUEST['api_key']`
(`Civi/Authx/LegacyRestAuthenticator.php:34`), so a prefix test on `credValue`
works on all flows.

**Flows, as shipped on Standalone** (`out/q1-policy.txt`, no settings touched
by this stack):

```json
{"legacyrest_cred":["jwt","api_key"],"legacyrest_user":"require",
 "param_cred":["jwt","api_key"],"param_user":"optional",
 "header_cred":["jwt","api_key"],"xheader_cred":["jwt","api_key"],
 "guards":["site_key","perm"]}
```

All four are enabled out of the box. Measured working with a keyring token:

| Flow | Request shape | Result |
| --- | --- | --- |
| `legacyrest` | `GET /civicrm/ajax/rest?entity=…&action=…&api_key=<tok>&key=<sitekey>&json=…` | 200, `is_error:0` |
| `xheader` | `X-Civi-Auth: Bearer <tok>` + `X-Civi-Key: <sitekey>` | 200 |
| `header` | `Authorization: Bearer <tok>` | 200 |
| `param` | `&_authx=Bearer%20<tok>&_authxSiteKey=<sitekey>` | 200 |

APIv4 REST (`/civicrm/ajax/api4/Entity/action`) authenticates through the same
`Authenticator`, so all header/param flows cover it.

**The Outlook 365 add-in uses `legacyrest`.** Its request shape, read from
`assets/taskpane.js` in the upstream checkout:

```js
function restUrl(config) { return config.url + '/civicrm/ajax/rest'; }
var data = { "entity": "Group", "action": "get",
             "api_key": config.apikey, "key": config.sitekey,
             "json": { … } };
```

`GET`, query string, always both `api_key` and `key` (the **site key**), APIv3,
one contact-scoped API key per person. `legacyrest_user: "require"` means the
contact must have a Standalone user account — keyring tokens inherit that.

**The site key is not strictly required.** `authx_guards` is
`["site_key","perm"]` and the guards are OR'd
(`Civi/Authx/Authenticator.php:258-271`): the request passes if the site key
matches **or** the contact holds `authenticate with api key`. Measured: the
same call without `key=` returned `is_error:0`.

## Q2 — Does `hook_civicrm_permission_check` reach the decisions that matter?

Yes for everything measured, and **every** logged check entered through
`CRM_Core_Permission::check` — across 757 checks in four requests there was no
other entry point.

Volume per request (report mode, `out/q2-report.txt`):

| Request | checks | distinct permissions |
| --- | --- | --- |
| `civicrm/ajax/rest` (APIv3 Contact.get) | 30 | 10 |
| `civicrm/ajax/api4/Contact/get` | 12 | 7 |
| `civicrm/keyring/measure` (extension page) | 25 | 8 |
| `civicrm/contact/view` (core page) | 690 | 45 |

Call chains, trimmed to `Class::method`, one per decision kind:

```
'authenticate with api key'  granted=True
  CRM_Core_Permission::check → Civi\Authx\Authenticator->checkPolicy → Authenticator->auth → LegacyRestAuthenticator->on_civi_invoke_auth
'access CiviCRM'             granted=True
  CRM_Core_Permission::check → CRM_Core_Permission::checkMenu → CRM_Core_Permission::checkMenuItem → CRM_Core_Invoke::runItem
'access keyring measurement page' granted=True
  CRM_Core_Permission::check → CRM_Core_Permission::checkMenu → CRM_Core_Permission::checkMenuItem → CRM_Core_Invoke::runItem
'view all activities'        granted=True    (APIv4 entity permissions)
  CRM_Core_Permission::check → Civi\Api4\Generic\AbstractAction->isAuthorized → PermissionCheckSubscriber->onApiAuthorize
'view all contacts'          granted=True    (APIv3 ACL clause)
  CRM_Core_Permission::check → CRM_Contact_BAO_Contact_Permission::cacheClause → CRM_Contact_BAO_Query->generatePermissionClause
'view all contacts'          granted=True    (APIv4 ACL sub-query)
  CRM_Core_Permission::check → CRM_Contact_BAO_Contact_Permission::cacheSubquery → CRM_Contact_BAO_Contact->addSelectWhereClause
'edit api keys'              granted=False   (APIv3 field stripping)
  CRM_Core_Permission::check → CRM_Contact_BAO_Contact::unsetProtectedFields → civicrm_api3_contact_get
```

So `checkMenu`/`checkMenuItem` do **not** bypass the hook — they delegate to
`CRM_Core_Permission::check`. Neither do APIv4 entity permissions, nor the
coarse ACL clauses that decide whether a contact query returns rows at all.

**Enforcing at the hook really gates.** With the scope reduced to
`authenticate with api key`, `access CiviCRM`, `access AJAX API`:

| Call | Result |
| --- | --- |
| APIv3 `Contact.get` | HTTP 200, `"count":0` — the ACL clause matches nothing |
| APIv4 `Contact.get` | HTTP 200, `"count":0` |
| APIv3 `Activity.create` (POST) | error, permission refused |
| extension page | HTTP 403 |
| core page `civicrm/contact/view` | HTTP 302 (redirect to login) |

Adding `access keyring measurement page` to the scope served the page (200).
A read outside the scope therefore degrades to **zero rows, not an error** —
correct, but it means a scope that is too narrow looks like empty data to the
client rather than like a permission problem. Report mode exists for exactly
this.

### Decisions that do NOT pass through the hook

- **The authx guard itself.** `Authenticator::checkPolicy()` calls
  `CRM_Core_Permission::check('authenticate with api key', $contactId)`
  *before* login. Measured: with that permission out of the scope and no site
  key, authentication fails outright —
  `FATAL: Login not permitted. Must satisfy guard (site_key, perm).` With the
  site key sent, the same request authenticates. This check does reach the
  hook, and that is the problem: keyring must **exempt** it, or every token
  whose scope omits it breaks on sites that rely on the perm guard.
- **`CoreUtil::checkAccessRecord`** (`Civi/Api4/Generic/AbstractCreateAction.php:49`)
  — per-record ACL, evaluated separately from `CRM_Core_Permission::check`.
- **Row-level ACL rules** from `civicrm_acl` (group-based). The coarse
  `view all contacts` gate is hooked; individual ACL grants are not.
- **Pre-login checks.** `access CiviCRM` was logged `granted=False` via
  `CRM_Core_Resources_Common::coreResourceList` and then `granted=True` via
  `checkMenuItem` **in the same request** — the resource bundle is built before
  authx logs the contact in. Anything keyring decides must tolerate a
  contact-less first pass.

### The hook is not cached

`all CiviCRM permissions and ACLs` was asked **208 times** in one
`civicrm/contact/view` request, and the probe logged all 208. There is no
per-request memoisation of the hook result. Any keyring listener must be O(1)
against an in-memory scope array — no query, no file read per check.

## Q3 — Attribution

All five checks pass (`bash tests/e2e/measure/30-attribution.sh`), so a request
authenticated by a `civi.authx.checkCredential` subscriber is attributed to the
person exactly like an interactive login.

| Assertion | Result |
| --- | --- |
| APIv3 `Activity.create` → `civicrm_activity_contact.record_type_id=2` | contact id of the subject |
| APIv4 `Activity.create` → `source_contact_id` | contact id of the subject |
| APIv4 `Note.create` with `contact_id` **omitted** | defaults to the subject |
| `log_civicrm_activity.log_user_id` | contact id of the subject |
| `log_civicrm_note.log_user_id` | contact id of the subject |

Two facts worth carrying into the design:

- **`log_user_id` holds the CONTACT id, not the CMS user id.**
  `CRM_Core_Config::authenticate()` runs
  `SET @civicrm_user_id = <session 'userID'>` (`CRM/Core/Config.php:210-214`),
  and CiviCRM's session `userID` is the contact id. The subject's Standalone
  user id was 2 and `log_user_id` was 3, the contact id.
- **APIv4 `Activity.create` requires an explicit `source_contact_id`.** It
  derives nothing from the session:
  `ActivitySpecProvider.php:45 → $field->setRequired($action === 'create')`.
  Measured: omitting it returns
  `Mandatory values missing from Api4 Activity::create: source_contact_id`.
  APIv3 fills it from the session; APIv4 does not. Not keyring's problem, but
  it is why the add-in's APIv3 calls attribute correctly and a naive APIv4
  rewrite would not.
- Writes must be `POST`. A database-modifying `GET` is refused by core with
  `SECURITY: All requests that modify the database must be http POST, not GET`.

## Blocked: outlook365 cannot be installed on 6.17

`outlook365` `<requires>` `com.cividesk.apikey`, whose last commit is from
2021 and which targets CiviCRM 4.7/5.0. Enabling it aborts:

```
Enabling extension "com.cividesk.apikey"
In Smarty.php line 37:
  Cannot access protected property Smarty\Smarty::$template_dir
```

The civikitchen image auto-enables every extension mounted under `ext/`, so a
mount alone is enough to break the boot. The clones live outside the
repository in `~/.cache/keyring-ext/` (`.docker/fetch-ext.sh`) for source
reading and are not mounted. Q2's
"extension-declared page permission" was measured instead against
`civicrm/keyring/measure`, which is shaped exactly like outlook365's
`access outlook 365 pages` route (`xml/Menu/outlook365.xml:14`).

This matters for the first deployment: the add-in's server side needs a
maintained API-key extension, or the client's route stays plain
`civicrm/ajax/rest` against core entities.
