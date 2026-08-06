<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\REST\Auth\AuthSupport;
use BCC\Trust\Core\Services\Quest\QuestValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the display-name hygiene predicates (owner-directed 2026-08-06).
 *
 * Two pure gates share the vocabulary of "leaky" name shapes:
 *   - AuthSupport::sanitizePublicDisplayName — signup-side: leaky input
 *     collapses to '' so every signup path falls back to the handle.
 *   - QuestValidator::displayNameLooksChosen — completeness-side: leaky
 *     shapes AND the backfill's 'Member <n>' placeholder hold the
 *     profile-complete gate closed until the user picks a real name.
 *
 * The email class ('@') and internal-login class ('u_' §B3 prefix) are
 * the two observed real-world leaks; a regression on either re-exposes
 * private identity on every public surface (search, cards, OG images).
 */
#[CoversClass(AuthSupport::class)]
#[CoversClass(QuestValidator::class)]
final class DisplayNameHygieneTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function leakyNames(): iterable
    {
        yield 'plain email'          => ['someone@example.com'];
        yield 'email with spaces'    => ['  a@b.io  '];
        yield 'embedded @'           => ['hi @there'];
        yield 'internal login'       => ['u_claudeai'];
        yield 'empty'                => [''];
        yield 'whitespace only'      => ['   '];
    }

    /** @return iterable<string, array{string}> */
    public static function cleanNames(): iterable
    {
        yield 'plain name'      => ['Simon'];
        yield 'handle-like'     => ['claudeai'];
        yield 'two words'       => ['Cosmos Operator'];
        yield 'u without score' => ['unusual'];
        yield 'unicode'         => ['Пётр'];
    }

    #[DataProvider('leakyNames')]
    public function testSignupGateCollapsesLeakyNamesToHandleFallback(string $name): void
    {
        self::assertSame('', AuthSupport::sanitizePublicDisplayName($name));
    }

    #[DataProvider('cleanNames')]
    public function testSignupGatePassesCleanNames(string $name): void
    {
        self::assertSame(trim($name), AuthSupport::sanitizePublicDisplayName($name));
    }

    #[DataProvider('leakyNames')]
    public function testCompletenessGateRejectsLeakyNames(string $name): void
    {
        self::assertFalse(QuestValidator::displayNameLooksChosen($name));
    }

    #[DataProvider('cleanNames')]
    public function testCompletenessGateAcceptsCleanNames(string $name): void
    {
        self::assertTrue(QuestValidator::displayNameLooksChosen($name));
    }

    public function testBackfillPlaceholderHoldsTheGateClosed(): void
    {
        // 'Member <n>' is exactly what the backfill assigns — it must
        // read as NOT chosen so the completeness nag fires…
        self::assertFalse(QuestValidator::displayNameLooksChosen('Member 8241'));
        // …while an organic name that merely CONTAINS the word passes.
        self::assertTrue(QuestValidator::displayNameLooksChosen('Member of the Guild'));
    }
}
