<?php

declare(strict_types = 1);

namespace Civi\Keyring\Permission;

use Civi\Api4\Event\AuthorizeRecordEvent;
use Civi\Core\Event\GenericHookEvent;
use Civi\Core\Service\AutoSubscriber;
use Civi\Keyring\Permissions;
use Civi\Keyring\Value;

/**
 * Row-level access to keyring's own entities.
 *
 * `manage own keyring tokens` holds for one's own rows only, and core reaches a
 * row by two different routes:
 *
 * - `hook_civicrm_selectWhereClause` narrows every read, and with it every
 *   write that selects its rows first (revoke, batch update, delete).
 * - `civi.api4.authorizeRecord` covers the writes that never select: the
 *   single-id fast path in AbstractUpdateAction and the per-record check in
 *   AbstractSaveAction both name a row by id and would otherwise pass.
 *
 * The third rule, that the issuance fields never move, is in TokenWriteSubscriber.
 */
final class RecordAccessSubscriber extends AutoSubscriber {

  /**
   * @return array<string, string>
   */
  public static function getSubscribedEvents(): array {
    return [
      'hook_civicrm_selectWhereClause' => 'onSelectWhere',
      'civi.api4.authorizeRecord' => 'onAuthorizeRecord',
    ];
  }

  public static function onSelectWhere(GenericHookEvent $event): void {
    // Hook fields are ['entity', 'clauses', 'userId', 'conditions'].
    [$entity, $clauses, $userId] = $event->getHookValues();
    $field = is_string($entity) ? RecordOwnership::columns()[$entity] ?? NULL : NULL;
    if ($field === NULL || \CRM_Core_Permission::check(Permissions::ADMINISTER)) {
      return;
    }

    $contactId = is_numeric($userId) ? (int) $userId : 0;
    $condition = $field === 'contact_id'
      ? '= ' . $contactId
      : 'IN (SELECT id FROM civicrm_keyring_token WHERE contact_id = ' . $contactId . ')';

    $clauses = Value::row($clauses);
    $own = Value::arr($clauses[$field] ?? NULL);
    $own[] = $condition;
    $clauses[$field] = $own;
    $event->__set('clauses', $clauses);
  }

  /**
   * Write access to one named row. Fails closed: a record whose owner cannot
   * be established is refused rather than allowed.
   */
  public static function onAuthorizeRecord(AuthorizeRecordEvent $event): void {
    $entity = $event->getEntityName();
    // The event names the user whose access is being evaluated; that is the
    // principal to judge, not whoever happens to hold the session.
    $userId = $event->getUserID();
    if (!isset(RecordOwnership::columns()[$entity]) || \CRM_Core_Permission::check(Permissions::ADMINISTER, $userId)) {
      return;
    }

    foreach (RecordOwnership::of($entity, Value::row($event->getRecord())) as $owner) {
      if ($userId !== 0 && $owner === $userId) {
        continue;
      }
      $event->setAuthorized(FALSE);
      return;
    }
  }

}
