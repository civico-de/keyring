<?php

declare(strict_types = 1);

namespace Civi\Keyring\Permission;

/**
 * Whether a contact holds a permission through one that implies it.
 *
 * Asked of the CMS the way CRM_Core_Permission::check() does, so no
 * permission_check hook fires again.
 */
final class Implication {

  public const SUPER = 'all CiviCRM permissions and ACLs';

  /**
   * @param mixed $contactId
   *   The hook's contactId; NULL means the current user.
   */
  public static function heldBy(string $permission, $contactId): bool {
    $declared = \CRM_Core_Permission::basicPermissions(TRUE, TRUE)[$permission] ?? NULL;
    if (!is_array($declared) || !empty($declared['disabled'])) {
      return FALSE;
    }
    $implying = is_array($declared['implied_by'] ?? NULL) ? $declared['implied_by'] : [];
    $implying[] = self::SUPER;
    $userId = is_numeric($contactId) ? \CRM_Core_BAO_UFMatch::getUFId((int) $contactId) : NULL;
    $cms = \CRM_Core_Config::singleton()->userPermissionClass;
    foreach ($implying as $implied) {
      if (is_string($implied) && $cms->check($implied, $userId)) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
