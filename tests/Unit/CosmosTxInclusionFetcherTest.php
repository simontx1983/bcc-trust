<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Services\CosmosTxInclusionFetcher as Fetcher;
use BCC\Core\Crypto\Bech32;
use BCC\Trust\Onchain\Support\OnchainCircuitBreaker;
use BCC\Trust\Onchain\Support\ProviderRequestBudget;
use BCC\Trust\Onchain\ValueObjects\CosmosSecurityContact;
use BCC\Trust\Onchain\ValueObjects\CosmosTxInclusionResult as Result;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * A3 of the validator memo claim: was the transaction included, per BOTH
 * proof operators, and what are its exact bytes?
 *
 * The real ApiRetry and the real breaker run; only the wire is scripted, by
 * URL. The cosmos proof endpoints are the production list (Polkachu and
 * Allnodes/PublicNode).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CosmosTxInclusionFetcherTest extends TestCase
{
    private const A_RPC  = 'https://cosmos-rpc.polkachu.com';
    private const B_RPC  = 'https://cosmos-rpc.publicnode.com';
    private const A_REST = 'https://cosmos-api.polkachu.com';
    private const B_REST = 'https://cosmos-rest.publicnode.com';
    private const TIME   = '2026-11-24T10:00:00.123456789Z';
    private const BYTES  = "\x0a\x9b\x01raw-tx-bytes\x00\xff";

    private string $hash;
    private string $valoper;
    private string $account;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/cosmos-tx-proof-stubs.php';
        \BccWire::reset();
        \BccBreakerStore::reset();
        \BCC\Core\Observability\DegradationMetrics::$records = [];
        \BccTxProof::seedChains();

        $this->hash    = strtoupper(hash('sha256', self::BYTES));
        $this->valoper = Bech32::encode('cosmosvaloper', str_repeat("\x11", 20));
        $this->account = Bech32::encode('cosmos', str_repeat("\x11", 20));
    }

    private static function budget(int $requests = 20): ProviderRequestBudget
    {
        return new ProviderRequestBudget($requests, 60);
    }

    /** Both operators healthy and agreeing on an included, successful send. */
    private function bothAgree(?array $txResult = ['gas_used' => '70000']): void
    {
        foreach ([self::A_RPC, self::B_RPC] as $rpc) {
            \BccTxProof::route($rpc . '/status', \BccTxProof::status('cosmoshub-4'));
            \BccTxProof::route($rpc . '/tx?', \BccTxProof::tx($this->hash, self::BYTES, 123, $txResult));
            \BccTxProof::route($rpc . '/commit?', \BccTxProof::commit(123, 'cosmoshub-4', self::TIME));
        }
        foreach ([self::A_REST, self::B_REST] as $rest) {
            \BccTxProof::route($rest . '/cosmos/auth/', \BccTxProof::account(['@type' => '/cosmos.auth.v1beta1.BaseAccount', 'account_number' => '77']));
        }
    }

    private function fetch(?string $hash = null, string $slug = 'cosmos', ?string $valoper = null, ?ProviderRequestBudget $budget = null): Result
    {
        return Fetcher::fetch($slug, $hash ?? $this->hash, $valoper ?? $this->valoper, $budget ?? self::budget());
    }

    /** @return list<string> */
    private static function requested(string $needle): array
    {
        return array_values(array_filter(\BccWire::$urls, static fn (string $u): bool => str_contains($u, $needle)));
    }

    // ── Included ────────────────────────────────────────────────────────

    public function testBothOperatorsConfirmingCodeZeroIsIncludedWithTheVerifierInputs(): void
    {
        $this->bothAgree();

        $result = $this->fetch();

        self::assertSame(Result::INCLUDED, $result->state);
        self::assertTrue($result->isIncluded());
        $in = $result->inclusion;
        self::assertNotNull($in);
        self::assertSame(self::BYTES, $in->txRaw, 'the raw bytes, decoded, exactly');
        self::assertSame($this->hash, $in->txHash);
        self::assertSame('cosmoshub-4', $in->chainId, 'the pinned network id');
        self::assertSame(77, $in->accountNumber);
        self::assertSame($this->valoper, $in->operatorAddress);
        self::assertSame(123, $in->height);
        self::assertSame(gmmktime(10, 0, 0, 11, 24, 2026), $in->blockTime);
        self::assertSame('cosmos', $in->chainSlug);
        self::assertSame('cosmos', $in->accountPrefix, 'the pinned account prefix, for the from-address check');

        self::assertCount(1, self::requested(self::A_RPC . '/tx?hash=0x' . $this->hash));
        self::assertCount(1, self::requested(self::B_RPC . '/tx?hash=0x' . $this->hash));
        self::assertCount(1, self::requested(self::A_REST . '/cosmos/auth/v1beta1/accounts/' . $this->account), 'the operator ACCOUNT, derived from the valoper');
        self::assertSame([], self::requested(self::B_REST . '/cosmos/auth/'), 'one operator suffices for the account number');
        self::assertSame([], \BccTxProof::metrics());
        self::assertNull(\BccBreakerStore::counter(\BccTxProof::CHAIN_ID), 'nothing charged');
    }

    /** The account prefix is BCC's pin: a different bech32_prefix on the chains row changes nothing. */
    public function testTheAccountPrefixIsThePinNotTheChainsRow(): void
    {
        $this->bothAgree();
        \BccBreakerStore::$cache['bcc_chains:active_all'][0]->bech32_prefix = 'cosmo';

        $result = $this->fetch();

        self::assertSame(Result::INCLUDED, $result->state);
        self::assertSame('cosmos', $result->inclusion?->accountPrefix);
        self::assertCount(1, self::requested('/cosmos/auth/v1beta1/accounts/' . $this->account));
    }

    /** CometBFT's JSON omits a zero code: an ABSENT code is success, an absent result is not. */
    public function testAnAbsentCodeIsZeroButAnAbsentExecutionResultIsNot(): void
    {
        $this->bothAgree([]);
        self::assertSame(Result::INCLUDED, $this->fetch()->state);

        \BccWire::reset();
        $this->bothAgree(null);
        self::assertSame(Result::NOT_YET, $this->fetch()->state);
    }

    /** The verifier's 16 KiB cap: at the cap it is handed over, one byte past it is not. */
    public function testTheTransactionSizeCapMatchesTheVerifiers(): void
    {
        self::assertSame(16384, Fetcher::MAX_TX_BYTES);

        $atCap   = str_repeat("\x01", Fetcher::MAX_TX_BYTES);
        $overCap = $atCap . "\x01";
        foreach ([[$atCap, Result::INCLUDED], [$overCap, Result::NOT_YET]] as [$bytes, $expected]) {
            \BccWire::reset();
            \BccBreakerStore::$transients = [];
            $hash = strtoupper(hash('sha256', $bytes));
            foreach ([self::A_RPC, self::B_RPC] as $rpc) {
                \BccTxProof::route($rpc . '/status', \BccTxProof::status('cosmoshub-4'));
                \BccTxProof::route($rpc . '/tx?', \BccTxProof::tx($hash, $bytes));
                \BccTxProof::route($rpc . '/commit?', \BccTxProof::commit(123, 'cosmoshub-4', self::TIME));
            }
            \BccTxProof::route(self::A_REST . '/cosmos/auth/', \BccTxProof::account(['account_number' => '77']));

            self::assertSame($expected, $this->fetch($hash)->state, strlen($bytes) . ' bytes');
        }
    }

    public function testAHashIsNormalisedWithAnOptionalPrefix(): void
    {
        $this->bothAgree();

        $result = $this->fetch('0x' . strtolower($this->hash));

        self::assertSame(Result::INCLUDED, $result->state);
        self::assertSame($this->hash, $result->inclusion?->txHash);
    }

    // ── Refused before any request ──────────────────────────────────────

    /** @return iterable<string, array{0: string}> */
    public static function badHashes(): iterable
    {
        yield 'short'        => [str_repeat('A', 63)];
        yield 'long'         => [str_repeat('A', 65)];
        yield 'not hex'      => [str_repeat('G', 64)];
        yield 'a path'       => ['../' . str_repeat('A', 61)];
        yield 'a query'      => [str_repeat('A', 60) . '&x=1'];
        yield 'empty'        => [''];
    }

    #[DataProvider('badHashes')]
    public function testABadHashIsRefusedBeforeAnyRequest(string $hash): void
    {
        $this->bothAgree();

        self::assertSame(Result::INVALID_HASH, $this->fetch($hash)->state);
        self::assertSame([], \BccWire::$urls);
    }

    public function testAChainWithoutProofEndpointsIsUnsupportedBeforeAnyRequest(): void
    {
        self::assertSame(Result::UNSUPPORTED, $this->fetch(null, 'kujira', Bech32::encode('kujiravaloper', str_repeat("\x11", 20)))->state);
        self::assertSame(Result::UNSUPPORTED, $this->fetch(null, 'thorchain')->state);
        self::assertSame([], \BccWire::$urls);
    }

    /** @return iterable<string, array{0: string}> */
    public static function badValopers(): iterable
    {
        yield 'another chain'    => [Bech32::encode('osmovaloper', str_repeat("\x11", 20))];
        yield 'an account'       => [Bech32::encode('cosmos', str_repeat("\x11", 20))];
        yield 'upper case'       => [strtoupper(Bech32::encode('cosmosvaloper', str_repeat("\x11", 20)))];
        yield 'bad checksum'     => [substr(Bech32::encode('cosmosvaloper', str_repeat("\x11", 20)), 0, -1) . 'q'];
        yield 'not 20 bytes'     => [Bech32::encode('cosmosvaloper', str_repeat("\x11", 32))];
        yield 'a path'           => ['cosmosvaloper1/../../x'];
    }

    #[DataProvider('badValopers')]
    public function testAnOperatorAddressThatIsNotThisChainsValoperIsRefusedBeforeAnyRequest(string $valoper): void
    {
        require_once __DIR__ . '/../Stubs/cosmos-tx-proof-stubs.php';
        $this->bothAgree();

        self::assertSame(Result::INVALID_VALIDATOR, $this->fetch(null, 'cosmos', $valoper)->state);
        self::assertSame([], \BccWire::$urls);
    }

    // ── Not yet (nothing decided) ───────────────────────────────────────

    /** A hash not yet indexed is an ANSWER: not retried, the chain's breaker never charged. */
    public function testNotIndexedYetIsNotYetAndChargesNothing(): void
    {
        $this->bothAgree();
        array_unshift(\BccWire::$routes, [self::A_RPC . '/tx?', \BccTxProof::notFound($this->hash)]);

        self::assertSame(Result::NOT_YET, $this->fetch()->state);
        self::assertCount(1, self::requested(self::A_RPC . '/tx?'), 'not retried');
        self::assertSame([], \BccWire::$sleeps, 'no backoff');
        self::assertNull(\BccBreakerStore::counter(\BccTxProof::CHAIN_ID), 'the chain-wide breaker is not charged');
        self::assertSame([], \BccTxProof::metrics(), 'not a disagreement');
    }

    /** Any other 5xx is a server fault: retried and charged as usual. */
    public function testAnotherServerErrorIsRetriedAndCharged(): void
    {
        $this->bothAgree();
        array_unshift(\BccWire::$routes, [self::A_RPC . '/tx?', ['code' => 502, 'body' => 'Bad Gateway']]);

        self::assertSame(Result::NOT_YET, $this->fetch()->state);
        self::assertGreaterThan(1, count(self::requested(self::A_RPC . '/tx?')), 'retried');
        self::assertGreaterThanOrEqual(1, \BccBreakerStore::counter(\BccTxProof::CHAIN_ID) ?? 0, 'charged');
    }

    public function testOneOperatorNotIndexedYetIsNotYetWithoutADisagreement(): void
    {
        $this->bothAgree();
        array_unshift(\BccWire::$routes, [self::B_RPC . '/tx?', \BccTxProof::notFound($this->hash)]);

        self::assertSame(Result::NOT_YET, $this->fetch()->state);
        self::assertSame([], \BccTxProof::metrics());
    }

    /** @return iterable<string, array{0: string}> */
    public static function disagreements(): iterable
    {
        yield 'other bytes'    => ['bytes'];
        yield 'another height' => ['height'];
        yield 'another code'   => ['code'];
        yield 'another time'   => ['time'];
    }

    /** The operators disagree: not yet, and counted. */
    #[DataProvider('disagreements')]
    public function testOperatorsThatDisagreeAreNotYetAndCounted(string $on): void
    {
        $this->bothAgree();
        [$needle, $response] = match ($on) {
            'bytes'  => [self::B_RPC . '/tx?', \BccTxProof::tx($this->hash, self::BYTES . 'x')],
            'height' => [self::B_RPC . '/tx?', \BccTxProof::tx($this->hash, self::BYTES, 124)],
            'code'   => [self::B_RPC . '/tx?', \BccTxProof::tx($this->hash, self::BYTES, 123, ['code' => 5])],
            'time'   => [self::B_RPC . '/commit?', \BccTxProof::commit(123, 'cosmoshub-4', '2026-11-24T10:00:01Z')],
        };
        array_unshift(\BccWire::$routes, [$needle, $response]);

        self::assertSame(Result::NOT_YET, $this->fetch()->state);
        self::assertSame(['endpoint_disagreement'], \BccTxProof::metrics());
    }

    public function testBothOperatorsAgreeingTheTransactionFailedIsFailedOnChain(): void
    {
        $this->bothAgree(['code' => 5, 'codespace' => 'sdk']);

        $result = $this->fetch();

        self::assertSame(Result::FAILED_ON_CHAIN, $result->state);
        self::assertSame(5, $result->txCode);
        self::assertNull($result->inclusion);
    }

    // ── The pinned network ──────────────────────────────────────────────

    public function testANodeOnAnotherNetworkIsNotYetBeforeAnyTransactionRequest(): void
    {
        $this->bothAgree();
        array_unshift(\BccWire::$routes, [self::B_RPC . '/status', \BccTxProof::status('cosmoshub-3')]);

        self::assertSame(Result::NOT_YET, $this->fetch()->state);
        self::assertSame(['network_mismatch'], \BccTxProof::metrics());
        self::assertSame([], self::requested('/tx?'), 'no node is asked for the transaction');
    }

    public function testABlockHeaderNamingAnotherChainIsNotYet(): void
    {
        $this->bothAgree();
        array_unshift(\BccWire::$routes, [self::A_RPC . '/commit?', \BccTxProof::commit(123, 'cosmoshub-3', self::TIME)]);

        self::assertSame(Result::NOT_YET, $this->fetch()->state);
        self::assertSame(['network_mismatch'], \BccTxProof::metrics());
    }

    /** @return iterable<string, array{0: array{code: int, body: string}}> */
    public static function badStatuses(): iterable
    {
        yield 'a redirect'     => [['code' => 301, 'body' => '', 'headers' => ['location' => 'https://elsewhere.example/status']]];
        yield 'unreachable'    => [['code' => 503, 'body' => '']];
        yield 'no network'     => [['code' => 200, 'body' => '{"result":{"node_info":{}}}']];
        yield 'not json'       => [['code' => 200, 'body' => '<html>']];
    }

    #[DataProvider('badStatuses')]
    public function testANodeWhoseNetworkCannotBeProvenIsNotYet(array $status): void
    {
        require_once __DIR__ . '/../Stubs/cosmos-tx-proof-stubs.php';
        $this->bothAgree();
        array_unshift(\BccWire::$routes, [self::A_RPC . '/status', $status]);

        self::assertSame(Result::NOT_YET, $this->fetch()->state);
        self::assertSame([], self::requested('/tx?'));
    }

    /** A pass is remembered (no /status next time); a failure never is. */
    public function testOnlyAProvenNetworkIsRemembered(): void
    {
        $this->bothAgree();
        self::assertSame(Result::INCLUDED, $this->fetch()->state);
        $statusCalls = count(self::requested('/status'));
        self::assertSame(2, $statusCalls);

        self::assertSame(Result::INCLUDED, $this->fetch()->state);
        self::assertSame($statusCalls, count(self::requested('/status')), 'cached after a pass');

        \BccBreakerStore::$transients = [];
        array_unshift(\BccWire::$routes, [self::A_RPC . '/status', \BccTxProof::status('cosmoshub-3')]);
        self::assertSame(Result::NOT_YET, $this->fetch()->state);
        array_shift(\BccWire::$routes);
        // The node that FAILED must be asked again: its failure was not
        // remembered as a pass. (The other node was never reached, so it
        // would be asked again either way.)
        $before = count(self::requested(self::A_RPC . '/status'));
        self::assertSame(Result::INCLUDED, $this->fetch()->state);
        self::assertSame($before + 1, count(self::requested(self::A_RPC . '/status')), 'the failed node is re-checked');
    }

    // ── Budget, breaker ─────────────────────────────────────────────────

    public function testARunOutBudgetIsNotYet(): void
    {
        $this->bothAgree();

        self::assertSame(Result::NOT_YET, $this->fetch(null, 'cosmos', null, self::budget(1))->state);
        self::assertCount(1, self::requested('/tx?'), 'stops at its budget');
    }

    public function testAnOpenBreakerIsNotYet(): void
    {
        $this->bothAgree();
        \BccBreakerStore::seedOpen(\BccTxProof::CHAIN_ID, 8, time());

        self::assertSame(Result::NOT_YET, $this->fetch()->state);
        self::assertSame([], self::requested('/tx?'), 'the breaker refused before the wire');
    }

    // ── Account number ──────────────────────────────────────────────────

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function accountShapes(): iterable
    {
        yield 'BaseAccount'          => [['@type' => '/cosmos.auth.v1beta1.BaseAccount', 'account_number' => '77']];
        yield 'EthAccount (Injective)' => [['@type' => '/injective.types.v1beta1.EthAccount', 'base_account' => ['account_number' => '77']]];
        yield 'a vesting account'    => [['@type' => '/cosmos.vesting.v1beta1.ContinuousVestingAccount', 'base_vesting_account' => ['base_account' => ['account_number' => '77']]]];
    }

    /** @param array<string, mixed> $account */
    #[DataProvider('accountShapes')]
    public function testTheAccountNumberIsReadFromEveryAccountShape(array $account): void
    {
        require_once __DIR__ . '/../Stubs/cosmos-tx-proof-stubs.php';
        $this->bothAgree();
        array_unshift(\BccWire::$routes, [self::A_REST . '/cosmos/auth/', \BccTxProof::account($account)]);

        self::assertSame(77, $this->fetch()->inclusion?->accountNumber);
    }

    public function testAnUnreadableAccountNumberFallsToTheOtherOperatorThenNotYet(): void
    {
        $this->bothAgree();
        array_unshift(\BccWire::$routes, [self::A_REST . '/cosmos/auth/', \BccTxProof::account(['account_number' => '-1'])]);
        self::assertSame(77, $this->fetch()->inclusion?->accountNumber, 'the second operator answered');

        array_unshift(\BccWire::$routes, [self::B_REST . '/cosmos/auth/', \BccTxProof::account(['account_number' => 77])]);
        self::assertSame(Result::NOT_YET, $this->fetch()->state, 'a number that is not a decimal string is unreadable');
    }

    // ── security_contact ────────────────────────────────────────────────

    private function contacts(?string $a, ?string $b): CosmosSecurityContact
    {
        foreach ([self::A_RPC, self::B_RPC] as $rpc) {
            \BccTxProof::route($rpc . '/status', \BccTxProof::status('cosmoshub-4'));
        }
        \BccTxProof::route(self::A_REST . '/cosmos/staking/v1beta1/validators/' . $this->valoper, \BccTxProof::validator($a));
        \BccTxProof::route(self::B_REST . '/cosmos/staking/v1beta1/validators/' . $this->valoper, \BccTxProof::validator($b));

        return Fetcher::securityContact('cosmos', $this->valoper, self::budget());
    }

    public function testASecurityContactBothOperatorsAgreeOnIsReturnedTrimmed(): void
    {
        $contact = $this->contacts(' security@validator.example ', 'security@validator.example');

        self::assertSame(CosmosSecurityContact::AGREED, $contact->state);
        self::assertSame('security@validator.example', $contact->value);
        self::assertSame([], \BccTxProof::metrics());
    }

    public function testOperatorsThatDisagreeOnTheContactGiveNoValueAndAreCounted(): void
    {
        $contact = $this->contacts('security@validator.example', 'attacker@elsewhere.example');

        self::assertSame(CosmosSecurityContact::DISAGREED, $contact->state);
        self::assertNull($contact->value);
        self::assertSame(['contact_disagreement'], \BccTxProof::metrics());
    }

    public function testAnEmptyContactOnBothIsNotSet(): void
    {
        $contact = $this->contacts('', '  ');

        self::assertSame(CosmosSecurityContact::NOT_SET, $contact->state);
        self::assertNull($contact->value);
    }

    /** @return iterable<string, array{0: ?string, 1: ?string}> */
    public static function unreadableContacts(): iterable
    {
        yield 'a missing field' => [null, 'security@validator.example'];
        yield 'over-long'       => [str_repeat('a', 257), str_repeat('a', 257)];
    }

    #[DataProvider('unreadableContacts')]
    public function testAnUnreadableContactIsUnavailable(?string $a, ?string $b): void
    {
        require_once __DIR__ . '/../Stubs/cosmos-tx-proof-stubs.php';
        self::assertSame(CosmosSecurityContact::UNAVAILABLE, $this->contacts($a, $b)->state);
    }

    public function testAnOperatorThatCannotBeReadMakesTheContactUnavailable(): void
    {
        \BccTxProof::route(self::B_REST . '/cosmos/staking/', ['code' => 404, 'body' => '{"code":5}']);

        $contact = $this->contacts('security@validator.example', 'security@validator.example');

        self::assertSame(CosmosSecurityContact::UNAVAILABLE, $contact->state);
        self::assertNull($contact->value);
    }

    public function testTheContactIsNotReadFromANodeOffNetwork(): void
    {
        \BccTxProof::route(self::A_RPC . '/status', \BccTxProof::status('cosmoshub-3'));

        self::assertSame(CosmosSecurityContact::UNAVAILABLE, $this->contacts('a@b.example', 'a@b.example')->state);
        self::assertSame([], self::requested('/cosmos/staking/'));
    }

    // ── Pure readers ────────────────────────────────────────────────────

    public function testOnlyCometBftsNotFoundAnswerIsAnAnswer(): void
    {
        $hash = str_repeat('AB', 32);
        $nf   = \BccTxProof::notFound($hash);

        self::assertTrue(Fetcher::isTxNotFound($nf['body'], 500));
        self::assertFalse(Fetcher::isTxNotFound($nf['body'], 502), 'only a 500');
        self::assertFalse(Fetcher::isTxNotFound('{"error":{"data":"tx (' . $hash . ') not found, retry"}}', 500), 'exactly that message');
        self::assertFalse(Fetcher::isTxNotFound('{"error":{"data":"Internal error"}}', 500));
        self::assertFalse(Fetcher::isTxNotFound('not json', 500));
        self::assertFalse(Fetcher::isTxNotFound('{"error":"tx (' . $hash . ') not found"}', 500), 'the JSON-RPC data field, not any string');
    }

    public function testHeaderTimesAreUtcRfc3339Only(): void
    {
        self::assertSame(gmmktime(10, 0, 0, 11, 24, 2026), Fetcher::headerTime('2026-11-24T10:00:00Z'));
        self::assertSame(gmmktime(10, 0, 0, 11, 24, 2026), Fetcher::headerTime('2026-11-24T10:00:00.999999999Z'));
        self::assertNull(Fetcher::headerTime('2026-11-24T10:00:00+01:00'));
        self::assertNull(Fetcher::headerTime('2026-11-24 10:00:00'));
        self::assertNull(Fetcher::headerTime(''));
    }

    public function testTheOperatorAccountIsTheValopersBytesUnderTheAccountPrefix(): void
    {
        self::assertSame($this->account, Fetcher::operatorAccount($this->valoper, 'cosmos'));
        self::assertSame(
            Bech32::encode('inj', str_repeat("\x22", 20)),
            Fetcher::operatorAccount(Bech32::encode('injvaloper', str_repeat("\x22", 20)), 'inj')
        );
        self::assertNull(Fetcher::operatorAccount($this->valoper, ''));
        self::assertNull(Fetcher::operatorAccount($this->valoper, 'osmo'));
    }
}
