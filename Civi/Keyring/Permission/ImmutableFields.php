<?php

declare(strict_types = 1);

namespace Civi\Keyring\Permission;

use Civi\Keyring\Expiry;
use Civi\Keyring\Value;

/**
 * What a token was issued with never changes.
 *
 * `contact_id` is the identity the token authenticates as, so letting it move
 * would turn "edit my own token" into "become somebody else"; the digest, the
 * display prefix and the issuance record are the audit trail.
 */
final class ImmutableFields {

  /**
   * Fields fixed at issue. `created_date` is compared as an instant, so a
   * differently formatted spelling of the same moment is not a change.
   */
  public const NAMES = ['contact_id', 'token_prefix', 'created_id', 'created_date'];

  private const TIMESTAMP_FIELD = 'created_date';

  /**
   * The first field an edit would change, or NULL when it changes none.
   *
   * @param array<string, mixed> $incoming
   * @param array<string, mixed> $stored
   */
  public static function violation(array $incoming, array $stored): ?string {
    if ($stored === []) {
      return NULL;
    }
    foreach (array_intersect(self::NAMES, array_keys($incoming)) as $field) {
      if (!self::same($field, $incoming[$field], $stored[$field] ?? NULL)) {
        return $field;
      }
    }
    return NULL;
  }

  public static function refusal(string $field): string {
    return sprintf(
      'keyring: "%s" is fixed when the token is issued. Revoke it and issue a new one.',
      $field,
    );
  }

  /**
   * @param mixed $incoming
   * @param mixed $stored
   */
  private static function same(string $field, $incoming, $stored): bool {
    $a = Value::text($incoming);
    $b = Value::text($stored);
    return $field === self::TIMESTAMP_FIELD ? Expiry::isSameInstant($a, $b) : $a === $b;
  }

}
