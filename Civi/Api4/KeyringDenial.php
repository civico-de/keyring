<?php

declare(strict_types = 1);

namespace Civi\Api4;

use Civi\Keyring\Permissions;

/**
 * Report-mode denial trail. Written by the scope enforcement service, read
 * through the API, never written through it.
 *
 * @searchable secondary
 */
class KeyringDenial extends Generic\DAOEntity {

  /**
   * @return array<string, list<mixed>>
   */
  public static function permissions(): array {
    $deny = [\CRM_Core_Permission::ALWAYS_DENY_PERMISSION];
    return [
      'meta' => ['access CiviCRM'],
      'default' => [[Permissions::ADMINISTER, Permissions::MANAGE_OWN]],
      'create' => $deny,
      'update' => $deny,
      'save' => $deny,
      'replace' => $deny,
      // The denial trail is evidence; it is never removed through the API.
      'delete' => $deny,
    ];
  }

}
