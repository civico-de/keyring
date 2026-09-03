<?php

declare(strict_types = 1);

namespace Civi\Keyring\Permission;

use Civi\Core\Event\GenericHookEvent;
use Civi\Core\Service\AutoSubscriber;
use Civi\Keyring\Value;
use CRM_Keyring_ExtensionUtil as E;

/**
 * Declares keyring's own permissions.
 */
final class PermissionProvider extends AutoSubscriber {

  /**
   * @return array<string, string>
   */
  public static function getSubscribedEvents(): array {
    return ['hook_civicrm_permission' => 'onPermission'];
  }

  public static function onPermission(GenericHookEvent $event): void {
    // Hook fields are ['permissions']; the magic setter writes back into the
    // by-reference argument.
    [$permissions] = $event->getHookValues();
    $permissions = Value::row($permissions);
    // Literal keys, not the constants: this array is the single definition of
    // the two strings, and LifecycleHeadlessTest asserts that what lands in
    // CRM_Core_Permission::basicPermissions() is exactly what Permissions names.
    $permissions['manage own keyring tokens'] = [
      'label' => E::ts('keyring: manage own API tokens'),
      'description' => E::ts('Issue and revoke keyring tokens for oneself.'),
    ];
    $permissions['administer keyring tokens'] = [
      'label' => E::ts('keyring: administer API tokens'),
      'description' => E::ts('Issue and revoke keyring tokens for anybody, and read the denial trail.'),
    ];
    $event->__set('permissions', $permissions);
  }

}
