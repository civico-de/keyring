<?php

declare(strict_types = 1);

namespace Civi\Keyring\Permission;

use Civi\Core\Event\PreEvent;
use Civi\Core\Service\AutoSubscriber;
use Civi\Keyring\Expiry;
use Civi\Keyring\KeyringException;
use Civi\Keyring\Value;

/**
 * The two rules every write to a token row obeys, whoever makes it.
 *
 * 1. The issuance fields never change — see ImmutableFields.
 * 2. `expires_at` is a date. An edit supplies `YYYY-MM-DD` and it is stored as
 *    the end of that day, exactly as `create` does — one definition of the
 *    column, not two. The DAO has already rewritten the value to `YmdHis` by
 *    the time this runs, so a date and midnight read the same here and both
 *    mean the day; any other time of day is refused.
 *
 * Enforced on `hook_civicrm_pre`, which every DAO write passes through, so both
 * hold for administrators and `checkPermissions => FALSE` callers too. The
 * immutable fields also carry `readonly` in the schema, but APIv4 reads that as
 * metadata for form builders and still writes them (measured on 6.17.3), so the
 * hook is the enforcement and `readonly` only keeps them off generated forms.
 */
final class TokenWriteSubscriber extends AutoSubscriber {

  /**
   * @return array<string, string>
   */
  public static function getSubscribedEvents(): array {
    return ['hook_civicrm_pre' => 'onPre'];
  }

  public static function onPre(PreEvent $event): void {
    // Hook fields are ['op', 'objectName', 'id', 'params'].
    [$op, $entity, $id, $params] = $event->getHookValues();
    if ($op !== 'edit' || $entity !== \Civi\Api4\KeyringToken::getEntityName() || !is_numeric($id)) {
      return;
    }
    $incoming = Value::row($params);
    if (array_key_exists('token_hash', $incoming)) {
      throw new \CRM_Core_Exception(ImmutableFields::refusal('token_hash'));
    }

    $stored = self::stored((int) $id);
    $violation = ImmutableFields::violation($incoming, $stored);
    if ($violation !== NULL) {
      throw new \CRM_Core_Exception(ImmutableFields::refusal($violation));
    }

    $normalized = self::normalizeExpiry($incoming, $stored);
    if ($normalized !== NULL) {
      // PreEvent::$params is a live reference to the record the DAO is about
      // to write; its __set() is a no-op because it overrides getHookValues().
      $event->params['expires_at'] = $normalized;
    }
  }

  /**
   * The value `expires_at` should be written as, or NULL when the edit does not
   * change it. Re-sending the stored value is a no-op, so a token whose day has
   * already passed can still be relabelled.
   *
   * @param array<string, mixed> $incoming
   * @param array<string, mixed> $stored
   */
  private static function normalizeExpiry(array $incoming, array $stored): ?string {
    if (!array_key_exists('expires_at', $incoming)) {
      return NULL;
    }
    $value = Value::text($incoming['expires_at']);
    if ($value === '' || Expiry::isSameInstant($value, Value::text($stored['expires_at'] ?? NULL))) {
      return NULL;
    }
    try {
      return Expiry::normalizeWritten($value);
    }
    catch (KeyringException $e) {
      throw new \CRM_Core_Exception($e->getMessage(), 0, [], $e);
    }
  }

  /**
   * @return array<string, mixed>
   */
  private static function stored(int $id): array {
    $rows = (array) \Civi\Api4\KeyringToken::get(FALSE)
      ->addSelect('contact_id', 'token_prefix', 'created_id', 'created_date', 'expires_at')
      ->addWhere('id', '=', $id)
      ->execute();
    return Value::row($rows[0] ?? NULL);
  }

}
