<?php

declare(strict_types = 1);

/**
 * Angular module behind the contact tab: the token list, the issue form and
 * the one-time plaintext block.
 */
return [
  'js' => [
    'ang/keyringTokens.module.js',
    'ang/keyringTokens/*.js',
  ],
  'css' => [
    'ang/keyringTokens/keyringTokens.css',
  ],
  'partials' => [
    'ang/keyringTokens',
  ],
  // Afform maps a directive back to its module through `exports`; without it
  // <keyring-tokens> in the tab's markup would never be compiled.
  'exports' => [
    'keyring-tokens' => 'E',
  ],
  'requires' => [
    'crmUi',
    'crmUtil',
    'api4',
  ],
  'basePages' => [],
];
