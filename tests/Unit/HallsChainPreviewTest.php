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
 * without WordPress or MySQL, matching EliteEligibilityService::evaluate.
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

    /** A chain-registry row shaped like ChainRepository::COLUMNS returns. */
    private static function chainRow(array $overrides = []): object
    {
        return (object) ($overrides + [
            'id'                   => '3',
            'slug'                 => 'cosmos',
            'name'                 => 'Cosmos Hub',
            'chain_type'           => 'cosmos',
            'chain_id_hex'         => null,
            'rpc_url'              => 'https://rpc.cosmos.directory/cosmoshub', // MUST NOT be projected
            'rest_url'             => 'https://rest.cosmos.directory/cosmoshub', // MUST NOT be projected
            'explorer_url'         => 'https://www.mintscan.io/cosmos',
            'native_token'         => 'ATOM',
            'decimals'             => '6',
            'bech32_prefix'        => 'cosmos',
            'icon_url'             => 'https://cdn.example/atom.png',
            'color'                => '#2E3148',
            'marketplace_template' => 'https://www.stargaze.zone/m/{contract}/{token_id}',
            'description'          => 'The Internet of Blockchains — Cosmos SDK app-chains.',
            'is_testnet'           => '0',
            'is_active'            => '1',
            'created_at'           => '2026-01-01 00:00:00',
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

    // ── chain_profile projection (the /halls/:slug "About this chain" block) ─

    public function testChainProfileExposesExactlyTheWhitelistedFields(): void
    {
        $profile = HallsService::chainProfileFromRow(self::chainRow());
        self::assertNotNull($profile);

        self::assertSame(
            ['chain_type', 'color', 'description', 'explorer_url', 'icon_url', 'name', 'native_token', 'slug'],
            self::sortedKeys($profile),
            'chain_profile is a closed set — facts + description only; adding a field is a contract change'
        );
    }

    public function testChainProfileNeverProjectsInfraEndpoints(): void
    {
        $profile = HallsService::chainProfileFromRow(self::chainRow());
        self::assertNotNull($profile);

        // rpc_url / rest_url are internal infrastructure endpoints — never
        // part of a public payload.
        self::assertArrayNotHasKey('rpc_url', $profile);
        self::assertArrayNotHasKey('rest_url', $profile);

        // Assert by VALUE too, so renaming an infra endpoint into the payload
        // under a different key would still fail.
        $values = array_map(static fn ($v) => (string) $v, $profile);
        self::assertNotContains('https://rpc.cosmos.directory/cosmoshub', $values);
        self::assertNotContains('https://rest.cosmos.directory/cosmoshub', $values);
    }

    public function testChainProfileNeverProjectsWalletOrValidatorLinkage(): void
    {
        // Belt-and-suspenders: even if a chain row somehow carried a
        // wallet/validator-linkage column, the closed whitelist must not
        // surface it (parity with the validator-preview privacy guard).
        $profile = HallsService::chainProfileFromRow(self::chainRow([
            'wallet_link_id'   => '77',
            'operator_address' => 'cosmosvaloper1abc',
        ]));
        self::assertNotNull($profile);

        self::assertArrayNotHasKey('wallet_link_id', $profile);
        self::assertArrayNotHasKey('operator_address', $profile);
        $values = array_map(static fn ($v) => (string) $v, $profile);
        self::assertNotContains('cosmosvaloper1abc', $values);
    }

    public function testChainProfileProjectsTheFactsAndDescription(): void
    {
        $profile = HallsService::chainProfileFromRow(self::chainRow());
        self::assertNotNull($profile);

        self::assertSame('cosmos', $profile['slug']);
        self::assertSame('Cosmos Hub', $profile['name']);
        self::assertSame('ATOM', $profile['native_token']);
        self::assertSame('cosmos', $profile['chain_type']);
        self::assertSame('https://www.mintscan.io/cosmos', $profile['explorer_url']);
        self::assertSame('https://cdn.example/atom.png', $profile['icon_url']);
        self::assertSame('#2E3148', $profile['color']);
        self::assertSame('The Internet of Blockchains — Cosmos SDK app-chains.', $profile['description']);
    }

    public function testChainProfileEmptyNullableStringsNormaliseToNull(): void
    {
        $profile = HallsService::chainProfileFromRow(self::chainRow([
            'native_token' => '',
            'explorer_url' => '',
            'icon_url'     => '',
            'color'        => '',
            'description'  => '',
        ]));
        self::assertNotNull($profile);

        self::assertNull($profile['native_token']);
        self::assertNull($profile['explorer_url']);
        self::assertNull($profile['icon_url']);
        self::assertNull($profile['color']);
        self::assertNull($profile['description']);
        // slug / name / chain_type are non-nullable facts and stay strings.
        self::assertSame('cosmos', $profile['slug']);
    }

    public function testChainProfileNullDescriptionSurvivesAsNull(): void
    {
        $profile = HallsService::chainProfileFromRow(self::chainRow(['description' => null]));
        self::assertNotNull($profile);
        self::assertNull($profile['description']);
    }

    public function testChainlessHallYieldsNullChainProfile(): void
    {
        // A Hall with no chain row (chainless Hall, or a slug that resolves
        // to no active chain) must degrade to null, not error.
        self::assertNull(HallsService::chainProfileFromRow(null));
    }

    /** @param array<string, mixed> $row */
    private static function sortedKeys(array $row): array
    {
        $keys = array_keys($row);
        sort($keys);
        return $keys;
    }
}
