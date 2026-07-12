<?php

declare(strict_types=1);

namespace BCC\Core\DB {
    // bcc-core isn't autoloaded in the unit context; stub the table resolver
    // ValidatorRepository::table() reaches for. Guarded — first definition wins.
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

    use BCC\Trust\Onchain\Repositories\ValidatorRepository;
    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\TestCase;

    /**
     * Pins the validator-enrichment due-check clock convention.
     *
     * next_enrichment_at / retry_after are WRITTEN in UTC (EnrichmentScheduler
     * uses gmdate()), so the due-check must compare against UTC_TIMESTAMP(), not
     * NOW() (the MySQL session tz). With NOW() on a non-UTC MySQL every validator
     * fired late by the session offset and retry backoffs were inflated. This
     * suite turns red if the gate reverts to NOW().
     */
    #[CoversClass(ValidatorRepository::class)]
    final class ValidatorEnrichmentDueSqlTest extends TestCase
    {
        protected function setUp(): void
        {
            global $wpdb;
            $wpdb = new class {
                public string $prefix = 'wp_';
                /** @var list<string> */
                public array $prepared = [];

                /** @param mixed ...$args */
                public function prepare(string $sql, ...$args): string
                {
                    $this->prepared[] = $sql;
                    return $sql;
                }

                /** @return array<int, object> */
                public function get_results(string $sql): array
                {
                    return [];
                }
            };
        }

        protected function tearDown(): void
        {
            global $wpdb;
            $wpdb = null;
        }

        public function testDueCheckComparesAgainstUtcNotSessionClock(): void
        {
            ValidatorRepository::fetchEnrichmentBatch(3, 10);

            global $wpdb;
            $sql = (string) preg_replace('/\s+/', ' ', trim($wpdb->prepared[0]));

            self::assertStringContainsString('next_enrichment_at <= UTC_TIMESTAMP()', $sql);
            self::assertStringContainsString('retry_after <= UTC_TIMESTAMP()', $sql);
            // Regression guard: the UTC-written columns must NOT be gated on NOW().
            self::assertStringNotContainsString('next_enrichment_at <= NOW()', $sql);
            self::assertStringNotContainsString('retry_after <= NOW()', $sql);
        }
    }
}
