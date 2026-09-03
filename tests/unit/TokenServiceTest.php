<?php

declare(strict_types = 1);

namespace Civi\Keyring\Tests\Unit;

use Civi\Keyring\TokenService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Civi\Keyring\TokenService
 */
final class TokenServiceTest extends TestCase {

  public function testGeneratedTokenCarriesThePrefix(): void {
    $token = TokenService::generate();
    self::assertStringStartsWith('kr_', $token);
    self::assertTrue(TokenService::looksLikeToken($token));
  }

  public function testGeneratedTokenIsFullEntropyBase32(): void {
    $token = TokenService::generate();
    // 32 random bytes are 52 base32 characters, plus the three-byte prefix.
    self::assertSame(55, strlen($token));
    self::assertMatchesRegularExpression('/^kr_[a-z2-7]{52}$/', $token);
  }

  public function testTokensDoNotRepeat(): void {
    $tokens = [];
    for ($i = 0; $i < 50; $i++) {
      $tokens[] = TokenService::generate();
    }
    self::assertCount(50, array_unique($tokens));
  }

  public function testHashIsSha256OfThePlaintext(): void {
    self::assertSame(hash('sha256', 'kr_abc'), TokenService::hash('kr_abc'));
    self::assertSame(64, strlen(TokenService::hash('kr_abc')));
  }

  public function testDisplayPrefixIsTheFirstNineCharacters(): void {
    $token = TokenService::generate();
    self::assertSame(substr($token, 0, 9), TokenService::displayPrefix($token));
    self::assertSame(9, strlen(TokenService::displayPrefix($token)));
  }

  public function testForeignCredentialsAreNotClaimed(): void {
    self::assertFalse(TokenService::looksLikeToken('some-contact-api-key'));
    self::assertFalse(TokenService::looksLikeToken(''));
    self::assertFalse(TokenService::looksLikeToken('KR_uppercase'));
  }

  public function testScopeDecodesFromJsonAndFromAnArray(): void {
    self::assertSame(['a', 'b'], TokenService::decodeScope('["a","b"]'));
    self::assertSame(['a'], TokenService::decodeScope(['a']));
    self::assertSame([], TokenService::decodeScope(NULL));
    self::assertSame([], TokenService::decodeScope(''));
    self::assertSame([], TokenService::decodeScope('not json'));
    // A malformed entry is dropped rather than turned into a stringified one:
    // an invented permission name is worse than a missing one.
    self::assertSame(['a'], TokenService::decodeScope('["a",17]'));
  }

}
