<?php

declare(strict_types = 1);

namespace Civi\Keyring\Tests\Unit;

use Civi\Keyring\KeyringException;
use Civi\Keyring\Value;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Civi\Keyring\Value
 */
final class ValueTest extends TestCase {

  public function testIntAcceptsNumbersAndNumericStrings(): void {
    self::assertSame(7, Value::int(7));
    self::assertSame(7, Value::int('7'));
  }

  public function testIntRefusesAnythingElse(): void {
    $this->expectExceptionMessageMatches('/expected a number/');
    Value::int(NULL);
  }

  public function testStrAcceptsStringsAndNumbers(): void {
    self::assertSame('a', Value::str('a'));
    self::assertSame('7', Value::str(7));
  }

  public function testStrRefusesAnythingElse(): void {
    $this->expectException(KeyringException::class);
    Value::str(['a']);
  }

  public function testStrOrNullTreatsEmptyAsAbsent(): void {
    self::assertNull(Value::strOrNull(NULL));
    self::assertNull(Value::strOrNull(''));
    self::assertSame('a', Value::strOrNull('a'));
  }

  public function testArrPassesArraysAndSwallowsTheRest(): void {
    self::assertSame(['a'], Value::arr(['a']));
    self::assertSame([], Value::arr('a'));
  }

  public function testRowKeysAreStrings(): void {
    self::assertSame(['0' => 'a'], Value::row(['a']));
    self::assertSame([], Value::row(NULL));
  }

}
