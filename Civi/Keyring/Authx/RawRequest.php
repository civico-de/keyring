<?php

declare(strict_types = 1);

namespace Civi\Keyring\Authx;

use Civi\Keyring\TokenService;

/**
 * The unparsed request, read for tokens PHP's parameter parsing loses.
 *
 * A repeated scalar key keeps only its last value, and a separator other than
 * a plain space hides a credential from `stripScheme()`. Both survive here.
 */
final class RawRequest {

  /**
   * Whether a whole token sits in the query string or the body.
   *
   * @param string|null $rawBody
   *   The unparsed body; read from `php://input` when omitted.
   */
  public static function leaksToken(?string $rawBody = NULL): bool {
    $raw = self::queryString() . '&' . ($rawBody ?? self::body());
    return TokenService::containsToken(urldecode($raw));
  }

  private static function queryString(): string {
    /** @var mixed $raw */
    $raw = $_SERVER['QUERY_STRING'] ?? '';
    return is_string($raw) ? $raw : '';
  }

  /**
   * Empty for a multipart body, which the parsed bags cover instead, and under
   * CLI, where `php://input` is the process's own stdin rather than a request.
   */
  private static function body(): string {
    if (PHP_SAPI === 'cli') {
      return '';
    }
    $raw = file_get_contents('php://input');
    return is_string($raw) ? $raw : '';
  }

}
