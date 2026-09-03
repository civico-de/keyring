<?php

declare(strict_types = 1);

namespace Civi\Keyring;

/**
 * Normalising and looking up permission allowlists.
 */
final class ScopeService {

  /**
   * The scope as it is stored and enforced.
   *
   * `authenticate with api key` is added because authx checks it before login,
   * `all CiviCRM permissions and ACLs` is removed because it would short-
   * circuit every later check and make the allowlist a no-op.
   *
   * @param list<string> $permissions
   *
   * @return list<string>
   */
  public static function normalize(array $permissions): array {
    $clean = [];
    foreach ($permissions as $permission) {
      $permission = trim($permission);
      if ($permission === '' || $permission === Permissions::NEVER_IN_SCOPE) {
        continue;
      }
      $clean[$permission] = TRUE;
    }
    $clean[Permissions::ALWAYS_IN_SCOPE] = TRUE;
    $out = array_keys($clean);
    sort($out);
    return $out;
  }

  /**
   * Lookup table for the hot path.
   *
   * @param list<string> $permissions
   *
   * @return array<string, true>
   */
  public static function lookupTable(array $permissions): array {
    return array_fill_keys(self::normalize($permissions), TRUE);
  }

  /**
   * The permission list a create request asked for: either a hand-built scope
   * or a configured profile, never both and never neither.
   *
   * @param list<string>|null $scope
   *
   * @return list<string>
   *
   * @throws \Civi\Keyring\KeyringException
   */
  public static function resolve(?array $scope, ?string $profile): array {
    if ($scope !== NULL && $profile !== NULL) {
      throw new KeyringException('keyring: pass either scope or scopeProfile, not both.');
    }
    if ($scope !== NULL) {
      return array_values($scope);
    }
    if ($profile === NULL) {
      throw new KeyringException('keyring: either scope or scopeProfile is required.');
    }
    $profiles = ScopeProfiles::all();
    if (!isset($profiles[$profile])) {
      throw new KeyringException(sprintf('keyring: unknown scope profile "%s".', $profile));
    }
    return $profiles[$profile];
  }

}
