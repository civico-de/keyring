<?php

declare(strict_types = 1);

namespace Civi\Keyring\Permission;

use Civi\Keyring\Value;

/**
 * Which contact a row of keyring's own entities belongs to.
 *
 * Shared by the three places that enforce `manage own keyring tokens`: the
 * select clause, the record authorisation and the immutability of `contact_id`.
 */
final class RecordOwnership {

  /**
   * The column that ties a row of each entity back to a contact. Keyed by the
   * API entity names themselves, so a rename cannot silently unhook this.
   *
   * @return array<string, string>
   */
  public static function columns(): array {
    return [
      \Civi\Api4\KeyringToken::getEntityName() => 'contact_id',
      \Civi\Api4\KeyringDenial::getEntityName() => 'token_id',
    ];
  }

  /**
   * Every contact a record ties itself to: the owner it has now and the owner
   * it is being given. An unresolvable owner yields 0, which matches nobody.
   *
   * @param array<string, mixed> $record
   *
   * @return list<int>
   */
  public static function of(string $entity, array $record): array {
    if ($entity === \Civi\Api4\KeyringDenial::getEntityName()) {
      return [self::tokenOwner(self::numeric($record['token_id'] ?? NULL))];
    }

    $owners = [];
    if (array_key_exists('contact_id', $record)) {
      $owners[] = self::numeric($record['contact_id']);
    }
    $id = self::numeric($record['id'] ?? NULL);
    if ($id !== 0) {
      $owners[] = self::tokenOwner($id);
    }
    return $owners === [] ? [0] : $owners;
  }

  /**
   * The contact a stored token belongs to, or 0 if there is no such row.
   */
  public static function tokenOwner(int $tokenId): int {
    if ($tokenId === 0) {
      return 0;
    }
    $rows = (array) \Civi\Api4\KeyringToken::get(FALSE)
      ->addSelect('contact_id')
      ->addWhere('id', '=', $tokenId)
      ->execute();
    return self::numeric(Value::row($rows[0] ?? NULL)['contact_id'] ?? NULL);
  }

  /**
   * @param mixed $value
   */
  private static function numeric($value): int {
    return is_numeric($value) ? (int) $value : 0;
  }

}
