<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Services\NotificationDispatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * F-14: the bell text for a claim the re-verify sweep revoked. One message per
 * reason, and a missing entity name never yields "your this claim"
 * (#594 review).
 */
#[CoversClass(NotificationDispatcher::class)]
final class ClaimBadgeRemovedMessageTest extends TestCase
{
    /** @return array<string, array{string, string, string}> */
    public static function cases(): array
    {
        return [
            'wallet, named' => ['Iqlusion', 'wallet_link_gone',
                "We couldn't confirm you still control the wallet behind your Iqlusion claim, so the verified badge was removed. Reconnect the wallet and claim again to restore it."],
            'wallet, unnamed' => ['', 'wallet_link_gone',
                "We couldn't confirm you still control the wallet behind your claim, so the verified badge was removed. Reconnect the wallet and claim again to restore it."],
            'validator gone, named' => ['Iqlusion', 'validator_row_gone',
                "We can no longer find the validator behind your Iqlusion claim, so the verified badge was removed. If it's still running, claim it again to restore the badge."],
            'validator gone, unnamed' => ['  ', 'validator_row_gone',
                "We can no longer find the validator behind your claim, so the verified badge was removed. If it's still running, claim it again to restore the badge."],
            'holder, named' => ['Bad Kids', 'no_longer_holder',
                "We couldn't confirm your linked wallets still hold Bad Kids, so the holder badge was removed. Claim again once a linked wallet holds it."],
            'holder, unnamed' => ['', 'no_longer_holder',
                "We couldn't confirm your linked wallets still hold this collection, so the holder badge was removed. Claim again once a linked wallet holds it."],
        ];
    }

    #[DataProvider('cases')]
    public function testEachReasonHasItsOwnPlainMessage(string $name, string $reason, string $expected): void
    {
        self::assertSame($expected, NotificationDispatcher::claimBadgeRemovedMessage($name, $reason));
    }

    public function testNoMessageEverReadsYourThisClaimOrCarriesANudge(): void
    {
        foreach (['wallet_link_gone', 'validator_row_gone', 'no_longer_holder'] as $reason) {
            $text = NotificationDispatcher::claimBadgeRemovedMessage('', $reason);
            self::assertStringNotContainsString('your this', $text);
            self::assertStringNotContainsString('%s', $text);
            // The cadence-pressure guard's patterns (scripts/cadence-pressure-guard.sh).
            self::assertDoesNotMatchRegularExpression("/haven['’]?t|\\bstreak\\b|you should|days since/i", $text);
        }
    }
}
