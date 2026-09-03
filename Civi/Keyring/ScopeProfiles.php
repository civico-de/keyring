<?php

declare(strict_types = 1);

namespace Civi\Keyring;

/**
 * The named permission lists an administrator offers when a token is issued.
 *
 * Stored as one JSON setting rather than an entity: it is a short, site-wide
 * mapping edited by hand a few times a year, and an entity would add a table,
 * an API and a screen for it.
 */
final class ScopeProfiles {

  public const SETTING = 'keyring_scope_profiles';

  /**
   * @return array<string, list<string>>
   *
   * @throws \Civi\Keyring\KeyringException
   */
  public static function all(): array {
    $raw = \Civi::settings()->get(self::SETTING);
    return self::parse(is_string($raw) ? $raw : '');
  }

  /**
   * @return array<string, list<string>>
   *
   * @throws \Civi\Keyring\KeyringException
   */
  public static function parse(string $json): array {
    $json = trim($json);
    if ($json === '') {
      return [];
    }
    $decoded = json_decode($json, TRUE);
    if (!is_array($decoded)) {
      throw new KeyringException(self::SHAPE_ERROR);
    }
    $profiles = [];
    foreach ($decoded as $name => $permissions) {
      $profileName = self::name($name);
      $profiles[$profileName] = self::permissions($profileName, $permissions);
    }
    return $profiles;
  }

  /**
   * Validation callback for the setting.
   *
   * CRM_Admin_Form_Generic shows the message of a CRM_Core_Exception and a
   * generic string for anything else, so the domain error is translated at
   * this boundary rather than upstream in the parser.
   *
   * @param mixed $value
   *
   * @throws \CRM_Core_Exception
   */
  public static function validateSetting(&$value): bool {
    try {
      self::parse(is_string($value) ? $value : '');
    }
    catch (KeyringException $e) {
      throw new \CRM_Core_Exception($e->getMessage(), 0, [], $e);
    }
    return TRUE;
  }

  private const SHAPE_ERROR = 'keyring: scope profiles must be a JSON object of name => list of permissions.';

  /**
   * Matches the `scope_profile` column, so a name that survives validation can
   * always be stored on a token.
   */
  public const MAX_NAME_LENGTH = 64;

  /**
   * @param mixed $name
   */
  private static function name($name): string {
    if (!is_string($name) || trim($name) === '') {
      throw new KeyringException(self::SHAPE_ERROR);
    }
    $name = trim($name);
    if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
      throw new KeyringException(sprintf(
        'keyring: scope profile name "%s" is longer than %d characters.',
        mb_substr($name, 0, 20) . '…',
        self::MAX_NAME_LENGTH,
      ));
    }
    return $name;
  }

  /**
   * @param mixed $permissions
   *
   * @return list<string>
   */
  private static function permissions(string $name, $permissions): array {
    if (!is_array($permissions)) {
      throw new KeyringException(self::SHAPE_ERROR);
    }
    $clean = [];
    foreach ($permissions as $permission) {
      if (!is_string($permission) || trim($permission) === '') {
        throw new KeyringException(
          sprintf('keyring: scope profile "%s" must be a list of non-empty permission names.', $name),
        );
      }
      // A repeated permission is the same allowlist written twice.
      $clean[trim($permission)] = TRUE;
    }
    return array_keys($clean);
  }

}
