<?php

declare(strict_types = 1);

namespace Civi\Api4\Action\KeyringToken;

use Civi\Api4\Generic\AbstractBatchAction;
use Civi\Api4\Generic\Result;
use Civi\Keyring\Value;

/**
 * Marks tokens revoked. Never deletes: the denial history and the audit trail
 * hang off the row.
 *
 * Which rows a caller may reach is decided by the `get` this runs, so
 * `manage own keyring tokens` holders can only revoke their own. The write
 * itself is unchecked, because `revoked_at` is closed to the generic update —
 * the ownership decision has already been made, one line above.
 */
final class Revoke extends AbstractBatchAction {

  public function _run(Result $result): void {
    $now = date('Y-m-d H:i:s', \CRM_Utils_Time::time());
    foreach ($this->getBatchRecords() as $raw) {
      $record = Value::row($raw);
      $id = Value::int($record['id'] ?? NULL);
      // The WHERE repeats the revoked_at test so a second revocation cannot
      // overwrite the timestamp the first one wrote.
      \Civi\Api4\KeyringToken::update(FALSE)
        ->addWhere('id', '=', $id)
        ->addWhere('revoked_at', 'IS NULL')
        ->addValue('revoked_at', $now)
        ->execute();

      // Read back rather than echo the value written: a first and a repeated
      // revocation must report the timestamp in the same shape.
      $stored = Value::row(
        \Civi\Api4\KeyringToken::get(FALSE)
          ->addSelect('id', 'revoked_at')
          ->addWhere('id', '=', $id)
          ->execute()
          ->first(),
      );
      $result[] = [
        'id' => $id,
        'revoked_at' => Value::strOrNull($stored['revoked_at'] ?? NULL),
      ];
    }
  }

  /**
   * @return list<string>
   */
  protected function getSelect(): array {
    return ['id', 'revoked_at'];
  }

}
