<?php

declare(strict_types = 1);

namespace Civi\Keyring;

use Civi\Api4\KeyringDenial;
use Civi\Api4\KeyringToken;
use Civi\Authx\CheckCredentialEvent;
use Civi\Keyring\Authx\TokenSubscriber;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * Token minting, authentication and scope enforcement against a real schema.
 *
 * @group headless
 */
final class TokenHeadlessTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  private int $contactId;

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return ck_headless()->apply();
  }

  protected function setUp(): void {
    parent::setUp();
    TokenContext::reset();
    DenialRecorder::reset();
    unset($_SERVER['HTTP_X_CIVI_AUTH'], $_SERVER['HTTP_AUTHORIZATION']);
    $this->contactId = (int) \Civi\Api4\Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'Keyring')
      ->addValue('last_name', 'Subject')
      ->execute()->single()['id'];
  }

  protected function tearDown(): void {
    TokenContext::reset();
    DenialRecorder::reset();
    \CRM_Utils_Time::resetTime();
    unset($_SERVER['HTTP_X_CIVI_AUTH'], $_SERVER['HTTP_AUTHORIZATION']);
    parent::tearDown();
  }

  // ── minting ────────────────────────────────────────────────────────────

  public function testCreateReturnsThePlaintextOnceAndStoresOnlyItsHash(): void {
    $created = $this->mint(['access CiviCRM']);
    $plaintext = $created['token'];
    self::assertStringStartsWith('kr_', $plaintext);

    $stored = \CRM_Core_DAO::singleValueQuery(
      'SELECT token_hash FROM civicrm_keyring_token WHERE id = %1',
      [1 => [$created['id'], 'Integer']],
    );
    self::assertSame(TokenService::hash($plaintext), $stored);

    $row = KeyringToken::get(FALSE)->addWhere('id', '=', $created['id'])->execute()->single();
    self::assertArrayNotHasKey('token', $row);
    self::assertSame(TokenService::displayPrefix($plaintext), $row['token_prefix']);
  }

  public function testGetNeverExposesTheHash(): void {
    $created = $this->mint(['access CiviCRM']);
    $this->grantEverything();
    $row = KeyringToken::get(TRUE)->addSelect('*')
      ->addWhere('id', '=', $created['id'])->execute()->single();
    self::assertArrayNotHasKey('token_hash', $row);

    // Naming the field explicitly does not get it either: APIv4 drops an
    // always-denied field from the select rather than raising.
    $explicit = KeyringToken::get(TRUE)->addSelect('id', 'token_hash')
      ->addWhere('id', '=', $created['id'])->execute()->single();
    self::assertSame(['id'], array_keys($explicit));
  }

  public function testWildcardPermissionIsStrippedOnSave(): void {
    $created = $this->mint(['access CiviCRM', Permissions::NEVER_IN_SCOPE]);
    self::assertNotContains(Permissions::NEVER_IN_SCOPE, $created['scope']);
  }

  public function testGuardPermissionIsAddedOnSave(): void {
    $created = $this->mint([]);
    self::assertContains(Permissions::ALWAYS_IN_SCOPE, $created['scope']);
  }

  public function testCreateRefusesAnUnknownScopeProfile(): void {
    $this->expectExceptionMessageMatches('/unknown scope profile/');
    KeyringToken::create(FALSE)
      ->setContactId($this->contactId)
      ->setLabel('nope')
      ->setScopeProfile('does-not-exist')
      ->execute();
  }

  public function testCreateRefusesBothScopeAndProfile(): void {
    $this->expectExceptionMessageMatches('/not both/');
    KeyringToken::create(FALSE)
      ->setContactId($this->contactId)
      ->setLabel('nope')
      ->setScope(['access CiviCRM'])
      ->setScopeProfile('empty')
      ->execute();
  }

  public function testCreateRefusesWithoutAScope(): void {
    $this->expectExceptionMessageMatches('/scope or scopeProfile is required/');
    KeyringToken::create(FALSE)->setContactId($this->contactId)->setLabel('nope')->execute();
  }

  public function testCreateAcceptsAConfiguredScopeProfile(): void {
    \Civi::settings()->set(
      ScopeProfiles::SETTING,
      '{"addin": ["access CiviCRM", "access AJAX API"]}',
    );
    $created = KeyringToken::create(FALSE)
      ->setContactId($this->contactId)
      ->setLabel('from profile')
      ->setScopeProfile('addin')
      ->execute()->single();
    self::assertSame('addin', $created['scope_profile']);
    self::assertContains('access AJAX API', $created['scope']);
  }

  // ── authentication ─────────────────────────────────────────────────────

  public function testAValidTokenInAHeaderAuthenticatesTheContact(): void {
    $created = $this->mint(['access CiviCRM']);
    $event = $this->check($created['token']);
    self::assertSame(
      ['credType' => 'api_key', 'contactId' => $this->contactId],
      $event->getPrincipal(),
    );
    self::assertTrue($event->isPropagationStopped());
  }

  public function testTheTokenActivatesItsScopeAndMode(): void {
    $created = $this->mint(['view all contacts'], 'report');
    $this->check($created['token']);
    self::assertTrue(TokenContext::isActive());
    self::assertSame($created['id'], TokenContext::tokenId());
    self::assertSame(TokenContext::MODE_REPORT, TokenContext::mode());
    self::assertTrue(TokenContext::allows('view all contacts'));
    self::assertTrue(TokenContext::allows(Permissions::ALWAYS_IN_SCOPE));
    self::assertFalse(TokenContext::allows('edit all contacts'));
  }

  public function testATokenSentAsAUrlParameterIsRejectedNamingTheHeader(): void {
    $created = $this->mint(['access CiviCRM']);
    // No header set: the credential can only have come from the URL or body.
    $event = new CheckCredentialEvent('Bearer ' . $created['token'], 'civicrm/ajax/rest');
    TokenSubscriber::onCheckCredential($event);
    self::assertNull($event->getPrincipal());
    self::assertStringContainsString('X-Civi-Auth', (string) $event->getRejection());
    self::assertFalse(TokenContext::isActive());
  }

  public function testUnknownRevokedAndExpiredTokensFailIdentically(): void {
    $unknown = $this->check('kr_' . str_repeat('a', 52))->getRejection();

    $revoked = $this->mint(['access CiviCRM']);
    KeyringToken::revoke(FALSE)->addWhere('id', '=', $revoked['id'])->execute();
    $revokedMessage = $this->check($revoked['token'])->getRejection();

    $expired = $this->mint(['access CiviCRM'], 'enforce', date('Y-m-d'));
    \CRM_Utils_Time::setTime('+2 days');
    $expiredMessage = $this->check($expired['token'])->getRejection();
    \CRM_Utils_Time::resetTime();

    self::assertSame(TokenSubscriber::REJECTION, $unknown);
    self::assertSame($unknown, $revokedMessage);
    self::assertSame($unknown, $expiredMessage);
    self::assertFalse(TokenContext::isActive());
  }

  public function testAForeignCredentialIsLeftToCore(): void {
    $event = $this->check('some-plain-contact-api-key');
    self::assertNull($event->getPrincipal());
    self::assertNull($event->getRejection());
    self::assertFalse($event->isPropagationStopped());
  }

  public function testLastUsedAtIsWrittenOncePerHour(): void {
    $created = $this->mint(['access CiviCRM']);
    $this->check($created['token']);
    $first = $this->lastUsedAt($created['id']);
    self::assertNotNull($first);

    // The throttle is the statement's own WHERE clause: a second touch inside
    // the interval leaves the stored value exactly as it was, even though the
    // timestamp it offers is a different one.
    TokenContext::reset();
    $inside = \CRM_Utils_Time::time() + TokenService::TOUCH_INTERVAL - 60;
    TokenService::touch($created['id'], $inside);
    self::assertSame($first, $this->lastUsedAt($created['id']));

    // Past the window it is written again, to the offered timestamp.
    $outside = \CRM_Utils_Time::time() + TokenService::TOUCH_INTERVAL + 60;
    TokenService::touch($created['id'], $outside);
    self::assertSame(date('Y-m-d H:i:s', $outside), $this->lastUsedAt($created['id']));
  }

  public function testARevokedTokenIsNeverTouched(): void {
    $created = $this->mint(['access CiviCRM']);
    KeyringToken::revoke(FALSE)->addWhere('id', '=', $created['id'])->execute();
    self::assertNull($this->lastUsedAt($created['id']));
    TokenService::touch($created['id'], \CRM_Utils_Time::time());
    self::assertNull($this->lastUsedAt($created['id']));
  }

  // ── expiry ─────────────────────────────────────────────────────────────

  public function testAnExpiryDateIsStoredAsTheEndOfThatDay(): void {
    $created = $this->mint(['access CiviCRM'], 'enforce', '2030-06-01');
    self::assertSame('2030-06-01 23:59:59', $created['expires_at']);
    $row = KeyringToken::get(FALSE)->addSelect('expires_at')
      ->addWhere('id', '=', $created['id'])->execute()->single();
    self::assertSame('2030-06-01 23:59:59', $row['expires_at']);
  }

  public function testADatetimeExpiryIsRefusedRatherThanNormalised(): void {
    $this->expectExceptionMessageMatches('/a time of day is not accepted/');
    $this->mint(['access CiviCRM'], 'enforce', '2030-06-01 12:00:00');
  }

  public function testAnExpiryInThePastIsRefused(): void {
    $this->expectExceptionMessageMatches('/is in the past/');
    $this->mint(['access CiviCRM'], 'enforce', '2020-01-01');
  }

  public function testStateTurnsFromActiveToExpiredWhenTheDayIsOver(): void {
    $created = $this->mint(['access CiviCRM'], 'enforce', date('Y-m-d'));

    \CRM_Utils_Time::setTime(date('Y-m-d') . ' 23:00:00');
    self::assertSame('active', $this->stateOf($created['id']));
    // The whole of the last day still counts.
    \CRM_Utils_Time::setTime(date('Y-m-d') . ' 23:59:58');
    self::assertSame('active', $this->stateOf($created['id']));

    \CRM_Utils_Time::setTime('+2 days');
    self::assertSame('expired', $this->stateOf($created['id']));
    \CRM_Utils_Time::resetTime();
  }

  public function testStateIsRevokedEvenWhenTheTokenHasNotExpired(): void {
    $created = $this->mint(['access CiviCRM'], 'enforce', '2030-06-01');
    self::assertSame('active', $this->stateOf($created['id']));
    KeyringToken::revoke(FALSE)->addWhere('id', '=', $created['id'])->execute();
    self::assertSame('revoked', $this->stateOf($created['id']));
  }

  public function testStateIsActiveWithoutAnExpiry(): void {
    $created = $this->mint(['access CiviCRM']);
    self::assertSame('active', $this->stateOf($created['id']));
  }

  public function testStateCarriesATranslatedLabel(): void {
    $created = $this->mint(['access CiviCRM']);
    $row = KeyringToken::get(FALSE)->addSelect('state:label')
      ->addWhere('id', '=', $created['id'])->execute()->single();
    self::assertSame('Active', $row['state:label']);
  }

  public function testStateIsNotWritable(): void {
    $created = $this->mint(['access CiviCRM']);
    $field = KeyringToken::getFields(FALSE)->addWhere('name', '=', 'state')
      ->execute()->single();
    self::assertTrue($field['readonly']);
    self::assertSame('active', $this->stateOf($created['id']));
  }

  public function testAnAdministratorsUpdateStoresADateAsTheEndOfTheDay(): void {
    $created = $this->mint(['access CiviCRM']);
    KeyringToken::update(FALSE)->addWhere('id', '=', $created['id'])
      ->addValue('expires_at', '2030-06-01')->execute();

    $row = KeyringToken::get(FALSE)->addSelect('expires_at', 'state')
      ->addWhere('id', '=', $created['id'])->execute()->single();
    self::assertSame('2030-06-01 23:59:59', $row['expires_at']);
    self::assertSame('active', $row['state']);
  }

  public function testAnUpdateWithATimeOfDayIsRefused(): void {
    $created = $this->mint(['access CiviCRM'], 'enforce', '2030-06-01');
    try {
      KeyringToken::update(FALSE)->addWhere('id', '=', $created['id'])
        ->addValue('expires_at', '2030-07-01 12:00:00')->execute();
      self::fail('a datetime expiry should have been refused');
    }
    catch (\CRM_Core_Exception $e) {
      self::assertStringContainsString('a time of day is not accepted', $e->getMessage());
    }
    self::assertSame('2030-06-01 23:59:59', $this->expiresAt($created['id']));
  }

  public function testAnUpdateIntoThePastIsRefused(): void {
    $created = $this->mint(['access CiviCRM'], 'enforce', '2030-06-01');
    try {
      KeyringToken::update(FALSE)->addWhere('id', '=', $created['id'])
        ->addValue('expires_at', '2020-01-01')->execute();
      self::fail('a past expiry should have been refused');
    }
    catch (\CRM_Core_Exception $e) {
      self::assertStringContainsString('is in the past', $e->getMessage());
    }
    self::assertSame('2030-06-01 23:59:59', $this->expiresAt($created['id']));
  }

  public function testResendingTheStoredExpiryIsNotAChange(): void {
    $created = $this->mint(['access CiviCRM'], 'enforce', '2030-06-01');
    KeyringToken::update(FALSE)->addWhere('id', '=', $created['id'])
      ->addValue('expires_at', '2030-06-01 23:59:59')
      ->addValue('label', 'relabelled')->execute();

    $row = KeyringToken::get(FALSE)->addSelect('expires_at', 'label')
      ->addWhere('id', '=', $created['id'])->execute()->single();
    self::assertSame('2030-06-01 23:59:59', $row['expires_at']);
    self::assertSame('relabelled', $row['label']);
  }

  public function testAnExpiryCanBeCleared(): void {
    $created = $this->mint(['access CiviCRM'], 'enforce', '2030-06-01');
    KeyringToken::update(FALSE)->addWhere('id', '=', $created['id'])
      ->addValue('expires_at', NULL)->execute();
    self::assertNull($this->expiresAt($created['id']));
  }

  // ── issuance metadata ──────────────────────────────────────────────────

  public function testIssuanceMetadataCannotBeRewritten(): void {
    $created = $this->mint(['access CiviCRM']);
    $prefix = $created['token_prefix'];

    try {
      KeyringToken::update(FALSE)->addWhere('id', '=', $created['id'])
        ->addValue('token_prefix', 'kr_forged')->execute();
      self::fail('rewriting token_prefix should have been refused');
    }
    catch (\CRM_Core_Exception $e) {
      self::assertStringContainsString('token_prefix', $e->getMessage());
    }

    $row = KeyringToken::get(FALSE)->addSelect('token_prefix')
      ->addWhere('id', '=', $created['id'])->execute()->single();
    self::assertSame($prefix, $row['token_prefix']);
  }

  public function testTheStoredDigestCannotBeRewritten(): void {
    $created = $this->mint(['access CiviCRM']);
    $before = \CRM_Core_DAO::singleValueQuery(
      'SELECT token_hash FROM civicrm_keyring_token WHERE id = %1',
      [1 => [$created['id'], 'Integer']],
    );

    $this->expectException(\CRM_Core_Exception::class);
    try {
      KeyringToken::update(FALSE)->addWhere('id', '=', $created['id'])
        ->addValue('token_hash', TokenService::hash('kr_forged'))->execute();
    }
    finally {
      self::assertSame($before, \CRM_Core_DAO::singleValueQuery(
        'SELECT token_hash FROM civicrm_keyring_token WHERE id = %1',
        [1 => [$created['id'], 'Integer']],
      ));
    }
  }

  public function testAnEditThatRepeatsTheStoredValuesIsFine(): void {
    $created = $this->mint(['access CiviCRM']);
    KeyringToken::update(FALSE)->addWhere('id', '=', $created['id'])
      ->addValue('contact_id', $this->contactId)
      ->addValue('token_prefix', $created['token_prefix'])
      ->addValue('label', 'renamed')
      ->execute();
    $row = KeyringToken::get(FALSE)->addSelect('label')
      ->addWhere('id', '=', $created['id'])->execute()->single();
    self::assertSame('renamed', $row['label']);
  }

  // ── revocation ─────────────────────────────────────────────────────────

  public function testRevokeMarksTheRowAndKeepsIt(): void {
    $created = $this->mint(['access CiviCRM']);
    $revoked = KeyringToken::revoke(FALSE)->addWhere('id', '=', $created['id'])->execute()->single();
    self::assertNotNull($revoked['revoked_at']);
    self::assertSame(1, KeyringToken::get(FALSE)->addWhere('id', '=', $created['id'])->execute()->count());
  }

  public function testRevokeIsIdempotent(): void {
    $created = $this->mint(['access CiviCRM']);
    $first = KeyringToken::revoke(FALSE)->addWhere('id', '=', $created['id'])->execute()->single();
    $second = KeyringToken::revoke(FALSE)->addWhere('id', '=', $created['id'])->execute()->single();
    self::assertSame($first['revoked_at'], $second['revoked_at']);
  }

  // ── cascades ───────────────────────────────────────────────────────────

  public function testDeletingTheContactRemovesItsTokens(): void {
    $created = $this->mint(['access CiviCRM']);
    \CRM_Core_DAO::executeQuery('DELETE FROM civicrm_contact WHERE id = %1', [
      1 => [$this->contactId, 'Integer'],
    ]);
    self::assertSame(0, KeyringToken::get(FALSE)->addWhere('id', '=', $created['id'])->execute()->count());
  }

  public function testNoPermissionCanDeleteAToken(): void {
    $created = $this->mint(['access CiviCRM']);
    $this->grantEverything();
    $this->expectException(\Civi\API\Exception\UnauthorizedException::class);
    KeyringToken::delete(TRUE)->addWhere('id', '=', $created['id'])->execute();
  }

  public function testDeletingTheTokenRemovesItsDenials(): void {
    $created = $this->mint(['access CiviCRM'], 'report');
    DenialRecorder::record($created['id'], 'view all contacts', 'civicrm/ajax/rest');
    DenialRecorder::flush();
    self::assertSame(1, KeyringDenial::get(FALSE)->addWhere('token_id', '=', $created['id'])->execute()->count());

    KeyringToken::delete(FALSE)->addWhere('id', '=', $created['id'])->execute();
    self::assertSame(0, KeyringDenial::get(FALSE)->addWhere('token_id', '=', $created['id'])->execute()->count());
  }

  // ── denials ────────────────────────────────────────────────────────────

  public function testDenialsAggregateByTokenPermissionAndPath(): void {
    $created = $this->mint(['access CiviCRM'], 'report');
    for ($i = 0; $i < 5; $i++) {
      DenialRecorder::record($created['id'], 'view all contacts', 'civicrm/contact/view');
    }
    DenialRecorder::record($created['id'], 'view all contacts', 'civicrm/ajax/rest');
    DenialRecorder::flush();

    $rows = KeyringDenial::get(FALSE)
      ->addWhere('token_id', '=', $created['id'])
      ->addOrderBy('hit_count', 'DESC')
      ->execute();
    self::assertCount(2, $rows);
    self::assertSame(5, $rows[0]['hit_count']);
    self::assertSame(1, $rows[1]['hit_count']);
  }

  public function testASecondFlushAddsHitsNotRows(): void {
    $created = $this->mint(['access CiviCRM'], 'report');
    DenialRecorder::record($created['id'], 'view all contacts', 'civicrm/contact/view');
    DenialRecorder::flush();
    DenialRecorder::record($created['id'], 'view all contacts', 'civicrm/contact/view');
    DenialRecorder::flush();

    $rows = KeyringDenial::get(FALSE)->addWhere('token_id', '=', $created['id'])->execute();
    self::assertCount(1, $rows);
    self::assertSame(2, $rows[0]['hit_count']);
    self::assertNotEmpty($rows[0]['first_seen']);
  }

  public function testFlushingAnEmptyBufferWritesNothing(): void {
    DenialRecorder::flush();
    self::assertSame([], DenialRecorder::buffered());
  }

  public function testKeyringDenialCannotBeWrittenThroughTheApi(): void {
    $created = $this->mint(['access CiviCRM'], 'report');
    $this->expectException(\Civi\API\Exception\UnauthorizedException::class);
    KeyringDenial::create(TRUE)
      ->addValue('token_id', $created['id'])
      ->addValue('permission', 'forged')
      ->addValue('request_path', 'forged')
      ->addValue('hit_count', 1)
      ->addValue('first_seen', 'now')
      ->addValue('last_seen', 'now')
      ->execute();
  }

  // ── scope enforcement through CRM_Core_Permission ───────────────────────

  public function testEnforceModeTakesAwayPermissionsOutsideTheScope(): void {
    $created = $this->mint(['access CiviCRM'], 'enforce');
    $this->grantEverything();
    self::assertTrue(\CRM_Core_Permission::check('view all contacts'));

    $this->check($created['token']);
    self::assertTrue(\CRM_Core_Permission::check('access CiviCRM'));
    self::assertFalse(\CRM_Core_Permission::check('view all contacts'));
  }

  public function testTheScopeNeverWidensAVerdict(): void {
    $created = $this->mint(['view all contacts'], 'enforce');
    \CRM_Core_Config::singleton()->userPermissionClass->permissions = ['access CiviCRM'];
    $this->check($created['token']);
    self::assertFalse(\CRM_Core_Permission::check('view all contacts'));
  }

  public function testReportModeDeniesNothingAndRecordsInstead(): void {
    $created = $this->mint(['access CiviCRM'], 'report');
    $this->grantEverything();
    $this->check($created['token']);

    self::assertTrue(\CRM_Core_Permission::check('view all contacts'));
    DenialRecorder::flush();
    $rows = KeyringDenial::get(FALSE)->addWhere('token_id', '=', $created['id'])->execute();
    self::assertGreaterThan(0, $rows->count());
    self::assertContains('view all contacts', array_column((array) $rows, 'permission'));
  }

  public function testWithoutATokenNothingIsNarrowed(): void {
    $this->grantEverything();
    self::assertTrue(\CRM_Core_Permission::check('view all contacts'));
    self::assertSame([], DenialRecorder::buffered());
  }

  // ── helpers ────────────────────────────────────────────────────────────

  /**
   * @param list<string> $scope
   *
   * @return array<string, mixed>
   */
  private function mint(array $scope, string $mode = 'enforce', ?string $expiresAt = NULL): array {
    $action = KeyringToken::create(FALSE)
      ->setContactId($this->contactId)
      ->setLabel('headless')
      ->setScope($scope)
      ->setMode($mode);
    if ($expiresAt !== NULL) {
      $action->setExpiresAt($expiresAt);
    }
    return $action->execute()->single();
  }

  private function check(string $plaintext): CheckCredentialEvent {
    $_SERVER['HTTP_X_CIVI_AUTH'] = 'Bearer ' . $plaintext;
    $event = new CheckCredentialEvent('Bearer ' . $plaintext, 'civicrm/ajax/rest');
    TokenSubscriber::onCheckCredential($event);
    return $event;
  }

  private function expiresAt(int $tokenId): ?string {
    $row = KeyringToken::get(FALSE)->addSelect('expires_at')
      ->addWhere('id', '=', $tokenId)->execute()->single();
    return $row['expires_at'] === NULL ? NULL : (string) $row['expires_at'];
  }

  private function stateOf(int $tokenId): string {
    $row = KeyringToken::get(FALSE)->addSelect('state')
      ->addWhere('id', '=', $tokenId)->execute()->single();
    return (string) $row['state'];
  }

  private function lastUsedAt(int $tokenId): ?string {
    $value = \CRM_Core_DAO::singleValueQuery(
      'SELECT last_used_at FROM civicrm_keyring_token WHERE id = %1',
      [1 => [$tokenId, 'Integer']],
    );
    return $value === NULL ? NULL : (string) $value;
  }

  private function grantEverything(): void {
    \CRM_Core_Config::singleton()->userPermissionClass->permissions = NULL;
  }

}
