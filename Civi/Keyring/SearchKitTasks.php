<?php

declare(strict_types = 1);

namespace Civi\Keyring;

use Civi\Core\Event\GenericHookEvent;
use Civi\Core\Service\AutoSubscriber;
use CRM_Keyring_ExtensionUtil as E;

/**
 * Bulk revoke on the admin token list. Per-token revocation lives on the
 * contact tab, where the button can name the token it revokes.
 */
final class SearchKitTasks extends AutoSubscriber {

  /**
   * @return array<string, string>
   */
  public static function getSubscribedEvents(): array {
    return ['hook_civicrm_searchKitTasks' => 'addTasks'];
  }

  public static function addTasks(GenericHookEvent $event): void {
    // Hook fields are ['tasks', 'checkPermissions', 'userId', 'search',
    // 'display']. The hook fires twice: once per display with real
    // permissions, and once with everything NULL purely to harvest module
    // names, so the task is always declared and only the permission is gated.
    [$tasks, $checkPermissions] = $event->getHookValues();
    if ($checkPermissions === TRUE && !\CRM_Core_Permission::check(Permissions::ADMINISTER)) {
      return;
    }
    $tasks = Value::row($tasks);
    $entityTasks = Value::row($tasks['KeyringToken'] ?? NULL);
    $entityTasks['keyring_revoke'] = [
      'title' => E::ts('Revoke tokens'),
      'icon' => 'fa-ban',
      'apiBatch' => [
        'action' => 'revoke',
        'params' => NULL,
        'confirmMsg' => E::ts('Revoke %1 %2? Any client still using them stops working immediately.'),
        'runMsg' => E::ts('Revoking %1 %2...'),
        'successMsg' => E::ts('Revoked %1 %2.'),
        'errorMsg' => E::ts('An error occurred while revoking %1 %2.'),
      ],
    ];
    $tasks['KeyringToken'] = $entityTasks;
    $event->__set('tasks', $tasks);
  }

}
