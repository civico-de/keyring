# Changelog

## [Unreleased]

## [0.1.0-alpha.2] - 2026-10-22

### Changed

- Require PHP 8.2 or later (previously 8.3).

### Fixed

- A token now grants an in-scope permission that its contact holds only through an implying
  permission or the `all CiviCRM permissions and ACLs` super-permission, so a superuser's token
  is no longer denied everything.

## [0.1.0-alpha.1] - 2026-09-13

### Changed

- Internal maintenance; no change for sites.

## [0.1.0-alpha] - 2026-09-07

### Added

- Scoped, revocable API tokens per contact: several tokens per person, each with a permission
  allowlist, an expiry and a last-use timestamp, all attributed to the person.
- Token authentication through the authx header flows only (`X-Civi-Auth: Bearer` or
  `Authorization: Bearer`); tokens in URLs or form parameters are refused.
- Scope enforcement that removes every permission outside the token's scope, with a report mode
  that records denials instead of enforcing them.
- APIv4 entities for tokens and recorded denials, with the permissions `manage own keyring
  tokens` and `administer keyring tokens`.
- A contact tab for issuing and revoking tokens, admin searches, and scope profile settings.

[Unreleased]: https://github.com/civico-de/keyring/compare/v0.1.0-alpha.2...HEAD
[0.1.0-alpha.2]: https://github.com/civico-de/keyring/compare/v0.1.0-alpha.1...v0.1.0-alpha.2
[0.1.0-alpha.1]: https://github.com/civico-de/keyring/compare/v0.1.0-alpha...v0.1.0-alpha.1
[0.1.0-alpha]: https://github.com/civico-de/keyring/releases/tag/v0.1.0-alpha
