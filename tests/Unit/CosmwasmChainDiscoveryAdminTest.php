<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Admin\NftDiscoveryPage;
use BCC\Trust\Onchain\Admin\VerifyCollectionsPage;
use BCC\Trust\Onchain\Admin\Views\CosmwasmScannerPanel;
use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\CollectionRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmCodeFamilyRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmContractRepository;
use BCC\Trust\Onchain\Repositories\NftSpamContractRepository;
use BCC\Trust\Onchain\Services\CosmwasmDiscoveryHealthSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The per-chain CosmWasm discovery opt-in: the operator CONTROL and the
 * panel STATUS that explains it.
 *
 * ── WHAT THIS FILE IS DEFENDING ─────────────────────────────────────────
 * `wp_bcc_chains.cosmwasm_nft_discovery_enabled` is one of the five
 * conditions {@see \BCC\Trust\Onchain\Workers\CosmwasmDiscoveryWorker}
 * intersects before it will scan a chain, and it ships 0 on every row.
 * Two things can go wrong with putting a switch on it, and they are
 * opposites:
 *
 *   1. THE WRITE DOES TOO MUCH. A chain row also carries `is_active`, the
 *      RPC/LCD endpoints and the Hall identity fields. An operator turning
 *      the scanner off for a chain must not be able to deactivate it,
 *      blank its endpoints or lose its description — every one of those
 *      would break wallet linking, holdings or Halls, and none of them
 *      would look like a scanner bug afterwards.
 *
 *   2. THE DISPLAY SAYS TOO MUCH. The panel lists every active Cosmos
 *      chain. If a row that will never be scanned looks the same as one
 *      that will — or worse, if a field the panel cannot read defaults to
 *      "opted in" — the operator concludes a chain is covered when it is
 *      not, which is the failure this whole feature exists to make
 *      visible.
 *
 * So the write is asserted to be surgical (every other column byte-for-
 * byte identical), and the display is asserted to fail CLOSED: exactly one
 * value means "will be scanned", and an absent field is never it.
 *
 * ── AND BOTH GATES, PROVEN SEPARATELY ───────────────────────────────────
 * A nonce proves the request came from our form. A capability proves the
 * person may use it. Neither implies the other, so each refusal is proven
 * on its own with the other gate satisfied — a test that failed both at
 * once would still pass if one of the two checks were deleted.
 *
 * Isolation: the cosmwasm-discovery stubs. Every repository faked at its
 * production FQN inside a subprocess, no database, no network. The real
 * classes under test are VerifyCollectionsPage, CosmwasmScannerPanel and
 * CosmwasmDiscoveryHealthSnapshot.
 */
#[CoversClass(VerifyCollectionsPage::class)]
#[CoversClass(CosmwasmScannerPanel::class)]
#[CoversClass(CosmwasmDiscoveryHealthSnapshot::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CosmwasmChainDiscoveryAdminTest extends TestCase
{
    private const CHAIN_ID = 8;
    private const SLUG     = 'cosmos';

    /** A second chain, so "only the one you named" is checkable. */
    private const OTHER_CHAIN_ID = 9;
    private const OTHER_SLUG     = 'juno';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/cosmwasm-discovery-stubs.php';

        CosmwasmCodeFamilyRepository::reset();
        CosmwasmContractRepository::reset();
        CollectionRepository::reset();
        ChainCheckpointRepository::reset();
        ChainRepository::reset();
        NftSpamContractRepository::reset();
        \BCC\Core\Log\Logger::reset();
        \BccTestCronStore::reset();
        \BccTestCapabilities::reset();

        // Both chains ship DISABLED, which is the production default the
        // migration guarantees. A test that wants one enabled says so.
        ChainRepository::seed(self::CHAIN_ID, self::SLUG, 'https://a.example', 'cosmos', 0);
        ChainRepository::seed(self::OTHER_CHAIN_ID, self::OTHER_SLUG, 'https://b.example', 'cosmos', 0);

        $_POST = [];
    }

    protected function tearDown(): void
    {
        $_POST = [];
        parent::tearDown();
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /**
     * Post one admin action through the REAL dispatcher, exactly as the
     * browser does.
     *
     * @return list<array{type: string, message: string}>
     */
    private function post(string $action, string $nonce = 'test-nonce'): array
    {
        // VC-B2: the discovery opt-in LEFT the Verify Collections
        // dispatcher for Chains ▸ NFT Discovery ▸ CosmWasm / CW-721, so
        // driving handlePost() here would now exercise the fail-closed
        // refusal instead of the write.
        //
        // What this file protects is the DOMAIN half — the surgical
        // single-column write, the cache bust, the read-back, and the
        // refusal to claim success it cannot confirm. That lives in
        // NftDiscoveryPage::apply_cw_discovery(), which is exactly what the new
        // route calls, so every assertion below keeps its meaning.
        //
        // The TRANSPORT half — capability, POST-only, direction- and
        // chain-scoped nonces, PRG, replay — is pinned by
        // ChainsCwDiscoveryRouteTest, which loads the admin shims this stub
        // family deliberately does not carry.
        $enable  = strpos($action, 'cw_discovery_on_') === 0;
        $chainId = (int) substr($action, strrpos($action, '_') + 1);

        $handler = new ReflectionMethod(NftDiscoveryPage::class, 'apply_cw_discovery');
        $handler->setAccessible(true);

        $result = (string) $handler->invoke(null, $chainId, $enable);

        // Rebuild the operator notice through the REAL production builder,
        // exactly as the PRG redirect does. A test-local copy of the copy
        // would assert wording nobody ships.
        $_GET = ['bcc_cwd' => $result];

        $notice = new ReflectionMethod(NftDiscoveryPage::class, 'cw_discovery_notice_from_query');
        $notice->setAccessible(true);

        /** @var array{type: string, message: string}|null $built */
        $built = $notice->invoke(null);

        return $built === null ? [] : [$built];
    }

    /** The opt-in as the registry currently holds it. */
    private function flag(int $chainId = self::CHAIN_ID): ?string
    {
        $chain = ChainRepository::getById($chainId);

        return $chain === null ? null : (string) $chain->cosmwasm_nft_discovery_enabled;
    }

    /**
     * Every property of a chain row, so "nothing else moved" is a
     * comparison rather than a hope.
     *
     * @return array<string, mixed>
     */
    private function rowSnapshot(int $chainId): array
    {
        $chain = ChainRepository::getById($chainId);
        self::assertNotNull($chain, "chain {$chainId} must exist to be snapshotted");

        return get_object_vars($chain);
    }

    /** @param list<array{type: string, message: string}> $notices */
    private function joined(array $notices): string
    {
        return implode(' ', array_column($notices, 'message'));
    }

    /** @param list<array{type: string, message: string}> $notices */
    private function types(array $notices): string
    {
        return implode(',', array_column($notices, 'type'));
    }

    /** @return list<array<string, mixed>> the audit lines at one level */
    private function logLines(string $level): array
    {
        return array_values(array_filter(
            \BCC\Core\Log\Logger::$lines,
            static fn(array $line): bool => $line['level'] === $level
        ));
    }

    private function renderPanel(): string
    {
        ob_start();
        CosmwasmScannerPanel::render(CosmwasmDiscoveryHealthSnapshot::buildSummary());
        $html = ob_get_clean();

        self::assertIsString($html);

        return $html;
    }

    /**
     * The CANONICAL per-chain surface, rendered from the same live summary.
     *
     * ── WHY THIS HELPER EXISTS ──────────────────────────────────────────
     * VC-B3b moved the per-chain table out of the scanner panel and into
     * Chains ▸ NFT Discovery ▸ CosmWasm / CW-721 Discovery. The eligibility
     * assertions below were written against the panel and are NOT deleted:
     * they follow the surface, with their scenarios intact.
     *
     * Keeping them here rather than folding them into
     * ChainsNftDiscoveryTabTest is deliberate. That file renders from a
     * FAKE snapshot, which is right for its own job — proving the renderer
     * prints what it is handed. These go the whole way: seeded chain rows
     * and checkpoints through the real buildSummary() and out to the
     * markup, which is the only way an eligibility verdict that is correct
     * in isolation but wrong end to end gets caught.
     */
    private function renderDiscovery(): string
    {
        $chains = CosmwasmDiscoveryHealthSnapshot::buildSummary()['chains'];
        self::assertIsArray($chains);

        ob_start();
        NftDiscoveryPage::render_cw_discovery_section(array_values($chains));
        $html = ob_get_clean();

        self::assertIsString($html);

        return $html;
    }

    /** The panel row for one chain, as the summary describes it. */
    private function summaryRow(int $chainId): array
    {
        foreach (CosmwasmDiscoveryHealthSnapshot::buildSummary()['chains'] as $row) {
            if ($row['chain_id'] === $chainId) {
                return $row;
            }
        }

        self::fail("chain {$chainId} is not in the summary");
    }

    // ═══════════════════════════════════════════════════════════════════
    //  AUTHORISATION — both gates, each proven alone
    // ═══════════════════════════════════════════════════════════════════

    /** The disable direction is gated identically — it is a write too. */
    // ═══════════════════════════════════════════════════════════════════
    //  THE WRITE — surgical, confirmed, and nothing else
    // ═══════════════════════════════════════════════════════════════════

    public function test_enabling_moves_the_opt_in_and_nothing_else_on_the_row(): void
    {
        $before = $this->rowSnapshot(self::CHAIN_ID);
        self::assertSame('0', $before['cosmwasm_nft_discovery_enabled'], 'precondition: ships disabled');

        $notices = $this->post('cw_discovery_on_' . self::CHAIN_ID);

        self::assertSame('success', $notices[0]['type']);
        self::assertSame('1', $this->flag());

        $after = $this->rowSnapshot(self::CHAIN_ID);

        // EVERY other property, byte for byte. Not a spot-check of the
        // fields somebody happened to think of.
        unset($before['cosmwasm_nft_discovery_enabled'], $after['cosmwasm_nft_discovery_enabled']);
        self::assertSame(
            $before,
            $after,
            'the discovery toggle must touch exactly one column — everything else on the chain row is somebody '
                . 'else\'s (wallet linking, holdings, validators, Halls)'
        );

        // …and named explicitly, because these are the ones whose loss
        // would break something far away from this page.
        self::assertSame('1', (string) $after['is_active'], 'the chain must stay active');
        self::assertSame('https://a.example', (string) $after['rest_url'], 'the endpoint must be untouched');
        self::assertSame('https://cdn.example/cosmos.png', (string) $after['icon_url'], 'Hall identity: icon');
        self::assertSame('#123456', (string) $after['color'], 'Hall identity: colour');
        self::assertSame('About cosmos.', (string) $after['description'], 'Hall identity: description');
    }

    public function test_disabling_moves_the_opt_in_and_nothing_else_on_the_row(): void
    {
        ChainRepository::seed(self::CHAIN_ID, self::SLUG, 'https://a.example', 'cosmos', 1);
        $before = $this->rowSnapshot(self::CHAIN_ID);

        $notices = $this->post('cw_discovery_off_' . self::CHAIN_ID);

        self::assertSame('success', $notices[0]['type']);
        self::assertStringContainsString('Future automatic passes will not select this chain', $notices[0]['message']);
        self::assertStringContainsString('Existing inventory and progress were kept', $notices[0]['message']);
        self::assertStringContainsString('no collection was unverified or removed', $notices[0]['message']);
        self::assertSame('0', $this->flag());

        $after = $this->rowSnapshot(self::CHAIN_ID);
        unset($before['cosmwasm_nft_discovery_enabled'], $after['cosmwasm_nft_discovery_enabled']);
        self::assertSame($before, $after);
    }

    public function test_the_toggle_touches_only_the_chain_it_names(): void
    {
        $otherBefore = $this->rowSnapshot(self::OTHER_CHAIN_ID);

        $this->post('cw_discovery_on_' . self::CHAIN_ID);

        self::assertSame('1', $this->flag(self::CHAIN_ID));
        self::assertSame('0', $this->flag(self::OTHER_CHAIN_ID), 'the neighbouring chain must be untouched');
        self::assertSame($otherBefore, $this->rowSnapshot(self::OTHER_CHAIN_ID));

        self::assertSame(
            [['chain_id' => self::CHAIN_ID, 'enabled' => true]],
            ChainRepository::$discoveryWrites,
            'exactly one write, naming exactly one chain'
        );
    }

    /**
     * THE CACHE BUST. `getActive()` — which is what the worker's
     * eligibility chokepoint reads — is served from a 5-minute
     * object-cache/transient pair. A toggle that did not invalidate would
     * leave the scanner working from the OLD answer for the rest of the
     * TTL while this page displayed the new one, so the operator would
     * have no way to notice.
     *
     * The invalidation lives INSIDE
     * ChainRepository::setCosmwasmNftDiscoveryEnabled() precisely so no
     * caller can forget it; this asserts the control goes through that
     * path. (The real cache being genuinely cold afterwards is pinned
     * against MySQL in ChainCosmwasmDiscoveryFlagIntegrationTest.)
     */
    public function test_the_toggle_busts_the_chain_cache(): void
    {
        self::assertSame(0, ChainRepository::$cacheBusts, 'precondition');

        $this->post('cw_discovery_on_' . self::CHAIN_ID);

        self::assertGreaterThan(
            0,
            ChainRepository::$cacheBusts,
            'a stale chains cache keeps the scanner working from the pre-toggle answer'
        );
    }

    /**
     * End to end: the control changes what the ELIGIBILITY VIEW says, not
     * merely what a column holds. A write that landed but left the panel
     * (and therefore the worker's view of the world) unchanged would be
     * indistinguishable from no write at all.
     */
    public function test_the_toggle_changes_the_eligibility_the_panel_reports(): void
    {
        self::assertSame(
            CosmwasmDiscoveryHealthSnapshot::ELIGIBILITY_NOT_OPTED_IN,
            $this->summaryRow(self::CHAIN_ID)['eligibility'],
            'precondition: not opted in'
        );

        $this->post('cw_discovery_on_' . self::CHAIN_ID);

        self::assertSame(
            CosmwasmDiscoveryHealthSnapshot::ELIGIBILITY_ELIGIBLE,
            $this->summaryRow(self::CHAIN_ID)['eligibility']
        );
        self::assertTrue($this->summaryRow(self::CHAIN_ID)['discovery_opted_in']);
        self::assertSame(1, CosmwasmDiscoveryHealthSnapshot::buildSummary()['eligible_chain_count']);

        $this->post('cw_discovery_off_' . self::CHAIN_ID);

        self::assertSame(
            CosmwasmDiscoveryHealthSnapshot::ELIGIBILITY_NOT_OPTED_IN,
            $this->summaryRow(self::CHAIN_ID)['eligibility'],
            'and back — the disable direction is the one with teeth'
        );
        self::assertSame(0, CosmwasmDiscoveryHealthSnapshot::buildSummary()['eligible_chain_count']);
    }

    public function test_the_transition_is_audit_logged_with_the_actor_and_both_states(): void
    {
        $this->post('cw_discovery_on_' . self::CHAIN_ID);

        $info = array_values(array_filter(
            $this->logLines('info'),
            static fn(array $line): bool => ($line['context']['action'] ?? null) === 'cosmwasm_chain_discovery_toggle'
        ));

        self::assertCount(1, $info, 'exactly one audit line per transition');
        self::assertSame(self::CHAIN_ID, $info[0]['context']['chain_id'] ?? null);
        self::assertSame(self::SLUG, $info[0]['context']['chain'] ?? null);
        self::assertFalse($info[0]['context']['enabled_before'] ?? null, 'old value');
        self::assertTrue($info[0]['context']['enabled_after'] ?? null, 'new value');
        self::assertSame(1, $info[0]['context']['operator'] ?? null, 'who did it');
    }

    // ═══════════════════════════════════════════════════════════════════
    //  WHEN THE WRITE DOES NOT LAND
    // ═══════════════════════════════════════════════════════════════════

    /**
     * The database refused. The operator is told NOTHING happened and what
     * state the chain is still in — a failed write that reports success is
     * how a chain silently stays out of (or in) the rotation.
     */
    public function test_a_failed_write_reports_an_error_and_never_claims_success(): void
    {
        ChainRepository::$rejectWrites = true;

        $notices = $this->post('cw_discovery_on_' . self::CHAIN_ID);

        self::assertSame('error', $this->types($notices));
        self::assertStringContainsString('database write failed', $notices[0]['message']);
        self::assertStringContainsString('could NOT be enabled', $notices[0]['message']);
        self::assertStringContainsString('nothing was changed', $notices[0]['message']);
        self::assertStringContainsString('It remains disabled', $notices[0]['message']);
        self::assertStringContainsString('It remains disabled', $notices[0]['message']);

        self::assertSame('0', $this->flag(), 'the flag genuinely did not move');

        $errors = $this->logLines('error');
        self::assertNotSame([], $errors);
        self::assertSame('cosmwasm_chain_discovery_write_failed', $errors[0]['context']['action'] ?? null);
    }

    /**
     * The quieter failure: the statement RAN and reported no error, but
     * the value did not move. `$wpdb->query()` not returning false says
     * the SQL executed, not that the row now holds what was asked for —
     * which is exactly why the handler reads the flag back instead of
     * trusting the return value.
     */
    public function test_a_write_that_does_not_stick_is_not_reported_as_done(): void
    {
        ChainRepository::$swallowWrites = true;

        $notices = $this->post('cw_discovery_on_' . self::CHAIN_ID);

        self::assertSame('error', $this->types($notices), 'an unconfirmed change is not a success');
        self::assertStringContainsString('does not read back as enabled', $notices[0]['message']);
        self::assertStringContainsString('could NOT be confirmed', $notices[0]['message']);
        self::assertStringContainsString('does not read back as enabled', $notices[0]['message']);
        self::assertStringNotContainsString('remains', $notices[0]['message']);

        self::assertSame('0', $this->flag(), 'the flag really did not move — that is the point');

        $errors = $this->logLines('error');
        self::assertNotSame([], $errors);
        self::assertSame('cosmwasm_chain_discovery_unconfirmed', $errors[0]['context']['action'] ?? null);

        // And no audit line claims a transition that did not happen.
        self::assertSame([], array_values(array_filter(
            $this->logLines('info'),
            static fn(array $line): bool => ($line['context']['action'] ?? null) === 'cosmwasm_chain_discovery_toggle'
        )));
    }

    public function test_an_unknown_chain_is_refused_before_any_write(): void
    {
        $notices = $this->post('cw_discovery_on_999999');

        self::assertSame('error', $notices[0]['type']);
        self::assertStringContainsString('chain not found', $notices[0]['message']);
        self::assertSame([], ChainRepository::$discoveryWrites);
    }

    /**
     * A double-submit, a stale tab, a back-button re-post. It must not
     * report a transition that did not occur, and it must not write.
     */
    public function test_repeating_the_current_state_changes_nothing_and_says_so(): void
    {
        $this->post('cw_discovery_on_' . self::CHAIN_ID);
        ChainRepository::$discoveryWrites = [];
        \BCC\Core\Log\Logger::reset();

        $notices = $this->post('cw_discovery_on_' . self::CHAIN_ID);

        self::assertSame('info', $notices[0]['type']);
        self::assertStringContainsString('was already enabled', $notices[0]['message']);
        self::assertSame([], ChainRepository::$discoveryWrites, 'a no-op must not write');
        self::assertSame('1', $this->flag());
        self::assertSame([], $this->logLines('info'), 'and must not claim a transition in the audit log');
    }

    // ═══════════════════════════════════════════════════════════════════
    //  THE PANEL — four status cases, each named
    // ═══════════════════════════════════════════════════════════════════

    public function test_the_discovery_row_reports_an_eligible_chain(): void
    {
        ChainRepository::reset();
        ChainRepository::seed(self::CHAIN_ID, self::SLUG, 'https://a.example', 'cosmos', 1);

        $html = $this->renderDiscovery();

        self::assertStringContainsString('Eligible', $html);
        self::assertStringContainsString('Enabled', $html, 'the operator setting is shown');
        self::assertStringContainsString('Nothing is blocking this chain', $html);
    }

    /**
     * The panel keeps the AGGREGATE half of the same fact.
     *
     * "1 of 1 listed chains are eligible" is a scanner-wide number that
     * NFT Discovery, being one row per chain, never states. It lived
     * inside the deleted table's renderer and was restored into the
     * retained aggregates rather than lost with it — which is what this
     * asserts, alongside the panel no longer offering the mutation.
     */
    public function test_the_panel_keeps_the_scanner_wide_eligible_count(): void
    {
        ChainRepository::reset();
        ChainRepository::seed(self::CHAIN_ID, self::SLUG, 'https://a.example', 'cosmos', 1);

        $html = $this->renderPanel();

        self::assertStringContainsString('<strong>1 of 1</strong>', self::flatten($html));
        self::assertStringContainsString('page=' . NftDiscoveryPage::PAGE_SLUG, $html);
        self::assertStringNotContainsString('cw_discovery_off_', $html);
        self::assertStringNotContainsString('Disable discovery', $html, 'the mutation moved to Chains ▸ NFT Discovery');
    }

    /** Collapse HTML whitespace so an assertion can span line breaks. */
    private static function flatten(string $html): string
    {
        return (string) preg_replace('/\s+/', ' ', $html);
    }

    /**
     * The PROSE the operator reads: tags stripped, entities decoded,
     * whitespace collapsed.
     *
     * {@see flatten()} keeps the markup, which is what an assertion about a
     * badge colour or a notice class needs. A sentence signed off word for
     * word cannot be asserted that way — `<strong>` sits in the middle of
     * the first one — and asserting the fragments either side of the tag
     * instead is how the previous version of this copy passed its tests
     * while naming only two of the three ways a selection can be
     * unscannable.
     */
    private static function prose(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    public function test_the_discovery_row_reports_a_chain_nobody_opted_in(): void
    {
        ChainRepository::reset();
        ChainRepository::seed(self::CHAIN_ID, self::SLUG, 'https://a.example', 'cosmos', 0);

        $html = $this->renderDiscovery();

        self::assertStringContainsString('Not opted in', $html);
        self::assertStringContainsString('Disabled', $html, 'the operator setting is shown');
        self::assertStringContainsString('Discovery is switched off for this chain', $html);
        self::assertStringNotContainsString('Nothing is blocking this chain', $html);

        // The panel no longer carries the legacy transport for either
        // direction, and points at the canonical page instead.
        $panel = $this->renderPanel();
        self::assertStringNotContainsString('cw_discovery_on_', $panel);
        self::assertStringContainsString('page=' . NftDiscoveryPage::PAGE_SLUG, $panel);
    }

    /**
     * MEASURED capability, not operator intent: the chain answered the
     * code listing with a 501. It is opted IN here on purpose — the point
     * is that opting in cannot override a fact about the chain, and the
     * panel must say so rather than showing a green row that never moves.
     */
    public function test_the_discovery_row_reports_a_chain_with_no_wasm_module(): void
    {
        ChainRepository::reset();
        ChainRepository::seed(self::CHAIN_ID, self::SLUG, 'https://a.example', 'cosmos', 1);
        ChainCheckpointRepository::setCwDiscoveryState(
            self::CHAIN_ID,
            ChainCheckpointRepository::CW_STATE_UNSUPPORTED
        );

        $html = $this->renderDiscovery();

        self::assertStringContainsString('No CosmWasm module', $html);
        self::assertStringContainsString('Enabled', $html, 'the opt-in is still shown honestly');
        self::assertStringNotContainsString('Nothing is blocking this chain', $html);

        // VC-B3b: an unsupported chain is offered NO scanner operation —
        // there is no pass to pause, resume, backfill or retry.
        foreach ([
            NftDiscoveryPage::ACTION_CW_PAUSE,
            NftDiscoveryPage::ACTION_CW_RESUME,
            NftDiscoveryPage::ACTION_CW_BACKFILL,
            NftDiscoveryPage::ACTION_CW_RETRY,
        ] as $route) {
            self::assertStringNotContainsString($route, $html);
        }
        self::assertStringContainsString('nothing to pause, resume, backfill or retry', $html);

        // THE PERMANENCE CLAIM, AND ITS LIMITS. The code does prove this
        // state is terminal to everything automated — prepareChain()
        // refuses it, the shared eligibility verdict excludes it,
        // pauseCwDiscovery() refuses when it is already set, and
        // resumeCwDiscovery() only accepts `paused`, so no control on this
        // page can clear it. The copy claims exactly that and no more: a
        // direct database change is still possible, and a sentence that
        // denied it would be wrong.
        $flat = self::flatten($html);
        self::assertStringContainsString('it answered the code listing with a 501', $flat);
        self::assertStringContainsString('No pass retries that verdict', $flat);
        self::assertStringContainsString('no control on this page clears it', $flat);
        self::assertStringContainsString('only a direct database change would', $flat);

        // Still reversible: an opted-in unsupported chain must not be
        // stranded with no way to switch it back off. The opt-out control
        // is present — on the canonical surface, and only there.
        self::assertStringContainsString(NftDiscoveryPage::ACTION_CW_DISCOVERY_DISABLE, $html);

        $panel = $this->renderPanel();
        self::assertStringNotContainsString('cw_discovery_off_', $panel);
        self::assertStringContainsString('page=' . NftDiscoveryPage::PAGE_SLUG, $panel);
    }

    /**
     * The canary scope. The chain is opted in AND supported, and is still
     * not scanned — the case with no other visible symptom whatsoever,
     * which is exactly why it needs saying out loud.
     */
    public function test_the_discovery_row_reports_a_chain_outside_the_canary_allowlist(): void
    {
        define('BCC_COSMWASM_CHAIN_ALLOWLIST', '4321');

        ChainRepository::reset();
        ChainRepository::seed(self::CHAIN_ID, self::SLUG, 'https://a.example', 'cosmos', 1);

        $html = $this->renderDiscovery();

        self::assertStringContainsString('Outside canary scope', $html);
        self::assertStringContainsString('BCC_COSMWASM_CHAIN_ALLOWLIST names only chain 4321', $html);
        self::assertStringNotContainsString('Nothing is blocking this chain', $html);
        self::assertSame(0, CosmwasmDiscoveryHealthSnapshot::buildSummary()['eligible_chain_count']);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  THE PANEL — nobody opted anything in
    // ═══════════════════════════════════════════════════════════════════

    /**
     * The setUp state IS the zero-opt-in state: both chains ship disabled,
     * which is the production default the migration guarantees. Every
     * install therefore starts here, and it used to read YELLOW — a
     * degraded verdict handed to an operator on day one, about a system
     * behaving exactly as configured.
     */
    public function test_no_chain_opted_in_reports_idle_and_no_other_verdict(): void
    {
        $status = CosmwasmDiscoveryHealthSnapshot::buildSummary()['status'];

        self::assertSame(CosmwasmDiscoveryHealthSnapshot::STATUS_IDLE, $status);
        self::assertNotSame(CosmwasmDiscoveryHealthSnapshot::STATUS_GREEN, $status, 'idle is not healthy');
        self::assertNotSame(CosmwasmDiscoveryHealthSnapshot::STATUS_YELLOW, $status, 'idle is not degraded');
        self::assertNotSame(CosmwasmDiscoveryHealthSnapshot::STATUS_RED, $status, 'idle is not failed');
    }

    /**
     * The rendered half. The copy has to say the state out loud AND carry
     * none of the treatments this panel reserves for trouble: no error
     * notice, no warning notice, no alarm colour on the badge, and no
     * forcing the whole disclosure open the way RED and UNAVAILABLE do.
     *
     * The constant is still named. Idle outranking `disabled` is about
     * which fact leads, not about hiding the other one.
     */
    public function test_the_idle_panel_says_so_without_dressing_it_up_as_a_fault(): void
    {
        $html = $this->renderPanel();
        $flat = self::flatten($html);

        self::assertStringContainsString('Idle — no chains enabled for NFT discovery.', $html);
        self::assertStringContainsString('This is a configuration state, not a fault', $flat);

        // The badge is the NEUTRAL grey, and it says IDLE. Asserted as one
        // string so it cannot be satisfied by a grey badge somewhere else
        // on the page (every eligibility pill is grey here too).
        self::assertStringContainsString('background:#646970;"> IDLE', $flat);

        // None of the treatments that mean trouble.
        self::assertStringNotContainsString('notice-error', $html);
        self::assertStringNotContainsString('notice-warning', $html);
        self::assertStringNotContainsString('Scanner figures are unavailable', $html);
        self::assertStringNotContainsString('YELLOW', $html);
        self::assertStringNotContainsString('RED', $html);

        // And it does not force the disclosure open — that is reserved for
        // states an operator has to deal with now.
        self::assertSame(1, preg_match('/<details class="bcc-cw-scanner"[^>]*>/', $html, $tag));
        self::assertStringNotContainsString('open', $tag[0], 'idle must not shove the panel open');

        // Nothing is hidden: the undefined gate is still named, and the
        // per-chain controls are still there to act on.
        self::assertStringContainsString('BCC_COSMWASM_DISCOVERY_ENABLED', $html);
        self::assertStringContainsString('page=' . NftDiscoveryPage::PAGE_SLUG, $html);
        self::assertStringContainsString('Code families known', $html);
    }

    /**
     * The exit from idle, through the real control. One opt-in is enough:
     * the scanner now has somewhere to go, so the ordinary arithmetic —
     * and, here, the still-undefined gate — takes the verdict back.
     */
    public function test_opting_one_chain_in_takes_the_panel_out_of_idle(): void
    {
        $this->post('cw_discovery_on_' . self::CHAIN_ID);

        $status = CosmwasmDiscoveryHealthSnapshot::buildSummary()['status'];

        self::assertNotSame(CosmwasmDiscoveryHealthSnapshot::STATUS_IDLE, $status);
        self::assertSame(
            CosmwasmDiscoveryHealthSnapshot::STATUS_DISABLED,
            $status,
            'with a chain opted in, the undefined gate is the operative fact again'
        );
        self::assertStringNotContainsString('Idle — no chains enabled', $this->renderPanel());
    }

    // ═══════════════════════════════════════════════════════════════════
    //  THE PANEL — opted in, and none of it can be scanned
    // ═══════════════════════════════════════════════════════════════════

    /**
     * THE LIVE SHAPE, end to end through buildSummary(): one chain opted
     * in and unsupported, one supported chain nobody opted in. Reproduced
     * on staging, where it derived GREEN — the arithmetic counted the
     * chain the operator had left switched off as an eligible, healthy
     * chain, and reported the scanner fine while its entire selection was
     * unscannable.
     *
     * It also pins the precedence: the gate constant is undefined in this
     * process, and `blocked` still leads. Defining it would change nothing
     * while no opted-in chain can be scanned, so the constant is the less
     * useful of the two facts — the copy still names it.
     */
    public function test_an_opted_in_chain_with_no_wasm_module_reports_blocked(): void
    {
        ChainRepository::seed(self::CHAIN_ID, self::SLUG, 'https://a.example', 'cosmos', 1);
        ChainCheckpointRepository::setCwDiscoveryState(
            self::CHAIN_ID,
            ChainCheckpointRepository::CW_STATE_UNSUPPORTED
        );

        $status = CosmwasmDiscoveryHealthSnapshot::buildSummary()['status'];

        self::assertSame(CosmwasmDiscoveryHealthSnapshot::STATUS_BLOCKED, $status);
        self::assertNotSame(CosmwasmDiscoveryHealthSnapshot::STATUS_GREEN, $status, 'an unscannable selection is not healthy');
        self::assertNotSame(CosmwasmDiscoveryHealthSnapshot::STATUS_IDLE, $status, 'a selection was made — this is not idle');
        self::assertNotSame(CosmwasmDiscoveryHealthSnapshot::STATUS_DISABLED, $status, 'the constant is not the operative fact here');
    }

    /**
     * The rendered half. It has to say the state out loud, say what to do
     * about it, and stop short of calling it a failure — nothing broke.
     * It is not given the calm treatment idle gets either: there IS
     * something to change, so it takes the amber and forces the
     * disclosure open the way the other actionable states do.
     */
    public function test_the_blocked_panel_says_what_to_do_without_calling_it_a_failure(): void
    {
        ChainRepository::seed(self::CHAIN_ID, self::SLUG, 'https://a.example', 'cosmos', 1);
        ChainCheckpointRepository::setCwDiscoveryState(
            self::CHAIN_ID,
            ChainCheckpointRepository::CW_STATE_UNSUPPORTED
        );

        $html = $this->renderPanel();
        $flat = self::flatten($html);

        // THE OPERATOR COPY, VERBATIM, as one flattened string. Asserted
        // whole rather than in fragments because it is the sentence the
        // owner signed off, and because the version it replaces was wrong
        // in a way fragments would not have caught: it named two of the
        // three ways a selection can be unscannable and omitted the
        // allowlist — which was the one actually in play.
        self::assertStringContainsString(
            'No opted-in chain can currently be scanned. '
                . 'Every chain enabled for NFT discovery is paused, marked as lacking CosmWasm support, '
                . 'or outside the current canary allowlist. '
                . 'Nothing has failed and nothing is behind—the current selection cannot produce any scanner work. '
                . 'Enable an eligible chain, resume a paused chain, or update the canary allowlist. '
                // VC-B3b: the columns this sentence sends the operator to
                // moved to Chains ▸ NFT Discovery, so the sentence had to
                // move with them. Directions to a table that is no longer
                // there are worse than no directions.
                . 'The Discovery and State columns in Chains ▸ NFT Discovery show why each chain is excluded.',
            self::prose($html)
        );

        // Amber, and it says BLOCKED. Asserted as one string so a stray
        // amber pill elsewhere on the page cannot satisfy it.
        self::assertStringContainsString('background:#dba617;"> BLOCKED', $flat);

        // Actionable, not alarming: a warning notice, never an error one,
        // and never the unavailable treatment.
        self::assertStringContainsString('notice-warning', $html);
        self::assertStringNotContainsString('notice-error', $html);
        self::assertStringNotContainsString('Scanner figures are unavailable', $html);

        // It DOES force the disclosure open — unlike idle, something is
        // waiting on the operator here.
        self::assertSame(1, preg_match('/<details class="bcc-cw-scanner"[^>]*>/', $html, $tag));
        self::assertStringContainsString('open', $tag[0], 'blocked must not hide behind a closed disclosure');

        // Nothing is hidden: the undefined gate is still named, the row
        // says WHICH chain is in the way, and the controls are there.
        self::assertStringContainsString('BCC_COSMWASM_DISCOVERY_ENABLED', $html);
        self::assertStringContainsString('page=' . NftDiscoveryPage::PAGE_SLUG, $html);
        // WHICH chain is in the way is a per-chain fact, and lives with
        // the per-chain row on the canonical surface.
        self::assertStringContainsString('No CosmWasm module', $this->renderDiscovery());
    }

    /**
     * The exit from blocked, and the mixed case at panel level: opt a
     * SCANNABLE chain in beside the unsupported one and the verdict goes
     * back to the ordinary arithmetic — here, to the still-undefined gate.
     * The unsupported chain does not disappear; it keeps its own row and
     * its own reason, which is the distinction the panel exists to draw.
     */
    public function test_one_scannable_opt_in_takes_the_panel_out_of_blocked(): void
    {
        ChainRepository::seed(self::CHAIN_ID, self::SLUG, 'https://a.example', 'cosmos', 1);
        ChainCheckpointRepository::setCwDiscoveryState(
            self::CHAIN_ID,
            ChainCheckpointRepository::CW_STATE_UNSUPPORTED
        );
        ChainRepository::seed(self::OTHER_CHAIN_ID, self::OTHER_SLUG, 'https://b.example', 'cosmos', 1);

        $status = CosmwasmDiscoveryHealthSnapshot::buildSummary()['status'];

        self::assertNotSame(CosmwasmDiscoveryHealthSnapshot::STATUS_BLOCKED, $status);
        self::assertSame(
            CosmwasmDiscoveryHealthSnapshot::STATUS_DISABLED,
            $status,
            'with a scannable chain opted in, the undefined gate is the operative fact again'
        );

        $html = $this->renderPanel();

        self::assertStringNotContainsString('No opted-in chain can be scanned', $html);
        // The scanner-wide arithmetic stays on the panel…
        self::assertStringContainsString('<strong>1 of 2</strong>', self::flatten($html));
        // …and the unsupported chain is still named, one row at a time, on
        // the canonical per-chain surface.
        self::assertStringContainsString('No CosmWasm module', $this->renderDiscovery());
    }

    /**
     * THE FAIL-CLOSED CASE, and the reason the panel reads its eligibility
     * the pedantic way.
     *
     * The row here carries NO eligibility fields at all — the shape a
     * summary built by older code, or a partially-populated fixture,
     * produces. It must render as NOT eligible. If a missing field can
     * read as "opted in" or "eligible", the panel will confidently tell an
     * operator a chain is covered on the strength of a key nobody set.
     *
     * `Eligible` (capital E) appears in this panel ONLY as the eligible
     * badge — the summary line above the table says "are eligible" in
     * lower case — so its absence is a real assertion and not an accident
     * of wording. test_the_panel_reports_an_eligible_chain() is the
     * positive control that proves the string is printable at all.
     */
    public function test_a_chain_row_with_no_eligibility_fields_is_never_rendered_as_eligible(): void
    {
        $html = $this->renderPanelFor([
            'chain_id'                   => self::CHAIN_ID,
            'slug'                       => self::SLUG,
            'name'                       => 'Cosmos Hub',
            'state'                      => ChainCheckpointRepository::CW_STATE_IDLE,
            'state_label'                => 'Idle',
            'paused'                     => false,
            'unsupported'                => false,
            'progress_label'             => 'Never started.',
            'families_by_classification' => [],
            'last_discovery_age_seconds' => null,
            'last_error'                 => null,
            // NO 'eligibility', NO 'discovery_opted_in', NO
            // 'eligibility_reason'. That is the whole test.
        ]);

        self::assertStringContainsString('Unknown', $html);
        self::assertStringNotContainsString('Eligible', $html);
        self::assertStringNotContainsString('Nothing is blocking this chain', $html);
        self::assertStringContainsString('Disabled', $html);
        self::assertStringContainsString('could not be read', $html);
    }

    /**
     * The same pedantry against values that are merely truthy. `'1'` is
     * what a raw DB projection carries, and it must NOT satisfy a check
     * the snapshot is supposed to have already made — the panel prints a
     * decision, it does not make one.
     */
    public function test_a_truthy_but_non_boolean_opt_in_is_not_treated_as_opted_in(): void
    {
        $html = $this->renderPanelFor([
            'chain_id'                   => self::CHAIN_ID,
            'slug'                       => self::SLUG,
            'name'                       => 'Cosmos Hub',
            'state'                      => ChainCheckpointRepository::CW_STATE_IDLE,
            'state_label'                => 'Idle',
            'paused'                     => false,
            'unsupported'                => false,
            'progress_label'             => 'Never started.',
            'families_by_classification' => [],
            'last_discovery_age_seconds' => null,
            'last_error'                 => null,
            'discovery_opted_in'         => '1',
            'eligibility'                => '',
        ]);

        self::assertStringContainsString('Disabled', $html);
        self::assertStringContainsString('Unknown', $html);
        self::assertStringNotContainsString('Eligible', $html);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  THE PANEL — a failed read is still "unavailable"
    // ═══════════════════════════════════════════════════════════════════

    /**
     * The eligibility column must not become a back door around the
     * fail-closed rule the rest of the panel already obeys. When a
     * required read did not run there are no chain rows to describe, so
     * there is no eligibility to report either — and above all no "0 of 0
     * chains are eligible", which is a real and alarming state an operator
     * would act on.
     */
    public function test_the_panel_still_reports_unavailable_when_a_required_read_fails(): void
    {
        ChainRepository::reset();
        ChainRepository::seed(self::CHAIN_ID, self::SLUG, 'https://a.example', 'cosmos', 1);
        ChainCheckpointRepository::$failGetAll = true;

        $summary = CosmwasmDiscoveryHealthSnapshot::buildSummary();

        self::assertTrue($summary['data_unavailable']);
        self::assertSame(CosmwasmDiscoveryHealthSnapshot::STATUS_UNAVAILABLE, $summary['status']);
        self::assertNull(
            $summary['eligible_chain_count'],
            '"nobody could look" must not be reported as a count of eligible chains'
        );
        self::assertSame([], $summary['chains']);

        $html = $this->renderPanel();

        self::assertStringContainsString('Scanner figures are unavailable', $html);
        self::assertStringNotContainsString('Eligible', $html);
        self::assertStringNotContainsString('opt-in', $html);
        self::assertStringNotContainsString('cw_discovery_on_', $html);
        self::assertStringNotContainsString('listed chains are eligible', $html);
    }

    // ── helper: render the panel around ONE hand-built chain row ────────

    /**
     * A summary whose only interesting content is the chain row supplied.
     * Everything else is the minimum the panel needs, so an assertion
     * about the Discovery cell cannot accidentally be satisfied by
     * something else on the page.
     *
     * @param  array<string, mixed> $chain
     */
    private function renderPanelFor(array $chain): string
    {
        // VC-B3b: the per-chain row moved to Chains ▸ NFT Discovery, so the
        // fail-closed assertions below follow it. The row shape and the
        // property under test are unchanged: an absent or merely-truthy
        // opt-in must never render as opted in.
        ob_start();
        NftDiscoveryPage::render_cw_discovery_section([$chain]);
        $html = ob_get_clean();
        self::assertIsString($html);

        return $html;
    }

    /** @param array<string, mixed> $chain */
    private function renderPanelWithChain(array $chain): string
    {
        ob_start();
        CosmwasmScannerPanel::render([
            'discovery_enabled'    => true,
            'backfill_enabled'     => true,
            'disabled_reason'      => null,
            'status'               => CosmwasmDiscoveryHealthSnapshot::STATUS_GREEN,
            'data_unavailable'     => false,
            'unavailable_reason'   => null,
            'schedule'             => [],
            'chains'               => [$chain],
            'allowlist_chain_ids'  => null,
            'eligible_chain_count' => 0,
            'working_chain'        => null,
            'next_chain'           => null,
            'totals'               => [
                'families'                 => 0,
                'families_pending'         => 0,
                'families_cw721'           => 0,
                'families_not_cw721'       => 0,
                'families_inconclusive'    => 0,
                'contracts'                => 0,
                'contracts_inspected'      => 0,
                'candidates'               => 0,
                'candidates_awaiting_emit' => 0,
                'denied'                   => 0,
            ],
            'issues'               => [],
        ]);
        $html = ob_get_clean();

        self::assertIsString($html);

        return $html;
    }
}
