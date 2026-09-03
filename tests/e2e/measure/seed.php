<?php

declare(strict_types = 1);

// Seeds a NON-superuser subject for the e2e suites: a contact, a Standalone
// user and a role with a concrete, realistic permission set.
// Prints "contactId=<id>". Fixtures use example.org only.

$roleName = 'keyring_e2e';
$perms = [
  'access CiviCRM',
  'access AJAX API',
  'authenticate with api key',
  'view all contacts',
  'edit all contacts',
  'access all custom data',
  'view all activities',
  'edit all activities',
  'delete activities',
  'add contacts',
  'administer keyring tokens',
];

$role = \Civi\Api4\Role::get(FALSE)->addWhere('name', '=', $roleName)->execute()->first();
$role ??= \Civi\Api4\Role::create(FALSE)
  ->addValue('name', $roleName)
  ->addValue('label', 'keyring e2e subject')
  ->addValue('is_active', TRUE)
  ->addValue('permissions', $perms)
  ->execute()
  ->first();
\Civi\Api4\Role::update(FALSE)->addWhere('id', '=', $role['id'])->addValue('permissions', $perms)->execute();

$contact = \Civi\Api4\Contact::get(FALSE)
  ->addWhere('external_identifier', '=', 'keyring-e2e-subject')
  ->execute()
  ->first();
if ($contact === NULL) {
  $contact = \Civi\Api4\Contact::create(FALSE)
    ->addValue('contact_type', 'Individual')
    ->addValue('first_name', 'Keyring')
    ->addValue('last_name', 'E2e')
    ->addValue('external_identifier', 'keyring-e2e-subject')
    ->execute()
    ->first();
  \Civi\Api4\Email::create(FALSE)
    ->addValue('contact_id', $contact['id'])
    ->addValue('email', 'keyring-e2e@example.org')
    ->addValue('is_primary', TRUE)
    ->execute();
}

$user = \Civi\Api4\User::get(FALSE)->addWhere('username', '=', 'keyring-e2e')->execute()->first();
$user ??= \Civi\Api4\User::create(FALSE)
  ->addValue('username', 'keyring-e2e')
  ->addValue('contact_id', $contact['id'])
  ->addValue('uf_name', 'keyring-e2e@example.org')
  ->execute()
  ->first();
\Civi\Api4\User::update(FALSE)
  ->addWhere('id', '=', $user['id'])
  ->addValue('roles', [$role['id']])
  ->addValue('is_active', TRUE)
  ->execute();

echo 'contactId=' . $contact['id'] . "\n";
