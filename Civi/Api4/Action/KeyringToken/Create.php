<?php

declare(strict_types = 1);

namespace Civi\Api4\Action\KeyringToken;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\API\Exception\UnauthorizedException;
use Civi\Keyring\Expiry;
use Civi\Keyring\Permissions;
use Civi\Keyring\ScopeService;
use Civi\Keyring\TokenService;
use Civi\Keyring\Value;

/**
 * Mints a token and returns the plaintext exactly once.
 *
 * @method $this setContactId(int $contactId)
 * @method $this setLabel(string $label)
 * @method $this setScope(array<int, string> $scope)
 * @method $this setScopeProfile(string $scopeProfile)
 * @method $this setMode(string $mode)
 * @method $this setExpiresAt(string $expiresAt)
 */
final class Create extends AbstractAction {

  /**
   * The person the token acts as. Validated in _run rather than by the
   * kernel: a nullable property with a default is never "missing" to it.
   *
   * @var int|null
   */
  protected ?int $contactId = NULL;

  /**
   * What the token is for.
   *
   * @var string|null
   */
  protected ?string $label = NULL;

  /**
   * Hand-built permission allowlist. Mutually exclusive with scopeProfile.
   *
   * Core's ValidateFieldsSubscriber reads this docblock and understands only
   * array|bool|string|object|int|float|mixed; phpstan gets the precise type.
   *
   * @var array|null
   * @phpstan-var list<string>|null
   */
  protected ?array $scope = NULL;

  /**
   * Name of a configured scope profile. Mutually exclusive with scope.
   *
   * @var string|null
   */
  protected ?string $scopeProfile = NULL;

  /**
   * `report` records what the scope would have denied; `enforce` denies it.
   *
   * @var string
   */
  protected string $mode = 'report';

  /**
   * The last day the token is valid, as `YYYY-MM-DD`. Stored as the end of
   * that day; NULL means it never expires.
   *
   * @var string|null
   */
  protected ?string $expiresAt = NULL;

  public function _run(Result $result): void {
    [$contactId, $label, $expiresAt] = $this->validate();
    if ($this->checkPermissions) {
      $this->assertMayIssueFor($contactId);
    }

    $scope = ScopeService::normalize(ScopeService::resolve($this->scope, $this->scopeProfile));
    $plaintext = TokenService::generate();

    // The generic save, unchecked: `token_hash` is denied to every permissioned
    // caller, so this action is the only way a usable row comes into being.
    $created = Value::row(
      \Civi\Api4\KeyringToken::save(FALSE)
        ->addRecord([
          'contact_id' => $contactId,
          'label' => $label,
          'token_hash' => TokenService::hash($plaintext),
          'token_prefix' => TokenService::displayPrefix($plaintext),
          'scope' => $scope,
          'scope_profile' => $this->scopeProfile,
          'mode' => $this->mode,
          'expires_at' => $expiresAt,
          'created_id' => \CRM_Core_Session::getLoggedInContactID() ?: NULL,
        ])
        ->execute()
        ->single(),
    );

    $result[] = [
      'id' => Value::int($created['id'] ?? NULL),
      'contact_id' => $contactId,
      'label' => $label,
      'token_prefix' => TokenService::displayPrefix($plaintext),
      'scope' => $scope,
      'scope_profile' => $this->scopeProfile,
      'mode' => $this->mode,
      'expires_at' => $expiresAt,
      'state' => Expiry::state($expiresAt, NULL, \CRM_Utils_Time::time()),
      // Shown once. There is no way to read it back: only the digest is kept.
      'token' => $plaintext,
    ];
  }

  /**
   * The required parameters, or an explicit error. Nothing is guessed.
   *
   * @return array{0: int, 1: string, 2: ?string}
   */
  private function validate(): array {
    if ($this->contactId === NULL || $this->contactId <= 0) {
      throw new \CRM_Core_Exception('keyring: contactId is required.');
    }
    $label = trim((string) $this->label);
    if ($label === '') {
      throw new \CRM_Core_Exception('keyring: label is required.');
    }
    if (!in_array($this->mode, ['report', 'enforce'], TRUE)) {
      throw new \CRM_Core_Exception('keyring: mode must be "report" or "enforce".');
    }
    return [$this->contactId, $label, Expiry::normalize($this->expiresAt)];
  }

  private function assertMayIssueFor(int $contactId): void {
    if (\CRM_Core_Permission::check(Permissions::ADMINISTER)) {
      return;
    }
    $own = (int) (\CRM_Core_Session::getLoggedInContactID() ?: 0);
    if ($own === 0 || $own !== $contactId) {
      throw new UnauthorizedException(
        'keyring: "' . Permissions::MANAGE_OWN . '" only covers tokens for oneself.',
      );
    }
  }

  /**
   * @return array<string, string>
   */
  public function fields(): array {
    return [];
  }

}
