---
title: keyring
routes:
  - civicrm/admin/keyring/tokens
---

# keyring

keyring gives a contact several API tokens instead of the one API key CiviCRM provides. Each token has its own permission list, an optional expiry date and a record of when it was last used, and each can be revoked on its own. A token logs in as its contact, so everything done with it is attributed to that person. There is no extra user account and no shadow contact.

Use it for clients that talk to CiviCRM on a person's behalf, such as a mail add-in on a laptop. The client gets only the permissions it needs, and a lost device costs one token, not the person's whole access.

## Requirements

keyring needs the extensions authx, FormBuilder and SearchKit, which ship with CiviCRM. The supported CiviCRM and PHP versions are listed in the extension's `info.xml`.

## Install

Download the release archive from the [releases page](https://github.com/civico-de/keyring/releases), unpack it into your CiviCRM extensions directory and enable it:

```bash
cv ext:enable keyring
```

Then grant the permissions below to the roles that should work with tokens.

## Permissions

| Permission | Allows |
|---|---|
| manage own keyring tokens | Issuing, viewing and revoking one's own tokens |
| administer keyring tokens | The same for every contact, plus the lists under **Administer → keyring**, the denial list and editing a token |

## Issuing a token

1. Open the contact and go to the **API tokens** tab.
2. Click **Issue a token**.
3. Enter a label that names the client, for example "Outlook laptop".
4. Choose a scope profile and a mode, see [Scopes](scopes.md).
5. Optionally enter an expiry date.
6. Click **Create token**.

The token appears once, right after it is issued. Copy it into the client then. CiviCRM stores only a fingerprint of the token, so a lost token cannot be shown again. Revoke it and issue a new one.

## What the client sends

The client sends the token in a request header:

```
X-Civi-Auth: Bearer kr_…
```

`Authorization: Bearer kr_…` works the same way. A token in the address or in a form field is refused, because addresses end up in browser histories and server logs.

## States

The **API tokens** tab and the list under **Administer → keyring → Tokens** show the state of each token:

| State | Meaning |
|---|---|
| Active | The token works. |
| Expired | The expiry date has passed. |
| Revoked | The token was revoked. It never works again. |

An expiry date is a day, not a time: a token that expires on 31 December works until the end of that day. A date in the past is refused. Leave the field empty for a token that does not expire; its expiry then shows "never".

**Last used** shows when the token last logged in, updated at most once an hour. A token that was never used shows "never". Use it to find tokens nobody needs any more.

## Revoking

Click **Revoke** next to the token on the contact's **API tokens** tab and confirm. To revoke several tokens at once, select them under **Administer → keyring → Tokens** and choose **Revoke tokens**.

A revoked token stops working with the client's next request. Tokens cannot be deleted: a token is kept as the reference for its denial history. Deleting the contact removes the contact's tokens.
