# keyring

Scoped, revocable API tokens per contact for CiviCRM Standalone.

CiviCRM gives a contact exactly one `api_key`, and it carries the full scope of
that person's role. A client on a user device — the Outlook 365 add-in, for
instance — therefore runs with a credential that can do everything its owner
can do in the UI. keyring issues several tokens per person instead, each with a
permission allowlist, an expiry and a last-use timestamp, each revocable on its
own, and all of them still attributed to the person: no shadow contact, no
second user account.

## How a token flows through a request

1. The client sends `X-Civi-Auth: Bearer kr_…` (or `Authorization: Bearer`).
   A token in a URL or form parameter is refused with an error naming the
   header — a token must not land in browser history, proxy logs or an access
   log.
2. `Civi\Keyring\Authx\TokenSubscriber` runs on `civi.authx.checkCredential` at
   priority **-250** — above core's `api_key` resolver (-300), below its Basic
   listener (-200). Only `kr_`-prefixed credentials are touched; everything
   else falls through to core untouched.
3. The token is looked up by the SHA-256 of its plaintext. Unknown, revoked and
   expired all fail with the same words. `last_used_at` is refreshed at most
   once an hour.
4. On success the subscriber accepts the principal
   (`credType: api_key`, the contact's id), so authx logs in as the person and
   every write is attributed to them.
5. `Civi\Keyring\Permission\ScopeSubscriber` runs on
   `hook_civicrm_permission_check` at `PHP_INT_MIN` and takes away every
   permission outside the token's scope — or, in report mode, records it
   instead. It never grants anything.

A permission missing from a scope is not an error: a read simply returns no
rows. That is why report mode exists, and why no scope profile is guessed.

## Documentation

| Document | What it covers |
| --- | --- |
| [docs/operations.md](docs/operations.md) | Issuing a token, deriving a scope profile from report mode, revoking, what the client must send |
| [docs/plan.md](docs/plan.md) | Architecture: schema, APIv4 surface, scope enforcement, report mode, contact tab, tests |
| [docs/measurements.md](docs/measurements.md) | What was measured on CiviCRM 6.17.3 Standalone: authx listener priorities and flows, how far `hook_civicrm_permission_check` reaches, attribution of token-authenticated writes |
| [docs/contracts.md](docs/contracts.md) | Working notes and the contracts to preserve |

## Permissions

| Permission | Grants |
| --- | --- |
| `manage own keyring tokens` | Issue, read and revoke tokens for oneself |
| `administer keyring tokens` | The same for anybody, plus the admin lists, the denial trail, and editing or deleting a stored token |

`manage own keyring tokens` covers `create`, `get` and `revoke` and nothing
else: the generic APIv4 `update` and `save` need `administer keyring tokens`,
and `replace` is always denied. A token's `contact_id` is immutable after issue
for every caller, administrators included — it is the identity the token
authenticates as.

## Known gap

Token labels are free text and are stored and returned verbatim; escaping them
is the UI's job, and the contact tab does it with `_.escape()` before a label
reaches `CRM.confirm`, which inserts its message with jQuery `.html()`. There is
**no browser test** proving that escaping holds — the repository has no
JavaScript test runner, and adding one for a single call is not worth the build
step. What is tested is the layer below: `tests/e2e/measure/40-escaping.sh`
issues a token labelled `<img src=x onerror="alert(1)">` over HTTP and asserts
`get` returns it byte-for-byte, which is what makes the UI's escaping load-
bearing. Re-check the escaping by hand when touching `ang/keyringTokens/`.

## Development

civikitchen has no single "run everything" command, so the list below is the
whole of what CI runs — keep it that way, or a gate reaches the pipeline
unrun.

```bash
cd .docker && docker compose up -d      # CiviCRM on http://localhost:8100 (admin/admin)

# inside the app container, from /var/www/html/ext/keyring:
ckphpunit -c phpunit-unit.xml.dist      # pure unit suite, no database
ckcoverage tests/phpunit                # headless suite against the coverage floor
cklint --all && ckconform && ckcivix --check && ckfmt --check
phpstan analyse && ckcompat && ckdeps && cktaint && cksmarty && ckeslint
cklifecycle                             # install/disable/enable/uninstall

# from the host:
bash tests/e2e/measure/run.sh                       # end-to-end against the dev stack
/path/to/civikitchen/scaffold/ckinit.php --check .  # template drift
```

`ckfmt` without `--check` writes the formatting the gate asks for; re-run the
linters afterwards, because it moves code.

maildev is on http://localhost:1180, phpMyAdmin on http://localhost:8101. The
compose project is named `keyring`; `docker compose down -v && up -d` re-runs
provisioning from scratch, which is what a schema change needs.

`.docker/fetch-ext.sh` clones the Outlook 365 add-in and its dependencies into
`~/.cache/keyring-ext/` for source reading — outside the repository, and
deliberately **not** mounted into the stack. See `docs/measurements.md`.

## License

Proprietary. Copyright (C) 2026 civico GmbH. See `LICENSE.txt`.
