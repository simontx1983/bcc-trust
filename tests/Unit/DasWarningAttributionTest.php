<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Admin\AdminActionSupport;
use BCC\Trust\Onchain\Admin\SettingsPage;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Support\EndpointDescriptor;
use BCC\Trust\Onchain\Support\HeliusEndpoint;
use BCC\Trust\Onchain\Support\NftCapabilityOptionState;
use BCC\Trust\Onchain\Support\NftDriverRegistry;
use BCC\Trust\Onchain\Support\NftProviderReadiness;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * THE DAS WARNING PANEL MUST NOT CARRY ITS OWN COPY OF THE ATTRIBUTION RULE.
 *
 * ── THE DEFECT THIS PINS (#272) ─────────────────────────────────────────
 * The DAS-unsupported mark belongs to ONE endpoint.
 * `NftProviderReadiness::endpointRefusal()` applies `dasMarkApplies()` first,
 * so a mark left behind by an endpoint that is no longer configured reports
 * as ABSENT — which is what lets an operator clear it by repointing.
 *
 * `render_das_warnings()` read the option directly instead. It was therefore
 * a second copy of the rule, outside the class that owns it, and a copy that
 * never compared at all. The result was observable and it pointed the wrong
 * way: an operator repoints a Solana chain at a DAS-capable endpoint, the NFT
 * Discovery page and `isReady()` agree the refusal is gone, and this panel
 * went on announcing "NFT collection fetching is disabled" — telling them
 * their fix had failed when it had worked, with no way to clear it.
 *
 * ── WHY THESE CASES ASSERT ON RENDERED MARKUP ───────────────────────────
 * The predicate already has thorough coverage in DasMarkIdentityTest. What
 * was never covered is whether THIS SURFACE asks it. So the real private
 * renderer is invoked by reflection and its output is the observable, in the
 * manner of BreakerAdminObservationTest. Asserting on the option, or on
 * `endpointRefusal()` alone, would leave the bug exactly where it was.
 *
 * Everything that decides anything is the real class — see
 * tests/Stubs/das-warning-stubs.php for what is faked and what is not.
 */
#[CoversClass(SettingsPage::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class DasWarningAttributionTest extends TestCase
{
    private const CHAIN_ID = 77;

    /** The endpoint the mark was written against. */
    private const MARKED = 'https://das.example/v1/KEY-AAA';

    /** A different, DAS-capable endpoint the operator repoints to. */
    private const REPOINTED = 'https://das.example/v1/KEY-BBB';

    /** The sentence an operator must only ever see when it is true. */
    private const NOTICE = 'NFT collection fetching is disabled';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/das-warning-stubs.php';

        NftCapabilityOptionState::reset();
        ChainRepository::reset();
    }

    /** A Solana chain row shaped the way the real derivation reads it. */
    private static function solana(string $rpc, int $id = self::CHAIN_ID): object
    {
        return (object) [
            'id'                                  => (string) $id,
            'slug'                                => 'solana',
            'name'                                => 'Solana',
            'chain_type'                          => 'solana',
            'rpc_url'                             => $rpc,
            'rest_url'                            => '',
            'bcc_supports_nft_collections'        => '1',
            'manual_collection_discovery_enabled' => '1',
        ];
    }

    /** A chain no DAS driver serves — used to prove iteration continues. */
    private static function cosmos(int $id): object
    {
        return (object) [
            'id'                                  => (string) $id,
            'slug'                                => 'cosmoshub',
            'name'                                => 'Cosmos Hub',
            'chain_type'                          => 'cosmos',
            'rpc_url'                             => '',
            'rest_url'                            => 'https://lcd.example',
            'bcc_supports_nft_collections'        => '1',
            'manual_collection_discovery_enabled' => '1',
        ];
    }

    /**
     * Write the mark the way the production writer does — both fields, with
     * the identity built by the real EndpointDescriptor.
     */
    private static function mark(
        string $endpoint,
        int $chainId = self::CHAIN_ID,
        int $code = -32601,
        string $message = 'Method not found'
    ): void {
        NftCapabilityOptionState::$options[HeliusEndpoint::dasUnsupportedOptionKey($chainId)] = [
            'endpoint_display' => EndpointDescriptor::display($endpoint),
            'endpoint_id'      => EndpointDescriptor::identity($endpoint),
            'code'             => $code,
            'message'          => $message,
            'detected_at'      => 1700000000,
        ];
    }

    /** Render the real private panel and capture its markup. */
    private function render(): string
    {
        $m = new \ReflectionMethod(SettingsPage::class, 'render_das_warnings');
        $m->setAccessible(true);
        ob_start();
        $m->invoke(null);

        return (string) ob_get_clean();
    }

    private static function refusal(object $chain): ?array
    {
        return NftProviderReadiness::endpointRefusal($chain, NftDriverRegistry::DRIVER_DAS_RPC);
    }

    // ── 1. The positive control, so every absence below means something ──

    /**
     * The panel still warns while the mark attaches to the endpoint in use.
     *
     * This runs first because it is the anti-vacuity guard for the whole
     * file: a renderer that printed NOTHING, EVER would satisfy every
     * "notice is gone" assertion here.
     */
    public function testTheNoticeAppearsWhileTheMarkAttachesToTheEndpointInUse(): void
    {
        $chain = self::solana(self::MARKED);
        ChainRepository::seed([$chain]);
        self::mark(self::MARKED);

        self::assertNotNull(self::refusal($chain), 'precondition: the mark must apply');

        $html = $this->render();

        self::assertStringContainsString(self::NOTICE, $html);
        self::assertStringContainsString('Solana', $html);
        self::assertStringContainsString('-32601', $html);
        self::assertStringContainsString('Method not found', $html);
        self::assertStringContainsString('First detected', $html);
    }

    // ── 2. THE ISSUE, at the rendered surface ───────────────────────────

    /**
     * The notice is GONE once the chain points somewhere else.
     *
     * The mark is untouched in the option store — nothing clears it, and
     * nothing needs to. What changed is the endpoint it would be compared
     * against, and that is the whole fix.
     */
    public function testTheNoticeIsGoneAfterTheEndpointIsRepointed(): void
    {
        $marked = self::solana(self::MARKED);
        ChainRepository::seed([$marked]);
        self::mark(self::MARKED);
        self::assertStringContainsString(
            self::NOTICE,
            $this->render(),
            'precondition: the panel warns before the repoint'
        );

        // The operator repoints `chains.rpc_url`. The stored mark stays.
        $repointed = self::solana(self::REPOINTED);
        ChainRepository::seed([$repointed]);

        self::assertArrayHasKey(
            HeliusEndpoint::dasUnsupportedOptionKey(self::CHAIN_ID),
            NftCapabilityOptionState::$options,
            'the mark must still be stored — this is not a test about clearing it'
        );
        self::assertNull(self::refusal($repointed), 'the mark must no longer attach');

        $html = $this->render();

        self::assertStringNotContainsString(self::NOTICE, $html);
        self::assertStringNotContainsString('-32601', $html);
        self::assertStringNotContainsString('Method not found', $html);
    }

    /** And it comes back if they point it at the marked endpoint again. */
    public function testTheNoticeReturnsIfTheMarkedEndpointIsRestored(): void
    {
        self::mark(self::MARKED);

        ChainRepository::seed([self::solana(self::REPOINTED)]);
        self::assertStringNotContainsString(self::NOTICE, $this->render());

        ChainRepository::seed([self::solana(self::MARKED)]);
        self::assertStringContainsString(self::NOTICE, $this->render());
    }

    // ── 3. The panel and the predicate can no longer disagree ───────────

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     *         marked endpoint, endpoint in use, notice expected
     */
    public static function endpointStates(): array
    {
        return [
            'same endpoint'              => [self::MARKED, self::MARKED, true],
            'different path credential'  => [self::MARKED, self::REPOINTED, false],
            'different query credential' => ['https://das.example/?api-key=A', 'https://das.example/?api-key=B', false],
            'different host entirely'    => [self::MARKED, 'https://other.example/v1/KEY-AAA', false],
            'credential removed'         => [self::MARKED, 'https://das.example/v1/', false],
            'credential added'           => ['https://das.example/v1/', self::MARKED, false],
        ];
    }

    /**
     * THE ANTI-DIVERGENCE ASSERTION. For every state, the notice is present
     * if and only if `endpointRefusal()` returns something. Two admin
     * surfaces describing one chain at one moment cannot disagree if both are
     * derived from the same answer — and this is what makes that mechanical
     * rather than a promise in a comment.
     */
    #[DataProvider('endpointStates')]
    public function testThePanelAgreesWithThePredicateInEveryState(
        string $markedEndpoint,
        string $inUse,
        bool $expectNotice
    ): void {
        $chain = self::solana($inUse);
        ChainRepository::seed([$chain]);
        self::mark($markedEndpoint);

        $refusalExists = self::refusal($chain) !== null;
        $noticeShown   = str_contains($this->render(), self::NOTICE);

        self::assertSame(
            $expectNotice,
            $refusalExists,
            'the predicate itself must behave as this case expects'
        );
        self::assertSame(
            $refusalExists,
            $noticeShown,
            'the panel must say exactly what the predicate says'
        );
    }

    // ── 4. A legacy mark reads as absent here too ───────────────────────

    /**
     * A pre-#269 payload has `rpc_url` and NO `endpoint_id`, so it cannot be
     * matched to anything. Everywhere else that is treated as ABSENT — this
     * renderer displayed it, because it never checked.
     *
     * The direction matters: an unverifiable mark must not disable a driver
     * for ever, because the driver will re-mark itself if the endpoint really
     * cannot serve DAS.
     */
    public function testALegacyMarkWithNoIdentityShowsNoNotice(): void
    {
        ChainRepository::seed([self::solana(self::MARKED)]);
        NftCapabilityOptionState::$options[HeliusEndpoint::dasUnsupportedOptionKey(self::CHAIN_ID)] = [
            // Exactly the old shape: a query-only redaction, no identity.
            'rpc_url'     => 'https://das.example/v1/KEY-AAA',
            'code'        => -32601,
            'message'     => 'Method not found',
            'detected_at' => 1700000000,
        ];

        $html = $this->render();

        self::assertStringNotContainsString(self::NOTICE, $html);
        self::assertStringNotContainsString('KEY-AAA', $html, 'and the legacy value is never echoed');
    }

    // ── 5. Iteration still visits every active chain ────────────────────

    /**
     * `endpointRefusal()` returns null for a chain no DAS driver serves, so
     * the loop's skip had to become a null check. A `break`, or a skip that
     * swallowed the rest, would hide a real refusal on a later chain.
     */
    public function testEveryActiveChainIsStillVisited(): void
    {
        $marked = self::solana(self::MARKED, 91);
        ChainRepository::seed([
            self::cosmos(90),   // null: wrong driver family
            $marked,            // a real refusal, AFTER the null
            self::cosmos(92),   // null again, so nothing depends on being last
        ]);
        self::mark(self::MARKED, 91);

        $html = $this->render();

        self::assertStringContainsString(self::NOTICE, $html, 'the later chain must still be reached');
        self::assertStringContainsString('Solana', $html);
        self::assertStringNotContainsString('Cosmos Hub', $html, 'and a chain with no mark gets no notice');
        self::assertSame(1, substr_count($html, self::NOTICE), 'exactly one notice, for the one marked chain');
    }

    // ── 6. Upstream text is excerpted, not merely escaped ───────────────

    /**
     * @return array<string, array{0: string, 1: string}>
     *         message, fragment that must not appear
     */
    public static function hostileMessages(): array
    {
        return [
            'credentialed url' => ['failed calling https://das.example/v1/SUPERSECRET for owner', 'SUPERSECRET'],
            'absolute path'    => ['parse error in /home/deploy/app/src/Das.php:88', '/home/deploy'],
            'api key param'    => ['rejected: api-key=SUPERSECRET not authorised', 'SUPERSECRET'],
            'long blob'        => ['token ' . 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'],
        ];
    }

    /**
     * `endpointRefusal()` documents that `message` is UPSTREAM PROVIDER TEXT
     * and is deliberately NOT sanitised by it, so the caller owes the
     * operator-safe excerpt. `esc_html()` stops markup executing and does
     * nothing whatever about a credentialed URL or an absolute path.
     */
    #[DataProvider('hostileMessages')]
    public function testTheProviderMessageGoesThroughTheOperatorSafeExcerpt(
        string $message,
        string $mustNotAppear
    ): void {
        $chain = self::solana(self::MARKED);
        ChainRepository::seed([$chain]);
        self::mark(self::MARKED, self::CHAIN_ID, -32601, $message);

        // Anti-vacuity: the excerpt must genuinely alter this input, or the
        // assertion below would pass against a renderer that did nothing.
        self::assertNotSame(
            $message,
            AdminActionSupport::operatorSafeExcerpt($message),
            'fixture integrity: this message must be something the excerpt changes'
        );

        $html = $this->render();

        self::assertStringContainsString(self::NOTICE, $html, 'the notice itself must still render');
        self::assertStringNotContainsString($mustNotAppear, $html);
    }

    // ── 7. The second copy of the rule cannot come back ─────────────────

    /**
     * THE STRUCTURAL GUARD. The renderer's defect was not a wrong value; it
     * was reading the option at all. So the file must not touch the option
     * key — if it does, a second copy of the attribution rule is back,
     * whether or not it happens to agree today.
     *
     * Comments are stripped with token_get_all() rather than grepped,
     * because this file's own docblock names the thing it must not call.
     */
    public function testTheRendererReadsNoDasOptionDirectly(): void
    {
        $path = dirname(__DIR__, 2) . '/app/Domain/Onchain/Admin/SettingsPage.php';
        self::assertFileExists($path);

        $code = '';
        foreach (token_get_all((string) file_get_contents($path)) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                    $code .= str_repeat("\n", substr_count($t[1], "\n"));
                    continue;
                }
                $code .= $t[1];
            } else {
                $code .= $t;
            }
        }

        self::assertStringNotContainsString(
            'dasUnsupportedOptionKey',
            $code,
            "SettingsPage must ask NftProviderReadiness::endpointRefusal() and never\n"
                . "read the DAS mark itself. A direct read is a second copy of the\n"
                . 'attribution rule, outside the class that owns it. See #272.'
        );
        self::assertStringContainsString(
            'endpointRefusal',
            $code,
            'anti-vacuity: and it must actually consult the predicate'
        );
    }
}
