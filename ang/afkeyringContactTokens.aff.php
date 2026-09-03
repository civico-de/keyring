<?php

declare(strict_types = 1);

use CRM_Keyring_ExtensionUtil as E;

return [
  'type' => 'search',
  'title' => E::ts('API tokens'),
  'icon' => 'fa-key',
  'placement' => ['contact_summary_tab'],
  'summary_contact_type' => ['Individual'],
  'permission' => [
    'administer keyring tokens',
    'manage own keyring tokens',
  ],
  'permission_operator' => 'OR',
];
