<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Services\Tests;

use BCC\Trust\Core\Services\HallsService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Locks the chain-scoped preview a Hall renders above its feed.
 *
 * WHY THE PREVIEW EXISTS. Halls are BCC's answer to the reputation
 * cold-start problem: a person will not show up to "build decentralised
 * reputation", but they will show up to see who secures a chain. A
 * reputation graph cannot be seeded — that is the product's premise — but
 * validator and collection data is ALREADY indexed, so a Hall can be worth
 * entering with zero members. Before this, every Hall rendered a chain
 * badge, "1 member" and an empty feed while 261 validators sat unshown
 * behind it.
 *
 * The projection is a pure static over repository rows so it is testable
 * without WordPress or MySQL, matching EliteEligibilityService::evaluate
 * and RankProgressionListener::decideTransition.
 *
 * The load-bearing test here is the PRIVACY one. ValidatorRepository::COLUMNS
 * selects `wallet_link_id` (a pointer into a member's linked wallet) and
 * `operator_address`. Contract v1.51 closed 14 cross-user wallet-disclosure
 * paths; a public, unauthenticated Hall preview must not reopen one. Field
 * selection is the only thing standing between those columns and the wire,
 * so it is asserted rather than trusted.
 */
#[CoversClass(HallsService::class)]
final class HallsChainPreviewTest extends TestCase
{
    /** A validator row shaped like ValidatorRepository::COLUMNS returns. */
    private static function validatorRow(array $overrides = []): object
    {
        return (object) ($overrides + [
            'id'                  => '12',
            'wallet_link_id'      => '77',              // MUST NOT be projected
            'operator_address'    => 'cosmosvaloper1abc', // MUST NOT be projected
            'chain_id'            => '3',
            'moniker'             => 'Coinbase01',
            'status'              => 'active',
            'commission_rate'     => '0.0500',
            'total_stake'         => '67355405.19476300',
            'self_stake'          => '1000.0',
            'delegator_count'     => '942',
            'uptime_30d'          => '99.98',
            'jailed_count'        => '0',
            'voting_power_rank'   => '1',
            'logo_url'            => 'https://cdn.example/logo.png',
        ]);
    }

    private static function collectionRow(array $overrides = []): object
    {
        return (object) ($overrides + [
            'id'               => '5',
            'chain_id'         => '3',
            'contract_address' => 'cosmos12gsv9tmjhhg86wg',
            'collection_name'  => 'Bad Kids',
            'image_url'        => 'https://cdn.example/badkids.png',
            'chain_slug'       => 'cosmos',
            'chain_type'       => 'cosmos',
        ]);
    }

    // ── The privacy guard ────────────────────────────────────────────────

    public function testValidatorPreviewNeverProjectsWalletOrOperatorAddress(): void
    {
        $out = HallsService::previewFromRows([self::validatorRow()], []);
        $projected = $out['validators'][0];

        self::assertArrayNotHasKey('wallet_link_id', $projected, 'v1.51: wallet linkage must never reach a public read');
        self::assertArrayNotHasKey('operator_address', $projected);

        // Also assert by VALUE, so renaming the key into the payload would
        // still fail. Only the operator address is checked this way — it is
        // a distinctive string; the wallet id ('77') is a bare integer that
        // could legitimately occur inside a stake figure, and the
        // closed-set assertion below already proves that key is absent.
        $values = array_map(static fn ($v) => (string) $v, $projected);
        self::assertNotContains('cosmosvaloper1abc', $values);
    }

    public function testValidatorPreviewExposesExactlyTheAgreedFields(): void
    {
        $out = HallsService::previewFromRows([self::validatorRow()], []);

        self::assertSame(
            ['commission_rate', 'delegator_count', 'id', 'logo_url', 'moniker', 'total_stake', 'uptime_30d', 'voting_power_rank'],
            self::sortedKeys($out['validators'][0]),
            'the preview surface is a closed set — adding a field is a contract change'
        );
    }

    public function testCollectionPreviewExposesExactlyTheAgreedFields(): void
    {
        $out = HallsService::previewFromRows([], [self::collectionRow()]);

        self::assertSame(
            ['contract_address', 'id', 'image_url', 'name'],
            self::sortedKeys($out['collections'][0])
        );
    }

    // ── Projection correctness ───────────────────────────────────────────

    public function testNumericStringsFromMysqlBecomeInts(): void
    {
        // $wpdb hands every column back as a string; ids and counts must
        // not reach the client quoted.
        $v = HallsService::previewFromRows([self::validatorRow()], [])['validators'][0];

        self::assertSame(12, $v['id']);
        self::assertSame(942, $v['delegator_count']);
        self::assertSame(1, $v['voting_power_rank']);
        // Stake stays a STRING on purpose — it exceeds float precision.
        self::assertSame('67355405.19476300', $v['total_stake']);
    }

    public function testEmptyStringsNormaliseToNullNotEmptyString(): void
    {
        // An empty logo_url rendered verbatim makes the client request an
        // image from the site root.
        $v = HallsService::previewFromRows(
            [self::validatorRow(['logo_url' => '', 'commission_rate' => ''])],
            []
        )['validators'][0];

        self::assertNull($v['logo_url']);
        self::assertNull($v['commission_rate']);
    }

    public function testNullColumnsSurviveAsNull(): void
    {
        $v = HallsService::previewFromRows(
            [self::validatorRow(['logo_url' => null, 'delegator_count' => null, 'voting_power_rank' => null])],
            []
        )['validators'][0];

        self::assertNull($v['logo_url']);
        self::assertNull($v['delegator_count']);
        self::assertNull($v['voting_power_rank']);
    }

    public function testMissingMonikerBecomesEmptyStringNotNull(): void
    {
        // moniker is typed string (not nullable) in the view-model, so an
        // un-enriched validator must not break the client's type contract.
        $row = self::validatorRow();
        unset($row->moniker);

        self::assertSame('', HallsService::previewFromRows([$row], [])['validators'][0]['moniker']);
    }

    // ── Degradation ──────────────────────────────────────────────────────

    public function testNoRowsYieldsEmptyListsNotNull(): void
    {
        $out = HallsService::previewFromRows([], []);

        self::assertSame(['validators' => [], 'collections' => []], $out);
        // Lists, so they serialise as JSON arrays rather than objects.
        self::assertSame([], array_keys($out['validators']));
    }

    public function testPreviewIsAListWithSequentialKeysForJsonArrayOutput(): void
    {
        $out = HallsService::previewFromRows(
            [self::validatorRow(), self::validatorRow(['id' => '13'])],
            [self::collectionRow(), self::collectionRow(['id' => '6'])]
        );

        self::assertSame([0, 1], array_keys($out['validators']));
        self::assertSame([0, 1], array_keys($out['collections']));
        self::assertSame(13, $out['validators'][1]['id']);
    }

    /** @param array<string, mixed> $row */
    private static function sortedKeys(array $row): array
    {
        $keys = array_keys($row);
        sort($keys);
        return $keys;
    }
}
