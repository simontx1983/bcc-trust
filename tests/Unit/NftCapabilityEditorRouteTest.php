<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Admin\NftDiscoveryPage;
use BCC\Trust\Onchain\Repositories\ChainNftCapabilityRepository;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Services\NftCapabilityEditor;
use BCC\Trust\Onchain\Support\NftDriverRegistry;
use BCC\Trust\Onchain\Workers\CosmwasmDiscoveryWorker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * THE REQUEST BOUNDARY IN FRONT OF EVERY CAPABILITY WRITE.
 *
 * ── WHAT IS BEING PROVEN ────────────────────────────────────────────────
 * Not "the wrong request gets an error" — that is a redirect code, and a
 * redirect code is easy to produce while the write has already happened.
 * What is proven is that the request STOPPED BEFORE THE MUTATION, and every
 * refusal below asserts the same four things:
 *
 *   no database write        (repository call lists are empty)
 *   no audit state-change    (the durable action list is empty)
 *   no provider call         (the worker's pass counter is zero)
 *   no success redirect      (it died, or landed on a refusal code)
 *
 * A gate that produced the right message and the wrong side effect would
 * pass a message-only test and fail all four of these.
 *
 * ── WHY THE ORDER OF THE GATES IS ITSELF UNDER TEST ─────────────────────
 * `manage_options` → POST-only → shape → nonce → domain. Several tests here
 * would still pass if the order were shuffled, and one would not: the shape
 * checks run BEFORE the nonce because the operation and driver are part of
 * the nonce ACTION, and the nonce runs before ANY repository call because a
 * lookup ahead of it would let an unauthenticated caller probe which chains
 * and which override rows exist.
 */
#[CoversClass(NftDiscoveryPage::class)]
#[CoversClass(NftCapabilityEditor::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class NftCapabilityEditorRouteTest extends TestCase
{
    private const CHAIN_ID = 4;
    private const OTHER_CHAIN_ID = 9;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/nft-capability-editor-stubs.php';

        \BccAdminTestState::reset();
        \BCC\Core\Log\Logger::reset();
        \BCC\Trust\Core\Security\AuditLogger::reset();
        ChainRepository::reset();
        ChainNftCapabilityRepository::reset();
        CosmwasmDiscoveryWorker::reset();

        $_POST = [];
        $_GET  = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';

        // A Cosmos chain with product support ON and the permission OFF —
        // so an accepted request would visibly change something, and a
        // refusal is not indistinguishable from a no-op.
        ChainRepository::seed(self::CHAIN_ID, 'osmosis', false, true, false);
        ChainRepository::seed(self::OTHER_CHAIN_ID, 'juno', false, true, false);
    }

    // ── Driving ─────────────────────────────────────────────────────────

    /** @return array<string, string> the redirect args */
    private function driveExpectingRedirect(string $route): array
    {
        try {
            $this->dispatch($route);
        } catch (\BccAdminRedirect $r) {
            return $r->args;
        }

        $this->fail('The handler must terminate in a PRG redirect.');
    }

    private function driveExpectingDeath(string $route): \BccAdminDie
    {
        try {
            $this->dispatch($route);
        } catch (\BccAdminDie $d) {
            return $d;
        }

        $this->fail('The handler must halt.');
    }

    private function dispatch(string $route): void
    {
        $handler = [
            NftDiscoveryPage::ACTION_CAP_PRODUCT_ENABLE  => 'handle_cap_product_enable',
            NftDiscoveryPage::ACTION_CAP_PRODUCT_DISABLE => 'handle_cap_product_disable',
            NftDiscoveryPage::ACTION_CAP_MANUAL_ENABLE   => 'handle_cap_manual_enable',
            NftDiscoveryPage::ACTION_CAP_MANUAL_DISABLE  => 'handle_cap_manual_disable',
            NftDiscoveryPage::ACTION_CAP_DRIVER_DISABLE  => 'handle_cap_driver_disable',
            NftDiscoveryPage::ACTION_CAP_DRIVER_ENABLE   => 'handle_cap_driver_enable',
            NftDiscoveryPage::ACTION_CAP_DRIVER_INHERIT  => 'handle_cap_driver_inherit',
            NftDiscoveryPage::ACTION_CAP_STALE_REMOVE    => 'handle_cap_stale_remove',
        ][$route];

        NftDiscoveryPage::{$handler}();
    }

    /** A well-formed request for a flag route, with a matching nonce. */
    private function validFlagRequest(string $route, int $chainId = self::CHAIN_ID): void
    {
        $_POST['chain_id'] = (string) $chainId;
        $_POST['family']   = 'cosmos';
        \BccAdminTestState::$validNonceAction = $route . '_' . $chainId;
    }

    /** A well-formed request for a driver route, with a matching nonce. */
    private function validDriverRequest(
        string $route,
        int $chainId = self::CHAIN_ID,
        string $operation = NftDriverRegistry::OP_ENUMERATION,
        string $driverKey = NftDriverRegistry::DRIVER_COSMWASM_ENUMERATION
    ): void {
        $_POST['chain_id']   = (string) $chainId;
        $_POST['operation']  = $operation;
        $_POST['driver_key'] = $driverKey;
        $_POST['priority']   = '10';
        $_POST['family']     = 'cosmos';
        \BccAdminTestState::$validNonceAction =
            $route . '_' . $chainId . '_' . $operation . '_' . $driverKey;
    }

    /** @return list<string> */
    private function audits(): array
    {
        return \BCC\Trust\Core\Security\AuditLogger::actions();
    }

    /** Nothing was written, recorded, or contacted. */
    private function assertNothingHappened(string $why): void
    {
        $this->assertSame([], ChainRepository::$capabilityWrites, $why . ' — no chain-flag write');
        $this->assertSame([], ChainRepository::$discoveryWrites, $why . ' — no other settings write');
        $this->assertSame([], ChainNftCapabilityRepository::$writes, $why . ' — no override write');
        $this->assertSame([], ChainNftCapabilityRepository::$bumps, $why . ' — no generation bump');
        $this->assertSame([], $this->audits(), $why . ' — no durable audit row');
        $this->assertSame(0, CosmwasmDiscoveryWorker::$passes, $why . ' — no provider work');
        $this->assertSame(0, ChainRepository::$cacheBusts, $why . ' — no cache bust');
    }

    /** @return array<string, array{0: string}> every capability route */
    public static function allRoutes(): array
    {
        return [
            'product enable'  => [NftDiscoveryPage::ACTION_CAP_PRODUCT_ENABLE],
            'product disable' => [NftDiscoveryPage::ACTION_CAP_PRODUCT_DISABLE],
            'manual enable'   => [NftDiscoveryPage::ACTION_CAP_MANUAL_ENABLE],
            'manual disable'  => [NftDiscoveryPage::ACTION_CAP_MANUAL_DISABLE],
            'driver disable'  => [NftDiscoveryPage::ACTION_CAP_DRIVER_DISABLE],
            'driver enable'   => [NftDiscoveryPage::ACTION_CAP_DRIVER_ENABLE],
            'driver inherit'  => [NftDiscoveryPage::ACTION_CAP_DRIVER_INHERIT],
            'stale remove'    => [NftDiscoveryPage::ACTION_CAP_STALE_REMOVE],
        ];
    }

    /** @return array<string, array{0: string}> the four driver-scoped routes */
    public static function driverRoutes(): array
    {
        return [
            'driver disable' => [NftDiscoveryPage::ACTION_CAP_DRIVER_DISABLE],
            'driver enable'  => [NftDiscoveryPage::ACTION_CAP_DRIVER_ENABLE],
            'driver inherit' => [NftDiscoveryPage::ACTION_CAP_DRIVER_INHERIT],
            'stale remove'   => [NftDiscoveryPage::ACTION_CAP_STALE_REMOVE],
        ];
    }

    /** @return array<string, array{0: string}> the four chain-flag routes */
    public static function flagRoutes(): array
    {
        return [
            'product enable'  => [NftDiscoveryPage::ACTION_CAP_PRODUCT_ENABLE],
            'product disable' => [NftDiscoveryPage::ACTION_CAP_PRODUCT_DISABLE],
            'manual enable'   => [NftDiscoveryPage::ACTION_CAP_MANUAL_ENABLE],
            'manual disable'  => [NftDiscoveryPage::ACTION_CAP_MANUAL_DISABLE],
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    //  AUTHORIZATION
    // ═══════════════════════════════════════════════════════════════════

    /**
     * `manage_options` first, on EVERY route.
     *
     * `add_submenu_page()` gates the PAGE; it does not gate a handler that
     * is reachable through `admin-post.php` without the page ever being
     * rendered. Each handler therefore checks for itself.
     */
    #[DataProvider('allRoutes')]
    public function testEveryRouteRefusesWithoutTheCapability(string $route): void
    {
        \BccAdminTestState::$can = false;
        $this->validDriverRequest($route);
        $this->validFlagRequest($route);

        $die = $this->driveExpectingDeath($route);

        $this->assertSame(403, $die->status);
        $this->assertNothingHappened('the operator lacks manage_options');
    }

    /**
     * And the refusal trace carries nothing the requester chose.
     *
     * `chain_id` is attacker-controlled and unvalidated at the point the
     * trace is written, so echoing it would let an unauthenticated caller
     * write our log — and would answer "does chain 41 exist?" for anybody
     * who can POST.
     */
    public function testTheRefusalTraceEchoesNoRequestInput(): void
    {
        \BccAdminTestState::$can = false;
        $_POST['chain_id'] = "41\ninjected log line";
        $_POST['operation'] = 'evil';

        try {
            NftDiscoveryPage::handle_cap_product_enable();
        } catch (\BccAdminDie $d) {
            // expected
        }

        $logged = \BCC\Core\Log\Logger::$entries;
        $this->assertNotSame([], $logged, 'an unauthorised write attempt is worth seeing');

        $blob = json_encode($logged);
        self::assertIsString($blob);
        $this->assertStringNotContainsString('injected log line', $blob);
        $this->assertStringNotContainsString('41', $blob);
        $this->assertStringNotContainsString('evil', $blob);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  METHOD
    // ═══════════════════════════════════════════════════════════════════

    /**
     * GET is refused on every route.
     *
     * `admin-post.php` dispatches `admin_post_{action}` for GET as well as
     * POST — it reads the action out of `$_REQUEST`. A handler that merely
     * reads `$_POST` is not thereby POST-only: a crafted GET reaches it,
     * finds an empty `$_POST`, and runs whatever the empty-input path does.
     * It also keeps a capability mutation out of anything that prefetches
     * links.
     */
    #[DataProvider('allRoutes')]
    public function testEveryRouteRefusesGet(string $route): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->validDriverRequest($route);
        $this->validFlagRequest($route);

        $die = $this->driveExpectingDeath($route);

        $this->assertSame(405, $die->status);
        $this->assertNothingHappened('the request was a GET');
    }

    // ═══════════════════════════════════════════════════════════════════
    //  NONCE — SCOPED TO DIRECTION, CHAIN, OPERATION AND DRIVER
    // ═══════════════════════════════════════════════════════════════════

    #[DataProvider('allRoutes')]
    public function testEveryRouteRefusesAMissingNonce(string $route): void
    {
        $this->validDriverRequest($route);
        $this->validFlagRequest($route);
        \BccAdminTestState::$validNonceAction = null;

        $die = $this->driveExpectingDeath($route);

        $this->assertSame(403, $die->status);
        $this->assertNothingHappened('no nonce was supplied');
    }

    #[DataProvider('allRoutes')]
    public function testEveryRouteRefusesAnInvalidNonce(string $route): void
    {
        $this->validDriverRequest($route);
        $this->validFlagRequest($route);
        \BccAdminTestState::$validNonceAction = 'something_else_entirely';

        $die = $this->driveExpectingDeath($route);

        $this->assertSame(403, $die->status);
        $this->assertNothingHappened('the nonce was forged');
    }

    /**
     * ⚠️ A NONCE FOR THE OTHER DIRECTION IS NOT A NONCE FOR THIS ONE.
     *
     * This is the whole reason there are eight routes and not three
     * toggles. If an enable nonce satisfied the disable route, a stale tab
     * or a replayed form could flip a permission the operator had already
     * set, in the direction they were not looking at.
     */
    public function testAnEnableNonceCannotAuthoriseTheDisable(): void
    {
        $this->validFlagRequest(NftDiscoveryPage::ACTION_CAP_PRODUCT_DISABLE);
        \BccAdminTestState::$validNonceAction =
            NftDiscoveryPage::ACTION_CAP_PRODUCT_ENABLE . '_' . self::CHAIN_ID;

        $die = $this->driveExpectingDeath(NftDiscoveryPage::ACTION_CAP_PRODUCT_DISABLE);

        $this->assertSame(403, $die->status);
        $this->assertNothingHappened('the nonce was minted for the opposite direction');
    }

    public function testAManualNonceCannotAuthoriseAProductChange(): void
    {
        $this->validFlagRequest(NftDiscoveryPage::ACTION_CAP_PRODUCT_ENABLE);
        \BccAdminTestState::$validNonceAction =
            NftDiscoveryPage::ACTION_CAP_MANUAL_ENABLE . '_' . self::CHAIN_ID;

        $die = $this->driveExpectingDeath(NftDiscoveryPage::ACTION_CAP_PRODUCT_ENABLE);

        $this->assertSame(403, $die->status);
        $this->assertNothingHappened('a permission nonce cannot make a product decision');
    }

    /** ⚠️ A nonce for chain 9 must not authorise a change to chain 4. */
    #[DataProvider('flagRoutes')]
    public function testAFlagNonceForAnotherChainIsRefused(string $route): void
    {
        $this->validFlagRequest($route, self::CHAIN_ID);
        \BccAdminTestState::$validNonceAction = $route . '_' . self::OTHER_CHAIN_ID;

        $die = $this->driveExpectingDeath($route);

        $this->assertSame(403, $die->status);
        $this->assertNothingHappened('the nonce named a different chain');
    }

    #[DataProvider('driverRoutes')]
    public function testADriverNonceForAnotherChainIsRefused(string $route): void
    {
        $this->validDriverRequest($route, self::CHAIN_ID);
        \BccAdminTestState::$validNonceAction = $route . '_' . self::OTHER_CHAIN_ID
            . '_' . NftDriverRegistry::OP_ENUMERATION
            . '_' . NftDriverRegistry::DRIVER_COSMWASM_ENUMERATION;

        $die = $this->driveExpectingDeath($route);

        $this->assertSame(403, $die->status);
        $this->assertNothingHappened('the nonce named a different chain');
    }

    /** ⚠️ A nonce for `metadata` must not authorise a change to `enumeration`. */
    #[DataProvider('driverRoutes')]
    public function testADriverNonceForAnotherOperationIsRefused(string $route): void
    {
        $this->validDriverRequest($route);
        \BccAdminTestState::$validNonceAction = $route . '_' . self::CHAIN_ID
            . '_' . NftDriverRegistry::OP_METADATA
            . '_' . NftDriverRegistry::DRIVER_COSMWASM_ENUMERATION;

        $die = $this->driveExpectingDeath($route);

        $this->assertSame(403, $die->status);
        $this->assertNothingHappened('the nonce named a different operation');
    }

    /** ⚠️ And a nonce for `cw721_lcd` must not authorise a change to another driver. */
    #[DataProvider('driverRoutes')]
    public function testADriverNonceForAnotherDriverIsRefused(string $route): void
    {
        $this->validDriverRequest($route);
        \BccAdminTestState::$validNonceAction = $route . '_' . self::CHAIN_ID
            . '_' . NftDriverRegistry::OP_ENUMERATION
            . '_' . NftDriverRegistry::DRIVER_CW721_LCD;

        $die = $this->driveExpectingDeath($route);

        $this->assertSame(403, $die->status);
        $this->assertNothingHappened('the nonce named a different driver');
    }

    // ═══════════════════════════════════════════════════════════════════
    //  SHAPE — CHECKED BEFORE THE NONCE, NEVER SANITISED INTO VALIDITY
    // ═══════════════════════════════════════════════════════════════════

    /**
     * ⚠️ AN ARRAY IS REJECTED BEFORE ANY CAST.
     *
     * `(int) []` raises a PHP warning and yields 1 — a perfectly valid
     * looking chain id out of pure garbage. `(string) []` yields "Array",
     * a perfectly valid looking key. So `is_scalar()` comes first on every
     * field, and nothing derived from the raw value reaches the response.
     */
    #[DataProvider('allRoutes')]
    public function testAnArrayShapedChainIdIsRefused(string $route): void
    {
        $this->validDriverRequest($route);
        $this->validFlagRequest($route);
        $_POST['chain_id'] = ['4'];

        $die = $this->driveExpectingDeath($route);

        $this->assertSame(400, $die->status);
        $this->assertNothingHappened('chain_id was an array');
    }

    #[DataProvider('driverRoutes')]
    public function testAnArrayShapedOperationIsRefused(string $route): void
    {
        $this->validDriverRequest($route);
        $_POST['operation'] = [NftDriverRegistry::OP_ENUMERATION];

        $die = $this->driveExpectingDeath($route);

        $this->assertSame(400, $die->status);
        $this->assertNothingHappened('operation was an array');
    }

    #[DataProvider('driverRoutes')]
    public function testAnArrayShapedDriverKeyIsRefused(string $route): void
    {
        $this->validDriverRequest($route);
        $_POST['driver_key'] = [NftDriverRegistry::DRIVER_COSMWASM_ENUMERATION];

        $die = $this->driveExpectingDeath($route);

        $this->assertSame(400, $die->status);
        $this->assertNothingHappened('driver_key was an array');
    }

    public function testAnArrayShapedPriorityIsRefused(): void
    {
        $this->validDriverRequest(NftDiscoveryPage::ACTION_CAP_DRIVER_ENABLE);
        $_POST['priority'] = ['10'];

        $die = $this->driveExpectingDeath(NftDiscoveryPage::ACTION_CAP_DRIVER_ENABLE);

        $this->assertSame(400, $die->status);
        $this->assertNothingHappened('priority was an array');
    }

    /**
     * Malformed and overflowed chain ids.
     *
     * `0` and negatives are not chain ids; an 19-digit value overflows what
     * the column can hold and would be silently truncated by a cast; the
     * transformed strings are the ones a `sanitize_*` call would "fix" into
     * something acceptable.
     *
     * @return array<string, array{0: string}>
     */
    public static function malformedChainIds(): array
    {
        return [
            'zero'            => ['0'],
            'negative'        => ['-4'],
            'leading zero'    => ['04'],
            'float'           => ['4.0'],
            'scientific'      => ['4e0'],
            'hex'             => ['0x4'],
            'whitespace'      => [' 4'],
            'trailing space'  => ['4 '],
            'sql-looking'     => ['4 OR 1=1'],
            'null byte'       => ["4\0"],
            // ⚠️ THE PCRE TRAP. `$` matches at the end of the subject OR
            // immediately before a trailing newline, so `/^[0-9]+$/` accepts
            // "4\n" — which casts to a perfectly ordinary 4 while carrying a
            // control character through every downstream string. Every shape
            // check on this page anchors with `\z` for exactly this case, and
            // these three rows are what would fail if one of them regressed.
            'newline'         => ["4\n"],
            'crlf'            => ["4\r\n"],
            'trailing lf x2'  => ["4\n\n"],
            'overflow 19'     => ['9999999999999999999'],
            'overflow 25'     => ['9999999999999999999999999'],
            'empty'           => [''],
            'word'            => ['four'],
        ];
    }

    #[DataProvider('malformedChainIds')]
    public function testAMalformedChainIdIsRefusedNotRepaired(string $chainId): void
    {
        $route = NftDiscoveryPage::ACTION_CAP_PRODUCT_ENABLE;
        $this->validFlagRequest($route);
        $_POST['chain_id'] = $chainId;

        $die = $this->driveExpectingDeath($route);

        $this->assertSame(400, $die->status);
        $this->assertNothingHappened('the chain id was malformed');
    }

    /**
     * Hostile and transformed key shapes.
     *
     * Every one of these would become a legal-looking key if it were run
     * through `sanitize_key()` first — which is exactly why it is not.
     * Accepting `Enumeration` as `enumeration` accepts a request nobody
     * sent, and the nonce would then be checked against a string the
     * operator's browser never produced.
     *
     * @return array<string, array{0: string}>
     */
    public static function malformedKeys(): array
    {
        return [
            'uppercase'       => ['Enumeration'],
            'mixed case'      => ['cw721_LCD'],
            'hyphen'          => ['das-rpc'],
            'dot'             => ['das.rpc'],
            'space'           => ['das rpc'],
            'leading space'   => [' enumeration'],
            'newline'         => ["enumeration\n"],
            'null byte'       => ["enumeration\0"],
            'sql-looking'     => ["enumeration' OR 1=1"],
            'path traversal'  => ['../../etc/passwd'],
            'wildcard'        => ['%'],
            'empty'           => [''],
            'too long'        => ['a_very_long_driver_key_that_exceeds_the_column'],
            // ⚠️ Same PCRE trap as the chain id, and worse here: this value
            // is used as a STRING in the nonce action and is handed to the
            // domain, so a surviving newline would travel further than a
            // cast-to-int ever could.
            'trailing lf'     => ["enumeration\n"],
            'trailing crlf'   => ["enumeration\r\n"],
        ];
    }

    #[DataProvider('malformedKeys')]
    public function testAMalformedOperationIsRefusedNotRepaired(string $operation): void
    {
        $route = NftDiscoveryPage::ACTION_CAP_DRIVER_DISABLE;
        $this->validDriverRequest($route);
        $_POST['operation'] = $operation;

        $die = $this->driveExpectingDeath($route);

        $this->assertSame(400, $die->status);
        $this->assertNothingHappened('the operation was malformed');
    }

    #[DataProvider('malformedKeys')]
    public function testAMalformedDriverKeyIsRefusedNotRepaired(string $driverKey): void
    {
        $route = NftDiscoveryPage::ACTION_CAP_DRIVER_DISABLE;
        $this->validDriverRequest($route);
        $_POST['driver_key'] = $driverKey;

        $die = $this->driveExpectingDeath($route);

        $this->assertSame(400, $die->status);
        $this->assertNothingHappened('the driver key was malformed');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedPriorities(): array
    {
        return [
            'negative'   => ['-1'],
            'float'      => ['1.5'],
            'scientific' => ['1e3'],
            'whitespace' => [' 10'],
            'word'       => ['high'],
            'empty'      => [''],
            'overflow'   => ['99999999999999999999'],
            'sql'        => ['10 OR 1=1'],
            'trailing lf' => ["10\n"],
        ];
    }

    #[DataProvider('malformedPriorities')]
    public function testAMalformedPriorityIsRefusedNotRepaired(string $priority): void
    {
        $route = NftDiscoveryPage::ACTION_CAP_DRIVER_ENABLE;
        $this->validDriverRequest($route);
        $_POST['priority'] = $priority;

        $die = $this->driveExpectingDeath($route);

        $this->assertSame(400, $die->status);
        $this->assertNothingHappened('the priority was malformed');
    }

    // ═══════════════════════════════════════════════════════════════════
    //  DOMAIN — PAST THE NONCE, STILL REFUSED
    // ═══════════════════════════════════════════════════════════════════

    /**
     * A well-formed but UNKNOWN operation gets past the shape check and is
     * refused by the domain — with no write.
     *
     * These are the cases the shape check deliberately lets through: they
     * look exactly like real keys, and only the registry can say they are
     * not.
     */
    public function testAnUnknownOperationIsRefusedByTheDomain(): void
    {
        $route = NftDiscoveryPage::ACTION_CAP_DRIVER_DISABLE;
        $this->validDriverRequest($route, self::CHAIN_ID, 'teleportation');

        $args = $this->driveExpectingRedirect($route);

        $this->assertSame(NftCapabilityEditor::RESULT_OVERRIDE_INVALID_TRIPLE, $args['bcc_nftcap']);
        $this->assertNothingHappened('no such operation exists in this build');
    }

    public function testAnUnknownDriverIsRefusedByTheDomain(): void
    {
        $route = NftDiscoveryPage::ACTION_CAP_DRIVER_DISABLE;
        $this->validDriverRequest($route, self::CHAIN_ID, NftDriverRegistry::OP_ENUMERATION, 'moonbeam_nft');

        $args = $this->driveExpectingRedirect($route);

        $this->assertSame(NftCapabilityEditor::RESULT_OVERRIDE_INVALID_TRIPLE, $args['bcc_nftcap']);
        $this->assertNothingHappened('no such driver exists in this build');
    }

    /**
     * ⚠️ THE RETIRED `das` DRIVER CAN NEVER BE WRITTEN AGAIN.
     *
     * The single Solana DAS driver was split into `das_rpc` (the chain
     * row's endpoint) and `das_helius` (the Helius constants) because one
     * readiness answer for two different endpoints reported wallet
     * discovery READY while every call went to an endpoint with no DAS.
     * Accepting a `das` row would let that fusion back in through the
     * database.
     */
    public function testTheRetiredDasDriverIsRejected(): void
    {
        ChainRepository::seed(self::CHAIN_ID, 'solana', false, true, false, 'solana');

        $route = NftDiscoveryPage::ACTION_CAP_DRIVER_DISABLE;
        $this->validDriverRequest($route, self::CHAIN_ID, NftDriverRegistry::OP_OWNERSHIP, 'das');

        $args = $this->driveExpectingRedirect($route);

        $this->assertSame(NftCapabilityEditor::RESULT_OVERRIDE_INVALID_TRIPLE, $args['bcc_nftcap']);
        $this->assertNothingHappened('`das` was retired when it became two drivers');

        // And both replacements ARE real, so the refusal above is about the
        // retirement rather than about Solana having no drivers at all.
        $this->assertTrue(NftDriverRegistry::isDriver(NftDriverRegistry::DRIVER_DAS_RPC));
        $this->assertTrue(NftDriverRegistry::isDriver(NftDriverRegistry::DRIVER_DAS_HELIUS));
    }

    /**
     * ⚠️ A REAL DRIVER ON THE WRONG CHAIN FAMILY.
     *
     * `alchemy_nft` exists and performs `metadata` — on EVM. Pointing it at
     * a Cosmos chain would produce a stored row that resolves to nothing,
     * behind a UI that had accepted it.
     */
    public function testAValidDriverOnTheWrongFamilyIsRefused(): void
    {
        $route = NftDiscoveryPage::ACTION_CAP_DRIVER_DISABLE;
        $this->validDriverRequest(
            $route,
            self::CHAIN_ID,                                  // a COSMOS chain
            NftDriverRegistry::OP_METADATA,
            NftDriverRegistry::DRIVER_ALCHEMY_NFT            // an EVM driver
        );

        $args = $this->driveExpectingRedirect($route);

        $this->assertSame(NftCapabilityEditor::RESULT_OVERRIDE_INVALID_TRIPLE, $args['bcc_nftcap']);
        $this->assertNothingHappened('that driver does not serve this chain family');

        // The driver and the operation are each individually real.
        $this->assertTrue(NftDriverRegistry::isDriver(NftDriverRegistry::DRIVER_ALCHEMY_NFT));
        $this->assertTrue(NftDriverRegistry::driverPerformsOperation(
            NftDriverRegistry::DRIVER_ALCHEMY_NFT,
            NftDriverRegistry::OP_METADATA
        ));
    }

    /**
     * ⚠️ A REAL DRIVER ON THE WRONG OPERATION.
     *
     * `cosmwasm_enumeration` serves this exact chain, and `metadata` is a
     * real operation — but that driver does not perform it. Every part is
     * valid except the combination, which is the case a per-field check
     * would wave through.
     */
    public function testAValidDriverOnTheWrongOperationIsRefused(): void
    {
        $route = NftDiscoveryPage::ACTION_CAP_DRIVER_DISABLE;
        $this->validDriverRequest(
            $route,
            self::CHAIN_ID,
            NftDriverRegistry::OP_METADATA,
            NftDriverRegistry::DRIVER_COSMWASM_ENUMERATION
        );

        $args = $this->driveExpectingRedirect($route);

        $this->assertSame(NftCapabilityEditor::RESULT_OVERRIDE_INVALID_TRIPLE, $args['bcc_nftcap']);
        $this->assertNothingHappened('that driver does not perform that operation');

        $this->assertTrue(NftDriverRegistry::driverSupportsChain(
            NftDriverRegistry::DRIVER_COSMWASM_ENUMERATION,
            (object) ['chain_type' => 'cosmos', 'slug' => 'osmosis']
        ));
    }

    /**
     * An out-of-range priority is REFUSED, not clamped.
     *
     * Clamping 5000 to 1000 would store an ordering nobody chose and report
     * it as what was asked for. The shape check admits up to four digits on
     * purpose so this refusal can name the actual limit.
     */
    public function testAnOutOfRangePriorityIsRefusedNotClamped(): void
    {
        $route = NftDiscoveryPage::ACTION_CAP_DRIVER_ENABLE;
        $this->validDriverRequest($route);
        $_POST['priority'] = '5000';

        $args = $this->driveExpectingRedirect($route);

        $this->assertSame(NftCapabilityEditor::RESULT_OVERRIDE_INVALID_PRIORITY, $args['bcc_nftcap']);
        $this->assertNothingHappened('5000 is outside 0..1000');
    }

    public function testTheRangeBoundsThemselvesAreAccepted(): void
    {
        foreach ([NftCapabilityEditor::PRIORITY_MIN, NftCapabilityEditor::PRIORITY_MAX] as $priority) {
            ChainNftCapabilityRepository::reset();
            \BCC\Trust\Core\Security\AuditLogger::reset();

            $route = NftDiscoveryPage::ACTION_CAP_DRIVER_ENABLE;
            $this->validDriverRequest($route);
            $_POST['priority'] = (string) $priority;

            $args = $this->driveExpectingRedirect($route);

            $this->assertSame(
                NftCapabilityEditor::RESULT_OVERRIDE_ENABLED,
                $args['bcc_nftcap'],
                'priority ' . $priority . ' is inside the accepted range'
            );
        }
    }

    /** An unknown chain is refused after the nonce, with no write. */
    public function testAnUnknownChainIsRefusedWithoutAWrite(): void
    {
        $route = NftDiscoveryPage::ACTION_CAP_PRODUCT_ENABLE;
        $this->validFlagRequest($route, 777);

        $args = $this->driveExpectingRedirect($route);

        $this->assertSame(NftCapabilityEditor::RESULT_UNKNOWN_CHAIN, $args['bcc_nftcap']);
        $this->assertNothingHappened('there is no chain 777');
    }

    // ═══════════════════════════════════════════════════════════════════
    //  THE LANDING CARRIES NO TARGET
    // ═══════════════════════════════════════════════════════════════════

    /**
     * The PRG destination carries three keys and nothing else.
     *
     * Asserted on a SUCCESSFUL write, because that is the landing most
     * likely to acquire a helpful extra parameter — "so the editor can
     * reopen on the chain you just changed" is exactly the well-meant
     * change this pins against.
     */
    public function testASuccessfulLandingCarriesNoTarget(): void
    {
        $route = NftDiscoveryPage::ACTION_CAP_MANUAL_ENABLE;
        $this->validFlagRequest($route);

        $args = $this->driveExpectingRedirect($route);

        $this->assertSame(
            NftDiscoveryPage::CAPABILITY_REDIRECT_KEYS,
            array_keys($args),
            'the capability landing carries page, family and one result code'
        );
        $this->assertSame(NftDiscoveryPage::PAGE_SLUG, $args['page']);
        $this->assertSame(NftCapabilityEditor::RESULT_MANUAL_ENABLED, $args['bcc_nftcap']);
    }

    /** And a refusal landing carries no more than a success one. */
    public function testARefusalLandingCarriesNoTargetEither(): void
    {
        $route = NftDiscoveryPage::ACTION_CAP_DRIVER_DISABLE;
        $this->validDriverRequest($route, self::CHAIN_ID, 'teleportation');

        $args = $this->driveExpectingRedirect($route);

        $this->assertSame(NftDiscoveryPage::CAPABILITY_REDIRECT_KEYS, array_keys($args));
        $this->assertArrayNotHasKey('bcc_ref', $args);
    }

    /**
     * A submitted family only chooses a TAB, and an unknown one falls back.
     *
     * It is navigation rather than a target — the write has already happened
     * by the time it is read, and nothing downstream consults it — so the
     * worst a hostile value achieves is landing the operator on Cosmos.
     */
    public function testAHostileFamilyFallsBackToTheDefaultTab(): void
    {
        $route = NftDiscoveryPage::ACTION_CAP_MANUAL_ENABLE;
        $this->validFlagRequest($route);
        $_POST['family'] = "../../etc/passwd";

        $args = $this->driveExpectingRedirect($route);

        $this->assertSame('cosmos', $args['family']);
    }
}
