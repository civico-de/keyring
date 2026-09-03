<?php

declare(strict_types = 1);

namespace Civi\Keyring;

/**
 * Anything keyring refuses to do.
 *
 * A plain RuntimeException rather than CRM_Core_Exception: the scope and token
 * services are pure enough to unit-test without booting CiviCRM, and
 * inheriting from a core class would drag the framework into that suite.
 * APIv4 reports any Throwable the same way.
 */
class KeyringException extends \RuntimeException {}
