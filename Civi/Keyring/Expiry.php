<?php

declare(strict_types = 1);

namespace Civi\Keyring;

/**
 * `expires_at` is a date, not an instant.
 *
 * A token issued "until 2026-12-31" is usable for the whole of that day, so the
 * input is `YYYY-MM-DD` and the stored value is that day's 23:59:59 in the site
 * timezone — the same zone MySQL's `NOW()` and `CRM_Utils_Time` work in. A
 * datetime input is refused rather than silently truncated: a caller who sends
 * one means something the column cannot express.
 */
final class Expiry {

  public const STATE_ACTIVE = 'active';

  public const STATE_EXPIRED = 'expired';

  public const STATE_REVOKED = 'revoked';

  private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

  /**
   * The stored value for a date-only input, or NULL for "never expires".
   *
   * @throws \Civi\Keyring\KeyringException
   */
  public static function normalize(?string $input, ?int $now = NULL): ?string {
    $input = trim((string) $input);
    if ($input === '') {
      return NULL;
    }
    if (preg_match(self::DATE_PATTERN, $input) !== 1) {
      throw new KeyringException(
        'keyring: expiresAt is a date, "YYYY-MM-DD". It is stored as the end of that day; a time of day is not accepted.',
      );
    }
    [$year, $month, $day] = array_map('intval', explode('-', $input));
    if (!checkdate($month, $day, $year)) {
      throw new KeyringException(sprintf('keyring: "%s" is not a calendar date.', $input));
    }

    $now ??= \CRM_Utils_Time::time();
    if ($input < date('Y-m-d', $now)) {
      throw new KeyringException(
        sprintf('keyring: expiresAt "%s" is in the past; a token cannot be issued already expired.', $input),
      );
    }
    return $input . ' 23:59:59';
  }

  /**
   * The same rule applied to a value that has already passed through the DAO,
   * which rewrites every date into `YmdHis` before `hook_civicrm_pre` sees it
   * (measured on 6.17.3). A date and midnight are therefore indistinguishable
   * at that layer and both mean the day; any other time of day is refused.
   *
   * @throws \Civi\Keyring\KeyringException
   */
  public static function normalizeWritten(string $value, ?int $now = NULL): ?string {
    $digits = preg_replace('/\D/', '', trim($value)) ?? '';
    if ($digits === '') {
      return NULL;
    }
    if (strlen($digits) !== 14 || !in_array(substr($digits, 8), ['000000', '235959'], TRUE)) {
      throw new KeyringException(
        'keyring: expires_at is a date. It is stored as the end of that day; a time of day is not accepted.',
      );
    }
    return self::normalize(implode('-', [
      substr($digits, 0, 4),
      substr($digits, 4, 2),
      substr($digits, 6, 2),
    ]), $now);
  }

  /**
   * Two spellings of the same stored instant, compared digit by digit.
   */
  public static function isSameInstant(string $a, string $b): bool {
    return preg_replace('/\D/', '', $a) === preg_replace('/\D/', '', $b);
  }

  /**
   * The state a stored row is in, computed the same way the SQL renderer does.
   */
  public static function state(?string $expiresAt, ?string $revokedAt, int $now): string {
    if ($revokedAt !== NULL && trim($revokedAt) !== '') {
      return self::STATE_REVOKED;
    }
    return self::isExpired($expiresAt, $now) ? self::STATE_EXPIRED : self::STATE_ACTIVE;
  }

  /**
   * An unparseable stored value counts as expired: a token whose validity
   * cannot be established is not usable.
   */
  public static function isExpired(?string $expiresAt, int $now): bool {
    if ($expiresAt === NULL || trim($expiresAt) === '') {
      return FALSE;
    }
    $expiry = strtotime($expiresAt);
    return $expiry === FALSE || $expiry <= $now;
  }

}
