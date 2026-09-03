<?php

declare(strict_types = 1);

namespace Civi\Keyring;

/**
 * RFC 4648 base32, lowercase and unpadded: URL-safe, shell-safe, and
 * case-insensitive to read back off a screen.
 */
final class Base32 {

  public const ALPHABET = 'abcdefghijklmnopqrstuvwxyz234567';

  /**
   * How many characters `encode()` produces for a given number of input bytes.
   */
  public static function encodedLength(int $byteCount): int {
    return intdiv(($byteCount * 8) + 4, 5);
  }

  public static function encode(string $bytes): string {
    $out = '';
    $buffer = 0;
    $bits = 0;
    foreach (str_split($bytes) as $byte) {
      $buffer = ($buffer << 8) | ord($byte);
      $bits += 8;
      while ($bits >= 5) {
        $bits -= 5;
        $out .= self::ALPHABET[($buffer >> $bits) & 31];
      }
    }
    if ($bits > 0) {
      $out .= self::ALPHABET[($buffer << (5 - $bits)) & 31];
    }
    return $out;
  }

}
