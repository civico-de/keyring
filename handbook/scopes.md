---
title: Scopes
order: 10
routes:
  - civicrm/admin/keyring/denials
  - civicrm/admin/setting/keyring
---

# Scopes

The scope of a token is the list of permissions it may use. A token never gets more than its contact has: it can use a permission only if the permission is in its scope and the contact holds it.

A permission missing from a scope does not cause an error message. A search simply finds nothing, and a screen stays empty. A guessed scope therefore produces results that look plausible and are wrong. keyring has a report mode so that you can measure the scope instead.

## Scope profiles

A scope profile is a named list of permissions. Profiles are defined under **Administer → keyring → Settings** as a JSON object that maps each profile name to its permissions:

```json
{
  "empty": [],
  "addin": ["access CiviCRM", "access AJAX API", "view all contacts", "edit all contacts", "add contacts", "view all activities"]
}
```

Invalid JSON is refused on save, with the reason.

Two permissions are handled for you. "authenticate with api key" is always part of a scope, because a token cannot log in without it. "all CiviCRM permissions and ACLs" is always removed, because it would allow everything.

## Modes

| Mode | Effect |
|---|---|
| Enforce | The token can use only the permissions in its scope. |
| Report only | The token can use everything its contact may do. Every permission outside its scope is recorded as a denial instead of being refused. |

## Finding the right scope

1. Issue a token in report mode with the smallest profile you think is enough.
2. Let the client work for a representative period: a week for a mail add-in, a full cycle for a job that runs monthly.
3. Open **Administer → keyring → Denials**. Each row is one token, one permission and one address, with the number of times it was asked. A permission asked a few times from a single address is as real as one asked thousands of times.
4. Add the permissions you need to the profile.
5. Issue a new token with this profile in enforce mode and revoke the report-mode token.

FormBuilder pages are additionally protected by a permission named `@afform:` followed by the form name. It appears in the denial list like any other permission. Add it to the profile exactly as shown.
