<?php

declare(strict_types = 1);

namespace Civi\Keyring;

/**
 * The permission strings keyring declares and the two core permissions the
 * scope logic treats specially.
 */
final class Permissions {

  /**
   * Issue and revoke tokens for oneself.
   */
  public const MANAGE_OWN = 'manage own keyring tokens';

  /**
   * Issue and revoke tokens for anybody, and read the denial trail.
   */
  public const ADMINISTER = 'administer keyring tokens';

  /**
   * Checked by authx BEFORE login; denying it breaks authentication itself.
   */
  public const ALWAYS_IN_SCOPE = 'authenticate with api key';

  /**
   * Short-circuits CRM_Core_Permission::check, so a scope containing it is a
   * no-op.
   */
  public const NEVER_IN_SCOPE = 'all CiviCRM permissions and ACLs';

}
