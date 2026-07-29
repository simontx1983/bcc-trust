<?php

declare(strict_types=1);

// ── Global WP shim ──────────────────────────────────────────────────────────
namespace {
    if (!function_exists('get_option')) {
        /**
         * No stored overrides — FeatureAccessService::getLevelThresholds()
         * then returns defaultThresholds() unmodified, which is what
         * production does on every environment today
         * (`bcc_level_thresholds` is unset).
         *
         * @param mixed $default
         * @return mixed
         */
        function get_option(string $name, $default = false)
        {
            return $default;
        }
    }

    if (!function_exists('apply_filters')) {
        /**
         * Pass-through. defaultThresholds() reads its three numbers
         * through filters so they can be tuned without a redeploy; with
         * no filter registry in unit scope the shipped defaults from
         * includes/config/ranks.php (or the inline fallbacks, since that
         * file is not loaded here) are what these tests assert — which is
         * the point. PHP falls back from namespace to global for
         * functions, so this one shim covers RankCatalog's namespace too.
         *
         * @param mixed $value
         * @return mixed
         */
        function apply_filters(string $hook, $value, ...$args)
        {
            return $value;
        }
    }
}

namespace BCC\Trust\Core\Services\Tests {

    use BCC\Trust\Core\Services\FeatureAccessService;
    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\TestCase;
    use ReflectionClass;
    use ReflectionMethod;

    /**
     * Locks the real authorization boundary: level + trust tier (+ wallet).
     *
     * Until contract v1.58 nothing bound review eligibility to its gate — the
     * most consequential permission check in the subsystem was untested, and
     * the audit had to read the source to confirm what it did.
     *
     * NOTE ON WHAT IS BEING TESTED. `write_review` requires
     * `min_level => LEVEL_ACTIVE` plus `requires_min_tier => neutral`. It does
     * NOT check a rank value — no rank slug appears anywhere in
     * FeatureAccessService. "Journeyman" is the DISPLAY LABEL of level 2, not
     * the thing checked. These tests assert the level, deliberately, so the
     * distinction stays visible to whoever reads them next.
     *
     * The service is built via newInstanceWithoutConstructor(): the methods
     * under test read only constants, arguments and getLevelThresholds(), so
     * the two repositories are genuinely unused and stubbing them at their
     * FQNs would be fragile in this shared-process suite.
     */
    #[CoversClass(FeatureAccessService::class)]
    final class FeatureAccessGateTest extends TestCase
    {
        private static function service(): FeatureAccessService
        {
            return (new ReflectionClass(FeatureAccessService::class))
                ->newInstanceWithoutConstructor();
        }

        /** @param array<string, mixed> $overrides */
        private static function stats(array $overrides = []): array
        {
            return $overrides + [
                'pulls'            => 0,
                'reviews_written'  => 0,
                'account_age_days' => 0,
                'has_wallet'       => false,
                'reputation_tier'  => 'neutral',
            ];
        }

        /** @param array<string, mixed> $stats */
        private static function resolveLevel(array $stats): int
        {
            $m = new ReflectionMethod(FeatureAccessService::class, 'resolveLevel');
            $m->setAccessible(true);
            return (int) $m->invoke(self::service(), $stats);
        }

        /**
         * @param array<string, mixed> $stats
         * @return array{allowed: bool, unlock_hint: ?string}
         */
        private static function resolveFeature(string $key, int $level, array $stats): array
        {
            $m = new ReflectionMethod(FeatureAccessService::class, 'resolveFeature');
            $m->setAccessible(true);
            /** @var array{allowed: bool, unlock_hint: ?string} $out */
            $out = $m->invoke(self::service(), $key, $level, $stats);
            return $out;
        }

        // ── resolveLevel — threshold boundaries ──────────────────────

        public function testPullsGateBoundaryAtFive(): void
        {
            self::assertSame(
                FeatureAccessService::LEVEL_NEW,
                self::resolveLevel(self::stats(['pulls' => 4])),
                '4 pulls must NOT reach level 2'
            );
            self::assertSame(
                FeatureAccessService::LEVEL_ACTIVE,
                self::resolveLevel(self::stats(['pulls' => 5])),
                '5 pulls is the documented gate — inclusive'
            );
        }

        public function testVeteranNeedsBothReviewsAndAccountAgeAtTheirBoundaries(): void
        {
            $almost = ['pulls' => 5, 'reviews_written' => 3, 'account_age_days' => 29];
            self::assertSame(
                FeatureAccessService::LEVEL_ACTIVE,
                self::resolveLevel(self::stats($almost)),
                '29 days must not promote'
            );

            $reviewsShort = ['pulls' => 5, 'reviews_written' => 2, 'account_age_days' => 30];
            self::assertSame(
                FeatureAccessService::LEVEL_ACTIVE,
                self::resolveLevel(self::stats($reviewsShort)),
                '2 reviews must not promote'
            );

            $met = ['pulls' => 5, 'reviews_written' => 3, 'account_age_days' => 30];
            self::assertSame(
                FeatureAccessService::LEVEL_VETERAN,
                self::resolveLevel(self::stats($met)),
                'both gates inclusive at 3 / 30'
            );
        }

        public function testLevelThreeIsCumulativeSoReviewsAloneDoNotPromote(): void
        {
            // Documented in resolveLevel's docblock but previously unproven:
            // "a user with 100 reviews but 0 pulls is NOT Level 3".
            $level = self::resolveLevel(self::stats([
                'pulls'            => 0,
                'reviews_written'  => 100,
                'account_age_days' => 3650,
            ]));

            self::assertSame(FeatureAccessService::LEVEL_NEW, $level);
        }

        public function testAccountAgeAloneNeverPromotesAnybody(): void
        {
            // The honest reading of the v1.58 rename: account age accrues
            // while dormant, so it must never be sufficient on its own.
            self::assertSame(
                FeatureAccessService::LEVEL_NEW,
                self::resolveLevel(self::stats(['account_age_days' => 9999]))
            );
        }

        public function testTheWholeVeteranLadderIsSatisfiableWithoutAnySustainedActivity(): void
        {
            // Not an endorsement — a pin. This is the audit's core finding, and
            // if a future change makes it false, that change is the point and
            // this test should be updated deliberately rather than by accident.
            // 5 self-chosen follows + 3 votes + an account that merely aged.
            $level = self::resolveLevel(self::stats([
                'pulls'            => 5,
                'reviews_written'  => 3,
                'account_age_days' => 30,
            ]));

            self::assertSame(FeatureAccessService::LEVEL_VETERAN, $level);
        }

        // ── write_review — the explicit claim under audit ─────────────

        public function testWriteReviewNeedsLevelTwoEvenForAnEliteTier(): void
        {
            $r = self::resolveFeature(
                'write_review',
                FeatureAccessService::LEVEL_NEW,
                self::stats(['reputation_tier' => 'elite'])
            );

            self::assertFalse($r['allowed'], 'top trust tier does not buy past the level gate');
            self::assertNotNull($r['unlock_hint']);
        }

        public function testWriteReviewNeedsNeutralTierEvenAtTheTopLevel(): void
        {
            foreach (['risky', 'caution'] as $tier) {
                $r = self::resolveFeature(
                    'write_review',
                    FeatureAccessService::LEVEL_VETERAN,
                    self::stats(['reputation_tier' => $tier])
                );
                self::assertFalse($r['allowed'], "{$tier} must not write reviews");
            }
        }

        public function testWriteReviewIsAllowedAtLevelTwoWithNeutralTier(): void
        {
            $r = self::resolveFeature(
                'write_review',
                FeatureAccessService::LEVEL_ACTIVE,
                self::stats(['reputation_tier' => 'neutral'])
            );

            self::assertTrue($r['allowed']);
            self::assertNull($r['unlock_hint'], 'an allowed feature carries no hint');
        }

        public function testWriteReviewAllowsEveryTierAtOrAboveNeutral(): void
        {
            foreach (['neutral', 'trusted', 'elite'] as $tier) {
                $r = self::resolveFeature(
                    'write_review',
                    FeatureAccessService::LEVEL_ACTIVE,
                    self::stats(['reputation_tier' => $tier])
                );
                self::assertTrue($r['allowed'], "{$tier} should clear the neutral floor");
            }
        }

        // ── open_dispute — the level-3 gate that matters most ─────────

        public function testOpenDisputeAlsoRequiresAVerifiedWallet(): void
        {
            $noWallet = self::resolveFeature(
                'open_dispute',
                FeatureAccessService::LEVEL_VETERAN,
                self::stats(['reputation_tier' => 'trusted', 'has_wallet' => false])
            );
            self::assertFalse($noWallet['allowed'], 'wallet is a hard requirement');

            $withWallet = self::resolveFeature(
                'open_dispute',
                FeatureAccessService::LEVEL_VETERAN,
                self::stats(['reputation_tier' => 'trusted', 'has_wallet' => true])
            );
            self::assertTrue($withWallet['allowed']);
        }

        public function testUnknownFeatureKeysAreNotSilentlyGranted(): void
        {
            // canPerform() fails closed on unknown keys; prove the requirement
            // table is the only source of truth for what exists.
            $m = new ReflectionMethod(FeatureAccessService::class, 'canPerform');
            self::assertTrue($m->isPublic(), 'canPerform is the public entry point');

            $known = ['write_review', 'vouch_reaction', 'open_dispute',
                      'see_signal_details', 'see_trust_breakdown', 'feed_tab_signals'];
            $rc    = new ReflectionClass(FeatureAccessService::class);
            /** @var array<string, mixed> $reqs */
            $reqs = $rc->getConstant('FEATURE_REQUIREMENTS') ?: [];

            self::assertSame(
                $known,
                array_keys($reqs),
                'a new gated feature must be added here deliberately'
            );
        }

        // ── the rank axis is NOT consulted ───────────────────────────

        public function testTheGateDoesNotDependOnTheRankAxisAtAll(): void
        {
            // Structural guard for the audit's central finding: rank is a
            // display label, not an authorization input. If someone wires a
            // rank check into the gate, that is a design change and should
            // fail here first.
            //
            // Asserted against the rank CLASSES rather than the slug strings:
            // 'Veteran' is also the LEVEL 3 label, so a string search cannot
            // tell a rank reference from a level one. (That ambiguity is
            // exactly why the ladder was renamed to match the level in v1.58.)
            $source = (string) file_get_contents(
                (string) (new ReflectionClass(FeatureAccessService::class))->getFileName()
            );

            // Strip comments — the docblock legitimately discusses rank.
            $code = (string) preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $source);

            foreach (['RankCatalog', 'RankService', 'rankForLevel', 'autoDerivedRank'] as $symbol) {
                self::assertStringNotContainsString(
                    $symbol,
                    $code,
                    "FeatureAccessService must not consult {$symbol} — the level gates, the rank only labels"
                );
            }
        }
    }
}
