<?php

declare(strict_types = 1);

namespace Civi\Keyring\Api4;

use Civi\Api4\Query\Api4SelectQuery;
use Civi\Api4\Service\Spec\FieldSpec;
use Civi\Api4\Service\Spec\Provider\Generic\SpecProviderInterface;
use Civi\Api4\Service\Spec\RequestSpec;
use Civi\Core\Service\AutoService;
use Civi\Keyring\Expiry;
use CRM_Keyring_ExtensionUtil as E;

/**
 * `KeyringToken.state`: active, expired or revoked, computed in SQL.
 *
 * The state is the server's answer, not the client's arithmetic. A UI that
 * derives it from the stored timestamps has to guess the site's timezone and
 * the format the column came back in, and would disagree with the
 * authentication path that actually refuses the token.
 *
 * @service
 * @internal
 */
final class TokenStateSpecProvider extends AutoService implements SpecProviderInterface {

  public function modifySpec(RequestSpec $spec): void {
    $field = (new FieldSpec('state', $spec->getEntity(), 'String'))
      ->setTitle(E::ts('State'))
      ->setLabel(E::ts('State'))
      ->setDescription(E::ts('Whether the token is usable right now.'))
      ->setColumnName('id')
      ->setType('Extra')
      ->setReadonly(TRUE)
      ->setOptions([
        Expiry::STATE_ACTIVE => E::ts('Active'),
        Expiry::STATE_EXPIRED => E::ts('Expired'),
        Expiry::STATE_REVOKED => E::ts('Revoked'),
      ])
      ->setSqlRenderer([__CLASS__, 'renderState']);
    $spec->addFieldSpec($field);
  }

  public function applies($entity, $action): bool {
    return $entity === \Civi\Api4\KeyringToken::getEntityName() && $action === 'get';
  }

  /**
   * `sql_name` is "<alias>.id"; the sibling columns hang off the same alias.
   *
   * The comparison time comes from CRM_Utils_Time rather than MySQL's NOW(),
   * so a test that freezes the clock moves this field with it.
   *
   * @param array<string, mixed> $field
   */
  public static function renderState(array $field, Api4SelectQuery $query): string {
    /** @var mixed $rawName */
    $rawName = $field['sql_name'] ?? '';
    $sqlName = is_string($rawName) ? $rawName : '';
    $alias = substr($sqlName, 0, (int) strrpos($sqlName, '.'));
    $now = \CRM_Core_DAO::escapeString(date('Y-m-d H:i:s', \CRM_Utils_Time::time()));
    return sprintf(
      "IF(%1\$s.`revoked_at` IS NOT NULL, '%2\$s',"
      . " IF(%1\$s.`expires_at` IS NOT NULL AND %1\$s.`expires_at` <= '%3\$s', '%4\$s', '%5\$s'))",
      $alias,
      Expiry::STATE_REVOKED,
      $now,
      Expiry::STATE_EXPIRED,
      Expiry::STATE_ACTIVE,
    );
  }

}
