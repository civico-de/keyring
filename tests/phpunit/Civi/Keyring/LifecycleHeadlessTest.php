<?php

declare(strict_types = 1);

namespace Civi\Keyring;

use Civi\Api4\Afform;
use Civi\Api4\Navigation;
use Civi\Api4\SavedSearch;
use Civi\Api4\SearchDisplay;
use Civi\Test\HeadlessInterface;
use PHPUnit\Framework\TestCase;

/**
 * Install, disable, enable, uninstall, reinstall — and what the extension is
 * expected to have contributed while installed.
 *
 * Deliberately NOT transactional: the extension manager runs DDL.
 *
 * @group headless
 */
final class LifecycleHeadlessTest extends TestCase implements HeadlessInterface {

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return ck_headless()->apply();
  }

  public function testTablesAndConfigurationExistAfterInstall(): void {
    self::assertTrue(\CRM_Core_DAO::checkTableExists('civicrm_keyring_token'));
    self::assertTrue(\CRM_Core_DAO::checkTableExists('civicrm_keyring_denial'));

    self::assertSame('{"empty": []}', \Civi::settings()->get(ScopeProfiles::SETTING));
    self::assertSame(['empty' => []], ScopeProfiles::all());

    $permissions = \CRM_Core_Permission::basicPermissions(TRUE);
    self::assertArrayHasKey(Permissions::MANAGE_OWN, $permissions);
    self::assertArrayHasKey(Permissions::ADMINISTER, $permissions);
  }

  public function testManagedConfigurationIsReconciled(): void {
    self::assertSame(1, SavedSearch::get(FALSE)->addWhere('name', '=', 'KeyringTokens')->execute()->count());
    self::assertSame(1, SavedSearch::get(FALSE)->addWhere('name', '=', 'KeyringDenials')->execute()->count());
    self::assertSame(
      1,
      SearchDisplay::get(FALSE)->addWhere('name', '=', 'KeyringTokens_Table')->execute()->count(),
    );
    self::assertSame(
      1,
      Navigation::get(FALSE)->addWhere('name', '=', 'keyring_admin')->execute()->count(),
    );
  }

  public function testTheContactTabIsRegisteredAsAnAfform(): void {
    $form = Afform::get(FALSE)
      ->addSelect('placement', 'permission')
      ->addWhere('name', '=', 'afkeyringContactTokens')
      ->execute()
      ->single();
    self::assertContains('contact_summary_tab', (array) $form['placement']);
    self::assertContains(Permissions::MANAGE_OWN, (array) $form['permission']);
  }

  public function testDisableEnableUninstallReinstall(): void {
    $manager = \CRM_Extension_System::singleton()->getManager();

    $manager->disable(['keyring']);
    self::assertSame($manager::STATUS_DISABLED, $manager->getStatus('keyring'));

    $manager->enable(['keyring']);
    self::assertSame($manager::STATUS_INSTALLED, $manager->getStatus('keyring'));
    self::assertTrue(\CRM_Core_DAO::checkTableExists('civicrm_keyring_token'));

    $manager->disable(['keyring']);
    $manager->uninstall(['keyring']);
    self::assertSame($manager::STATUS_UNINSTALLED, $manager->getStatus('keyring'));
    self::assertFalse(\CRM_Core_DAO::checkTableExists('civicrm_keyring_token'));
    self::assertFalse(\CRM_Core_DAO::checkTableExists('civicrm_keyring_denial'));

    $manager->install(['keyring']);
    self::assertSame($manager::STATUS_INSTALLED, $manager->getStatus('keyring'));
    self::assertTrue(\CRM_Core_DAO::checkTableExists('civicrm_keyring_token'));
    self::assertTrue(\CRM_Core_DAO::checkTableExists('civicrm_keyring_denial'));
  }

}
