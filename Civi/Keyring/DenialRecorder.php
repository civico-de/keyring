<?php

declare(strict_types = 1);

namespace Civi\Keyring;

/**
 * Report-mode denials, aggregated in memory and written once per request.
 *
 * One `civicrm/contact/view` asks 690 permission checks and repeats a single
 * permission 208 times; a row per check would be unreadable and a statement
 * per check unaffordable.
 *
 * The buffer is uncapped: the same page produced 7 distinct
 * (permission, path) keys, so the aggregation bounds itself.
 *
 * The flush is an APIv4 read-then-write, not an atomic upsert, so two requests
 * denying the same permission at the same instant can lose one increment or,
 * on the very first denial of a key, one row. Accepted: the counter ranks
 * permissions for deriving a scope, it is not an audit of record.
 */
final class DenialRecorder {

  /**
   * Pending counts, keyed "tokenId\0permission\0path".
   *
   * @var array<string, array{token_id: int, permission: string, request_path: string, hits: int}>
   */
  private static array $buffer = [];

  private static bool $flushScheduled = FALSE;

  public static function record(int $tokenId, string $permission, string $requestPath): void {
    $key = $tokenId . "\0" . $permission . "\0" . $requestPath;
    if (isset(self::$buffer[$key])) {
      self::$buffer[$key]['hits']++;
      return;
    }
    self::$buffer[$key] = [
      'token_id' => $tokenId,
      'permission' => self::fit($permission),
      'request_path' => self::fit($requestPath),
      'hits' => 1,
    ];
    if (!self::$flushScheduled) {
      self::$flushScheduled = TRUE;
      register_shutdown_function([self::class, 'flush']);
    }
  }

  /**
   * Cut to what the varchar(255) column holds, counted in bytes but never
   * through the middle of a character.
   */
  private static function fit(string $value): string {
    return mb_strcut($value, 0, 255, 'UTF-8');
  }

  /**
   * The path a denial is attributed to. Never a query string: it would carry
   * the caller's search terms into an audit table.
   */
  public static function requestPath(): string {
    $path = \CRM_Utils_System::currentPath();
    return is_string($path) && $path !== '' ? $path : 'cli';
  }

  public static function flush(): void {
    if (self::$buffer === []) {
      return;
    }
    $pending = self::$buffer;
    self::$buffer = [];
    $now = date('YmdHis');
    foreach ($pending as $row) {
      try {
        self::store($row, $now);
      }
      catch (\Throwable $e) {
        // This runs at shutdown, after the response. One unwritable row must
        // not take the rest of the request's denials with it.
        \Civi::log()->error('keyring: could not record a denial of {permission}: {message}', [
          'permission' => $row['permission'],
          'message' => $e->getMessage(),
        ]);
      }
    }
  }

  /**
   * @param array{token_id: int, permission: string, request_path: string, hits: int} $row
   */
  private static function store(array $row, string $now): void {
    $existing = \Civi\Api4\KeyringDenial::get(FALSE)
      ->addSelect('id', 'hit_count')
      ->addWhere('token_id', '=', $row['token_id'])
      ->addWhere('permission', '=', $row['permission'])
      ->addWhere('request_path', '=', $row['request_path'])
      ->execute()
      ->first();

    if ($existing === NULL) {
      \Civi\Api4\KeyringDenial::create(FALSE)
        ->addValue('token_id', $row['token_id'])
        ->addValue('permission', $row['permission'])
        ->addValue('request_path', $row['request_path'])
        ->addValue('hit_count', $row['hits'])
        ->addValue('first_seen', $now)
        ->addValue('last_seen', $now)
        ->execute();
      return;
    }

    \Civi\Api4\KeyringDenial::update(FALSE)
      ->addWhere('id', '=', Value::int(Value::row($existing)['id'] ?? NULL))
      ->addValue('hit_count', Value::int(Value::row($existing)['hit_count'] ?? NULL) + $row['hits'])
      ->addValue('last_seen', $now)
      ->execute();
  }

  /**
   * @return array<string, array{token_id: int, permission: string, request_path: string, hits: int}>
   */
  public static function buffered(): array {
    return self::$buffer;
  }

  public static function reset(): void {
    self::$buffer = [];
  }

}
