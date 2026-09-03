<?php

declare(strict_types = 1);

namespace Civi\Keyring\Tests\Unit;

use Civi\Keyring\DenialRecorder;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Civi\Keyring\DenialRecorder
 */
final class DenialRecorderTest extends TestCase {

  protected function setUp(): void {
    DenialRecorder::reset();
  }

  protected function tearDown(): void {
    DenialRecorder::reset();
  }

  public function testNothingIsBufferedByDefault(): void {
    self::assertSame([], DenialRecorder::buffered());
  }

  public function testRepeatedDenialsCollapseIntoOneCountedEntry(): void {
    for ($i = 0; $i < 208; $i++) {
      DenialRecorder::record(1, 'view all contacts', 'civicrm/contact/view');
    }
    $buffered = array_values(DenialRecorder::buffered());
    self::assertCount(1, $buffered);
    self::assertSame(208, $buffered[0]['hits']);
  }

  public function testEachTokenPermissionAndPathIsItsOwnEntry(): void {
    DenialRecorder::record(1, 'a', 'p1');
    DenialRecorder::record(1, 'a', 'p2');
    DenialRecorder::record(1, 'b', 'p1');
    DenialRecorder::record(2, 'a', 'p1');
    self::assertCount(4, DenialRecorder::buffered());
  }

  public function testOverlongValuesAreTruncatedToTheColumnWidth(): void {
    DenialRecorder::record(1, str_repeat('x', 400), str_repeat('y', 400));
    $row = array_values(DenialRecorder::buffered())[0];
    self::assertSame(255, strlen($row['permission']));
    self::assertSame(255, strlen($row['request_path']));
  }

  public function testTruncationNeverSplitsAMultibyteCharacter(): void {
    // 'ä' is two bytes, so a byte-counted cut at 255 lands mid-character.
    DenialRecorder::record(1, str_repeat('ä', 400), str_repeat('ä', 400));
    $row = array_values(DenialRecorder::buffered())[0];
    foreach (['permission', 'request_path'] as $field) {
      self::assertLessThanOrEqual(255, strlen($row[$field]));
      self::assertTrue(mb_check_encoding($row[$field], 'UTF-8'), $field . ' is not valid UTF-8');
    }
    self::assertSame(254, strlen($row['permission']));
  }

}
