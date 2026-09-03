<?php

declare(strict_types = 1);

namespace Civi\Keyring;

use Civi\Core\Event\GenericHookEvent;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * The bulk "Revoke tokens" task SearchKit offers on the admin list.
 *
 * @group headless
 */
final class SearchKitTasksHeadlessTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return ck_headless()->apply();
  }

  protected function tearDown(): void {
    \CRM_Core_Config::singleton()->userPermissionClass->permissions = NULL;
    parent::tearDown();
  }

  public function testTheTaskIsOfferedToAnAdministrator(): void {
    \CRM_Core_Config::singleton()->userPermissionClass->permissions = [Permissions::ADMINISTER];
    $tasks = $this->fire(TRUE);
    self::assertArrayHasKey('keyring_revoke', $tasks['KeyringToken']);
    self::assertSame('revoke', $tasks['KeyringToken']['keyring_revoke']['apiBatch']['action']);
    self::assertNotEmpty($tasks['KeyringToken']['keyring_revoke']['apiBatch']['confirmMsg']);
  }

  public function testTheTaskIsWithheldFromEverybodyElse(): void {
    \CRM_Core_Config::singleton()->userPermissionClass->permissions = ['access CiviCRM'];
    self::assertSame([], $this->fire(TRUE));
  }

  public function testTheModuleHarvestingPassAlwaysGetsTheTask(): void {
    // search_kit fires the hook a second time with everything NULL purely to
    // collect Angular module names. Gating that pass on a permission would
    // stop the dialog from ever loading.
    \CRM_Core_Config::singleton()->userPermissionClass->permissions = ['access CiviCRM'];
    $tasks = $this->fire(NULL);
    self::assertArrayHasKey('keyring_revoke', $tasks['KeyringToken']);
  }

  public function testExistingTasksOfOtherEntitiesAreKept(): void {
    \CRM_Core_Config::singleton()->userPermissionClass->permissions = [Permissions::ADMINISTER];
    $tasks = $this->fire(TRUE, ['Contact' => ['export' => ['title' => 'Export']]]);
    self::assertArrayHasKey('export', $tasks['Contact']);
    self::assertArrayHasKey('keyring_revoke', $tasks['KeyringToken']);
  }

  /**
   * @param array<string, mixed> $tasks
   *
   * @return array<string, mixed>
   */
  private function fire(?bool $checkPermissions, array $tasks = []): array {
    $userId = NULL;
    $search = NULL;
    $display = NULL;
    $event = GenericHookEvent::create([
      'tasks' => &$tasks,
      'checkPermissions' => &$checkPermissions,
      'userId' => &$userId,
      'search' => &$search,
      'display' => &$display,
    ]);
    SearchKitTasks::addTasks($event);
    return $tasks;
  }

}
