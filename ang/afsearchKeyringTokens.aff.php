<?php

declare(strict_types = 1);

use CRM_Keyring_ExtensionUtil as E;

return [
  'type' => 'search',
  'title' => E::ts('keyring - Tokens'),
  'icon' => 'fa-key',
  'server_route' => 'civicrm/admin/keyring/tokens',
  'permission' => ['administer keyring tokens'],
];
