<?php

declare(strict_types = 1);

namespace Civi\Keyring\Tests\Unit;

use Civi\Keyring\Base32;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Civi\Keyring\Base32
 */
final class Base32Test extends TestCase {

  /**
   * RFC 4648 section 10, lowercased and with the padding dropped.
   *
   * @dataProvider vectors
   */
  public function testRfc4648Vectors(string $input, string $expected): void {
    self::assertSame($expected, Base32::encode($input));
  }

  /**
   * @return array<string, array{0: string, 1: string}>
   */
  public static function vectors(): array {
    return [
      'empty' => ['', ''],
      'f' => ['f', 'my'],
      'fo' => ['fo', 'mzxq'],
      'foo' => ['foo', 'mzxw6'],
      'foob' => ['foob', 'mzxw6yq'],
      'fooba' => ['fooba', 'mzxw6ytb'],
      'foobar' => ['foobar', 'mzxw6ytboi'],
    ];
  }

  public function testAlphabetIsLowercaseBase32Only(): void {
    self::assertMatchesRegularExpression('/^[a-z2-7]+$/', Base32::encode(random_bytes(32)));
  }

  public function test32BytesBecome52Characters(): void {
    self::assertSame(52, strlen(Base32::encode(random_bytes(32))));
  }

}
