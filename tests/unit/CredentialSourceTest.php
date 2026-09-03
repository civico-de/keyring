<?php

declare(strict_types = 1);

namespace Civi\Keyring\Tests\Unit;

use Civi\Keyring\Authx\CredentialSource;
use Civi\Keyring\Base32;
use Civi\Keyring\TokenService;
use PHPUnit\Framework\TestCase;

/**
 * A token that also rode in the URL or the body has already been logged, so it
 * fails the request whatever the header says.
 *
 * @covers \Civi\Keyring\Authx\CredentialSource
 */
final class CredentialSourceTest extends TestCase {

  /**
   * A keyring-shaped credential, assembled rather than written out: the
   * scanner reads a literal next to `api_key` as a leaked secret.
   */
  private const TOKEN = TokenService::PREFIX . 'abc';

  private const FOREIGN = 'a-foreign-credential';

  /**
   * A full-length minted token: the raw scan matches the complete shape, not
   * the bare prefix the parsed bags are judged by.
   */
  private static function mintedToken(): string {
    return TokenService::PREFIX
      . str_repeat('a', Base32::encodedLength(TokenService::SECRET_BYTES));
  }

  protected function setUp(): void {
    parent::setUp();
    $_GET = [];
    $_POST = [];
    $_SERVER['QUERY_STRING'] = '';
  }

  protected function tearDown(): void {
    $_GET = [];
    $_POST = [];
    unset($_SERVER['QUERY_STRING']);
    parent::tearDown();
  }

  public function testNoParametersAtAllIsClean(): void {
    self::assertFalse(CredentialSource::isInRequestParams());
  }

  public function testAForeignApiKeyIsNotKeyrings(): void {
    $_GET['api_key'] = self::FOREIGN;
    $_POST['_authx'] = 'Bearer ' . self::FOREIGN;
    self::assertFalse(CredentialSource::isInRequestParams());
  }

  public function testABareTokenInAQueryParameterIsCaught(): void {
    $_GET['api_key'] = self::TOKEN;
    self::assertTrue(CredentialSource::isInRequestParams());
  }

  public function testATokenBehindABearerSchemeIsCaught(): void {
    $_POST['_authx'] = 'Bearer ' . self::TOKEN;
    self::assertTrue(CredentialSource::isInRequestParams());
  }

  public function testSurroundingWhitespaceDoesNotHideAToken(): void {
    $_GET['api_key'] = '  ' . self::TOKEN . '  ';
    self::assertTrue(CredentialSource::isInRequestParams());

    $_GET = ['_authx' => "  Bearer   " . self::TOKEN . " \n"];
    self::assertTrue(CredentialSource::isInRequestParams());
  }

  public function testATokenNestedInAnArrayIsCaught(): void {
    $_GET['api_key'] = [self::TOKEN];
    self::assertTrue(CredentialSource::isInRequestParams());
  }

  public function testATokenNestedDeeperIsCaught(): void {
    $_POST['_authx'] = ['outer' => ['inner' => ' Bearer ' . self::TOKEN . ' ']];
    self::assertTrue(CredentialSource::isInRequestParams());
  }

  public function testARepeatedParameterIsCaughtWhicheverEntryCarriesIt(): void {
    $_GET['api_key'] = ['harmless', self::TOKEN];
    self::assertTrue(CredentialSource::isInRequestParams());

    $_GET['api_key'] = [self::TOKEN, 'harmless'];
    self::assertTrue(CredentialSource::isInRequestParams());
  }

  public function testNonStringScalarsAreNotMistakenForTokens(): void {
    $_GET['api_key'] = [17, 1.5, TRUE, NULL];
    self::assertFalse(CredentialSource::isInRequestParams());
  }

  /**
   * PHP keeps only the last value of a repeated scalar key, so the leaked
   * token never reaches `$_GET`. The raw query string still carries it.
   */
  public function testARepeatedScalarKeyCannotHideATokenInTheQueryString(): void {
    $token = self::mintedToken();
    $_SERVER['QUERY_STRING'] = 'api_key=' . $token . '&api_key=' . self::FOREIGN;
    $_GET['api_key'] = self::FOREIGN;
    self::assertTrue(CredentialSource::isInRequestParams(''));
  }

  public function testATabSeparatorDoesNotHideATokenInTheQueryString(): void {
    $token = self::mintedToken();
    $_SERVER['QUERY_STRING'] = '_authx=Bearer%09' . $token;
    $_GET['_authx'] = "Bearer\t" . $token;
    self::assertTrue(CredentialSource::isInRequestParams(''));
  }

  public function testATabSeparatorDoesNotHideATokenInAParsedParameter(): void {
    $_POST['_authx'] = "Bearer\t" . self::TOKEN;
    self::assertTrue(CredentialSource::isInRequestParams(''));
  }

  public function testATokenInARawJsonBodyIsCaught(): void {
    $body = json_encode(['apiKey' => self::mintedToken()]);
    self::assertIsString($body);
    self::assertTrue(CredentialSource::isInRequestParams($body));
  }

  public function testATokenInAFormUrlencodedBodyIsCaught(): void {
    $body = http_build_query(['something_else' => self::mintedToken()]);
    self::assertTrue(CredentialSource::isInRequestParams($body));
  }

  public function testAForeignKeyInTheQueryStringStaysClean(): void {
    $_SERVER['QUERY_STRING'] = 'api_key=' . self::FOREIGN . '&entity=Contact';
    self::assertFalse(CredentialSource::isInRequestParams(''));
  }

  /**
   * The prefix inside a longer word is not a credential.
   */
  public function testADecoySubstringInsideALongerWordStaysClean(): void {
    $_SERVER['QUERY_STRING'] = 'note=ab' . self::mintedToken();
    self::assertFalse(CredentialSource::isInRequestParams(''));
    self::assertFalse(CredentialSource::isInRequestParams('ab' . self::mintedToken()));
  }

  public function testTheHeaderComparisonIsExact(): void {
    $_SERVER['HTTP_X_CIVI_AUTH'] = 'Bearer ' . self::TOKEN;
    self::assertTrue(CredentialSource::isHeader('Bearer', self::TOKEN));
    self::assertFalse(CredentialSource::isHeader('Bearer', TokenService::PREFIX . 'other'));
    unset($_SERVER['HTTP_X_CIVI_AUTH']);
    self::assertFalse(CredentialSource::isHeader('Bearer', self::TOKEN));
  }

}
