<?php

declare(strict_types = 1);

namespace Civi\Api4;

use Civi\Keyring\Permissions;

/**
 * Scoped, revocable API tokens belonging to a contact.
 *
 * `create` is overridden so that a row can only come into existence with a
 * hash and a prefix; the generic `save` and `replace` cannot mint one, because
 * `token_hash` is required and permanently denied to the API.
 *
 * The write surface is `create` and `revoke`; `delete` is denied to everyone,
 * because a token row carries its own audit trail. Contact deletion still
 * cascades the rows away through the foreign key, which is intended.
 * `update`, `save` and `replace` are shut to `manage own keyring tokens`
 * holders: a single-id `update` takes a fast path in core's
 * AbstractUpdateAction that bypasses the select clause, so a row-level rule
 * alone would not stop a token being reassigned to another contact.
 *
 * Row-level access and the immutability of `contact_id` are applied in
 * \Civi\Keyring\Permission\RecordAccessSubscriber.
 *
 * @searchable secondary
 */
class KeyringToken extends Generic\DAOEntity {

  /**
   * @return \Civi\Api4\Action\KeyringToken\Create
   */
  public static function create($checkPermissions = TRUE) {
    return (new Action\KeyringToken\Create(static::getEntityName(), __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * @return \Civi\Api4\Action\KeyringToken\Revoke
   */
  public static function revoke(bool $checkPermissions = TRUE) {
    return (new Action\KeyringToken\Revoke(static::getEntityName(), __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * @return array<string, list<mixed>>
   */
  public static function permissions(): array {
    // CRM_Core_Permission::check ANDs the top level and ORs a nested array,
    // so one nested list is "either of these".
    $own = [[Permissions::ADMINISTER, Permissions::MANAGE_OWN]];
    $administer = [Permissions::ADMINISTER];
    return [
      'meta' => ['access CiviCRM'],
      'default' => $own,
      'get' => $own,
      'create' => $own,
      'revoke' => $own,
      // Editing a stored row is an administrator's job. A holder's surface is
      // create, get and revoke; a generic update is how a token's contact_id
      // would be moved onto somebody else.
      'update' => $administer,
      // Listed explicitly: core maps an unlisted 'save' onto 'create'.
      'save' => $administer,
      // The model is revoke-only: the row anchors the denial history and the
      // audit trail, so nothing may remove it through the API.
      'delete' => [\CRM_Core_Permission::ALWAYS_DENY_PERMISSION],
      // Delete-and-recreate over a whole selection. Nothing here needs it, and
      // it is the one write that would drop rows without naming them.
      'replace' => [\CRM_Core_Permission::ALWAYS_DENY_PERMISSION],
    ];
  }

}
