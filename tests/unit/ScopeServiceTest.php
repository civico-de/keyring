<?php

declare(strict_types = 1);

namespace Civi\Keyring\Tests\Unit;

use Civi\Keyring\KeyringException;
use Civi\Keyring\Permissions;
use Civi\Keyring\ScopeService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Civi\Keyring\ScopeService
 */
final class ScopeServiceTest extends TestCase {

  public function testGuardPermissionIsAlwaysAdded(): void {
    self::assertContains(Permissions::ALWAYS_IN_SCOPE, ScopeService::normalize([]));
    self::assertContains(Permissions::ALWAYS_IN_SCOPE, ScopeService::normalize(['access CiviCRM']));
  }

  public function testWildcardPermissionIsStripped(): void {
    $scope = ScopeService::normalize(['access CiviCRM', Permissions::NEVER_IN_SCOPE]);
    self::assertNotContains(Permissions::NEVER_IN_SCOPE, $scope);
    self::assertContains('access CiviCRM', $scope);
  }

  public function testScopeIsDeduplicatedTrimmedAndSorted(): void {
    $scope = ScopeService::normalize(['  b perm ', 'a perm', 'b perm', '', '   ']);
    self::assertSame(['a perm', Permissions::ALWAYS_IN_SCOPE, 'b perm'], $scope);
  }

  public function testLookupTableIsKeyedByPermission(): void {
    $table = ScopeService::lookupTable(['access CiviCRM']);
    self::assertTrue($table['access CiviCRM']);
    self::assertTrue($table[Permissions::ALWAYS_IN_SCOPE]);
    self::assertArrayNotHasKey('view all contacts', $table);
  }

  public function testResolvePrefersAnExplicitScope(): void {
    self::assertSame(['a'], ScopeService::resolve(['a'], NULL));
  }

  public function testResolveRefusesBothScopeAndProfile(): void {
    $this->expectException(KeyringException::class);
    ScopeService::resolve(['a'], 'empty');
  }

  public function testResolveRefusesNeither(): void {
    $this->expectException(KeyringException::class);
    ScopeService::resolve(NULL, NULL);
  }

}
