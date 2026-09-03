<?php

declare(strict_types = 1);

use CRM_Keyring_ExtensionUtil as E;

/**
 * Admin navigation: a "keyring" block under Administer, everything behind
 * `administer keyring tokens`.
 */
$child = static fn (string $name, string $label, string $url, int $weight): array => [
  'name' => "Navigation_{$name}",
  'entity' => 'Navigation',
  'cleanup' => 'unused',
  'update' => 'unmodified',
  'params' => [
    'version' => 4,
    'values' => [
      'name' => $name,
      'label' => $label,
      'url' => $url,
      'permission' => 'administer keyring tokens',
      'permission_operator' => 'OR',
      'parent_id.name' => 'keyring_admin',
      'is_active' => TRUE,
      'weight' => $weight,
      'domain_id' => 'current_domain',
    ],
    'match' => ['name', 'domain_id'],
  ],
];

return [
  [
    'name' => 'Navigation_keyring_admin',
    'entity' => 'Navigation',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'keyring_admin',
        'label' => E::ts('keyring'),
        'url' => NULL,
        'permission' => 'administer keyring tokens',
        'permission_operator' => 'OR',
        'parent_id.name' => 'Administer',
        'is_active' => TRUE,
        'weight' => 90,
        'domain_id' => 'current_domain',
      ],
      'match' => ['name', 'domain_id'],
    ],
  ],
  $child('keyring_tokens', E::ts('Tokens'), 'civicrm/admin/keyring/tokens', 10),
  $child('keyring_denials', E::ts('Denials'), 'civicrm/admin/keyring/denials', 20),
  $child('keyring_settings', E::ts('Settings'), 'civicrm/admin/setting/keyring?reset=1', 30),
];
