<?php

declare(strict_types=1);

namespace BCC\Core\DB {
    // bcc-core is not autoloaded in the unit context (see tests/bootstrap.php),
    // so stub the one collaborator computePageClaimBonus reaches for: the
    // table-name resolver. Guarded so the first definition in the shared
    // PHPUnit process wins.
    if (!class_exists(__NAMESPACE__ . '\\DB', false)) {
        final class DB
        {
            public static function table(string $name): string
            {
                return 'wp_bcc_' . $name;
            }
        }
    }
}

namespace BCC\Trust\Onchain\Repositories\Tests {

    use BCC\Trust\Onchain\Repositories\ClaimRepository;
    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\TestCase;

    /**
     * Pins claimant-centric attribution for on-chain claim bonuses.
     *
     * A verified claim must credit the CLAIMANT's own page. The prior
     * page-centric query summed every verified claim on any entity
     * wallet-linked to the page (via a `w.post_id = %d` join), so a
     * holder's claim on a creator-linked collection inflated the
     * CREATOR's score and the holder got nothing — a trust-attribution
     * category error. This suite fails red if that leak comes back:
     * the wallet/page join must be absent and both entity branches must
     * key on `cl.user_id`.
     *
     * A real $wpdb is replaced with a recorder so the exact query and
     * bound args can be asserted without a database.
     */
    #[CoversClass(ClaimRepository::class)]
    final class ClaimBonusAttributionSqlTest extends TestCase
    {
        protected function setUp(): void
        {
            global $wpdb;
            $wpdb = new class {
                public string $prefix = 'wp_';
                /** @var list<array{sql: string, args: array<int, mixed>}> */
                public array $prepared = [];
                /** @var mixed */
                public $var = '0';

                /** @param mixed ...$args */
                public function prepare(string $sql, ...$args): string
                {
                    $this->prepared[] = ['sql' => $sql, 'args' => $args];
                    return $sql;
                }

                /** @return mixed */
                public function get_var(string $sql)
                {
                    return $this->var;
                }
            };
        }

        protected function tearDown(): void
        {
            global $wpdb;
            $wpdb = null;
        }

        private static function fakeWpdb(): object
        {
            global $wpdb;
            \assert(\is_object($wpdb));
            return $wpdb;
        }

        /** Collapse whitespace so multi-line SQL can be matched by structure. */
        private static function norm(string $sql): string
        {
            return (string) preg_replace('/\s+/', ' ', trim($sql));
        }

        public function testCreditsOnlyTheClaimantsOwnVerifiedClaims(): void
        {
            ClaimRepository::computePageClaimBonus(4242);

            $sql = self::norm(self::fakeWpdb()->prepared[0]['sql']);

            // Both entity branches are present…
            self::assertStringContainsString("cl.entity_type = 'validator'", $sql);
            self::assertStringContainsString("cl.entity_type = 'collection'", $sql);

            // …and BOTH are keyed on the claimant, not on a page/wallet link.
            self::assertSame(
                2,
                substr_count($sql, 'cl.user_id = %d'),
                'each entity branch must filter on the claimant'
            );
            self::assertStringContainsString("cl.status = 'verified'", $sql);

            // The attribution key is the user, passed once per branch — never a page id.
            self::assertSame([4242, 4242], self::fakeWpdb()->prepared[0]['args']);
        }

        public function testDoesNotAttributeViaWalletOwnedPage(): void
        {
            ClaimRepository::computePageClaimBonus(7);

            $sql = self::norm(self::fakeWpdb()->prepared[0]['sql']);

            // The leak path: crediting a page for OTHER users' claims because
            // the claimed entity's wallet is linked to that page. Must be gone.
            self::assertStringNotContainsString('w.post_id', $sql, 'no page-owned-via-wallet attribution');
            self::assertStringNotContainsString('wallet_link_id', $sql, 'wallet-link join removed');
        }

        public function testReturnsSummedBonusAsFloat(): void
        {
            self::fakeWpdb()->var = '11.00'; // COALESCE(SUM(bonus),0) arrives as a numeric string
            $bonus = ClaimRepository::computePageClaimBonus(7);

            self::assertSame(11.0, $bonus);
        }
    }
}
