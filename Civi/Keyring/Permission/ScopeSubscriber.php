<?php

declare(strict_types = 1);

namespace Civi\Keyring\Permission;

use Civi\Core\Event\GenericHookEvent;
use Civi\Core\Service\AutoSubscriber;
use Civi\Keyring\DenialRecorder;
use Civi\Keyring\TokenContext;

/**
 * Narrows a token-authenticated request to the token's scope.
 *
 * PHP_INT_MIN: every other listener has spoken, so this sees the verdict
 * CRM_Core_Permission::check() is about to return and has the last word. A
 * token grants what is in its scope AND what the contact holds, directly or
 * through a permission that implies it; it never grants beyond the contact.
 */
final class ScopeSubscriber extends AutoSubscriber {

  /**
   * @return array<string, list<array{0: string, 1: int}>>
   */
  public static function getSubscribedEvents(): array {
    return ['hook_civicrm_permission_check' => [['onCheck', PHP_INT_MIN]]];
  }

  public static function onCheck(GenericHookEvent $event): void {
    if (!TokenContext::isActive()) {
      return;
    }
    // Hook fields are ['permission', 'granted', 'contactId']; the scope belongs to
    // the request, so every check it covers is narrowed whatever contact it names.
    [$permission, $granted, $contactId] = $event->getHookValues();
    if (!is_string($permission)) {
      return;
    }
    if (TokenContext::allows($permission)) {
      // Core asks the implying permissions next ('all CiviCRM permissions and
      // ACLs' last), and those are out of scope: answer for them here.
      if ($granted !== TRUE && Implication::heldBy($permission, $contactId)) {
        $event->__set('granted', TRUE);
      }
      return;
    }
    if ($granted !== TRUE) {
      return;
    }

    $tokenId = TokenContext::tokenId();
    if (TokenContext::mode() === TokenContext::MODE_REPORT) {
      if ($tokenId !== NULL) {
        DenialRecorder::record($tokenId, $permission, DenialRecorder::requestPath());
      }
      return;
    }

    // The magic setter writes back into the by-reference $granted.
    $event->__set('granted', FALSE);
  }

}
