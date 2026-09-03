<?php

declare(strict_types = 1);

namespace Civi\Keyring;

/**
 * Minting, hashing and looking up tokens.
 */
final class TokenService {

  public const PREFIX = 'kr_';

  /**
   * Length of the stored display prefix: `kr_` plus six random characters.
   */
  public const PREFIX_LENGTH = 9;

  /**
   * How stale `last_used_at` may get before it is written again.
   */
  public const TOUCH_INTERVAL = 3600;

  /**
   * How much entropy the secret carries.
   */
  public const SECRET_BYTES = 32;

  /**
   * A fresh plaintext token: the prefix plus the secret in base32.
   */
  public static function generate(): string {
    return self::PREFIX . Base32::encode(random_bytes(self::SECRET_BYTES));
  }

  public static function looksLikeToken(string $candidate): bool {
    return str_starts_with($candidate, self::PREFIX);
  }

  /**
   * Whether a whole token sits somewhere inside arbitrary text.
   *
   * Unlike `looksLikeToken()`, which judges a value that is already known to
   * be a credential, this searches unparsed text and so matches the full
   * minted shape only, bounded on both sides so that a longer word carrying
   * the same letters is not a hit.
   */
  public static function containsToken(string $haystack): bool {
    $pattern =
      '/(?<![A-Za-z0-9_])'
      . preg_quote(self::PREFIX, '/')
      . '['
      . preg_quote(Base32::ALPHABET, '/')
      . ']{'
      . Base32::encodedLength(self::SECRET_BYTES)
      . '}(?!['
      . preg_quote(Base32::ALPHABET, '/')
      . '])/';
    return preg_match($pattern, $haystack) === 1;
  }

  /**
   * Unsalted on purpose: the secret already has 256 bits of entropy, and a
   * per-row salt would turn the hot-path lookup into a table scan.
   */
  public static function hash(string $plaintext): string {
    return hash('sha256', $plaintext);
  }

  public static function displayPrefix(string $plaintext): string {
    return substr($plaintext, 0, self::PREFIX_LENGTH);
  }

  /**
   * The token row for a plaintext credential, or NULL.
   *
   * @return array{id: int, contact_id: int, scope: list<string>, mode: string, expires_at: ?string, last_used_at: ?string, revoked_at: ?string}|null
   */
  public static function lookup(string $plaintext): ?array {
    // This runs before authentication, on every request. APIv4 would boot its
    // kernel and fire the ACL and permission machinery that is about to ask
    // this very token for permission.
    // ckconform-ignore raw-sql -- pre-authentication: APIv4 here is circular
    $dao = \CRM_Core_DAO::executeQuery(
      'SELECT id, contact_id, scope, mode, expires_at, last_used_at, revoked_at
         FROM civicrm_keyring_token WHERE token_hash = %1',
      [1 => [self::hash($plaintext), 'String']],
    );
    $rows = $dao instanceof \CRM_Core_DAO ? $dao->fetchAll() : [];
    if ($rows === []) {
      return NULL;
    }
    $row = Value::row($rows[0]);
    return [
      'id' => Value::int($row['id'] ?? NULL),
      'contact_id' => Value::int($row['contact_id'] ?? NULL),
      'scope' => self::decodeScope($row['scope'] ?? NULL),
      'mode' => Value::str($row['mode'] ?? NULL),
      'expires_at' => Value::strOrNull($row['expires_at'] ?? NULL),
      'last_used_at' => Value::strOrNull($row['last_used_at'] ?? NULL),
      'revoked_at' => Value::strOrNull($row['revoked_at'] ?? NULL),
    ];
  }

  /**
   * Stamps `last_used_at`, at most once per interval and never on a revoked
   * row. The throttle is the WHERE clause, not a prior read: two requests
   * arriving together would both pass a read-decide-write and both write.
   */
  public static function touch(int $tokenId, int $now): void {
    // Same pre-authentication path as lookup().
    // ckconform-ignore raw-sql -- pre-authentication: APIv4 here is circular
    \CRM_Core_DAO::executeQuery(
      'UPDATE civicrm_keyring_token
          SET last_used_at = %1
        WHERE id = %2
          AND revoked_at IS NULL
          AND (last_used_at IS NULL OR last_used_at < %3)',
      [
        1 => [date('YmdHis', $now), 'Timestamp'],
        2 => [$tokenId, 'Integer'],
        3 => [date('YmdHis', $now - self::TOUCH_INTERVAL), 'Timestamp'],
      ],
    );
  }

  /**
   * @param mixed $raw
   *
   * @return list<string>
   */
  public static function decodeScope($raw): array {
    $decoded = $raw;
    if (is_string($raw)) {
      $decoded = $raw === '' ? [] : json_decode($raw, TRUE);
    }
    if (!is_array($decoded)) {
      return [];
    }
    // A non-string entry is dropped, never stringified: an invented permission
    // name is worse than a missing one.
    return array_values(array_filter($decoded, 'is_string'));
  }

}
