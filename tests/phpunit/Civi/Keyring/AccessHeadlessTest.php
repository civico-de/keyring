<?php

declare(strict_types = 1);

namespace Civi\Keyring;

use Civi\Api4\KeyringToken;
use Civi\Api4\Utils\CoreUtil;
use Civi\API\Exception\UnauthorizedException;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * Who may issue, read and revoke which tokens.
 *
 * @group headless
 */
final class AccessHeadlessTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  private int $selfId;

  private int $otherId;

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return ck_headless()->apply();
  }

  protected function setUp(): void {
    parent::setUp();
    TokenContext::reset();
    $this->selfId = $this->contact('Self');
    $this->otherId = $this->contact('Other');
  }

  protected function tearDown(): void {
    TokenContext::reset();
    \CRM_Core_Session::singleton()->set('userID', NULL);
    \CRM_Core_Config::singleton()->userPermissionClass->permissions = NULL;
    parent::tearDown();
  }

  public function testManageOwnCanIssueForItself(): void {
    $this->actAs($this->selfId, [Permissions::MANAGE_OWN, 'access CiviCRM']);
    $created = KeyringToken::create(TRUE)
      ->setContactId($this->selfId)
      ->setLabel('mine')
      ->setScope([])
      ->execute()
      ->single();
    self::assertSame($this->selfId, $created['contact_id']);
  }

  public function testManageOwnCannotIssueForSomebodyElse(): void {
    $this->actAs($this->selfId, [Permissions::MANAGE_OWN, 'access CiviCRM']);
    $this->expectException(UnauthorizedException::class);
    KeyringToken::create(TRUE)
      ->setContactId($this->otherId)
      ->setLabel('theirs')
      ->setScope([])
      ->execute();
  }

  public function testAdministerCanIssueForSomebodyElse(): void {
    $this->actAs($this->selfId, [Permissions::ADMINISTER, 'access CiviCRM']);
    $created = KeyringToken::create(TRUE)
      ->setContactId($this->otherId)
      ->setLabel('theirs')
      ->setScope([])
      ->execute()
      ->single();
    self::assertSame($this->otherId, $created['contact_id']);
  }

  public function testWithoutEitherPermissionNothingIsIssued(): void {
    $this->actAs($this->selfId, ['access CiviCRM']);
    $this->expectException(UnauthorizedException::class);
    KeyringToken::create(TRUE)
      ->setContactId($this->selfId)
      ->setLabel('mine')
      ->setScope([])
      ->execute();
  }

  public function testManageOwnSeesOnlyItsOwnTokens(): void {
    $mine = $this->mintFor($this->selfId);
    $theirs = $this->mintFor($this->otherId);

    $this->actAs($this->selfId, [Permissions::MANAGE_OWN, 'access CiviCRM']);
    $ids = array_column((array) KeyringToken::get(TRUE)->addSelect('id')->execute(), 'id');
    self::assertContains($mine, $ids);
    self::assertNotContains($theirs, $ids);
  }

  public function testAdministerSeesEverybodysTokens(): void {
    $mine = $this->mintFor($this->selfId);
    $theirs = $this->mintFor($this->otherId);

    $this->actAs($this->selfId, [Permissions::ADMINISTER, 'access CiviCRM']);
    $ids = array_column((array) KeyringToken::get(TRUE)->addSelect('id')->execute(), 'id');
    self::assertContains($mine, $ids);
    self::assertContains($theirs, $ids);
  }

  public function testManageOwnCannotRevokeAnotherPersonsToken(): void {
    $theirs = $this->mintFor($this->otherId);
    $this->actAs($this->selfId, [Permissions::MANAGE_OWN, 'access CiviCRM']);

    $revoked = KeyringToken::revoke(TRUE)->addWhere('id', '=', $theirs)->execute();
    self::assertCount(0, $revoked);
    self::assertNull($this->revokedAt($theirs));
  }

  public function testManageOwnCanRevokeItsOwnToken(): void {
    $mine = $this->mintFor($this->selfId);
    $this->actAs($this->selfId, [Permissions::MANAGE_OWN, 'access CiviCRM']);

    KeyringToken::revoke(TRUE)->addWhere('id', '=', $mine)->execute();
    self::assertNotNull($this->revokedAt($mine));
  }

  public function testManageOwnCannotReassignItsOwnTokenToSomebodyElse(): void {
    $mine = $this->mintFor($this->selfId);
    $this->actAs($this->selfId, [Permissions::MANAGE_OWN, 'access CiviCRM']);

    $this->expectException(UnauthorizedException::class);
    KeyringToken::update(TRUE)->addWhere('id', '=', $mine)->addValue('contact_id', $this->otherId)->execute();
  }

  public function testManageOwnCannotUpdateAnotherPersonsTokenByItsId(): void {
    $theirs = $this->mintFor($this->otherId);
    $this->actAs($this->selfId, [Permissions::MANAGE_OWN, 'access CiviCRM']);

    $this->expectException(UnauthorizedException::class);
    KeyringToken::update(TRUE)->addWhere('id', '=', $theirs)->addValue('label', 'taken over')->execute();
  }

  public function testManageOwnCannotReassignAnyTokenThroughSave(): void {
    $mine = $this->mintFor($this->selfId);
    $this->actAs($this->selfId, [Permissions::MANAGE_OWN, 'access CiviCRM']);

    $this->expectException(UnauthorizedException::class);
    KeyringToken::save(TRUE)->addRecord(['id' => $mine, 'contact_id' => $this->otherId])->execute();
  }

  public function testManageOwnCannotReplaceTokens(): void {
    $this->mintFor($this->selfId);
    $this->actAs($this->selfId, [Permissions::MANAGE_OWN, 'access CiviCRM']);

    $this->expectException(UnauthorizedException::class);
    KeyringToken::replace(TRUE)->addWhere('contact_id', '=', $this->selfId)->setRecords([])->execute();
  }

  public function testAdministerMayEditALabel(): void {
    $theirs = $this->mintFor($this->otherId);
    $this->actAs($this->selfId, [Permissions::ADMINISTER, 'access CiviCRM']);

    KeyringToken::update(TRUE)->addWhere('id', '=', $theirs)->addValue('label', 'renamed')->execute();
    self::assertSame('renamed', $this->labelOf($theirs));
  }

  public function testEvenAdministerCannotMoveATokenToAnotherContact(): void {
    $theirs = $this->mintFor($this->otherId);
    $this->actAs($this->selfId, [Permissions::ADMINISTER, 'access CiviCRM']);

    $this->expectException(\CRM_Core_Exception::class);
    KeyringToken::update(TRUE)->addWhere('id', '=', $theirs)->addValue('contact_id', $this->selfId)->execute();
  }

  public function testAnUncheckedWriteCannotMoveATokenEither(): void {
    $mine = $this->mintFor($this->selfId);

    $this->expectException(\CRM_Core_Exception::class);
    KeyringToken::update(FALSE)->addWhere('id', '=', $mine)->addValue('contact_id', $this->otherId)->execute();
  }

  public function testNotEvenAdministerCanDeleteAToken(): void {
    $mine = $this->mintFor($this->selfId);
    $this->actAs($this->selfId, [Permissions::ADMINISTER, 'access CiviCRM']);
    try {
      KeyringToken::delete(TRUE)->addWhere('id', '=', $mine)->execute();
      self::fail('delete should be denied to everyone');
    }
    catch (UnauthorizedException $e) {
      self::assertSame(1, KeyringToken::get(FALSE)->addWhere('id', '=', $mine)->execute()->count());
    }
  }

  public function testNotEvenAdministerCanDeleteADenial(): void {
    $denial = $this->denialFor($this->mintFor($this->selfId));
    $this->actAs($this->selfId, [Permissions::ADMINISTER, 'access CiviCRM']);
    $this->expectException(UnauthorizedException::class);
    \Civi\Api4\KeyringDenial::delete(TRUE)->addWhere('id', '=', $denial)->execute();
  }

  public function testARecordCheckJudgesTheUserItIsGiven(): void {
    $theirs = $this->mintFor($this->otherId);
    $mine = $this->mintFor($this->selfId);
    $this->actAs($this->selfId, [Permissions::MANAGE_OWN, 'access CiviCRM']);

    // The authorizeRecord listener evaluates the user the event names, so a
    // limited contact reaches its own row and no other.
    $request = KeyringToken::update(TRUE);
    self::assertTrue(CoreUtil::checkAccessRecord($request, ['id' => $mine], $this->selfId));
    self::assertFalse(CoreUtil::checkAccessRecord($request, ['id' => $theirs], $this->selfId));
  }

  public function testManageOwnSeesOnlyDenialsOfItsOwnTokens(): void {
    $mineDenial = $this->denialFor($this->mintFor($this->selfId));
    $theirsDenial = $this->denialFor($this->mintFor($this->otherId));

    $this->actAs($this->selfId, [Permissions::MANAGE_OWN, 'access CiviCRM']);
    $ids = array_column((array) \Civi\Api4\KeyringDenial::get(TRUE)->addSelect('id')->execute(), 'id');
    self::assertContains($mineDenial, $ids);
    self::assertNotContains($theirsDenial, $ids);
  }

  public function testAdministerSeesEverybodysDenials(): void {
    $mineDenial = $this->denialFor($this->mintFor($this->selfId));
    $theirsDenial = $this->denialFor($this->mintFor($this->otherId));

    $this->actAs($this->selfId, [Permissions::ADMINISTER, 'access CiviCRM']);
    $ids = array_column((array) \Civi\Api4\KeyringDenial::get(TRUE)->addSelect('id')->execute(), 'id');
    self::assertContains($mineDenial, $ids);
    self::assertContains($theirsDenial, $ids);
  }

  // ── helpers ────────────────────────────────────────────────────────────

  private function contact(string $lastName): int {
    return (int) \Civi\Api4\Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'Keyring')
      ->addValue('last_name', $lastName)
      ->execute()
      ->single()['id'];
  }

  private function mintFor(int $contactId): int {
    return (int) KeyringToken::create(FALSE)
      ->setContactId($contactId)
      ->setLabel('t')
      ->setScope([])
      ->execute()
      ->single()['id'];
  }

  /**
   * KeyringToken.contact_id is an FK to Contact, so core's own DAO adds the
   * contact ACL sub-query before keyring's clause is even asked. A caller who
   * cannot see the contact sees none of its tokens either — which is why the
   * fixture holds a contact-viewing permission alongside the keyring one.
   *
   * @param list<string> $permissions
   */
  private function actAs(int $contactId, array $permissions): void {
    \CRM_Core_Session::singleton()->set('userID', $contactId);
    \CRM_Core_Config::singleton()->userPermissionClass->permissions = array_merge($permissions, ['view all contacts']);
  }

  private function denialFor(int $tokenId): int {
    DenialRecorder::reset();
    DenialRecorder::record($tokenId, 'view all contacts', 'civicrm/contact/view');
    DenialRecorder::flush();
    return (int) \Civi\Api4\KeyringDenial::get(FALSE)
      ->addSelect('id')
      ->addWhere('token_id', '=', $tokenId)
      ->execute()
      ->single()['id'];
  }

  private function labelOf(int $tokenId): string {
    return (string) \CRM_Core_DAO::singleValueQuery(
      'SELECT label FROM civicrm_keyring_token WHERE id = %1',
      [1 => [$tokenId, 'Integer']],
    );
  }

  private function revokedAt(int $tokenId): ?string {
    $value = \CRM_Core_DAO::singleValueQuery(
      'SELECT revoked_at FROM civicrm_keyring_token WHERE id = %1',
      [1 => [$tokenId, 'Integer']],
    );
    return $value === NULL ? NULL : (string) $value;
  }

}
