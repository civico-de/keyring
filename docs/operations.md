# Operating keyring

Everything here is a supported path through the UI or APIv4. Nothing needs
database access.

## What the client must send

```
X-Civi-Auth: Bearer kr_<the token>
```

`Authorization: Bearer kr_<token>` works identically. Both flows are enabled in
authx out of the box.

A token sent as `?api_key=…` (the legacy REST flow) or `?_authx=…` (the param
flow) is **refused**, with:

> A keyring token must be sent as a request header ("X-Civi-Auth: Bearer
> \<token>" or "Authorization: Bearer \<token>"), never as a URL or form
> parameter.

The site key is optional. `authx_guards` is `["site_key","perm"]` and the guards
are OR'd; every keyring scope implicitly contains `authenticate with api key`,
so the perm guard is always satisfied.

## Issuing a token

On the contact's **API tokens** tab: *Issue a token*, pick a label, a scope
profile and a mode, optionally an expiry date. The plaintext appears once, in a
block that takes focus and announces itself; copy it then. Only its SHA-256
digest is stored, so a lost token is replaced, never recovered.

Through APIv4:

```php
\Civi\Api4\KeyringToken::create()
  ->setContactId($contactId)
  ->setLabel('Outlook laptop')
  ->setScopeProfile('addin')     // or ->setScope(['access CiviCRM', …])
  ->setMode('report')
  ->execute()->single()['token'];
```

`scope` and `scopeProfile` are mutually exclusive and one of them is required —
there is no default scope. Two permissions are handled for you: `authenticate
with api key` is always added, `all CiviCRM permissions and ACLs` is always
removed.

`manage own keyring tokens` covers tokens for oneself; issuing for somebody else
needs `administer keyring tokens`.

## Deriving a scope profile from real traffic

A permission missing from a scope does not produce an error — a read returns
zero rows. Guessing a scope therefore ships silent, plausible-looking wrong
answers. Derive it instead:

1. Issue the token in **report mode** with the smallest scope you believe in.
   Report mode denies nothing; it records what the scope *would* have refused.
2. Let the client run for a representative period — a week for a mail add-in, a
   full cycle for anything with a monthly job.
3. Read **Administer → keyring → Denials**. Each row is one
   `(token, permission, request path)` with a hit count; sort by hits and read
   from the top. A permission with a handful of hits from one path is as real as
   one with thousands.
4. Add the permissions to a profile under **Administer → keyring → Settings**,
   a JSON object of profile name to list of permissions:

   ```json
   {
     "empty": [],
     "addin": ["access CiviCRM", "access AJAX API", "view all contacts", "edit all contacts", "add contacts", "view all activities"]
   }
   ```

   Malformed JSON is rejected on save with the reason.
5. Issue a fresh token on the new profile in **enforce** mode and revoke the
   report-mode one. Switching an existing token's mode works too, but a new
   token proves the profile from scratch.

Two things do not show up as denials:

- **Afform pages** are additionally gated by a synthetic `@afform:<form name>`
  permission. It appears in the denial list like any other; add it verbatim.
- **`authenticate with api key`** never appears, because it is always in scope.

## Revoking

Per token: the **Revoke** button on the contact's tab, which names the token it
revokes and asks for confirmation.

In bulk: select rows on **Administer → keyring → Tokens** and run *Revoke
tokens*.

Through APIv4:

```php
\Civi\Api4\KeyringToken::revoke()->addWhere('id', '=', $tokenId)->execute();
```

Revocation is enforced at authentication, so it takes effect on the client's
very next request. A revoked token fails with exactly the same message as an
unknown one.

**There is no delete.** `KeyringToken.delete` and `KeyringDenial.delete` are
refused for every permission, so the SearchKit lists offer no Delete task: the
row is the anchor of the denial history and the audit trail. Deleting the
contact still removes their tokens, through the foreign key. A token that is
finished is revoked, not removed.

## Expiry

`expires_at` is a **date**, entered as `YYYY-MM-DD`; empty means the token never
expires. It is stored as the end of that day (`23:59:59`) in the site timezone,
so a token that expires on 2026-12-31 works for the whole of 31 December. A time
of day is refused rather than quietly truncated, and a date in the past is
refused — a token is never issued already expired.

Editing the expiry later follows the same rule: an administrator's update takes
`YYYY-MM-DD`, stores the end of that day, and is refused for a time of day or a
past date. An empty value clears the expiry.

An expired token is refused at authentication, identically to a revoked one.
Expiry is not a substitute for revocation — it is what limits the damage of a
token nobody remembers.

The contact tab and the admin list show a **state** the server computes:

| State | Meaning |
| --- | --- |
| Active | usable right now |
| Expired | past the end of its expiry day |
| Revoked | revoked, whatever the expiry says |

Through APIv4 it is the `state` field on `KeyringToken.get`, with `state:label`
for the translated text. It is read-only and cannot be set.

## What an administrator may change

`label`, `scope`, `mode` and `expires_at` (as a date, see above). Fixed at issue and refused on every
write path, `checkPermissions => FALSE` included: `contact_id` (the identity the
token authenticates as), `token_hash`, `token_prefix`, `created_id` and
`created_date`. Reissue rather than edit.

## Reading who still uses what

`last_used_at` is written at most once per hour, which is enough to answer "is
this token still in use, can I revoke it". A token with no last use has never
authenticated. A revoked token stops being stamped, so its last use is the last
time it actually worked.

## Visibility

A holder of `manage own keyring tokens` sees only their own tokens and denials.
Because `KeyringToken.contact_id` is a contact reference, core's own ACL applies
first: a caller who cannot see the contact sees none of its tokens either.
`administer keyring tokens` bypasses both.
