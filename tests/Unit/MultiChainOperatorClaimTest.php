<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Support\Bech32;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * One account, many chains: operator authority is per validator, and the
 * address comparison folds case ONLY where the chain family allows it.
 *
 * ── WHY THIS SUITE EXISTS ────────────────────────────────────────────
 * Multi-chain claiming was already correct in the schema and never
 * covered by a test. `bcc_onchain_claims` carries
 * `UNIQUE KEY uq_user_entity (user_id, entity_type, entity_id)` and
 * `ClaimRepository::createExclusiveClaim()` locks
 * `bcc_claim:{entityType}:{entityId}:{role}` — neither is scoped to a
 * user or a chain, so nothing caps an account at one validator page.
 * Section 3 pins that, so a future change cannot quietly introduce a
 * global "one validator page per user" rule.
 *
 * ⚠⚠ THE SECURITY PROPERTY THIS SUITE PROTECTS. `matchValidator()` used
 * to lowercase both addresses before every comparison. For EVM that is
 * correct — hex is case-insensitive and EIP-55 casing carries no
 * identity. For base58 (Solana) and SS58 (Polkadot) upper and lower case
 * are DISTINCT characters, so folding loses information and two
 * different addresses can compare equal. Section 2 asserts the fold
 * happens for EVM and NOWHERE ELSE, in both directions.
 *
 * ⛔ No test here may make a claim succeed on a label, a moniker, or a
 * substring. Authority is only ever the same key.
 */
#[CoversNothing]
final class MultiChainOperatorClaimTest extends TestCase
{
    private const SERVICE = 'BCC\\Trust\\Onchain\\Services\\ClaimService';

    private static function read(string $relative): string
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/' . $relative);
        self::assertNotFalse($src, "could not read {$relative}");
        self::assertNotSame('', $src, "{$relative} is empty");

        return (string) $src;
    }

    /** Strip comments so prose can never satisfy a code assertion. */
    private static function code(string $src): string
    {
        $out = preg_replace('!/\*.*?\*/!s', '', $src) ?? '';

        return (string) preg_replace('!//[^\r\n]*!', '', $out);
    }

    /** @param list<array{address: string, chain_id: int}> $wallets */
    private static function match(array $wallets, string $operatorAddress, string $chainType): ?array
    {
        $objs = [];
        foreach ($wallets as $w) {
            $objs[] = (object) [
                'wallet_address' => $w['address'],
                'chain_id'       => $w['chain_id'],
            ];
        }
        $entity = (object) [
            'operator_address' => $operatorAddress,
            'chain_type'       => $chainType,
        ];

        $m = new ReflectionMethod(self::SERVICE, 'matchValidator');
        $m->setAccessible(true);

        /** @var array{wallet_address: string, chain_id: int, role: string}|null $out */
        $out = $m->invoke(null, $objs, $entity);

        return $out;
    }

    /** A real 20-byte Cosmos account/valoper pair, built from the same bytes. */
    private static function cosmosPair(): array
    {
        $bytes = hex2bin('0123456789abcdef0123456789abcdef01234567');
        self::assertIsString($bytes);
        self::assertSame(20, strlen((string) $bytes));

        $account = Bech32::encode('cosmos', (string) $bytes);
        $valoper = Bech32::encode('cosmosvaloper', (string) $bytes);
        self::assertIsString($account);
        self::assertIsString($valoper);
        self::assertNotSame($account, $valoper, 'the HRPs must differ');

        return [(string) $account, (string) $valoper];
    }

    // ── 1. the subject is really loaded ────────────────────────────────

    public function testTheSubjectAndHelpersAreReallyLoaded(): void
    {
        self::assertTrue(class_exists(self::SERVICE), 'ClaimService must autoload');
        self::assertTrue(
            (new ReflectionMethod(self::SERVICE, 'matchValidator'))->isPrivate(),
            'matchValidator must exist and stay private'
        );
        self::assertStringContainsString('matchValidator', self::read('app/Domain/Onchain/Services/ClaimService.php'));
    }

    // ── 2. case folding is per chain family ────────────────────────────

    /** EVM hex is case-insensitive — this must keep working, unchanged. */
    public function testEvmFoldsCaseInBothDirections(): void
    {
        $lower = '0xabcdef0123456789abcdef0123456789abcdef01';
        $upper = '0xABCDEF0123456789ABCDEF0123456789ABCDEF01';

        self::assertNotNull(self::match([['address' => $upper, 'chain_id' => 7]], $lower, 'evm'));
        self::assertNotNull(self::match([['address' => $lower, 'chain_id' => 7]], $upper, 'evm'));
    }

    /**
     * ⭐ SS58 is case-SENSITIVE base58. A wallet differing only in case is
     * a DIFFERENT account and must be refused. This is the assertion the
     * old lowercasing implementation would have failed.
     */
    public function testPolkadotDoesNotFoldCase(): void
    {
        // Format-plausible synthetic SS58 (prefix-0 shape); only the case differs.
        $stash = '15oF4uVJwmo4TdGW7VfQxNLavjCXviqxT9S1MgbjMNHr6Sp5';
        $folded = strtolower($stash);
        self::assertNotSame($stash, $folded, 'the fixture must actually contain upper case');

        self::assertNotNull(
            self::match([['address' => $stash, 'chain_id' => 21]], $stash, 'polkadot'),
            'the identical stash address must match'
        );
        self::assertNull(
            self::match([['address' => $folded, 'chain_id' => 21]], $stash, 'polkadot'),
            'a case-folded SS58 address is a different account and must be refused'
        );
    }

    /** Solana is base58 too, so the same rule applies. */
    public function testSolanaDoesNotFoldCase(): void
    {
        $node = '7Np41oeYqPefeNQEHSv1UDhYrehxin3NStELsSKCT4K2';
        $folded = strtolower($node);
        self::assertNotSame($node, $folded);

        self::assertNotNull(self::match([['address' => $node, 'chain_id' => 19]], $node, 'solana'));
        self::assertNull(
            self::match([['address' => $folded, 'chain_id' => 19]], $node, 'solana'),
            'a case-folded base58 address must be refused'
        );
    }

    /** ⛔ Nothing matches on a label or a substring. */
    public function testNoMatchOnLabelsOrSubstrings(): void
    {
        [$account, $valoper] = self::cosmosPair();

        self::assertNull(self::match([['address' => 'My Validator', 'chain_id' => 8]], $valoper, 'cosmos'));
        self::assertNull(self::match([['address' => substr($valoper, 0, 20), 'chain_id' => 8]], $valoper, 'cosmos'));
        self::assertNull(self::match([['address' => $valoper . 'x', 'chain_id' => 8]], $valoper, 'cosmos'));
        self::assertNull(self::match([['address' => '', 'chain_id' => 8]], $valoper, 'cosmos'));
        self::assertNull(self::match([], $valoper, 'cosmos'), 'no wallets means no match');
        self::assertNull(self::match([['address' => $account, 'chain_id' => 8]], '', 'cosmos'), 'no operator address means no match');
    }

    // ── 3. one account, many chains ───────────────────────────────────

    /** ⭐ The Cosmos HRP swap still matches on the decoded bytes. */
    public function testCosmosAccountMatchesItsValoper(): void
    {
        [$account, $valoper] = self::cosmosPair();

        $hit = self::match([['address' => $account, 'chain_id' => 8]], $valoper, 'cosmos');
        self::assertNotNull($hit);
        self::assertSame('operator', $hit['role']);
        self::assertSame($account, $hit['wallet_address']);
        self::assertSame(8, $hit['chain_id'], 'the WALLET chain id is returned, not the entity chain');
    }

    /**
     * ⭐ THE MULTI-CHAIN CASE. One account holding wallets on two chains
     * matches the right one per validator, and the other wallet neither
     * blocks nor satisfies the match.
     */
    public function testOneAccountWithWalletsOnTwoChainsMatchesThePerChainValidator(): void
    {
        [$cosmosAccount, $cosmosValoper] = self::cosmosPair();
        $stash = '15oF4uVJwmo4TdGW7VfQxNLavjCXviqxT9S1MgbjMNHr6Sp5';

        $wallets = [
            ['address' => $stash,         'chain_id' => 21],
            ['address' => $cosmosAccount, 'chain_id' => 8],
        ];

        $cosmosHit = self::match($wallets, $cosmosValoper, 'cosmos');
        self::assertNotNull($cosmosHit, 'the cosmos validator must match the cosmos wallet');
        self::assertSame(8, $cosmosHit['chain_id']);

        $dotHit = self::match($wallets, $stash, 'polkadot');
        self::assertNotNull($dotHit, 'the polkadot validator must match the polkadot wallet');
        self::assertSame(21, $dotHit['chain_id']);

        // And neither wallet satisfies the other chain's validator.
        self::assertNull(self::match([['address' => $stash, 'chain_id' => 21]], $cosmosValoper, 'cosmos'));
        self::assertNull(self::match([['address' => $cosmosAccount, 'chain_id' => 8]], $stash, 'polkadot'));
    }

    /** An unknown chain family gets the strict path, not a lenient one. */
    public function testAnUnknownChainFamilyStillComparesStrictly(): void
    {
        $addr = 'SomeMixedCaseAddress123';
        self::assertNotNull(self::match([['address' => $addr, 'chain_id' => 1]], $addr, 'tendermint-ish'));
        self::assertNull(self::match([['address' => strtolower($addr), 'chain_id' => 1]], $addr, 'tendermint-ish'));
    }

    // ── 4. no global one-page-per-user rule ───────────────────────────

    /**
     * ⭐ Pinned at the SCHEMA, because this is where a well-meaning change
     * would break multi-chain operators.
     */
    public function testTheClaimsTableDoesNotCapAUserAtOneEntity(): void
    {
        $schema = self::code(self::read('includes/database/schema-claims.php'));

        self::assertStringContainsString(
            'UNIQUE KEY uq_user_entity (user_id, entity_type, entity_id)',
            $schema,
            'uniqueness must include the entity, or a user could hold only one claim'
        );
        self::assertStringNotContainsString('UNIQUE KEY uq_user (user_id)', $schema);
        self::assertStringNotContainsString('UNIQUE KEY uq_user_role', $schema);
    }

    /** Exclusivity is per entity+role, never per user. */
    public function testExclusivityIsScopedToTheEntityAndRole(): void
    {
        $repo = self::code(self::read('app/Domain/Onchain/Repositories/ClaimRepository.php'));

        self::assertStringContainsString(
            '"bcc_claim:{$entityType}:{$entityId}:{$role}"',
            $repo,
            'the advisory lock must be keyed on entity+role'
        );
        self::assertStringNotContainsString('bcc_claim:{$userId}', $repo);
    }

    // ── 5. the dashboard read is user-scoped and bounded ──────────────

    public function testTheOperatorDashboardReadIsUserScopedAndBounded(): void
    {
        $repo = self::code(self::read('app/Domain/Onchain/Repositories/ClaimRepository.php'));
        $from = strpos($repo, 'function listVerifiedValidatorPagesForUser');
        self::assertNotFalse($from, 'the user-scoped read must exist');
        $body = substr($repo, $from, 3000);

        self::assertStringContainsString('cl.user_id     = %d', $body, 'the read must be user-scoped');
        self::assertStringContainsString("cl.status      = 'verified'", $body, 'only verified claims authorize anything');
        self::assertStringContainsString("cl.claim_role  = 'operator'", $body);
        self::assertStringContainsString('LIMIT %d', $body, 'the read stays bounded (§2)');
        self::assertStringNotContainsString('SELECT *', $body, 'explicit columns only (§2)');
    }

    /**
     * ⚠ The endpoint must not leak a membership claim or a fake freshness
     * date — see the corrections recorded in its own docblock.
     */
    public function testTheEndpointMakesNoMembershipClaimAndHidesFetchedAt(): void
    {
        $ep = self::read('app/Domain/Core/REST/MeValidatorPagesEndpoint.php');
        $code = self::code($ep);

        self::assertStringContainsString("'/me/validator-pages'", $code);
        self::assertStringContainsString('bcc_unauthorized', $code, 'anonymous must get a clean 401');
        self::assertStringContainsString("'chain_reported_status'", $code, 'the status field must be named for what it is');
        self::assertStringNotContainsString('fetched_at', $code, 'fetched_at must not be exposed as freshness');
        self::assertStringNotContainsString('last_seen', $code);
        self::assertStringNotContainsString('in_active_set', $code);
        self::assertStringNotContainsString("'status' =>", $code, 'no bare `status` key that reads as membership');
    }
}
