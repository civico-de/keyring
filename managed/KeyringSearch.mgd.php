<?php

declare(strict_types = 1);

use CRM_Keyring_ExtensionUtil as E;

/**
 * SearchKit configuration behind the two admin lists. The Afform pages embed
 * these displays by name.
 */
return [
  [
    'name' => 'SavedSearch_KeyringTokens',
    'entity' => 'SavedSearch',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'KeyringTokens',
        'label' => E::ts('keyring tokens'),
        'api_entity' => 'KeyringToken',
        'api_params' => [
          'version' => 4,
          'select' => [
            'contact_id.display_name',
            'label',
            'token_prefix',
            'scope_profile',
            'mode:label',
            'expires_at',
            'last_used_at',
            'revoked_at',
            'state:label',
            'created_date',
          ],
          'orderBy' => ['created_date' => 'DESC'],
        ],
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'SearchDisplay_KeyringTokens_Table',
    'entity' => 'SearchDisplay',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'KeyringTokens_Table',
        'label' => E::ts('keyring tokens'),
        'saved_search_id.name' => 'KeyringTokens',
        'type' => 'table',
        'settings' => [
          'actions' => TRUE,
          'limit' => 50,
          'pager' => ['show_count' => TRUE, 'expose_limit' => TRUE],
          'sort' => [['created_date', 'DESC']],
          'columns' => [
            [
              'key' => 'contact_id.display_name',
              'label' => E::ts('Contact'),
              'type' => 'field',
              'link' => ['path' => 'civicrm/contact/view#?cid=[contact_id]'],
            ],
            ['key' => 'label', 'label' => E::ts('Label'), 'type' => 'field'],
            ['key' => 'token_prefix', 'label' => E::ts('Prefix'), 'type' => 'field'],
            ['key' => 'scope_profile', 'label' => E::ts('Profile'), 'type' => 'field'],
            ['key' => 'mode:label', 'label' => E::ts('Mode'), 'type' => 'field'],
            ['key' => 'expires_at', 'label' => E::ts('Expires'), 'type' => 'field'],
            ['key' => 'last_used_at', 'label' => E::ts('Last used'), 'type' => 'field'],
            ['key' => 'revoked_at', 'label' => E::ts('Revoked'), 'type' => 'field'],
            ['key' => 'state:label', 'label' => E::ts('State'), 'type' => 'field'],
          ],
        ],
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'SavedSearch_KeyringDenials',
    'entity' => 'SavedSearch',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'KeyringDenials',
        'label' => E::ts('keyring denials'),
        'api_entity' => 'KeyringDenial',
        'api_params' => [
          'version' => 4,
          'select' => [
            'token_id.label',
            'token_id.contact_id.display_name',
            'permission',
            'request_path',
            'hit_count',
            'first_seen',
            'last_seen',
          ],
          'orderBy' => ['hit_count' => 'DESC'],
        ],
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'SearchDisplay_KeyringDenials_Table',
    'entity' => 'SearchDisplay',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'KeyringDenials_Table',
        'label' => E::ts('keyring denials'),
        'saved_search_id.name' => 'KeyringDenials',
        'type' => 'table',
        'settings' => [
          'actions' => FALSE,
          'limit' => 100,
          'pager' => ['show_count' => TRUE, 'expose_limit' => TRUE],
          'sort' => [['hit_count', 'DESC']],
          'columns' => [
            ['key' => 'token_id.label', 'label' => E::ts('Token'), 'type' => 'field'],
            [
              'key' => 'token_id.contact_id.display_name',
              'label' => E::ts('Contact'),
              'type' => 'field',
            ],
            ['key' => 'permission', 'label' => E::ts('Permission'), 'type' => 'field'],
            ['key' => 'request_path', 'label' => E::ts('Request path'), 'type' => 'field'],
            ['key' => 'hit_count', 'label' => E::ts('Hits'), 'type' => 'field'],
            ['key' => 'first_seen', 'label' => E::ts('First seen'), 'type' => 'field'],
            ['key' => 'last_seen', 'label' => E::ts('Last seen'), 'type' => 'field'],
          ],
        ],
      ],
      'match' => ['name'],
    ],
  ],
];
