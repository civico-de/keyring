<?php

declare(strict_types = 1);

/**
 * A scoped, revocable API token belonging to one contact. The plaintext is
 * never stored; only its unsalted SHA-256 digest and a display prefix.
 */
return [
  'name' => 'KeyringToken',
  'table' => 'civicrm_keyring_token',
  'class' => 'CRM_Keyring_DAO_KeyringToken',
  'getInfo' => fn () => [
    'title' => 'Keyring Token',
    'title_plural' => 'Keyring Tokens',
    'description' => 'Scoped, revocable API tokens acting as a contact.',
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
    'contact_id' => [
      'title' => 'Contact',
      'sql_type' => 'int unsigned',
      'input_type' => 'EntityRef',
      'required' => TRUE,
      'description' => 'The person the token acts as.',
      'entity_reference' => [
        'entity' => 'Contact',
        'key' => 'id',
        'on_delete' => 'CASCADE',
      ],
    ],
    'label' => [
      'title' => 'Label',
      'sql_type' => 'varchar(128)',
      'input_type' => 'Text',
      'required' => TRUE,
      'description' => 'What this token is for, e.g. the device it lives on.',
    ],
    'token_hash' => [
      'title' => 'Token hash',
      'sql_type' => 'char(64)',
      'input_type' => 'Text',
      'required' => TRUE,
      'description' => 'Unsalted SHA-256 of the plaintext token.',
      // The digest is the credential's only stored form; nothing outside the
      // authentication path may read it back.
      'permission' => ['*always deny*'],
    ],
    'token_prefix' => [
      'title' => 'Prefix',
      'sql_type' => 'varchar(16)',
      'input_type' => 'Text',
      'required' => TRUE,
      'readonly' => TRUE,
      'description' => 'First characters of the token, for display and audit.',
    ],
    'scope' => [
      'title' => 'Scope',
      'sql_type' => 'text',
      'input_type' => 'Text',
      'description' => 'Allowlist of permission strings.',
      'serialize' => CRM_Core_DAO::SERIALIZE_JSON,
    ],
    'scope_profile' => [
      'title' => 'Scope profile',
      'sql_type' => 'varchar(64)',
      'input_type' => 'Text',
      'description' => 'Name of the profile the scope came from, if any.',
    ],
    'mode' => [
      'title' => 'Mode',
      'sql_type' => 'varchar(16)',
      'input_type' => 'Select',
      'required' => TRUE,
      'default' => 'report',
      'pseudoconstant' => [
        'callback' => ['CRM_Keyring_DAO_KeyringToken', 'getModes'],
      ],
    ],
    'expires_at' => [
      'title' => 'Expires',
      'sql_type' => 'datetime',
      'input_type' => 'Date',
      'description' => 'End of the last valid day, stored as 23:59:59 in the site timezone. Empty means the token never expires.',
    ],
    'last_used_at' => [
      'title' => 'Last used',
      'sql_type' => 'datetime',
      'input_type' => 'Date',
      'readonly' => TRUE,
      'description' => 'Updated at most once per hour.',
    ],
    'revoked_at' => [
      'title' => 'Revoked',
      'sql_type' => 'datetime',
      'input_type' => 'Date',
      'readonly' => TRUE,
    ],
    'created_id' => [
      'title' => 'Issued by',
      'sql_type' => 'int unsigned',
      'input_type' => 'EntityRef',
      'readonly' => TRUE,
      'entity_reference' => [
        'entity' => 'Contact',
        'key' => 'id',
        'on_delete' => 'SET NULL',
      ],
    ],
    'created_date' => [
      'title' => 'Created',
      'sql_type' => 'timestamp',
      'input_type' => 'Date',
      'required' => TRUE,
      'readonly' => TRUE,
      'default' => 'CURRENT_TIMESTAMP',
    ],
  ],
  'getIndices' => fn () => [
    'UI_token_hash' => [
      'fields' => ['token_hash' => TRUE],
      'unique' => TRUE,
    ],
    'index_contact' => [
      'fields' => ['contact_id' => TRUE],
    ],
  ],
  'getPaths' => fn () => [],
];
