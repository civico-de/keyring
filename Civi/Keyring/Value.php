<?php

declare(strict_types = 1);

namespace Civi\Keyring;

/**
 * Narrowing helpers for values that arrive as `mixed`: DAO rows, APIv4 results
 * and hook arguments. Each one converts or refuses; none invents a default for
 * a value that was supposed to be there.
 */
final class Value {

  /**
   * @param mixed $value
   */
  public static function int($value): int {
    if (is_int($value)) {
      return $value;
    }
    if (is_numeric($value)) {
      return (int) $value;
    }
    throw new KeyringException('keyring: expected a number, got ' . get_debug_type($value));
  }

  /**
   * @param mixed $value
   */
  public static function str($value): string {
    if (is_string($value)) {
      return $value;
    }
    if (is_int($value) || is_float($value)) {
      return (string) $value;
    }
    throw new KeyringException('keyring: expected a string, got ' . get_debug_type($value));
  }

  /**
   * @param mixed $value
   */
  public static function strOrNull($value): ?string {
    return ($value === NULL || $value === '') ? NULL : self::str($value);
  }

  /**
   * A scalar as text. Anything else yields '', which no stored column value
   * equals — the caller sees a difference rather than a coincidence.
   *
   * @param mixed $value
   */
  public static function text($value): string {
    return is_scalar($value) ? (string) $value : '';
  }

  /**
   * @param mixed $value
   *
   * @return array<int|string, mixed>
   */
  public static function arr($value): array {
    return is_array($value) ? $value : [];
  }

  /**
   * @param mixed $value
   *
   * @return array<string, mixed>
   */
  public static function row($value): array {
    $out = [];
    foreach (self::arr($value) as $key => $item) {
      $out[(string) $key] = $item;
    }
    return $out;
  }

}
