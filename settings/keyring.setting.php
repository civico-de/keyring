<?php

declare(strict_types = 1);

use CRM_Keyring_ExtensionUtil as E;

/**
 * Site-wide settings. Everything per-token lives on the KeyringToken row.
 */
return [
  'keyring_scope_profiles' => [
    'name' => 'keyring_scope_profiles',
    'settings_pages' => ['keyring' => ['weight' => 10]],
    'type' => 'String',
    'default' => '{"empty": []}',
    'html_type' => 'textarea',
    'html_attributes' => ['rows' => 12, 'cols' => 80],
    'title' => E::ts('keyring: scope profiles'),
    'description' => E::ts(
      'A JSON object of profile name to list of permission strings, offered when a token is issued. Ships with one empty profile on purpose: a profile guessed from a client\'s documentation is wrong in a way nobody notices, because a permission missing from a scope returns no rows rather than an error. Derive the real list by issuing a token in report mode and reading its denials.',
    ),
    'validate_callback' => ['Civi\Keyring\ScopeProfiles', 'validateSetting'],
    'is_domain' => 1,
    'is_contact' => 0,
  ],
];
