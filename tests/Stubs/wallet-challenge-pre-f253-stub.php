<?php
/**
 * bcc-core's WalletChallenge as it was before F-253: no account-change
 * purposes, no AUTHED_PURPOSES. Loaded by AccountWalletConfirmTest BEFORE
 * wallet-confirm-stubs.php to prove bcc-trust refuses the wallet path (and
 * keeps the password path) against an older core.
 */

declare(strict_types=1);

namespace BCC\Core\Crypto;

final class WalletChallenge
{
    public const PURPOSE_SIGN_IN = 'sign_in';
    public const PURPOSE_VERIFY  = 'verify';
}
