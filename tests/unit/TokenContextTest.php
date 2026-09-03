<?php

declare(strict_types = 1);

namespace Civi\Keyring\Tests\Unit;

use Civi\Keyring\ScopeService;
use Civi\Keyring\TokenContext;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Civi\Keyring\TokenContext
 */
final class TokenContextTest extends TestCase {

  protected function setUp(): void {
    TokenContext::reset();
  }

  protected function tearDown(): void {
    TokenContext::reset();
  }

  public function testNoTokenIsActiveByDefault(): void {
    self::assertFalse(TokenContext::isActive());
    self::assertNull(TokenContext::tokenId());
    self::assertFalse(TokenContext::allows('access CiviCRM'));
  }

  public function testActivationExposesScopeAndMode(): void {
    TokenContext::activate(7, ScopeService::lookupTable(['access CiviCRM']), TokenContext::MODE_REPORT);
    self::assertTrue(TokenContext::isActive());
    self::assertSame(7, TokenContext::tokenId());
    self::assertSame(TokenContext::MODE_REPORT, TokenContext::mode());
    self::assertTrue(TokenContext::allows('access CiviCRM'));
    self::assertFalse(TokenContext::allows('view all contacts'));
  }

  public function testResetClearsEverything(): void {
    TokenContext::activate(7, ['access CiviCRM' => TRUE], TokenContext::MODE_ENFORCE);
    TokenContext::reset();
    self::assertFalse(TokenContext::isActive());
    self::assertSame([], TokenContext::scope());
  }

}
