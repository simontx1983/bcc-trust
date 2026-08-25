<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Structural guard for the #212 write shape.
 *
 * ── WHY A SOURCE-LEVEL TEST ─────────────────────────────────────────────
 * The behavioural proof lives in
 * {@see \BCC\Trust\Tests\Integration\CollectionUpsertCanonicalKeyIntegrationTest},
 * which needs a real unique key. But that suite only runs where MySQL is
 * available, and the two things most likely to be reintroduced by a careless
 * edit — the three-column lookup and the bare INSERT — are visible in the
 * source itself. This pins them at unit speed, on every run, everywhere.
 *
 * It also pins the half the integration suite CANNOT see: that all three
 * call sites consume the returned status. A discarded return value is how
 * the original defect stayed invisible for months, and no database test can
 * observe a caller ignoring a result.
 */
#[Group('unit')]
final class CollectionUpsertWriteShapeTest extends TestCase
{
    private const REPO = __DIR__ . '/../../app/Domain/Onchain/Repositories/CollectionRepository.php';

    /** Every production file that may call upsert() directly. */
    private const CALL_SITES = [
        __DIR__ . '/../../app/Domain/Onchain/Services/CollectionPersistBatch.php',
        __DIR__ . '/../../app/Domain/Onchain/Services/WalletSeedService.php',
        __DIR__ . '/../../app/Domain/Onchain/Services/ChainRefreshService.php',
        __DIR__ . '/../../app/Domain/Core/Plugin.php',
    ];

    private const PLUGIN = __DIR__ . '/../../app/Domain/Core/Plugin.php';

    private function repoSource(): string
    {
        $src = file_get_contents(self::REPO);
        self::assertIsString($src, 'CollectionRepository.php must be readable');
        self::assertNotSame('', $src);

        return $src;
    }

    /** Just the body of upsert(), so sibling writers cannot satisfy an assertion for it. */
    private function upsertBody(): string
    {
        $src   = $this->repoSource();
        $start = strpos($src, 'public static function upsert(');
        self::assertIsInt($start, 'upsert() must exist');

        // Ends where the next method on the class begins.
        $end = strpos($src, 'public static function bulkUpsert(', $start);
        self::assertIsInt($end, 'bulkUpsert() must follow upsert()');

        return substr($src, $start, $end - $start);
    }

    /**
     * THE REGRESSION. The lookup keyed on wallet_link_id is what disagreed
     * with `uq_chain_contract (chain_id, contract_address)` and caused the
     * silent drops.
     */
    public function testUpsertDoesNotLookUpByWalletLinkId(): void
    {
        self::assertStringNotContainsString(
            'WHERE wallet_link_id = %d AND chain_id',
            $this->repoSource(),
            'the three-column lookup disagrees with the table\'s unique key — that IS #212'
        );
    }

    /** The write must be one atomic statement governed by the real key. */
    public function testUpsertWritesViaOnDuplicateKeyUpdate(): void
    {
        $body = $this->upsertBody();

        self::assertStringContainsString('ON DUPLICATE KEY UPDATE', $body, 'the write must be atomic');
        self::assertStringNotContainsString(
            '$wpdb->insert(',
            $body,
            'a bare $wpdb->insert() is rejected by uq_chain_contract for a contract another wallet already holds'
        );
        self::assertStringNotContainsString(
            '$wpdb->update(',
            $body,
            'read-then-update reintroduces the check-then-write race'
        );
    }

    /**
     * Operator decisions and curation must never be assignment targets in the
     * conflict branch. Asserted against the SET clause specifically — the
     * column names legitimately appear in the INSERT column list above it.
     */
    public function testProtectedColumnsAreNotAssignedOnConflict(): void
    {
        $body = $this->upsertBody();
        $at   = strpos($body, 'ON DUPLICATE KEY UPDATE');
        self::assertIsInt($at);

        $setClause = substr($body, $at, (int) strpos($body, '",', $at) - $at);
        self::assertNotSame('', $setClause);

        foreach (['wallet_link_id', 'is_verified', 'show_on_profile', 'source'] as $protected) {
            self::assertDoesNotMatchRegularExpression(
                '/^\s*' . preg_quote($protected, '/') . '\s*=/m',
                $setClause,
                "{$protected} must survive an automated refresh"
            );
        }

        // And the refresh itself must still happen, or the guard above would
        // pass trivially against an empty SET clause.
        self::assertMatchesRegularExpression('/^\s*collection_name\s*=/m', $setClause);
        self::assertMatchesRegularExpression('/^\s*expires_at\s*=/m', $setClause);
    }

    /**
     * Metadata is preserved rather than blanked, so one wallet's sparse
     * response cannot erase another's richer data.
     */
    public function testMetadataIsCoalescedRatherThanOverwritten(): void
    {
        // Collapse every whitespace run to one space so the assertion pins the
        // SQL, not the column alignment.
        $flat = (string) preg_replace('/\s+/', ' ', $this->upsertBody());

        foreach (['collection_name', 'image_url', 'total_supply'] as $column) {
            self::assertStringContainsString(
                "{$column} = COALESCE(VALUES({$column}), {$column})",
                $flat,
                "{$column} must not be blanked by a sparse fetch"
            );
        }
    }

    /** The contract callers branch on. */
    public function testUpsertReturnsADiscriminatedResult(): void
    {
        $src = $this->repoSource();

        self::assertStringContainsString(
            "@return array{status: 'created'|'updated'|'failed', id: int}",
            $src,
            'the documented return contract is what callers branch on'
        );
        self::assertStringContainsString(
            'public static function upsert(array $data, int $walletLinkId, int $ttlSeconds = 4 * HOUR_IN_SECONDS): array',
            $src,
            'the array return type must be enforced, not merely documented'
        );
        self::assertStringNotContainsString(
            'return $wpdb->insert_id ?: false;',
            $src,
            'the old int|false return let every caller ignore a failure'
        );
    }

    /**
     * No call site may discard the result. This is the half no database test
     * can see: MySQL cannot observe a caller throwing away a return value.
     */
    public function testEveryCallSiteConsumesTheResult(): void
    {
        $found = 0;

        foreach (self::CALL_SITES as $path) {
            $src = file_get_contents($path);
            self::assertIsString($src, "call site must be readable: {$path}");

            foreach (explode("\n", $src) as $i => $line) {
                if (!str_contains($line, 'CollectionRepository::upsert(')) {
                    continue;
                }
                // Skip docblock references to the method.
                if (preg_match('/^\s*\*/', $line)) {
                    continue;
                }
                $found++;

                // A consumed result is assigned, branched on, returned, or
                // subscripted in the same statement. A discarded one starts
                // the statement with the call itself.
                self::assertMatchesRegularExpression(
                    '/(\$\w+\s*=\s*|if\s*\(|return\s+|\)\[)/',
                    $line,
                    sprintf(
                        "%s:%d discards upsert()'s result — that is exactly how #212 hid",
                        basename($path),
                        $i + 1
                    )
                );
            }
        }

        self::assertGreaterThan(0, $found, 'the guard must actually have inspected a call site');
    }

    /**
     * THE WATERMARK GATE. Advancing "holdings refreshed" after losing writes
     * suppresses the next attempt and makes the loss permanent — the same
     * "failure leaves no trace" shape as #212 itself.
     */
    public function testGalleryRefreshMarksHoldingsOnlyWhenFullyPersisted(): void
    {
        $src = file_get_contents(self::PLUGIN);
        self::assertIsString($src);

        $at = strpos($src, 'markHoldingsRefreshed($walletId)');
        self::assertIsInt($at, 'the gallery-refresh task must still mark the wallet on success');

        // The 400 characters before the call must contain the success gate.
        $preceding = substr($src, max(0, $at - 400), min($at, 400));
        self::assertStringContainsString(
            'CollectionPersistBatch::allPersisted($persisted)',
            $preceding,
            'markHoldingsRefreshed() must be gated on a fully-persisted batch, not called unconditionally'
        );
    }

    /**
     * Blank string metadata is treated as "not reported". Numeric columns keep
     * their own helpers so a genuine zero is still written — a broad empty()
     * rule would discard both.
     */
    public function testBlankStringsAreTreatedAsAbsentButZeroIsNot(): void
    {
        $body = $this->upsertBody();

        self::assertMatchesRegularExpression(
            '/trim\(\$string\) === \x27\x27 \? \x27NULL\x27/',
            $body,
            "'' and whitespace-only must resolve to SQL NULL so COALESCE preserves the existing value"
        );
        self::assertStringNotContainsString(
            'empty($value)',
            $body,
            'empty() would discard a legitimate numeric zero and the string "0"'
        );
        // The numeric helpers must remain null-only, never blank-sensitive.
        self::assertStringContainsString(
            "return \$value === null ? 'NULL' : \$wpdb->prepare('%d', (int) \$value);",
            $body,
            'integer columns must still write a genuine 0'
        );
    }

    /** The id must never be taken from the connection-sticky insert_id. */
    public function testRowIdIsAlwaysResolvedFromTheCanonicalKey(): void
    {
        $body = $this->upsertBody();

        self::assertStringNotContainsString(
            '$wpdb->insert_id',
            $body,
            'insert_id is connection-sticky and, under CLIENT_FOUND_ROWS, an unchanged update also reports affected=1 — it can name the wrong row'
        );
        self::assertMatchesRegularExpression(
            '/SELECT id FROM \{\$table\}\s+WHERE chain_id = %d AND contract_address = %s/',
            $body,
            'the id must come from the canonical key'
        );
    }
}
