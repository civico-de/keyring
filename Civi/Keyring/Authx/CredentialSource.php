<?php

declare(strict_types = 1);

namespace Civi\Keyring\Authx;

use Civi\Keyring\TokenService;

/**
 * Where the credential authx is checking physically came from.
 *
 * `CheckCredentialEvent` carries no flow name (verified against
 * core/ext/authx/Civi/Authx/CheckCredentialEvent.php on 6.17.3), so the source
 * is established by comparing the credential the event holds against the raw
 * request headers. Anything that does not match a header arrived in the URL or
 * the request body.
 */
final class CredentialSource {

  public const XHEADER = 'X-Civi-Auth';

  public const HEADER = 'Authorization';

  private const SERVER_KEYS = [
    self::XHEADER => 'HTTP_X_CIVI_AUTH',
    self::HEADER => 'HTTP_AUTHORIZATION',
  ];

  /**
   * The request parameters authx reads a credential from.
   */
  private const PARAM_KEYS = ['_authx', 'api_key'];

  public static function isHeader(string $credFormat, string $credValue): bool {
    $credential = $credValue === '' ? $credFormat : $credFormat . ' ' . $credValue;
    foreach (self::SERVER_KEYS as $serverKey) {
      /** @var mixed $raw */
      $raw = $_SERVER[$serverKey] ?? NULL;
      if (is_string($raw) && hash_equals($raw, $credential)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Whether a keyring token also rides in the URL or the request body.
   *
   * A matching header does not make the request safe on its own: the same
   * token sent in `_authx` or `api_key` as well has already been written to
   * the access log, the browser history and every proxy on the way. Any `kr_`
   * credential in a parameter fails the request, matching header or not.
   *
   * Two passes. `RawRequest` searches the undecoded query string and body,
   * catching what PHP's parameter parsing loses. The parsed bags are then
   * walked recursively and trimmed, covering the multipart bodies that leave
   * `php://input` empty.
   *
   * @param string|null $rawBody
   *   The unparsed request body; read from `php://input` when omitted.
   */
  public static function isInRequestParams(?string $rawBody = NULL): bool {
    if (RawRequest::leaksToken($rawBody)) {
      return TRUE;
    }
    $wanted = array_flip(self::PARAM_KEYS);
    foreach ([$_GET, $_POST] as $bag) {
      if (self::holdsToken(array_intersect_key($bag, $wanted))) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * @param mixed $value
   */
  private static function holdsToken($value): bool {
    if (is_array($value)) {
      /** @var mixed $item */
      foreach ($value as $item) {
        if (self::holdsToken($item)) {
          return TRUE;
        }
      }
      return FALSE;
    }
    // Only a string can carry the prefix; numbers and booleans cannot.
    return is_string($value) && TokenService::looksLikeToken(self::stripScheme($value));
  }

  private static function stripScheme(string $credential): string {
    $credential = trim($credential);
    // Any run of ASCII whitespace separates the scheme, not a space alone.
    $parts = preg_split('/\s+/', $credential, 2) ?: [$credential];
    return count($parts) === 2 ? trim($parts[1]) : $credential;
  }

}
