<?php

declare(strict_types = 1);

use CRM_Keyring_ExtensionUtil as E;

return [
  'type' => 'search',
  'title' => E::ts('keyring - Denials'),
  'icon' => 'fa-list',
  'server_route' => 'civicrm/admin/keyring/denials',
  'permission' => ['administer keyring tokens'],
];
