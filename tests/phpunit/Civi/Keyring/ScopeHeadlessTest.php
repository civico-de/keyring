<?php

declare(strict_types = 1);

namespace Civi\Keyring;

use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * What a token-authenticated request may do: its scope, and only what the contact holds.
 *
 * @group headless
 */
final class ScopeHeadlessTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return ck_headless()->apply();
  }

  protected function tearDown(): void {
    TokenContext::reset();
    \CRM_Core_Config::singleton()->userPermissionClass->permissions = NULL;
    parent::tearDown();
  }

  public function testAPermissionTheContactHoldsAndTheScopeNamesIsGranted(): void {
    $this->holdWithScope(['edit all contacts'], ['edit all contacts']);
    self::assertTrue(\CRM_Core_Permission::check('edit all contacts'));
  }

  public function testTheScopeNeverGrantsWhatTheContactLacks(): void {
    $this->holdWithScope(['access CiviCRM'], ['access CiviCRM', 'edit all contacts']);
    self::assertFalse(\CRM_Core_Permission::check('edit all contacts'));
  }

  public function testASuperuserTokenCarriesItsScope(): void {
    $this->holdWithScope(['all CiviCRM permissions and ACLs'], ['access CiviCRM', 'view all contacts']);
    self::assertTrue(\CRM_Core_Permission::check('access CiviCRM'));
    self::assertTrue(\CRM_Core_Permission::check('view all contacts'));
    // The guard permission authx asks before login is always in scope.
    self::assertTrue(\CRM_Core_Permission::check(Permissions::ALWAYS_IN_SCOPE));
  }

  public function testASuperuserTokenStillStopsAtItsScope(): void {
    $this->holdWithScope(['all CiviCRM permissions and ACLs'], ['access CiviCRM']);
    self::assertFalse(\CRM_Core_Permission::check('edit all contacts'));
    self::assertFalse(\CRM_Core_Permission::check('all CiviCRM permissions and ACLs'));
  }

  public function testAPermissionHeldThroughAMetaPermissionIsGrantedButNotTheMetaPermission(): void {
    // Core: `edit all contacts` implies `view all contacts`.
    $this->holdWithScope(['edit all contacts'], ['view all contacts']);
    self::assertTrue(\CRM_Core_Permission::check('view all contacts'));
    self::assertFalse(\CRM_Core_Permission::check('edit all contacts'));
  }

  public function testReportModeNarrowsNothing(): void {
    $this->holdWithScope(['all CiviCRM permissions and ACLs'], ['access CiviCRM'], TokenContext::MODE_REPORT);
    self::assertTrue(\CRM_Core_Permission::check('edit all contacts'));
  }

  /**
   * @param list<string> $held
   * @param list<string> $scope
   */
  private function holdWithScope(array $held, array $scope, string $mode = TokenContext::MODE_ENFORCE): void {
    \CRM_Core_Config::singleton()->userPermissionClass->permissions = $held;
    TokenContext::activate(1, ScopeService::lookupTable($scope), $mode);
  }

}
