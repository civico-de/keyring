<?php

declare(strict_types = 1);

namespace Civi\Keyring\Tests\Unit;

use Civi\Keyring\Expiry;
use Civi\Keyring\KeyringException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Civi\Keyring\Expiry
 */
final class ExpiryTest extends TestCase {

  private const NOW = 1_780_000_000;

  public function testNoExpiryStaysEmpty(): void {
    self::assertNull(Expiry::normalize(NULL, self::NOW));
    self::assertNull(Expiry::normalize('', self::NOW));
    self::assertNull(Expiry::normalize('   ', self::NOW));
  }

  public function testADateIsStoredAsTheEndOfThatDay(): void {
    self::assertSame('2030-12-31 23:59:59', Expiry::normalize('2030-12-31', self::NOW));
    self::assertSame('2030-12-31 23:59:59', Expiry::normalize(' 2030-12-31 ', self::NOW));
  }

  public function testTodayIsStillAcceptedAndLastsToMidnight(): void {
    $today = date('Y-m-d', self::NOW);
    self::assertSame($today . ' 23:59:59', Expiry::normalize($today, self::NOW));
  }

  public function testADatetimeIsRefusedRatherThanTruncated(): void {
    $this->expectExceptionMessageMatches('/a time of day is not accepted/');
    Expiry::normalize('2030-12-31 12:00:00', self::NOW);
  }

  public function testAPastDateIsAnError(): void {
    $this->expectExceptionMessageMatches('/is in the past/');
    Expiry::normalize('2020-01-01', self::NOW);
  }

  public function testAMalformedDateIsAnError(): void {
    $this->expectException(KeyringException::class);
    Expiry::normalize('31.12.2030', self::NOW);
  }

  public function testANonCalendarDateIsAnError(): void {
    $this->expectExceptionMessageMatches('/not a calendar date/');
    Expiry::normalize('2030-02-31', self::NOW);
  }

  public function testAWrittenValueIsAcceptedAtMidnightOrEndOfDay(): void {
    // The DAO rewrites every date to YmdHis before the pre hook sees it, so
    // midnight is what a date-only input arrives as.
    self::assertSame('2030-12-31 23:59:59', Expiry::normalizeWritten('20301231000000', self::NOW));
    self::assertSame('2030-12-31 23:59:59', Expiry::normalizeWritten('20301231235959', self::NOW));
    self::assertSame('2030-12-31 23:59:59', Expiry::normalizeWritten('2030-12-31 00:00:00', self::NOW));
  }

  public function testAWrittenValueWithATimeOfDayIsRefused(): void {
    $this->expectExceptionMessageMatches('/a time of day is not accepted/');
    Expiry::normalizeWritten('20301231120000', self::NOW);
  }

  public function testAWrittenValueInThePastIsRefused(): void {
    $this->expectExceptionMessageMatches('/is in the past/');
    Expiry::normalizeWritten('20200101000000', self::NOW);
  }

  public function testAnEmptyWrittenValueClearsTheExpiry(): void {
    self::assertNull(Expiry::normalizeWritten('', self::NOW));
    self::assertNull(Expiry::normalizeWritten('   ', self::NOW));
  }

  public function testTwoSpellingsOfTheSameInstantAreEqual(): void {
    self::assertTrue(Expiry::isSameInstant('20301231235959', '2030-12-31 23:59:59'));
    self::assertFalse(Expiry::isSameInstant('20301231000000', '2030-12-31 23:59:59'));
  }

  public function testStateIsRevokedBeforeAnythingElse(): void {
    self::assertSame(
      Expiry::STATE_REVOKED,
      Expiry::state('2020-01-01 23:59:59', '2021-01-01 00:00:00', self::NOW),
    );
  }

  public function testStateFollowsTheClock(): void {
    $expiry = '2026-06-01 23:59:59';
    $before = (int) strtotime('2026-06-01 12:00:00');
    $after = (int) strtotime('2026-06-02 00:00:01');
    self::assertSame(Expiry::STATE_ACTIVE, Expiry::state($expiry, NULL, $before));
    self::assertSame(Expiry::STATE_EXPIRED, Expiry::state($expiry, NULL, $after));
  }

  public function testAnEmptyExpiryNeverExpires(): void {
    self::assertFalse(Expiry::isExpired(NULL, self::NOW));
    self::assertFalse(Expiry::isExpired('', self::NOW));
    self::assertSame(Expiry::STATE_ACTIVE, Expiry::state(NULL, NULL, self::NOW));
  }

  public function testAnUnreadableStoredExpiryCountsAsExpired(): void {
    self::assertTrue(Expiry::isExpired('not a date', self::NOW));
  }

}
