<?php

declare(strict_types = 1);

namespace Civi\Keyring;

/**
 * The token that authenticated the current request.
 *
 * Static rather than a container service: it is read once per permission
 * check, and one CiviCRM page view asks 690 of them. The state lives exactly
 * as long as the PHP request, which is the lifetime of a stateless
 * authentication.
 */
final class TokenContext {

  public const MODE_REPORT = 'report';

  public const MODE_ENFORCE = 'enforce';

  private static ?int $tokenId = NULL;

  /**
   * Scope as a lookup table: permission string => TRUE.
   *
   * @var array<string, true>
   */
  private static array $scope = [];

  private static string $mode = self::MODE_ENFORCE;

  /**
   * @param array<string, true> $scope
   */
  public static function activate(int $tokenId, array $scope, string $mode): void {
    self::$tokenId = $tokenId;
    self::$scope = $scope;
    self::$mode = $mode;
  }

  public static function isActive(): bool {
    return self::$tokenId !== NULL;
  }

  public static function tokenId(): ?int {
    return self::$tokenId;
  }

  public static function mode(): string {
    return self::$mode;
  }

  public static function allows(string $permission): bool {
    return isset(self::$scope[$permission]);
  }

  /**
   * @return array<string, true>
   */
  public static function scope(): array {
    return self::$scope;
  }

  public static function reset(): void {
    self::$tokenId = NULL;
    self::$scope = [];
    self::$mode = self::MODE_ENFORCE;
  }

}
