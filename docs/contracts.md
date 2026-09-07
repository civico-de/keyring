# keyring — working notes

Several revocable API tokens per contact, each with a permission allowlist, an
expiry and a last-use timestamp. A token is recognised by its `kr_` prefix in a
`civi.authx.checkCredential` subscriber; the scope is enforced in
`hook_civicrm_permission_check`. The person's role is never modified.

`docs/plan.md` is the architecture and closes with the deviations the
implementation had to make; `docs/measurements.md` is the evidence it rests on; `docs/operations.md`
is how the thing is run. Keep all three in step with the code.

## Contracts to preserve

- **The authx subscriber sits at priority -250.** Core's listeners are at -200
  (Basic), -300 (Bearer api_key) and -400 (Bearer JWT). Above -300 so keyring
  owns its tokens; below -200 so username/password keeps precedence.
  `accept()` stops propagation — core never sees a claimed token.
- **Only `kr_`-prefixed credentials are touched.** Everything else falls
  through to core untouched. A `kr_` credential that does not resolve is
  `reject()`ed, never left to be tried as a contact api_key.
- **`credType` is `api_key`.** `Authenticator::checkPolicy()` rejects any
  credType outside `authx_<flow>_cred`, which ships as `["jwt","api_key"]`.
- **`authenticate with api key` is always in scope.** authx checks it via
  `CRM_Core_Permission::check` *before* login, and `authx_guards` is
  `["site_key","perm"]` (OR'd). Denying it breaks authentication itself on any
  site not sending the site key.
- **`all CiviCRM permissions and ACLs` is never in scope.**
  `CRM_Core_Permission::check` tests it first and short-circuits, so leaving it
  in makes the whole scope a no-op.
- **`PreEvent::__set()` is a silent no-op.** It overrides `getHookValues()` and
  never fills `hookValues`, so a `hook_civicrm_pre` listener that has to change
  a value writes through the live `$event->params` reference instead.
- **The permission hook is not memoised.** One `civicrm/contact/view` request
  asked one permission 208 times and fired the listener 208 times. The scope
  lives in a request-scoped in-memory array; no query, no file read, no cache
  lookup per check.
- **The plaintext token is never stored.** `sha256` of 32 random bytes, shown
  once at create. Unsalted deliberately: the secret has full entropy, and a
  per-row salt would turn the hot-path lookup into a table scan.
- **Revocation and expiry are enforced at authentication**, not at permission
  time — one check per request, and a revoked token fails exactly like an
  unknown one.
- **`expires_at` is a date.** Input `YYYY-MM-DD`, stored as that day's
  `23:59:59` in the site timezone, on `create` and on `update` alike. The DAO
  rewrites dates to `YmdHis` before `hook_civicrm_pre`, so the update path can
  only accept `00:00:00` or `23:59:59` and refuse any other time of day. `KeyringToken.state` (`active`/`expired`/
  `revoked`) is computed in SQL by a SpecProvider's `sql_renderer` against
  `CRM_Utils_Time`; nothing derives it in JavaScript.
- **Nothing is ever deleted.** `delete` is always-denied on both entities, so
  SearchKit offers no Delete task and the denial history survives. A contact
  deletion still cascades, which is intended.
- **The issuance fields are immutable.** `contact_id`, `token_hash`,
  `token_prefix`, `created_id`, `created_date` are locked in
  `hook_civicrm_pre` (`TokenWriteSubscriber`). Their `readonly` schema flag is metadata only — APIv4
  write actions ignore it (measured; version in `docs/measurements.md`).
- **Denials are aggregated**, keyed `(token_id, permission, request_path)` with
  a `hit_count`, flushed once per request. Per-check rows are unusable at 690
  checks per page view.
- **Not the session.** The flows the add-in uses are stateless; the token
  context is a request-scoped service and dies with the request.

- **A token is accepted only from a header.** `CheckCredentialEvent` carries no
  flow name, so `CredentialSource` compares the credential byte-for-byte against
  `X-Civi-Auth` and `Authorization`. Anything that does not match came from a
  URL or a body and is rejected with a message naming the header.
- **Afform routes are gated twice**, by their declared permission and by a
  synthetic `@afform:<form name>`. A scope that must reach an Afform page needs
  both.
- **`CRM_Core_Permission::check()` ANDs the top level, ORs a nested array.** An
  APIv4 `permissions()` entry meaning "either of these" is `[[A, B]]`.

## Tests

The e2e suites in `tests/e2e/measure/` are the regression suite; the directory
keeps its name because the evidence in `docs/measurements.md` cites it.
The subject is a seeded **non-superuser** (`tests/e2e/measure/seed.php`):
testing against Standalone's admin is worthless, because
`all CiviCRM permissions and ACLs` short-circuits every check before the real
permission is ever asked. Tokens never reach a command line — curl reads its
whole configuration, headers included, from stdin.

The list below is the whole of what CI runs; civikitchen ships no wrapper
that runs all of them, so anything left out locally reaches the pipeline unrun.

```bash
cd .docker && docker compose up -d      # down -v && up -d after a schema change

ck=(docker compose exec -u www-data -w /var/www/html/ext/keyring app)
"${ck[@]}" ckphpunit -c phpunit-unit.xml.dist
"${ck[@]}" ckcoverage tests/phpunit
"${ck[@]}" bash -c 'cklint --all && ckconform && ckcivix --check && ckfmt --check'
"${ck[@]}" bash -c 'phpstan analyse && ckcompat && ckdeps && cktaint && cksmarty && ckeslint'
"${ck[@]}" cklifecycle

bash tests/e2e/measure/run.sh
/path/to/civikitchen/scaffold/ckinit.php --check .
```

`ckfmt` without `--check` writes the fixes; the linters have to run again
after it, because formatting moves code. `--check` is what CI runs.

`ckconform`'s residual warnings are scanner artefacts, not findings:
`KeyringDenial` has a column literally named `permission`, which its
permission-closure check reads as a permission specification, and `*always deny*`
is a core sentinel missing from its list of core permissions.

`outlook365` cannot be installed on current core (its dependency
`com.cividesk.apikey` was last released for CiviCRM 5.0 and dies on Smarty 5).
The civikitchen image auto-enables everything mounted under `ext/`, so mounting
it alone breaks the boot. `.docker/fetch-ext.sh` clones it to
`~/.cache/keyring-ext/`, outside the tree, for reading only.
