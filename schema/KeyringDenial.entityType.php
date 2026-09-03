<?php

declare(strict_types = 1);

/**
 * Report-mode audit trail: permissions a token was refused by its scope,
 * aggregated per (token, permission, request path).
 */
return [
  'name' => 'KeyringDenial',
  'table' => 'civicrm_keyring_denial',
  'class' => 'CRM_Keyring_DAO_KeyringDenial',
  'getInfo' => fn () => [
    'title' => 'Keyring Denial',
    'title_plural' => 'Keyring Denials',
    'description' => 'Permissions a keyring token was refused, aggregated.',
    'log' => FALSE,
  ],
  'getFields' => fn () => [
    'id' => [
      'title' => 'ID',
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'required' => TRUE,
      'primary_key' => TRUE,
      'auto_increment' => TRUE,
    ],
    'token_id' => [
      'title' => 'Token',
      'sql_type' => 'int unsigned',
      'input_type' => 'EntityRef',
      'required' => TRUE,
      'entity_reference' => [
        'entity' => 'KeyringToken',
        'key' => 'id',
        'on_delete' => 'CASCADE',
      ],
    ],
    'permission' => [
      'title' => 'Permission',
      'sql_type' => 'varchar(255)',
      'input_type' => 'Text',
      'required' => TRUE,
    ],
    'request_path' => [
      'title' => 'Request path',
      'sql_type' => 'varchar(255)',
      'input_type' => 'Text',
      'required' => TRUE,
    ],
    'hit_count' => [
      'title' => 'Hits',
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'required' => TRUE,
      'default' => 0,
    ],
    'first_seen' => [
      'title' => 'First seen',
      'sql_type' => 'datetime',
      'input_type' => 'Date',
      'required' => TRUE,
    ],
    'last_seen' => [
      'title' => 'Last seen',
      'sql_type' => 'datetime',
      'input_type' => 'Date',
      'required' => TRUE,
    ],
  ],
  'getIndices' => fn () => [
    'UI_token_permission_path' => [
      'fields' => ['token_id' => TRUE, 'permission' => TRUE, 'request_path' => TRUE],
      'unique' => TRUE,
    ],
  ],
  'getPaths' => fn () => [],
];
