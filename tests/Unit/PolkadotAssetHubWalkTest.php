<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Fetchers\IndexTimeBudgetSpent;
use BCC\Trust\Onchain\Fetchers\PolkadotFetcher;
use BCC\Trust\Onchain\Support\ProviderOutcomeReceipt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Polkadot's validator index on Subscan's Asset Hub API.
 *
 * The walk reads 25 rows a request (Subscan refuses 50 and up for this key
 * with HTTP 403), paced to the key's 2-per-second limit, and never returns a
 * list it knows is incomplete. The single-validator call, which the hourly
 * enrichment writes from, carries no amounts and no rank: on Asset Hub it
 * answers `bonded_total: "0"` for a staked validator.
 *
 * Fixtures are synthetic: no real stash address or identity name.
 */
#[CoversClass(PolkadotFetcher::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PolkadotAssetHubWalkTest extends TestCase
{
    private const T0 = 1_700_000_000;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/apiretry-real-stubs.php';
        if (!defined('BCC_SUBSCAN_API_KEY')) {
            define('BCC_SUBSCAN_API_KEY', 'unit-test-key');
        }
        \BCC\Core\Log\Logger::reset();
    }

    private static function chain(?string $restUrl = null): object
    {
        return (object) ['id' => 19, 'slug' => 'polkadot', 'name' => 'Polkadot', 'chain_type' => 'polkadot', 'decimals' => 10, 'rest_url' => $restUrl];
    }

    private static function stash(int $i): string
    {
        return sprintf('1SyntheticStash%033d', $i);
    }

    /** @return list<array<string, mixed>> rows $from .. $from+$n-1, stake descending */
    private static function rows(int $from, int $n): array
    {
        $out = [];
        for ($i = $from; $i < $from + $n; $i++) {
            $out[] = [
                'stash_account_display' => ['address' => self::stash($i)],
                'bonded_total'          => (string) (10_000_000_000_000 - $i),
                'bonded_owner'          => '1000000000000',
                'count_nominators'      => 3,
                'validator_prefs_value' => 0,
                'status'                => '',
            ];
        }
        return $out;
    }

    /** @return array{list: list<array<string, mixed>>, count?: int} */
    private static function page(int $from, int $n, ?int $count = null): array
    {
        $page = ['list' => self::rows($from, $n)];
        if ($count !== null) {
            $page['count'] = $count;
        }
        return $page;
    }

    // ── The walk ────────────────────────────────────────────────────────

    public function testTheWholeSetIsWalkedTwentyFiveAtATimeWithNoRequestPastTheEnd(): void
    {
        $f = new WalkPolkadotFetcher(self::chain());
        for ($p = 0; $p < 16; $p++) {
            $f->pages[$p] = self::page($p * 25, 25, 400);
        }

        $rows = $f->fetch_all_validators(new ProviderOutcomeReceipt());

        self::assertSame(range(0, 15), array_column($f->requested, 'page'), 'sixteen pages, and no empty one after them');
        foreach ($f->requested as $body) {
            self::assertSame(['order' => 'desc', 'order_field' => 'bonded_total', 'row' => 25, 'page' => $body['page']], $body);
        }
        self::assertCount(400, $rows);
        self::assertSame(range(1, 400), array_column($rows, 'voting_power_rank'));
        self::assertSame(['active'], array_values(array_unique(array_column($rows, 'status'))), 'list membership is the active signal; status "" is not read');
        self::assertSame(self::stash(0), $rows[0]['operator_address']);
    }

    public function testAShortPageEndsTheWalkWhenNoCountIsGiven(): void
    {
        $f = new WalkPolkadotFetcher(self::chain());
        $f->pages = [self::page(0, 25), self::page(25, 7)];

        self::assertCount(32, $f->fetch_all_validators());
        self::assertSame([0, 1], array_column($f->requested, 'page'));
    }

    public function testAWalkShortOfSubscansCountIsRefused(): void
    {
        $f = new WalkPolkadotFetcher(self::chain());
        // Page 1 repeats five rows of page 0 (a stake shift across a page
        // boundary), so five validators were never seen.
        $f->pages = [
            self::page(0, 25, 60),
            ['list' => array_merge(self::rows(20, 5), self::rows(25, 20)), 'count' => 60],
            self::page(50, 10, 60),
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('found 55 distinct validators where Subscan reports 60; refusing to index a truncated list');
        $f->fetch_all_validators();
    }

    /** The set grew while the walk ran: more than the count is not truncation. */
    public function testMoreValidatorsThanTheCountIsNotTruncation(): void
    {
        $f = new WalkPolkadotFetcher(self::chain());
        $f->pages = [self::page(0, 25, 40), self::page(25, 25, 40)];

        self::assertCount(50, $f->fetch_all_validators());
        self::assertSame([0, 1], array_column($f->requested, 'page'));
    }

    public function testTheCapRefusesAListThatNeverEnds(): void
    {
        $f = new WalkPolkadotFetcher(self::chain());
        for ($p = 0; $p < 41; $p++) {
            $f->pages[$p] = self::page($p * 25, 25);
        }

        try {
            $f->fetch_all_validators();
            self::fail('a list that does not end must not be indexed');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('did not end within 40 pages of 25 (1000 rows fetched)', $e->getMessage());
        }
        self::assertSame(range(0, 39), array_column($f->requested, 'page'));
    }

    public function testTheBudgetStopsTheWalkBetweenPagesAndIsNotAProviderFailure(): void
    {
        $f = new WalkPolkadotFetcher(self::chain());
        for ($p = 0; $p < 16; $p++) {
            $f->pages[$p] = self::page($p * 25, 25, 400);
        }
        $f->secondsPerPage = 15; // page 8 would start at +120s: past the 110s a page needs

        try {
            $f->fetch_all_validators();
            self::fail('a walk out of time must not return what it has');
        } catch (IndexTimeBudgetSpent $e) {
            self::assertSame('Polkadot validator walk reached its 120s budget before page 8 (200 rows fetched).', $e->getMessage());
        }
        self::assertSame(range(0, 7), array_column($f->requested, 'page'));

        $deadline = new \ReflectionProperty(PolkadotFetcher::class, 'deadlineAt');
        $deadline->setAccessible(true);
        self::assertNull($deadline->getValue($f), 'the deadline belongs to the walk, not the object');
    }

    public function testTheBudgetBoundaryIsTenSecondsLeft(): void
    {
        $f = new WalkPolkadotFetcher(self::chain());
        $f->pages = [self::page(0, 25, 50), self::page(25, 25, 50)];
        $f->secondsPerPage = 110; // page 1 starts with exactly 10s left: allowed

        self::assertCount(50, $f->fetch_all_validators());
    }

    // ── The wire: deadline, refusal, pacing ─────────────────────────────

    public function testTheWalkHandsItsDeadlineToApiRetryAndEnrichmentDoesNot(): void
    {
        $f = new WirePolkadotFetcher(self::chain());
        $f->responses = [
            \BccHttp::response(200, (string) json_encode(['code' => 0, 'data' => self::page(0, 25, 30)])),
            \BccHttp::response(200, (string) json_encode(['code' => 0, 'data' => self::page(25, 5, 30)])),
            \BccHttp::response(200, (string) json_encode(['code' => 0, 'data' => ['info' => self::rows(0, 1)[0]]])),
        ];

        self::assertCount(30, $f->fetch_all_validators(new ProviderOutcomeReceipt()));
        $f->fetch_validator(self::stash(0));

        self::assertCount(3, $f->sent);
        self::assertSame(self::T0 + PolkadotFetcher::INDEX_TIME_BUDGET, $f->sent[0]['options']['deadline_at']);
        self::assertSame(self::T0 + PolkadotFetcher::INDEX_TIME_BUDGET, $f->sent[1]['options']['deadline_at']);
        self::assertArrayNotHasKey('deadline_at', $f->sent[2]['options'], 'enrichment keeps ApiRetry\'s own default');

        self::assertSame(PolkadotFetcher::DEFAULT_REST_URL . '/api/scan/staking/validators', $f->sent[0]['url']);
        self::assertSame(19, $f->sent[0]['options']['chain_id']);
        self::assertInstanceOf(ProviderOutcomeReceipt::class, $f->sent[0]['options']['outcome']);
        self::assertSame(25, json_decode((string) $f->sent[0]['args']['body'], true)['row']);
    }

    public function testADeadlineSpentBeforeARequestIsTheBudgetNotAFailedPage(): void
    {
        $f = new WirePolkadotFetcher(self::chain());
        $f->responses = [
            \BccHttp::response(200, (string) json_encode(['code' => 0, 'data' => self::page(0, 25, 50)])),
            new \WP_Error('deadline_exceeded', 'Deadline reached before any attempt for Subscan /api/scan/staking/validators'),
        ];

        $this->expectException(IndexTimeBudgetSpent::class);
        $this->expectExceptionMessage('reached its 120s budget before /api/scan/staking/validators could start');
        $f->fetch_all_validators();
    }

    public function testARefusedPageFailsTheWalk(): void
    {
        $f = new WirePolkadotFetcher(self::chain());
        $f->responses = [\BccHttp::response(403, '{"code":403,"message":"row_limit_exceeded"}')];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Polkadot validator page 0 failed');
        $f->fetch_all_validators();
    }

    public function testRequestsAreSpacedAtLeast550msApart(): void
    {
        $f = new WirePolkadotFetcher(self::chain());
        $f->fetch_validator(self::stash(1));
        $f->fetch_validator(self::stash(2));
        $f->fetch_validator(self::stash(3));

        self::assertCount(2, $f->slept, 'the first request does not wait');
        foreach ($f->slept as $micros) {
            self::assertEqualsWithDelta(550_000, $micros, 1);
        }
    }

    public function testNoWaitWhenTheLastRequestIsAlreadyLongEnoughAgo(): void
    {
        $f = new WirePolkadotFetcher(self::chain());
        $f->fetch_validator(self::stash(1));
        $f->clockNow += 0.6;
        $f->fetch_validator(self::stash(2));

        self::assertSame([], $f->slept);
    }

    /** The hourly enrichment builds a fetcher per validator: the spacing must hold across them. */
    public function testTheSpacingHoldsAcrossFetcherInstances(): void
    {
        $a = new WirePolkadotFetcher(self::chain());
        $b = new WirePolkadotFetcher(self::chain());
        $a->fetch_validator(self::stash(1));
        $b->clockNow = $a->clockNow;
        $b->fetch_validator(self::stash(2));

        self::assertSame([], $a->slept);
        self::assertCount(1, $b->slept);
        self::assertEqualsWithDelta(550_000, $b->slept[0], 1);
    }

    // ── Identity names ──────────────────────────────────────────────────

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function identities(): iterable
    {
        $addr = sprintf('1SyntheticStash%033d', 7);
        yield 'a sub-identity: PARENT/SUB' => [['address' => $addr, 'people' => ['parent' => ['display' => 'Example Validators', 'sub_symbol' => 'Node 7', 'identity' => true]]], 'Example Validators/Node 7'];
        yield 'a parent with no sub name'  => [['address' => $addr, 'people' => ['parent' => ['display' => 'Example Validators']]], 'Example Validators'];
        yield 'an identity of its own'     => [['address' => $addr, 'people' => ['display' => 'Solo Operator']], 'Solo Operator'];
        yield 'the relay shape still wins' => [['address' => $addr, 'display' => 'Relay Name', 'people' => ['display' => 'Solo Operator']], 'Relay Name'];
        yield 'no identity at all'         => [['address' => $addr], '1Synth...0007'];
        yield 'an empty parent name'       => [['address' => $addr, 'people' => ['parent' => ['display' => '', 'sub_symbol' => 'Node 7']]], '1Synth...0007'];
    }

    /** @param array<string, mixed> $account */
    #[DataProvider('identities')]
    public function testTheMonikerIsTheIdentityName(array $account, string $expected): void
    {
        $map = new \ReflectionMethod(PolkadotFetcher::class, 'mapValidator');
        $map->setAccessible(true);

        /** @var array<string, mixed> $row */
        $row = $map->invoke(new PolkadotFetcher(self::chain()), ['stash_account_display' => $account, 'bonded_total' => '10000000000'], 0);

        self::assertSame($expected, $row['moniker']);
    }

    // ── The single call never zeroes a stake ────────────────────────────

    /** @return array<string, mixed> the single call's `info`, as Asset Hub answers it */
    private static function info(string $status): array
    {
        return [
            'stash_account_display' => ['address' => self::stash(4), 'people' => ['parent' => ['display' => 'Example Validators', 'sub_symbol' => 'Node 4']]],
            'bonded_total'          => '0',
            'bonded_owner'          => '100000000000000',
            'count_nominators'      => 6,
            'validator_prefs_value' => 50_000_000,
            'status'                => $status,
        ];
    }

    /** @return array<string, mixed> */
    private static function single(string $status): array
    {
        $f = new WirePolkadotFetcher(self::chain());
        $f->responses = [\BccHttp::response(200, (string) json_encode(['code' => 0, 'data' => ['info' => self::info($status)]]))];

        return $f->fetch_validator(self::stash(4));
    }

    public function testTheSingleCallContributesNoAmountsAndNoRank(): void
    {
        $mapped = self::single('active');

        foreach (['total_stake', 'self_stake', 'voting_power_rank'] as $key) {
            self::assertArrayNotHasKey($key, $mapped, "{$key} would overwrite the list's value every hour");
        }
        self::assertSame(self::stash(4), $mapped['operator_address']);
        self::assertSame(19, $mapped['chain_id']);
        self::assertSame('active', $mapped['status']);
        self::assertSame('Example Validators/Node 4', $mapped['moniker']);
        self::assertSame(6, $mapped['delegator_count']);
        self::assertSame(5.0, $mapped['commission_rate']);
    }

    /** @return iterable<string, array{0: string, 1: ?string}> */
    public static function statuses(): iterable
    {
        yield 'active'                  => ['active', 'active'];
        yield 'waiting'                 => ['waiting', 'inactive'];
        yield 'inactive'                => ['inactive', 'inactive'];
        yield 'empty (the list shape)'  => ['', null];
        yield 'anything else'           => ['chilled', null];
    }

    #[DataProvider('statuses')]
    public function testTheSingleCallsStatusIsReadNotGuessed(string $status, ?string $expected): void
    {
        $mapped = self::single($status);

        if ($expected === null) {
            self::assertArrayNotHasKey('status', $mapped, 'an unknown status leaves the stored one alone');
        } else {
            self::assertSame($expected, $mapped['status']);
        }
    }

    /** The list walk still carries the amounts and the rank, from the list. */
    public function testTheListStillCarriesTheAmounts(): void
    {
        $f = new WalkPolkadotFetcher(self::chain());
        $f->pages = [self::page(0, 3)];

        $rows = $f->fetch_all_validators();

        self::assertSame(1000.0, $rows[0]['total_stake']);
        self::assertSame(100.0, $rows[0]['self_stake']);
        self::assertSame(1, $rows[0]['voting_power_rank']);
    }
}

/** Scripted pages through the apiPost() seam, on a clock each page advances. */
final class WalkPolkadotFetcher extends PolkadotFetcher
{
    /** @var array<int, array<string, mixed>|null> */
    public array $pages = [];
    /** @var list<array<string, mixed>> */
    public array $requested = [];
    public int $clock = 1_700_000_000;
    public int $secondsPerPage = 0;

    protected function apiPost(string $path, array $body, ?ProviderOutcomeReceipt $outcome = null): ?array
    {
        $this->requested[] = $body;
        $this->clock += $this->secondsPerPage;
        $page = (int) ($body['page'] ?? -1);

        return array_key_exists($page, $this->pages) ? $this->pages[$page] : ['list' => []];
    }

    protected function now(): int
    {
        return $this->clock;
    }
}

/** The real apiPost(), with the wire, the clocks and the sleep replaced. */
final class WirePolkadotFetcher extends PolkadotFetcher
{
    /** @var list<array<string, mixed>|\WP_Error> */
    public array $responses = [];
    /** @var list<array{url: string, args: array<string, mixed>, options: array<string, mixed>}> */
    public array $sent = [];
    /** @var list<int> */
    public array $slept = [];
    public float $clockNow = 1000.0;

    protected function sendPost(string $url, array $args, array $options)
    {
        $this->sent[] = ['url' => $url, 'args' => $args, 'options' => $options];

        return array_shift($this->responses) ?? \BccHttp::response(200, '{"code":0,"data":{"info":{}}}');
    }

    protected function now(): int
    {
        return 1_700_000_000;
    }

    protected function clock(): float
    {
        return $this->clockNow;
    }

    protected function sleepMicros(int $micros): void
    {
        $this->slept[] = $micros;
        $this->clockNow += $micros / 1_000_000;
    }
}
