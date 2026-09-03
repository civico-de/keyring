<?php

declare(strict_types = 1);

namespace Civi\Keyring\Tests\Unit;

use Civi\Keyring\KeyringException;
use Civi\Keyring\ScopeProfiles;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Civi\Keyring\ScopeProfiles
 */
final class ScopeProfilesTest extends TestCase {

  public function testEmptySettingYieldsNoProfiles(): void {
    self::assertSame([], ScopeProfiles::parse(''));
    self::assertSame([], ScopeProfiles::parse('   '));
  }

  public function testProfilesAreParsedFromJson(): void {
    $profiles = ScopeProfiles::parse('{"empty": [], "addin": ["access CiviCRM"]}');
    self::assertSame(['empty' => [], 'addin' => ['access CiviCRM']], $profiles);
  }

  public function testMalformedJsonIsAnErrorNotAnEmptyList(): void {
    $this->expectException(KeyringException::class);
    ScopeProfiles::parse('{ not json');
  }

  public function testProfileWithNonListValueIsAnError(): void {
    $this->expectException(KeyringException::class);
    ScopeProfiles::parse('{"addin": "access CiviCRM"}');
  }

  public function testProfileWithNonStringPermissionIsAnError(): void {
    $this->expectExceptionMessageMatches('/non-empty permission names/');
    ScopeProfiles::parse('{"addin": [17]}');
  }

  public function testProfileWithAnEmptyPermissionIsAnError(): void {
    $this->expectExceptionMessageMatches('/non-empty permission names/');
    ScopeProfiles::parse('{"addin": ["access CiviCRM", "  "]}');
  }

  public function testRepeatedPermissionsAreNormalisedAway(): void {
    $profiles = ScopeProfiles::parse(
      '{"addin": ["access CiviCRM", " access CiviCRM ", "access AJAX API"]}',
    );
    self::assertSame(['access CiviCRM', 'access AJAX API'], $profiles['addin']);
  }

  public function testAProfileNameIsTrimmed(): void {
    self::assertSame(['addin' => []], ScopeProfiles::parse('{" addin ": []}'));
  }

  public function testAProfileNameLongerThanTheColumnIsAnError(): void {
    $long = str_repeat('n', ScopeProfiles::MAX_NAME_LENGTH + 1);
    $this->expectExceptionMessageMatches('/longer than 64 characters/');
    ScopeProfiles::parse(json_encode([$long => []], JSON_THROW_ON_ERROR));
  }

  public function testAProfileNameOfExactlyTheColumnLengthIsAccepted(): void {
    $name = str_repeat('n', ScopeProfiles::MAX_NAME_LENGTH);
    $profiles = ScopeProfiles::parse(json_encode([$name => []], JSON_THROW_ON_ERROR));
    self::assertSame([$name], array_keys($profiles));
  }

  public function testProfileWithAnEmptyNameIsAnError(): void {
    $this->expectException(KeyringException::class);
    ScopeProfiles::parse('{"": []}');
  }

  public function testProfileWithABlankNameIsAnError(): void {
    $this->expectException(KeyringException::class);
    ScopeProfiles::parse('{"   ": []}');
  }

  public function testValidationCallbackAcceptsValidJson(): void {
    $value = '{"empty": []}';
    self::assertTrue(ScopeProfiles::validateSetting($value));
  }

}
