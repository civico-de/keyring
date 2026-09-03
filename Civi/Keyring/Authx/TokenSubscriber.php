<?php

declare(strict_types = 1);

namespace Civi\Keyring\Authx;

use Civi\Authx\CheckCredentialEvent;
use Civi\Core\Service\AutoSubscriber;
use Civi\Keyring\Expiry;
use Civi\Keyring\ScopeService;
use Civi\Keyring\TokenContext;
use Civi\Keyring\TokenService;

/**
 * Resolves `kr_` credentials into a contact principal.
 *
 * Priority -250: above core's Bearer api_key resolver (-300), so keyring owns
 * its tokens, and below core's Basic listener (-200), so username/password
 * keeps precedence. Accepting stops propagation, so core never sees a token
 * keyring claimed.
 */
final class TokenSubscriber extends AutoSubscriber {

  public const PRIORITY = -250;

  /**
   * Revoked, expired and unknown tokens fail with the same words: the client
   * learns that the credential is not usable, not why.
   */
  public const REJECTION = 'Unknown keyring token';

  public const URL_REJECTION = 'A keyring token must be sent as a request header ("X-Civi-Auth: Bearer <token>" or "Authorization: Bearer <token>"), never as a URL or form parameter.';

  /**
   * @return array<string, list<array{0: string, 1: int}>>
   */
  public static function getSubscribedEvents(): array {
    return ['civi.authx.checkCredential' => [['onCheckCredential', self::PRIORITY]]];
  }

  public static function onCheckCredential(CheckCredentialEvent $e): void {
    if (!TokenService::looksLikeToken($e->credValue)) {
      return;
    }

    // A header has to carry the token and no parameter may repeat it: proving
    // the header exists says nothing about what else the URL still holds.
    if (!CredentialSource::isHeader($e->credFormat, $e->credValue) || CredentialSource::isInRequestParams()) {
      $e->reject(self::URL_REJECTION);
      return;
    }

    $token = TokenService::lookup($e->credValue);
    $now = \CRM_Utils_Time::time();
    if ($token === NULL || $token['revoked_at'] !== NULL || Expiry::isExpired($token['expires_at'], $now)) {
      $e->reject(self::REJECTION);
      return;
    }

    TokenContext::activate($token['id'], ScopeService::lookupTable($token['scope']), $token['mode']);
    // Throttled inside the statement itself, so two concurrent requests write
    // at most one row change per interval.
    TokenService::touch($token['id'], $now);

    $e->accept(['credType' => 'api_key', 'contactId' => $token['contact_id']]);
  }

}
