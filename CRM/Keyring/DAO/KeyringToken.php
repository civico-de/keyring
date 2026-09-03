<?php

class CRM_Keyring_DAO_KeyringToken extends CRM_Keyring_DAO_Base {

  /** @var string */
  public static $_tableName = 'civicrm_keyring_token';

  /**
   * @return array<string, string>
   */
  public static function getModes(): array {
    return [
      'report' => ts('Report only'),
      'enforce' => ts('Enforce'),
    ];
  }

}
