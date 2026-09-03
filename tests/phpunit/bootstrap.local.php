<?php

declare(strict_types = 1);

/**
 * Repo-specific test setup. `bootstrap.php` is template-managed and rewritten
 * wholesale by `ckinit --update`, so nothing keyring-specific may live there.
 */

define('KEYRING_TEST', TRUE);
