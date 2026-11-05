<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\REST\PagesEndpoint;
use BCC\Trust\Core\Services\GroupsService;
use BCC\Trust\Core\ValueObjects\GroupContext;
use BCC\Trust\Core\ValueObjects\GroupType;
use BCC\Trust\Core\ValueObjects\PeepSoPrivacy;
use BCC\Trust\Onchain\OnchainPlugin;
use BCC\Trust\Onchain\Repositories\ValidatorGroupRepository;
use BCC\Trust\Onchain\Repositories\ValidatorRepository;
use BCC\Trust\Onchain\REST\PageDelegatorGroupEndpoint;
use BCC\Trust\Onchain\REST\ValidatorGroupsEndpoint;
use BCC\Trust\Onchain\Services\DelegatorGroupView;
use BCC\Trust\Onchain\Services\NftGroupGateService;
use BCC\Trust\Onchain\Services\ValidatorGroupProvisioningService;
use BCC\Trust\Onchain\Services\ValidatorPageOperatorAuthority;
use BCC\Trust\Onchain\Support\Bech32;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The delegator community, end to end: nothing creates one but its page's
 * operator, the operator sets and edits the minimum, and the join gate and the
 * revoke sweep both enforce the value as it stands now.
 *
 * Every route is entered through its real handler against a real database, with
 * the LCD faked one level below the eligibility service, so a verdict here is
 * computed by the real code from what the chain would have answered. See
 * tests/Stubs/delegator-group-stubs.php for the exact line.
 *
 * Each refusal asserts on the rows a write touches, not only on the response:
 * a route that answered 403 after writing is the failure that matters.
 */
#[Group('integration')]
#[Group('mariadb')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(PageDelegatorGroupEndpoint::class)]
#[CoversClass(ValidatorGroupProvisioningService::class)]
#[CoversClass(DelegatorGroupView::class)]
final class DelegatorGroupIntegrationTest extends TestCase
{
    private const OPERATOR    = 610;
    private const STRANGER    = 611;
    private const RIVAL       = 612;
    private const MEMBER      = 613;
    private const WELL_STAKED = 614;

    private const PAGE       = 7101;
    private const OTHER_PAGE = 7102;

    private const VALOPER = 'cosmosvaloper1fixtureaaaa';

    private const FORBIDDEN = [
        'error' => [
            'code'    => 'bcc_forbidden',
            'message' => 'Not permitted.',
            'status'  => 403,
            'data'    => ['reason_code' => 'not_claimer'],
        ],
        '_meta' => ['version' => 'v1'],
    ];

    private int $cosmosChain = 0;
    private int $otherCosmosChain = 0;
    private int $evmChain = 0;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/delegator-group-stubs.php';

        $GLOBALS['__bcc_actions']        = [];
        $GLOBALS['__bcc_hooks']          = [];
        $GLOBALS['__bcc_posts']          = [];
        $GLOBALS['__bcc_usermeta']       = [];
        $GLOBALS['__bcc_lcd']            = [];
        $GLOBALS['__bcc_lcd_calls']      = [];
        $GLOBALS['__bcc_group_creates']  = [];
        $GLOBALS['__bcc_group_on_create'] = null;
        $GLOBALS['__bcc_throttle_calls'] = [];
        $GLOBALS['__bcc_throttle_denied'] = false;
        $GLOBALS['__bcc_current_user']   = self::OPERATOR;
        $GLOBALS['__bcc_test_object_cache'] = [];
        $GLOBALS['__bcc_mail']           = [];
        $GLOBALS['__bcc_lcd_on_fetch']   = null;
        delete_option('bcc_validator_group_revoke_cursor');

        global $wpdb;
        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}peepso_group_members` (
                gm_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                gm_user_id BIGINT UNSIGNED NOT NULL,
                gm_group_id BIGINT UNSIGNED NOT NULL,
                gm_user_status VARCHAR(32) NOT NULL DEFAULT 'member',
                gm_joined DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (gm_id)
            )"
        );
        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}peepso_notifications` (
                not_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                not_user_id BIGINT UNSIGNED NOT NULL,
                not_from_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                not_module_id INT NOT NULL,
                not_external_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                not_act_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                not_type VARCHAR(64) NOT NULL,
                not_message TEXT NOT NULL,
                not_timestamp DATETIME NOT NULL,
                not_read TINYINT NOT NULL DEFAULT 0,
                PRIMARY KEY (not_id)
            )"
        );
        foreach ([
            $wpdb->prefix . 'peepso_notifications',
            $wpdb->prefix . 'bcc_onchain_claims',
            $wpdb->prefix . 'bcc_onchain_validators',
            $wpdb->prefix . 'bcc_wallet_links',
            $wpdb->prefix . 'peepso_group_members',
            $wpdb->prefix . 'bcc_trust_activity',
            $wpdb->postmeta,
            $wpdb->posts,
        ] as $table) {
            self::assertNotFalse($wpdb->query("DELETE FROM `{$table}`"), $wpdb->last_error);
        }
        $this->normaliseClaimsSchema();

        $cosmos = $wpdb->get_col("SELECT id FROM `{$wpdb->prefix}bcc_chains` WHERE chain_type = 'cosmos' ORDER BY id ASC LIMIT 2");
        self::assertCount(2, $cosmos);
        $this->cosmosChain      = (int) $cosmos[0];
        $this->otherCosmosChain = (int) $cosmos[1];
        $this->evmChain = (int) $wpdb->get_var("SELECT id FROM `{$wpdb->prefix}bcc_chains` WHERE chain_type = 'evm' ORDER BY id ASC LIMIT 1");
        self::assertGreaterThan(0, $this->evmChain);

        $this->insertPage(self::PAGE, 'Fixture Page');
        $this->insertPage(self::OTHER_PAGE, 'Other Page');
    }

    // ── Nothing creates one automatically ───────────────────────────────

    /**
     * The real claim route fires the real event, into every subscriber
     * bcc-trust.php binds to it. The subscriber in Plugin.php are the activity
     * writer and the messaging worker; DelegatorGroupNotAutomaticTest pins that
     * neither they nor anything else can reach group creation.
     */
    public function testAVerifiedClaimCreatesNoGroup(): void
    {
        self::assertSame(0, $this->bindFromBootstrap('bcc_page_claimed'), 'nothing in bcc-trust.php may subscribe here');

        $bytes       = '0123456789abcdef0123456789abcdef01234567';
        $validatorId = $this->insertValidator(
            $this->cosmosChain,
            null,
            (string) Bech32::encode('cosmosvaloper', (string) hex2bin($bytes)),
            'Fixture'
        );
        $this->bindPlaceholder(self::PAGE, $validatorId);
        $this->insertWallet(self::OPERATOR, $this->cosmosChain, (string) Bech32::encode('cosmos', (string) hex2bin($bytes)));

        $response = (new PagesEndpoint())->claim(new \WP_REST_Request([
            'id'          => self::PAGE,
            'entity_type' => 'validator',
            'entity_id'   => $validatorId,
        ]));

        self::assertSame(200, $response->get_status());
        self::assertSame('verified', $response->get_data()['data']['status']);
        self::assertContains(
            ['bcc_page_claimed', self::OPERATOR, self::PAGE, 'validator', $validatorId, 'operator'],
            $GLOBALS['__bcc_actions'],
            'the event under test must really have fired'
        );

        $this->assertNoGroupExists();
    }

    /**
     * The real handler: a verified operator claim is sitting there with no
     * community, which is exactly what the old backfill minted one for. The
     * holder sweep must still be what this hook runs, so its read is broken on
     * purpose and the handler has to report that.
     */
    public function testTheDailyProvisionHookCreatesNoDelegatorGroupAndStillRunsTheHolderSweep(): void
    {
        $this->seedClaimedPage();
        self::assertSame(1, $this->bindFromBootstrap('bcc_gated_group_provision'));

        do_action('bcc_gated_group_provision');
        $this->assertNoGroupExists();

        global $wpdb;
        \BCC\Core\Log\Logger::reset();
        $wpdb->failQueriesMatching = '/provisioning_state/';
        do_action('bcc_gated_group_provision');
        self::assertGreaterThan(0, $wpdb->injectedFailures, 'the holder sweep must have read its queue');
        $wpdb->clearFaultInjection();

        $swept = array_filter(
            \BCC\Core\Log\Logger::$lines,
            static fn (array $l): bool => $l['message'] === '[bcc-trust] Holder-group provisioning sweep'
        );
        self::assertCount(1, $swept, 'the handler must still surface the holder sweep result');
        $this->assertNoGroupExists();
    }

    // ── POST: success ───────────────────────────────────────────────────

    public function testTheOperatorCreatesTheGroupWithTheChosenMinimum(): void
    {
        $validatorId = $this->seedClaimedPage();

        $response = $this->post(self::PAGE, $validatorId, '2.5');

        self::assertSame(201, $response->get_status());
        $group = $response->get_data()['data']['group'];
        self::assertSame(['id', 'slug', 'url', 'min_stake', 'min_stake_display', 'validator'], array_keys($group));
        $chainName = $group['validator']['chain_name'];
        self::assertNotSame('', $chainName);
        self::assertSame(
            ['id' => $validatorId, 'name' => 'Fixture Moniker', 'chain_slug' => 'cosmos', 'chain_name' => $chainName],
            $group['validator']
        );
        self::assertIsInt($group['id']);
        self::assertSame('2.5', $group['min_stake']);
        self::assertSame('2.5 ATOM', $group['min_stake_display']);
        self::assertSame('/communities/' . $group['slug'], $group['url']);

        self::assertSame('2.5', ValidatorGroupRepository::readMinStake($group['id']));
        $config = ValidatorGroupRepository::getGateConfig($group['id']);
        self::assertNotNull($config);
        self::assertSame(2.5, $config->minStake);
        self::assertSame($validatorId, $config->validatorId);
        self::assertSame($this->cosmosChain, $config->chainId);
        self::assertSame(self::VALOPER, $config->operatorAddress);

        self::assertCount(1, $GLOBALS['__bcc_group_creates']);
        $data = $GLOBALS['__bcc_group_creates'][0];
        self::assertSame(self::OPERATOR, $data['owner_id']);
        self::assertSame('Delegators: Fixture Moniker', $data['name']);
        self::assertStringNotContainsString('Auto-managed', $data['description']);
        self::assertSame('member_owner', $this->memberStatus(self::OPERATOR, $group['id']));

        self::assertSame('private, no-store', $response->get_headers()['Cache-Control']);
        self::assertSame(1, $this->auditCount('validator_group_created'));
    }

    public function testAnEmptyMonikerFallsBackToThePageTitle(): void
    {
        foreach (['', '   '] as $i => $moniker) {
            $page = self::PAGE + 10 + $i;
            $this->insertPage($page, 'Page Title ' . $i);
            $validatorId = $this->insertValidator($this->cosmosChain, null, self::VALOPER . $i, $moniker);
            $this->bindPlaceholder($page, $validatorId);
            $this->insertClaim(self::OPERATOR, $validatorId);

            self::assertSame(201, $this->post($page, $validatorId, '1')->get_status());
            self::assertSame(
                'Delegators: Page Title ' . $i,
                $GLOBALS['__bcc_group_creates'][$i]['name']
            );
            self::assertSame(
                'Page Title ' . $i,
                $this->cardForPage($page, self::OPERATOR)['group']['validator']['name']
            );
        }
    }

    // ── POST: refusals ──────────────────────────────────────────────────

    public function testEveryNonOperatorGetsTheIdenticalRefusalAndCreatesNothing(): void
    {
        $validatorId = $this->seedClaimedPage();
        $unclaimed   = $this->insertValidator($this->cosmosChain, null, self::VALOPER . 'u', 'Unclaimed');
        $this->bindPlaceholder(self::OTHER_PAGE, $unclaimed);

        $contestedPage = self::PAGE + 5;
        $this->insertPage($contestedPage, 'Contested');
        $contested = $this->insertValidator($this->cosmosChain, null, self::VALOPER . 'c', 'Contested');
        $this->bindPlaceholder($contestedPage, $contested);
        $this->insertClaim(self::OPERATOR, $contested);
        $this->insertClaim(self::RIVAL, $contested);

        $elsewherePage = self::PAGE + 6;
        $this->insertPage($elsewherePage, 'Elsewhere');
        $elsewhere = $this->insertValidator($this->cosmosChain, null, self::VALOPER . 'e', 'Elsewhere');
        $this->bindPlaceholder($elsewherePage, $elsewhere);
        $this->insertClaim(self::RIVAL, $elsewhere);

        foreach ([
            'stranger'             => [self::STRANGER, self::PAGE, $validatorId],
            'other page operator'  => [self::RIVAL, self::PAGE, $validatorId],
            'unclaimed'            => [self::OPERATOR, self::OTHER_PAGE, $unclaimed],
            'ambiguous'            => [self::OPERATOR, $contestedPage, $contested],
            'not a page at all'    => [self::OPERATOR, 999999, $validatorId],
        ] as $case => [$viewer, $page, $validator]) {
            $GLOBALS['__bcc_current_user'] = $viewer;
            $before   = $this->snapshot();
            $response = $this->post($page, $validator, '1');

            self::assertSame(403, $response->get_status(), $case);
            self::assertSame(self::FORBIDDEN, $response->get_data(), $case);
            // The resolver itself audits a contested page (deduped per request),
            // which is the incident record, not a write of this route.
            self::assertSame($this->withoutAmbiguityAudit($before), $this->withoutAmbiguityAudit($this->snapshot()), $case . ' must write nothing');
        }
        self::assertSame([], $GLOBALS['__bcc_group_creates']);
    }

    public function testSignedOutIs401AndThrottledIs429BeforeAuthorityIsConsulted(): void
    {
        $validatorId = $this->seedClaimedPage();
        $before      = $this->snapshot();

        $GLOBALS['__bcc_current_user'] = 0;
        $response = $this->post(self::PAGE, $validatorId, '1');
        self::assertSame(401, $response->get_status());
        self::assertSame('bcc_unauthorized', $response->get_data()['error']['code']);
        self::assertSame([], $GLOBALS['__bcc_throttle_calls'], 'auth comes before throttle');

        // A stranger who is throttled hears 429, not 403: throttle comes first.
        $GLOBALS['__bcc_current_user']    = self::STRANGER;
        $GLOBALS['__bcc_throttle_denied'] = true;
        $response = $this->post(self::PAGE, $validatorId, '1');
        self::assertSame(429, $response->get_status());
        self::assertSame('bcc_rate_limited', $response->get_data()['error']['code']);

        self::assertSame($before, $this->snapshot());
    }

    public function testAValidatorNotBoundToThePageIs404(): void
    {
        $this->seedClaimedPage();
        $elsewhere = $this->insertValidator($this->cosmosChain, null, self::VALOPER . 'x', 'Elsewhere');
        $this->bindPlaceholder(self::OTHER_PAGE, $elsewhere);
        $before = $this->snapshot();

        foreach ([$elsewhere, 0, -1, '12x', null, 99999999] as $validator) {
            $response = $this->dispatchPost(self::PAGE, ['validator_id' => $validator, 'min_stake' => '1']);

            self::assertSame(404, $response->get_status(), var_export($validator, true));
            self::assertSame('bcc_not_found', $response->get_data()['error']['code']);
        }
        self::assertSame($before, $this->snapshot());
    }

    public function testAnUnsupportedChainIs400(): void
    {
        $validatorId = $this->insertValidator($this->evmChain, null, '0xfixtureevm', 'EVM Validator');
        $this->bindPlaceholder(self::PAGE, $validatorId);
        $this->insertClaim(self::OPERATOR, $validatorId);
        $before = $this->snapshot();

        $response = $this->post(self::PAGE, $validatorId, '1');

        self::assertSame(400, $response->get_status());
        self::assertSame('bcc_delegator_group_unavailable', $response->get_data()['error']['code']);
        self::assertSame($before, $this->snapshot());
    }

    /** @return list<mixed> */
    private static function invalidMinimums(): array
    {
        return [
            '', ' ', '0', '0.0', '0.000000', '-1', '+1', '1e3', '1E3', '1e-3', ' 1', '1 ', "1\n", "\t1",
            '1.', '.5', '01', '00.5', '1.2345678', '0.0000001', '1,000', '1.5.5', 'abc', '0x10', 'NaN', 'INF',
            '1000000001', '1000000000.000001', '99999999999999999999', "\u{0661}",
            1, 1.5, true, false, null, ['1'], [],
        ];
    }

    public function testEveryInvalidMinimumIs422OnCreateAndCreatesNothing(): void
    {
        $validatorId = $this->seedClaimedPage();
        $before      = $this->snapshot();

        foreach (self::invalidMinimums() as $bad) {
            $response = $this->dispatchPost(self::PAGE, ['validator_id' => $validatorId, 'min_stake' => $bad]);

            self::assertSame(422, $response->get_status(), var_export($bad, true));
            self::assertSame('bcc_invalid_request', $response->get_data()['error']['code']);
        }
        $response = $this->dispatchPost(self::PAGE, ['validator_id' => $validatorId]);
        self::assertSame(422, $response->get_status(), 'an absent minimum');

        self::assertSame($before, $this->snapshot());
    }

    public function testValidMinimumsAreAcceptedAndStoredCanonically(): void
    {
        foreach ([
            '1'                 => '1',
            '0.000001'          => '0.000001',
            '0.5'               => '0.5',
            '1.50'              => '1.5',
            '1.000000'          => '1',
            '999999999.999999'  => '999999999.999999',
            '1000000000'        => '1000000000',
            '1000000000.000000' => '1000000000',
        ] as $input => $stored) {
            self::assertSame($stored, ValidatorGroupProvisioningService::parseMinStake((string) $input), (string) $input);
        }
    }

    /**
     * auth, throttle, authority, binding, chain, min_stake, exists: each step
     * proven to answer ahead of the one after it.
     */
    public function testTheCheckOrderIsTheSpecifiedOne(): void
    {
        $validatorId = $this->seedClaimedPage();
        $elsewhere   = $this->insertValidator($this->cosmosChain, null, self::VALOPER . 'x', 'Elsewhere');

        $evm = $this->insertValidator($this->evmChain, null, '0xfixtureevm', 'EVM');
        $this->insertPage(self::OTHER_PAGE + 1, 'EVM page');
        $this->bindPlaceholder(self::OTHER_PAGE + 1, $evm);
        $this->insertClaim(self::OPERATOR, $evm);

        // authority before binding, and before any body check
        $GLOBALS['__bcc_current_user'] = self::STRANGER;
        self::assertSame(403, $this->post(self::PAGE, $elsewhere, 'garbage')->get_status());

        // binding before chain and min_stake
        $GLOBALS['__bcc_current_user'] = self::OPERATOR;
        self::assertSame(404, $this->post(self::PAGE, $evm, 'garbage')->get_status());

        // chain before min_stake
        self::assertSame(400, $this->post(self::OTHER_PAGE + 1, $evm, 'garbage')->get_status());

        // min_stake before exists
        self::assertSame(201, $this->post(self::PAGE, $validatorId, '1')->get_status());
        self::assertSame(422, $this->post(self::PAGE, $validatorId, 'garbage')->get_status());
        self::assertSame(409, $this->post(self::PAGE, $validatorId, '1')->get_status());
    }

    // ── One group per page ──────────────────────────────────────────────

    public function testASecondCreateIs409WithTheExistingGroup(): void
    {
        $validatorId = $this->seedClaimedPage();
        $first       = $this->post(self::PAGE, $validatorId, '3')->get_data()['data']['group'];
        $before      = $this->snapshot();

        $response = $this->post(self::PAGE, $validatorId, '9');

        self::assertSame(409, $response->get_status());
        $error = $response->get_data()['error'];
        self::assertSame('bcc_conflict', $error['code']);
        self::assertSame($first, $error['data']['group']);
        self::assertSame('3', $error['data']['group']['min_stake'], 'the refused value must not have landed');
        self::assertSame($before, $this->snapshot());
    }

    public function testASecondGroupOnTheSamePageIsRefusedForADifferentBoundValidator(): void
    {
        [$validatorOne, $validatorTwo] = $this->seedPageWithTwoValidators();

        // The group goes on the validator listed SECOND, so a check that only
        // looked at the page's first row would find nothing and create.
        $first = $this->post(self::PAGE, $validatorTwo, '1');
        self::assertSame(201, $first->get_status());
        $before = $this->snapshot();

        $second = $this->post(self::PAGE, $validatorOne, '1');

        self::assertSame(409, $second->get_status());
        self::assertSame('bcc_conflict', $second->get_data()['error']['code']);
        self::assertSame($first->get_data()['data']['group'], $second->get_data()['error']['data']['group']);
        self::assertSame($before, $this->snapshot());
        self::assertCount(1, $GLOBALS['__bcc_group_creates']);
    }

    public function testTheOperatorPicksWhichBoundValidatorTheGateVerifies(): void
    {
        [, $validatorTwo] = $this->seedPageWithTwoValidators();

        $group  = $this->post(self::PAGE, $validatorTwo, '1')->get_data()['data']['group'];
        $config = ValidatorGroupRepository::getGateConfig($group['id']);

        self::assertNotNull($config);
        self::assertSame($validatorTwo, $config->validatorId);
        self::assertSame($this->otherCosmosChain, $config->chainId);
        self::assertSame(self::VALOPER . '2', $config->operatorAddress);
        self::assertSame('1 OSMO', $group['min_stake_display'], 'the token is the chosen validator\'s chain');
        self::assertSame($validatorTwo, $group['validator']['id']);
        self::assertSame('Moniker Two', $group['validator']['name']);
        self::assertSame('osmosis', $group['validator']['chain_slug']);
    }

    /**
     * Chain and operator come from the bound row. Anything the request says
     * about them is ignored, on either leg of the binding.
     */
    public function testChainAndOperatorComeFromTheDatabaseRowNeverTheRequest(): void
    {
        $validatorId = $this->seedClaimedPage();

        $response = $this->dispatchPost(self::PAGE, [
            'validator_id'     => $validatorId,
            'min_stake'        => '1',
            'chain_id'         => $this->otherCosmosChain,
            'chain_slug'       => 'osmosis',
            'operator_address' => 'cosmosvaloper1attacker',
        ]);

        self::assertSame(201, $response->get_status());
        $config = ValidatorGroupRepository::getGateConfig($response->get_data()['data']['group']['id']);
        self::assertNotNull($config);
        self::assertSame($this->cosmosChain, $config->chainId);
        self::assertSame(self::VALOPER, $config->operatorAddress);
    }

    public function testAnotherPagesValidatorIsRefusedOnBothLegsOfTheBinding(): void
    {
        [$linkedOne] = $this->seedPageWithTwoValidators();

        $metaBound = $this->insertValidator($this->cosmosChain, null, self::VALOPER . 'm', 'Meta Bound');
        $this->bindPlaceholder(self::OTHER_PAGE, $metaBound);
        $this->insertClaim(self::OPERATOR, $metaBound);
        $before = $this->snapshot();

        // Post-meta leg's validator named on the wallet-link page, and the reverse.
        self::assertSame(404, $this->post(self::PAGE, $metaBound, '1')->get_status());
        self::assertSame(404, $this->post(self::OTHER_PAGE, $linkedOne, '1')->get_status());
        self::assertSame($before, $this->snapshot());

        // Each is fine on its own page.
        self::assertSame(201, $this->post(self::PAGE, $linkedOne, '1')->get_status());
        self::assertSame(201, $this->post(self::OTHER_PAGE, $metaBound, '1')->get_status());
    }

    // ── Two creates at once ─────────────────────────────────────────────

    /**
     * A second real MySQL session, from inside the critical section: at the
     * instant the group is being created it cannot take the page lock, and once
     * the request is over it can.
     */
    public function testTheLockIsHeldAcrossTheCreateAndReleasedAfter(): void
    {
        $validatorId = $this->seedClaimedPage();
        $peer        = $this->peerConnection();
        $lock        = 'bcc_delegator_group:' . self::PAGE;

        $observed = null;
        $GLOBALS['__bcc_group_on_create'] = function () use ($peer, $lock, &$observed): void {
            $observed = $peer->query("SELECT GET_LOCK('{$lock}', 0)")->fetch_row()[0];
        };

        self::assertSame(201, $this->post(self::PAGE, $validatorId, '1')->get_status());

        self::assertSame('0', (string) $observed, 'a peer must not be able to enter while a create is running');
        self::assertSame('1', (string) $peer->query("SELECT IS_FREE_LOCK('{$lock}')")->fetch_row()[0], 'GET_LOCK must be matched by a release');
    }

    /**
     * The real race, from the loser's side. A separate OS process holds the page
     * lock and writes the winner's group while this request is already waiting
     * on it. The loser has to come out with the winner's group and no second
     * one. Without the lock this request reads "none exists" and creates.
     */
    public function testALoserOfTheRaceWaitsAndAnswers409WithTheWinnersGroup(): void
    {
        $validatorId = $this->seedClaimedPage();

        $script = <<<'PHP'
            $c = mysqli_connect(getenv('BCC_TEST_DB_HOST') ?: '127.0.0.1', getenv('BCC_TEST_DB_USER') ?: 'root', getenv('BCC_TEST_DB_PASS') ?: 'root', getenv('RACE_DB'), (int) (getenv('BCC_TEST_DB_PORT') ?: 10005));
            $c->query("SELECT GET_LOCK('" . getenv('RACE_LOCK') . "', 10)");
            usleep(1000000);
            $c->query("INSERT INTO wp_posts (post_title, post_name, post_content, post_type, post_status) VALUES ('Delegators: Winner', 'winner', '', 'peepso-group', 'publish')");
            $id = (int) $c->insert_id;
            foreach ([['_bcc_group_kind', 'delegators'], ['_bcc_gate_chain_id', getenv('RACE_CHAIN')], ['_bcc_gate_validator_id', getenv('RACE_VALIDATOR')], ['_bcc_gate_operator_address', 'cosmosvaloper1winner'], ['_bcc_gate_min_stake', '7']] as [$k, $v]) {
                $c->query("INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES ({$id}, '{$k}', '{$v}')");
            }
            $c->query("SELECT RELEASE_LOCK('" . getenv('RACE_LOCK') . "')");
            PHP;

        global $wpdb;
        $env = array_merge(getenv(), [
            'RACE_DB'        => (string) $wpdb->get_var('SELECT DATABASE()'),
            'RACE_LOCK'      => 'bcc_delegator_group:' . self::PAGE,
            'RACE_CHAIN'     => (string) $this->cosmosChain,
            'RACE_VALIDATOR' => (string) $validatorId,
        ]);
        $process = proc_open([PHP_BINARY, '-r', $script], [], $pipes, null, $env);
        self::assertIsResource($process);

        // ⚠ Asked of MySQL, not of a pipe from the peer. On Windows fgets() on a
        // proc_open pipe can block until the child exits, so waiting on one made
        // this test run after the race was over, and it passed with the lock
        // removed. A mutation caught that.
        $held = false;
        for ($i = 0; $i < 200 && !$held; $i++) {
            $held = $wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s)', $env['RACE_LOCK'])) !== null;
            if (!$held) {
                usleep(25000);
            }
        }
        self::assertTrue($held, 'the peer must hold the lock first');

        $winnerAlreadyDone = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->posts}` WHERE post_type = 'peepso-group'");
        self::assertSame(0, $winnerAlreadyDone, 'the request must start while the race is still open');

        $response = $this->post(self::PAGE, $validatorId, '1');

        proc_close($process);

        self::assertSame(409, $response->get_status());
        self::assertSame('winner', $response->get_data()['error']['data']['group']['slug']);
        self::assertSame('7', $response->get_data()['error']['data']['group']['min_stake']);
        self::assertSame([], $GLOBALS['__bcc_group_creates'], 'the loser must not have created anything');
        self::assertSame(1, (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->posts}` WHERE post_type = 'peepso-group'"));
    }

    public function testALockThatCannotBeTakenRefusesAndCreatesNothing(): void
    {
        $validatorId = $this->seedClaimedPage();
        $peer        = $this->peerConnection();
        $lock        = 'bcc_delegator_group:' . self::PAGE;
        self::assertSame('1', (string) $peer->query("SELECT GET_LOCK('{$lock}', 0)")->fetch_row()[0]);
        $before = $this->snapshot();

        $response = $this->post(self::PAGE, $validatorId, '1');

        self::assertSame(503, $response->get_status());
        self::assertSame($before, $this->snapshot());
        self::assertSame([], $GLOBALS['__bcc_group_creates']);
    }

    // ── A create that fails part-way ────────────────────────────────────

    public function testAFailedPeepSoCreateAnswers503AndReleasesTheLock(): void
    {
        $validatorId = $this->seedClaimedPage();
        $GLOBALS['__bcc_group_on_create'] = static function (): void {
            throw new \RuntimeException('peepso refused');
        };
        $before = $this->snapshot();

        $response = $this->post(self::PAGE, $validatorId, '1');

        self::assertSame(503, $response->get_status());
        self::assertSame($before, $this->snapshot());
        self::assertSame(
            '1',
            (string) $this->peerConnection()->query("SELECT IS_FREE_LOCK('bcc_delegator_group:" . self::PAGE . "')")->fetch_row()[0]
        );
    }

    /**
     * A group whose gate didn't land is an ordinary closed group the exists
     * check can't see. It has to be taken back out, or a retry makes a second.
     */
    public function testAGateThatDoesNotLandTakesTheGroupBackOut(): void
    {
        $validatorId = $this->seedClaimedPage();
        global $wpdb;
        $wpdb->failQueriesMatching = '/_bcc_gate_min_stake/';

        $response = $this->post(self::PAGE, $validatorId, '1');
        $broken   = $wpdb->injectedFailures;
        $wpdb->clearFaultInjection();

        self::assertGreaterThan(0, $broken, 'the fault must really have fired');
        self::assertSame(503, $response->get_status());
        self::assertCount(1, $GLOBALS['__bcc_group_creates'], 'it did get as far as a group');
        self::assertSame(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->postmeta}` WHERE meta_key LIKE '\\_bcc\\_%' AND post_id <> " . self::PAGE));
        self::assertSame(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->posts}` WHERE post_type = 'peepso-group'"));
        self::assertSame(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->prefix}peepso_group_members`"));
    }

    // ── PATCH ───────────────────────────────────────────────────────────

    public function testTheOperatorEditsTheMinimumAndTheChangeIsAudited(): void
    {
        $validatorId = $this->seedClaimedPage();
        $groupId     = $this->post(self::PAGE, $validatorId, '1')->get_data()['data']['group']['id'];

        $response = $this->patch(self::PAGE, '12.5');

        self::assertSame(200, $response->get_status());
        $group = $response->get_data()['data']['group'];
        self::assertSame($groupId, $group['id']);
        self::assertSame('12.5', $group['min_stake']);
        self::assertSame('12.5 ATOM', $group['min_stake_display']);
        self::assertSame('12.5', ValidatorGroupRepository::readMinStake($groupId));
        self::assertSame(12.5, ValidatorGroupRepository::getGateConfig($groupId)?->minStake);
        self::assertSame('private, no-store', $response->get_headers()['Cache-Control']);

        $audit = $this->auditRows('validator_group_min_stake_changed');
        self::assertCount(1, $audit);
        self::assertSame(self::OPERATOR, (int) $audit[0]->user_id);
        self::assertSame($groupId, (int) $audit[0]->target_id);
        $meta = json_decode((string) $audit[0]->meta, true);
        self::assertSame('1', $meta['old_min_stake']);
        self::assertSame('12.5', $meta['new_min_stake']);
    }

    public function testAnIdenticalValueIsA200NoOpWithNoAudit(): void
    {
        $validatorId = $this->seedClaimedPage();
        $created     = $this->post(self::PAGE, $validatorId, '1.5')->get_data()['data']['group'];
        $before      = $this->snapshot();

        foreach (['1.5', '1.50', '1.500000'] as $same) {
            $response = $this->patch(self::PAGE, $same);

            self::assertSame(200, $response->get_status(), $same);
            self::assertSame($created, $response->get_data()['data']['group'], $same);
        }

        self::assertSame($before, $this->snapshot());
        self::assertSame(0, $this->auditCount('validator_group_min_stake_changed'));
    }

    public function testEveryNonOperatorIsRefusedAPatchAndNothingChanges(): void
    {
        $validatorId = $this->seedClaimedPage();
        $groupId     = $this->post(self::PAGE, $validatorId, '1')->get_data()['data']['group']['id'];

        $elsewherePage = self::PAGE + 6;
        $this->insertPage($elsewherePage, 'Elsewhere');
        $elsewhere = $this->insertValidator($this->cosmosChain, null, self::VALOPER . 'e', 'Elsewhere');
        $this->bindPlaceholder($elsewherePage, $elsewhere);
        $this->insertClaim(self::RIVAL, $elsewhere);
        $before = $this->snapshot();

        foreach (['stranger' => self::STRANGER, 'other page operator' => self::RIVAL] as $case => $viewer) {
            $GLOBALS['__bcc_current_user'] = $viewer;
            $response = $this->patch(self::PAGE, '99');
            self::assertSame(403, $response->get_status(), $case);
            self::assertSame(self::FORBIDDEN, $response->get_data(), $case);
        }

        $GLOBALS['__bcc_current_user'] = 0;
        $response = $this->patch(self::PAGE, '99');
        self::assertSame(401, $response->get_status());

        self::assertSame($before, $this->snapshot());
        self::assertSame('1', ValidatorGroupRepository::readMinStake($groupId));
    }

    /**
     * The authority is the page, NOT group ownership. Both directions: the
     * person who created the group and lost the page can't edit it, and the
     * operator who holds the page now can, without owning the group.
     */
    public function testPatchAuthorityFollowsThePageNotTheGroupOwner(): void
    {
        $validatorId = $this->seedClaimedPage();
        $groupId     = $this->post(self::PAGE, $validatorId, '1')->get_data()['data']['group']['id'];
        self::assertSame('member_owner', $this->memberStatus(self::OPERATOR, $groupId));

        global $wpdb;
        $wpdb->query("DELETE FROM `{$wpdb->prefix}bcc_onchain_claims`");
        $this->insertClaim(self::RIVAL, $validatorId);

        $response = $this->patch(self::PAGE, '5');
        self::assertSame(403, $response->get_status(), 'the group owner no longer holds the page');
        self::assertSame(self::FORBIDDEN, $response->get_data());
        self::assertSame('1', ValidatorGroupRepository::readMinStake($groupId));

        $GLOBALS['__bcc_current_user'] = self::RIVAL;
        self::assertSame(200, $this->patch(self::PAGE, '5')->get_status(), 'the current operator owns no group row');
        self::assertSame('5', ValidatorGroupRepository::readMinStake($groupId));
    }

    public function testAContestedPageRefusesAPatch(): void
    {
        $validatorId = $this->seedClaimedPage();
        $groupId     = $this->post(self::PAGE, $validatorId, '1')->get_data()['data']['group']['id'];
        $this->insertClaim(self::RIVAL, $validatorId);

        $response = $this->patch(self::PAGE, '5');

        self::assertSame(self::FORBIDDEN, $response->get_data());
        self::assertSame('1', ValidatorGroupRepository::readMinStake($groupId));
    }

    public function testEveryInvalidMinimumIs422OnPatchAndChangesNothing(): void
    {
        $validatorId = $this->seedClaimedPage();
        $this->post(self::PAGE, $validatorId, '1');
        $before = $this->snapshot();

        foreach (self::invalidMinimums() as $bad) {
            $response = (new PageDelegatorGroupEndpoint())->updateMinStake(
                new \WP_REST_Request(['id' => self::PAGE, 'min_stake' => $bad])
            );

            self::assertSame(422, $response->get_status(), var_export($bad, true));
            self::assertSame('bcc_invalid_request', $response->get_data()['error']['code']);
        }

        self::assertSame($before, $this->snapshot());
    }

    public function testPatchOnAPageWithNoGroupIs404(): void
    {
        $this->seedClaimedPage();
        $before = $this->snapshot();

        $response = $this->patch(self::PAGE, '5');

        self::assertSame(404, $response->get_status());
        self::assertSame('bcc_not_found', $response->get_data()['error']['code']);
        self::assertSame($before, $this->snapshot());
    }

    public function testAMinimumThatDoesNotLandIs503AndIsNotAudited(): void
    {
        $validatorId = $this->seedClaimedPage();
        $groupId     = $this->post(self::PAGE, $validatorId, '1')->get_data()['data']['group']['id'];
        global $wpdb;
        $wpdb->failQueriesMatching = '/^UPDATE `wp_postmeta` SET meta_value/';

        $response = $this->patch(self::PAGE, '5');
        $broken   = $wpdb->injectedFailures;
        $wpdb->clearFaultInjection();

        self::assertGreaterThan(0, $broken);
        self::assertSame(503, $response->get_status());
        self::assertSame('1', ValidatorGroupRepository::readMinStake($groupId));
        self::assertSame(0, $this->auditCount('validator_group_min_stake_changed'));
    }

    // ── Verified join, through the route ────────────────────────────────

    public function testDelegatingAtOrAboveTheMinimumJoinsAndBelowRefuses(): void
    {
        $groupId = $this->createdGroup('2.5');

        foreach ([
            'at the minimum' => [701, 2.5, true],
            'above it'       => [702, 2.500001, true],
            'just below it'  => [703, 2.499999, false],
            'no delegation'  => [704, 0.0, false],
        ] as $case => [$user, $amount, $joins]) {
            $this->giveWalletDelegating($user, $amount);
            $GLOBALS['__bcc_current_user'] = $user;

            $response = $this->join($groupId);

            if ($joins) {
                self::assertSame(200, $response->get_status(), $case);
                self::assertSame('ok', $response->get_data()['data']['code'], $case);
                self::assertSame('member', $this->memberStatus($user, $groupId), $case);
                continue;
            }
            self::assertSame(403, $response->get_status(), $case);
            self::assertSame('bcc_permission_denied', $response->get_data()['error']['code'], $case);
            self::assertStringContainsString('2.5 ATOM', $response->get_data()['error']['message'], $case);
            self::assertNull($this->memberStatus($user, $groupId), $case);
        }
    }

    /**
     * A sub-1 minimum end to end. `JoinResult::minBalance` rounds it up to 1 for
     * shape parity, which would refuse a 0.5 delegator if anything compared
     * against it. The verdict must use the real value, and the refusal copy has
     * to name it rather than falling back to "delegate to this validator".
     */
    public function testADecimalMinimumBelowOneIsEnforcedExactly(): void
    {
        $groupId = $this->createdGroup('0.5');

        $this->giveWalletDelegating(701, 0.5);
        $GLOBALS['__bcc_current_user'] = 701;
        self::assertSame(200, $this->join($groupId)->get_status());
        self::assertSame('member', $this->memberStatus(701, $groupId));

        $this->giveWalletDelegating(702, 0.499999);
        $GLOBALS['__bcc_current_user'] = 702;
        $refused = $this->join($groupId);
        self::assertSame(403, $refused->get_status());
        self::assertStringContainsString('0.5 ATOM', $refused->get_data()['error']['message']);
        self::assertNull($this->memberStatus(702, $groupId));
    }

    public function testAnLcdOutageRefusesTheJoinWithTheTransientError(): void
    {
        $groupId = $this->createdGroup('1');
        $this->insertWallet(701, $this->cosmosChain, 'cosmos1outage');
        $GLOBALS['__bcc_lcd']['cosmos1outage'] = null;
        $GLOBALS['__bcc_current_user']         = 701;

        $response = $this->join($groupId);

        self::assertSame(503, $response->get_status());
        self::assertSame('bcc_upstream_unavailable', $response->get_data()['error']['code']);
        self::assertSame(['cosmos1outage'], $GLOBALS['__bcc_lcd_calls'], 'the outage must have been reached');
        self::assertNull($this->memberStatus(701, $groupId));
    }

    /**
     * The delegation is NOT re-fetched between these joins: the wallet's set is
     * in the 5-minute cache throughout. What changes is the minimum, and the
     * verdict has to follow it at once, because a set is cached and a verdict
     * never is.
     */
    public function testAJoinUsesTheNewMinimumImmediately(): void
    {
        $groupId = $this->createdGroup('1');
        $this->giveWalletDelegating(701, 5.0);

        self::assertSame(200, $this->patch(self::PAGE, '6')->get_status());
        $GLOBALS['__bcc_current_user'] = 701;
        $refused = $this->join($groupId);
        self::assertSame(403, $refused->get_status(), 'raised above the delegation');
        self::assertStringContainsString('6 ATOM', $refused->get_data()['error']['message']);
        self::assertNull($this->memberStatus(701, $groupId));

        $GLOBALS['__bcc_current_user'] = self::OPERATOR;
        self::assertSame(200, $this->patch(self::PAGE, '5')->get_status());
        $GLOBALS['__bcc_current_user'] = 701;
        self::assertSame(200, $this->join($groupId)->get_status(), 'lowered to the delegation');
        self::assertSame('member', $this->memberStatus(701, $groupId));
        self::assertCount(1, $GLOBALS['__bcc_lcd_calls'], 'the LCD was asked once, so only the minimum moved');
    }

    /** Same status and code as a shortfall; only the reason tells them apart. */
    public function testAnOptOutCooldownRefusalReadsDifferentlyFromAShortfall(): void
    {
        $groupId = $this->createdGroup('1');
        $this->giveWalletDelegating(701, 5.0);
        (new NftGroupGateService())->recordOptOut(701, $groupId);
        $GLOBALS['__bcc_current_user'] = 701;

        $response = $this->join($groupId);

        self::assertSame(403, $response->get_status());
        self::assertSame('bcc_permission_denied', $response->get_data()['error']['code']);
        self::assertSame('opt_out_active', $response->get_data()['error']['data']['reason_code']);
        self::assertNull($this->memberStatus(701, $groupId));

        $GLOBALS['__bcc_current_user'] = 702;
        $this->insertWallet(702, $this->cosmosChain, 'cosmos1wallet702');
        self::assertArrayNotHasKey('data', $this->join($groupId)->get_data()['error']);
    }

    public function testTheGroupDetailStatsNameTheChain(): void
    {
        $validatorId = $this->seedClaimedPage();
        $rows        = ValidatorRepository::findCommunityStatsByIds([$validatorId]);

        $stats = ValidatorGroupsEndpoint::composeValidatorStats($rows[$validatorId], 1.0);

        self::assertNotNull($stats);
        self::assertNotSame('', (string) $stats['chain_name']);
        self::assertSame($rows[$validatorId]->chain_name, $stats['chain_name']);
    }

    public function testTheGroupDetailHintNamesTheCurrentMinimumWhenKnown(): void
    {
        $service = (new \ReflectionClass(GroupsService::class))->newInstanceWithoutConstructor();
        $method  = new \ReflectionMethod(GroupsService::class, 'resolveCanJoin');
        $context = new GroupContext(1, GroupType::Validator, PeepSoPrivacy::Closed, false, 'validator', 1, null);

        self::assertSame(
            [
                'allowed'     => false,
                'unlock_hint' => 'Delegate at least 2.5 ATOM to this validator to join its community.',
                'reason_code' => 'not_eligible',
            ],
            $method->invoke($service, $context, 701, false, false, 0, '2.5 ATOM')
        );
        self::assertSame(
            [
                'allowed'     => false,
                'unlock_hint' => 'Delegate to this validator to join its community.',
                'reason_code' => 'not_eligible',
            ],
            $method->invoke($service, $context, 701, false, false, 0, null)
        );
    }

    // ── The revoke sweep, after the minimum changes ─────────────────────

    /**
     * The whole of the RAISED rule, on the real hook handler with the real
     * opt-out listener bound: the member between old and new goes, nobody else
     * does, no opt-out of any kind is written, and they can come back.
     */
    public function testARaiseRemovesTheMemberBetweenOldAndNewWithNoOptOutAndTheyCanRejoin(): void
    {
        $groupId = $this->createdGroup('1');
        $this->joinDelegating(self::MEMBER, 5.0, $groupId);
        $this->joinDelegating(self::WELL_STAKED, 50.0, $groupId);

        self::assertSame(200, $this->patch(self::PAGE, '10')->get_status());
        self::assertSame('member', $this->memberStatus(self::MEMBER, $groupId), 'the PATCH itself removes nobody');

        $this->runRevokeTick();

        self::assertNull($this->memberStatus(self::MEMBER, $groupId), 'between the old and new minimum');
        self::assertSame('member', $this->memberStatus(self::WELL_STAKED, $groupId), 'above the new minimum');
        self::assertSame('member_owner', $this->memberStatus(self::OPERATOR, $groupId));

        self::assertSame([], $GLOBALS['__bcc_usermeta'][self::MEMBER] ?? [], 'no opt-out of any kind');
        self::assertFalse(
            (new NftGroupGateService())->isOptOutActive(self::MEMBER, $groupId),
            'the gate must not see one either'
        );

        $revoked = $this->auditRows('validator_group_revoked');
        self::assertCount(1, $revoked);
        self::assertSame(self::MEMBER, (int) $revoked[0]->user_id);

        // Rejoining needs a fresh read of the chain; see the next test.
        $this->delegate(self::MEMBER, 10.0);
        $this->expireDelegationCache();
        $GLOBALS['__bcc_current_user'] = self::MEMBER;
        self::assertSame(200, $this->join($groupId)->get_status());
        self::assertSame('member', $this->memberStatus(self::MEMBER, $groupId));
    }

    /**
     * ⚠ PINS EXISTING BEHAVIOUR, it does not endorse it. The rule is "they can
     * rejoin at once by delegating enough". The eligibility service caches each
     * wallet's delegation set for five minutes, and the sweep tick that removed
     * this member has just filled that cache, so on a site with a persistent
     * object cache a top-up isn't seen until the entry expires. Without one the
     * cache lasts one request and the rejoin is immediate. Flagged to the
     * coordinator as a decision; when it is made this test changes with it.
     */
    public function testARejoinWithinTheCacheWindowStillSeesTheOldDelegationSet(): void
    {
        $groupId = $this->createdGroup('1');
        $this->joinDelegating(self::MEMBER, 5.0, $groupId);
        self::assertSame(200, $this->patch(self::PAGE, '10')->get_status());
        $this->runRevokeTick();
        self::assertNull($this->memberStatus(self::MEMBER, $groupId));

        $this->delegate(self::MEMBER, 10.0);
        $GLOBALS['__bcc_current_user'] = self::MEMBER;

        $response = $this->join($groupId);

        self::assertSame(403, $response->get_status());
        self::assertNull($this->memberStatus(self::MEMBER, $groupId));
    }

    public function testALowerRemovesNobody(): void
    {
        $groupId = $this->createdGroup('10');
        $this->joinDelegating(self::MEMBER, 10.0, $groupId);
        $this->joinDelegating(self::WELL_STAKED, 50.0, $groupId);

        self::assertSame(200, $this->patch(self::PAGE, '1')->get_status());
        $this->runRevokeTick();

        self::assertSame('member', $this->memberStatus(self::MEMBER, $groupId));
        self::assertSame('member', $this->memberStatus(self::WELL_STAKED, $groupId));
        self::assertSame('member_owner', $this->memberStatus(self::OPERATOR, $groupId));
        self::assertSame(0, $this->auditCount('validator_group_revoked'));
    }

    public function testTheOwnerIsNeverRemoved(): void
    {
        // The owner delegates nothing at all and the bar goes up.
        $groupId = $this->createdGroup('1');
        self::assertSame(200, $this->patch(self::PAGE, '1000')->get_status());

        $stats = OnchainPlugin::instance()->validatorGroupRevokeService()->sweep();

        self::assertSame(0, $stats['checked'], 'the owner is not even checked');
        self::assertSame('member_owner', $this->memberStatus(self::OPERATOR, $groupId));
        self::assertSame([], $GLOBALS['__bcc_lcd_calls']);
    }

    public function testAnLcdOutageRemovesNobody(): void
    {
        $groupId = $this->createdGroup('1');
        $this->joinDelegating(self::MEMBER, 5.0, $groupId);
        self::assertSame(200, $this->patch(self::PAGE, '10')->get_status());

        $this->expireDelegationCache();
        foreach (array_keys($GLOBALS['__bcc_lcd']) as $address) {
            $GLOBALS['__bcc_lcd'][$address] = null;
        }

        $stats = OnchainPlugin::instance()->validatorGroupRevokeService()->sweep();

        self::assertSame(1, $stats['checked']);
        self::assertSame(1, $stats['skipped_unknown']);
        self::assertSame(0, $stats['revoked']);
        self::assertSame('member', $this->memberStatus(self::MEMBER, $groupId));
        self::assertSame(0, $this->auditCount('validator_group_revoked'));
    }

    /**
     * ⚠ THE OFFSET-SKIP BUG. listGroupMembers() is OFFSET-paged, so a page of
     * removals moves every later row up under the offset, and adding the full page
     * size skipped as many members as were removed. Ten pages of nothing but
     * removals used to leave half the group until the next rotation.
     */
    public function testRemovalsDuringASweepSkipNoMember(): void
    {
        $groupId = $this->createdGroup('10');
        $below   = $this->addMembers($groupId, 2000, 150, 5.0);

        $stats = OnchainPlugin::instance()->validatorGroupRevokeService()->sweep();

        self::assertSame(150, $stats['checked']);
        self::assertSame(150, $stats['revoked'], 'one tick reaches every member');
        foreach ($below as $user) {
            self::assertNull($this->memberStatus($user, $groupId));
        }
        self::assertSame('member_owner', $this->memberStatus(self::OPERATOR, $groupId));
    }

    /**
     * Budget out mid-group, with removals mixed through what was already walked.
     * The saved offset must count survivors only: the next tick checks exactly the
     * rest, neither re-checking a kept member nor skipping one.
     */
    public function testTheBatchLimitStopsTheTickAndTheCursorResumesMidGroup(): void
    {
        $groupId = $this->createdGroup('10');
        global $wpdb;
        for ($i = 0; $i < 260; $i++) {
            $this->addMembers($groupId, 3000 + $i, 1, $i % 2 === 0 ? 5.0 : 50.0);
        }

        $service = OnchainPlugin::instance()->validatorGroupRevokeService();

        $first = $service->sweep();
        self::assertSame(\BCC\Trust\Onchain\Services\ValidatorGroupRevokeService::SWEEP_BATCH_SIZE, $first['checked']);
        $cursor = get_option('bcc_validator_group_revoke_cursor');
        self::assertSame($groupId, $cursor['group_id']);
        self::assertSame(1 + 200 - $first['revoked'], $cursor['offset'], 'owner plus the members still present');

        $GLOBALS['__bcc_lcd_calls'] = [];
        $second = $service->sweep();

        self::assertSame(60, $second['checked'], 'exactly the members the first tick did not reach');
        self::assertSame(130, $first['revoked'] + $second['revoked']);
        self::assertSame(
            131 + 0,
            (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM `{$wpdb->prefix}peepso_group_members` WHERE gm_group_id = %d",
                $groupId
            )),
            'the owner and the 130 above the bar'
        );
    }

    public function testTheBatchFilterIsTheLimitWhenSet(): void
    {
        self::assertStringContainsString(
            "apply_filters('bcc_validator_group_revoke_batch_size', self::SWEEP_BATCH_SIZE)",
            (string) file_get_contents(dirname(__DIR__, 2) . '/app/Domain/Onchain/Services/ValidatorGroupRevokeService.php')
        );
    }

    /**
     * Between the list read and the leave, the member becomes the owner: the real
     * writer refuses. They stay, nothing is recorded or sent, the next member is
     * still reached, and the sweep ends.
     */
    public function testARefusedLeaveChangesNothingAndStillAdvances(): void
    {
        $groupId = $this->createdGroup('10');
        $this->addMembers($groupId, 2000, 3, 5.0);

        global $wpdb;
        $promoted = false;
        $GLOBALS['__bcc_lcd_on_fetch'] = function (string $address) use (&$promoted, $wpdb, $groupId): void {
            if ($promoted) {
                return;
            }
            $promoted = true;
            $user     = (int) substr($address, strlen('cosmos1wallet'));
            $wpdb->query($wpdb->prepare(
                "UPDATE `{$wpdb->prefix}peepso_group_members` SET gm_user_status = 'member_owner' WHERE gm_group_id = %d AND gm_user_id = %d",
                $groupId,
                $user
            ));
        };

        $stats = OnchainPlugin::instance()->validatorGroupRevokeService()->sweep();

        self::assertSame(3, $stats['checked']);
        self::assertSame(2, $stats['revoked'], 'the two whose leave was not refused');
        self::assertSame(2, $this->auditCount('validator_group_revoked'));
        self::assertCount(2, $this->storedNotices());
    }

    public function testAnEligibilityErrorRemovesNobodyAndTheNextTickRetries(): void
    {
        $groupId = $this->createdGroup('10');
        $this->addMembers($groupId, 2000, 1, 5.0);

        $GLOBALS['__bcc_lcd_on_fetch'] = static function (): void {
            throw new \RuntimeException('lcd client blew up');
        };
        $service = OnchainPlugin::instance()->validatorGroupRevokeService();

        $stats = $service->sweep();
        self::assertSame(1, $stats['skipped_unknown']);
        self::assertSame(0, $stats['revoked']);
        self::assertSame('member', $this->memberStatus(2000, $groupId));
        self::assertSame([], $this->storedNotices());
        self::assertSame(0, $this->auditCount('validator_group_revoked'));

        // Same member, next tick, chain answering.
        $GLOBALS['__bcc_lcd_on_fetch'] = null;
        $stats = $service->sweep();
        self::assertSame(1, $stats['revoked']);
        self::assertNull($this->memberStatus(2000, $groupId));
    }

    public function testAnOutageTickIsRetriedByTheNextTick(): void
    {
        $groupId = $this->createdGroup('10');
        $this->addMembers($groupId, 2000, 1, 5.0);

        $GLOBALS['__bcc_lcd']['cosmos1wallet2000'] = null;
        $service = OnchainPlugin::instance()->validatorGroupRevokeService();
        self::assertSame(1, $service->sweep()['skipped_unknown']);
        self::assertSame('member', $this->memberStatus(2000, $groupId));
        self::assertSame([], $this->storedNotices());

        $this->delegate(2000, 5.0);
        self::assertSame(1, $service->sweep()['revoked']);
    }

    /**
     * Beyond the first 500, which the old fixed list never reached. Owner-only
     * groups spend no member budget, so the group budget is what ends a tick.
     */
    public function testTheSweepContinuesPastTheFirst500GroupsAndWraps(): void
    {
        $validatorId = $this->seedClaimedPage();
        $ids         = $this->insertGroups(520, $validatorId);

        self::assertSame(array_slice($ids, 0, 500), ValidatorGroupRepository::listValidatorGroupIdsAfter(0, 500));
        self::assertSame(array_slice($ids, 500), ValidatorGroupRepository::listValidatorGroupIdsAfter($ids[499], 500));
        self::assertSame([], ValidatorGroupRepository::listValidatorGroupIdsAfter($ids[519], 500));

        $late = $ids[510];
        $this->addMembers($late, 2000, 1, 0.5);
        $first = $ids[0];
        $this->addMembers($first, 2001, 1, 0.5);

        $service = OnchainPlugin::instance()->validatorGroupRevokeService();

        $tick = $service->sweep();
        self::assertSame(1, $tick['revoked'], 'the first 500 groups');
        self::assertNull($this->memberStatus(2001, $first));
        self::assertSame('member', $this->memberStatus(2000, $late), 'not reached yet');
        self::assertSame($ids[499] + 1, get_option('bcc_validator_group_revoke_cursor')['group_id']);

        $tick = $service->sweep();
        self::assertSame(1, $tick['revoked'], 'the group beyond the old cap');
        self::assertNull($this->memberStatus(2000, $late));

        // Wrapped: a new member of the first group is reached without restarting anything.
        $this->addMembers($first, 2002, 1, 0.5);
        $reached = 0;
        for ($i = 0; $i < 3 && $this->memberStatus(2002, $first) !== null; $i++) {
            $reached += $service->sweep()['revoked'];
        }
        self::assertSame(1, $reached);
    }

    public function testAGroupDeletedBetweenTicksNeitherRestartsNorSkips(): void
    {
        $validatorId = $this->seedClaimedPage();
        [$a, $b, $c] = $this->insertGroups(3, $validatorId);
        $this->addMembers($a, 2000, 1, 0.5);
        $this->addMembers($c, 2001, 1, 0.5);

        // Mid-group in b, deep enough that a kept offset would pass c's member.
        update_option('bcc_validator_group_revoke_cursor', ['group_id' => $b, 'offset' => 50], false);
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->posts}` WHERE ID = %d", $b));

        $stats = OnchainPlugin::instance()->validatorGroupRevokeService()->sweep();

        self::assertNull($this->memberStatus(2001, $c), 'the next group, from its top');
        self::assertNull($this->memberStatus(2000, $a), 'then round to the first');
        self::assertSame(2, $stats['revoked']);
    }

    /** A cursor saved by the code before this change means what it always did. */
    public function testAnExistingCursorResumesAtItsGroupAndOffset(): void
    {
        $validatorId = $this->seedClaimedPage();
        [$a, $b]     = $this->insertGroups(2, $validatorId);
        $this->addMembers($a, 2000, 1, 0.5);
        $this->addMembers($b, 2001, 2, 50.0);

        // Offset 1 is past b's owner row... which b doesn't have here, so past one
        // member: only the other is checked in b.
        update_option('bcc_validator_group_revoke_cursor', ['group_id' => $b, 'offset' => 1], false);

        $stats = OnchainPlugin::instance()->validatorGroupRevokeService()->sweep();

        self::assertSame(2, $stats['checked'], 'one in b at its offset, then the one in a');
        self::assertNull($this->memberStatus(2000, $a));
    }

    // ── The removal notice ──────────────────────────────────────────────

    public function testARealRemovalCreatesExactlyOneNoticeWithTheCurrentFacts(): void
    {
        $groupId = $this->createdGroup('1');
        $this->joinDelegating(self::MEMBER, 5.0, $groupId);
        self::assertSame(200, $this->patch(self::PAGE, '10.5')->get_status());

        $this->runRevokeTick();

        $stored = $this->storedNotices();
        self::assertCount(1, $stored);
        self::assertSame(self::MEMBER, (int) $stored[0]->not_user_id);
        self::assertSame('bcc_delegator_group_removed', $stored[0]->not_type);
        self::assertSame($groupId, (int) $stored[0]->not_external_id);
        self::assertLessThanOrEqual(200, strlen($stored[0]->not_message), 'PeepSo cuts the column at 200');

        // A later lower shows in the notice too: it reads the current minimum.
        $GLOBALS['__bcc_current_user'] = self::MEMBER;
        $items = \BCC\Trust\Core\Plugin::instance()->notificationViewService()->getNotifications(self::MEMBER, 20, null)['items'];

        self::assertCount(1, $items);
        $chain = ValidatorRepository::findCommunityStatsByIds([ValidatorGroupRepository::getGateConfig($groupId)->validatorId]);
        $chainName = array_values($chain)[0]->chain_name;
        self::assertSame(
            "You're no longer in Delegators: Fixture Moniker, the delegator group for Fixture Moniker on {$chainName}. "
            . "It requires at least 10.5 ATOM delegated, and we couldn't confirm that from your linked wallet. "
            . 'You can rejoin any time once you meet it.',
            $items[0]['message']
        );
        self::assertSame('bcc_delegator_group_removed', $items[0]['type']);
        self::assertStringStartsWith('/communities/', $items[0]['link']);

        // NotificationsPanel reads these and crashes without them. Self-sent, as
        // the rank notices are, so the actor is the member and fully resolved.
        self::assertSame(['id', 'handle', 'display_name', 'avatar_url'], array_keys($items[0]['actor']));
        self::assertSame(self::MEMBER, $items[0]['actor']['id']);
        self::assertSame('user' . self::MEMBER, $items[0]['actor']['handle']);
        self::assertSame('User ' . self::MEMBER, $items[0]['actor']['display_name']);
        self::assertNotSame('', $items[0]['actor']['avatar_url']);
    }

    public function testTheNoticeFallsBackToTheStoredRowWhenTheGroupHasGone(): void
    {
        $groupId = $this->createdGroup('1');
        $this->joinDelegating(self::MEMBER, 5.0, $groupId);
        self::assertSame(200, $this->patch(self::PAGE, '10')->get_status());
        $this->runRevokeTick();

        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->postmeta}` WHERE post_id = %d", $groupId));
        $wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->posts}` WHERE ID = %d", $groupId));

        $items = \BCC\Trust\Core\Plugin::instance()->notificationViewService()->getNotifications(self::MEMBER, 20, null)['items'];

        self::assertCount(1, $items);
        self::assertStringStartsWith("You're no longer in ", $items[0]['message']);
        self::assertSame('/', $items[0]['link']);
    }

    public function testThePreferenceOffCreatesNoNoticeAndTheRemovalStands(): void
    {
        $groupId = $this->createdGroup('1');
        $this->joinDelegating(self::MEMBER, 5.0, $groupId);
        \BCC\Trust\Core\Support\NotificationPrefs::writePartial(self::MEMBER, ['bell' => ['bcc_delegator_group_removed' => false]]);
        self::assertFalse(\BCC\Trust\Core\Support\NotificationPrefs::isBellEnabled(self::MEMBER, 'bcc_delegator_group_removed'));
        self::assertSame(200, $this->patch(self::PAGE, '10')->get_status());

        $this->runRevokeTick();

        self::assertNull($this->memberStatus(self::MEMBER, $groupId));
        self::assertSame([], $this->storedNotices());
    }

    public function testNoEmailOrPushIsAttemptedAndTheDigestLeavesItOut(): void
    {
        $groupId = $this->createdGroup('1');
        $this->joinDelegating(self::MEMBER, 5.0, $groupId);
        \BCC\Trust\Core\Support\NotificationPrefs::writePartial(self::MEMBER, ['email_digest' => true]);
        self::assertSame(200, $this->patch(self::PAGE, '10')->get_status());
        $GLOBALS['bcc_scheduled'] = [];

        $this->runRevokeTick();

        self::assertCount(1, $this->storedNotices());
        self::assertSame([], $GLOBALS['__bcc_mail'], 'no mail');
        self::assertSame([], $GLOBALS['bcc_scheduled'], 'no job scheduled, which is how a push would go out');

        $repository = new \BCC\Trust\Core\Repositories\NotificationRepository();
        self::assertSame([], $repository->findUnreadSince(self::MEMBER, '2000-01-01 00:00:00'), 'the digest email reads this');
        self::assertCount(1, $repository->findForUser(self::MEMBER, 10, 0), 'the bell does not');
    }

    public function testAnOutageOrTheOwnerCreatesNoNotice(): void
    {
        $groupId = $this->createdGroup('1');
        $this->joinDelegating(self::MEMBER, 5.0, $groupId);
        self::assertSame(200, $this->patch(self::PAGE, '10')->get_status());
        $this->expireDelegationCache();
        $GLOBALS['__bcc_lcd']['cosmos1wallet' . self::MEMBER] = null;

        OnchainPlugin::instance()->validatorGroupRevokeService()->sweep();

        self::assertSame('member', $this->memberStatus(self::MEMBER, $groupId));
        self::assertSame('member_owner', $this->memberStatus(self::OPERATOR, $groupId));
        self::assertSame([], $this->storedNotices());
    }

    /**
     * The notifier throwing: the member it was about stays removed and audited,
     * and the member after them is still reached.
     */
    public function testANotifierThatThrowsStopsNeitherTheRemovalNorTheSweep(): void
    {
        $groupId = $this->createdGroup('10');
        $this->addMembers($groupId, 2000, 3, 5.0);
        $calls   = 0;
        $service = new \BCC\Trust\Onchain\Services\ValidatorGroupRevokeService(
            OnchainPlugin::instance()->delegationEligibilityService(),
            static function () use (&$calls): void {
                $calls++;
                throw new \RuntimeException('bell is down');
            }
        );

        $stats = $service->sweep();

        self::assertSame(3, $calls);
        self::assertSame(3, $stats['revoked']);
        self::assertSame(3, $this->auditCount('validator_group_revoked'));
        foreach ([2000, 2001, 2002] as $user) {
            self::assertNull($this->memberStatus($user, $groupId));
        }
    }

    public function testAVoluntaryLeaveCreatesNoNoticeFromThisPath(): void
    {
        $groupId = $this->createdGroup('1');
        $this->joinDelegating(self::MEMBER, 5.0, $groupId);
        self::assertSame(1, $this->bindFromBootstrap('peepso_action_group_user_delete'));
        $GLOBALS['__bcc_current_user'] = self::MEMBER;

        $response = (new ValidatorGroupsEndpoint())->postLeave(new \WP_REST_Request(['id' => $groupId]));

        self::assertSame(200, $response->get_status());
        self::assertNull($this->memberStatus(self::MEMBER, $groupId));
        self::assertSame([], $this->storedNotices());
    }

    public function testAModeratorEvictionCreatesNoNoticeFromThisPath(): void
    {
        $groupId = $this->createdGroup('1');
        $this->joinDelegating(self::MEMBER, 5.0, $groupId);
        self::assertSame(1, $this->bindFromBootstrap('peepso_action_group_user_delete'));

        // A moderator acting: not the member, and not inside the sweep.
        self::assertTrue(\BCC\Core\PeepSo\PeepSoGroupWriter::leave(self::MEMBER, $groupId));

        self::assertNull($this->memberStatus(self::MEMBER, $groupId));
        self::assertSame([], $this->storedNotices());
        self::assertTrue(
            (new NftGroupGateService())->isOptOutActive(self::MEMBER, $groupId),
            'an eviction keeps its permanent opt-out'
        );
    }

    /**
     * A removal's own writes: the member row, one audit row, one notice. Nothing
     * else in any table this harness can see, and no opt-out.
     */
    public function testARemovalWritesOnlyItsMemberRowAuditRowAndNotice(): void
    {
        $groupId = $this->createdGroup('1');
        $this->joinDelegating(self::MEMBER, 5.0, $groupId);
        self::assertSame(200, $this->patch(self::PAGE, '10')->get_status());
        $before = $this->snapshot();
        $audit  = $this->auditCountAll();

        $this->runRevokeTick();

        $after = $this->snapshot();
        global $wpdb;
        self::assertSame($before[$wpdb->posts], $after[$wpdb->posts]);
        self::assertSame($before[$wpdb->postmeta], $after[$wpdb->postmeta]);
        self::assertSame($before['usermeta'], $after['usermeta'], 'no opt-out of any kind');
        self::assertSame($audit + 1, $this->auditCountAll());
        self::assertSame(1, $this->auditCount('validator_group_revoked'));
        self::assertCount(1, $this->storedNotices());
    }

    // ── The card block ──────────────────────────────────────────────────

    public function testTheCardBlockPerStateAndPerViewer(): void
    {
        $validatorId = $this->seedClaimedPage();

        // No group yet.
        $operator = $this->cardFor(self::OPERATOR);
        self::assertNull($operator['group']);
        self::assertTrue($operator['can_create']);
        self::assertFalse($operator['can_edit_min_stake']);
        self::assertSame(
            [[
                'validator_id' => $validatorId,
                'name'         => 'Fixture Moniker',
                'chain_slug'   => 'cosmos',
                'chain_name'   => $operator['options'][0]['chain_name'],
                'native_token' => 'ATOM',
            ]],
            $operator['options']
        );
        self::assertNull($operator['unavailable_reason']);

        foreach ([self::STRANGER, 0] as $viewer) {
            $view = $this->cardFor($viewer);
            self::assertNull($view['group']);
            self::assertFalse($view['can_create'], 'viewer ' . $viewer);
            self::assertFalse($view['can_edit_min_stake'], 'viewer ' . $viewer);
            self::assertSame($operator['options'], $view['options']);
        }

        // With a group: everyone sees it, only the operator may edit.
        $created  = $this->post(self::PAGE, $validatorId, '2.5')->get_data()['data']['group'];
        $operator = $this->cardFor(self::OPERATOR);
        self::assertSame($created, $operator['group']);
        self::assertFalse($operator['can_create']);
        self::assertTrue($operator['can_edit_min_stake']);

        foreach ([self::STRANGER, 0] as $viewer) {
            $view = $this->cardFor($viewer);
            self::assertSame($created, $view['group'], 'viewer ' . $viewer);
            self::assertFalse($view['can_create'], 'viewer ' . $viewer);
            self::assertFalse($view['can_edit_min_stake'], 'viewer ' . $viewer);
        }

        // The edit shows at once.
        self::assertSame(200, $this->patch(self::PAGE, '3')->get_status());
        self::assertSame('3', $this->cardFor(self::STRANGER)['group']['min_stake']);

        // Contested: the same operator loses both controls.
        $this->insertClaim(self::RIVAL, $validatorId);
        $contested = $this->cardFor(self::OPERATOR);
        self::assertFalse($contested['can_create']);
        self::assertFalse($contested['can_edit_min_stake']);
    }

    public function testTheCardBlockOnAnUnsupportedChainExplainsAndOffersNothing(): void
    {
        $validatorId = $this->insertValidator($this->evmChain, null, '0xfixtureevm', 'EVM');
        $this->bindPlaceholder(self::PAGE, $validatorId);
        $this->insertClaim(self::OPERATOR, $validatorId);

        $view = $this->cardFor(self::OPERATOR);

        self::assertFalse($view['can_create']);
        self::assertSame([], $view['options']);
        self::assertNull($view['group']);
        self::assertMatchesRegularExpression("/^Delegator groups aren't available on .+ yet\\.$/", (string) $view['unavailable_reason']);
    }

    public function testTheCardBlockOffersEachBoundValidatorAndClosesOnceThePageHasAGroup(): void
    {
        [$validatorOne, $validatorTwo] = $this->seedPageWithTwoValidators();

        $view = $this->cardFor(self::OPERATOR);
        self::assertTrue($view['can_create']);
        self::assertEqualsCanonicalizing(['Moniker One', 'Moniker Two'], array_column($view['options'], 'name'));
        self::assertEqualsCanonicalizing(
            [$validatorOne, $validatorTwo],
            array_column($view['options'], 'validator_id')
        );

        $created = $this->post(self::PAGE, $validatorTwo, '1')->get_data()['data']['group'];

        $view = $this->cardFor(self::OPERATOR);
        self::assertSame($created, $view['group']);
        self::assertFalse($view['can_create'], 'the page has its one group, whichever validator it verifies');
    }

    /**
     * The block sits on an anonymous surface, and a valoper converts to the
     * operator's account address. See docs/wallet-privacy-policy.md. The binding
     * uses the address server-side; no response carries it.
     */
    public function testNoSerializedResponseCarriesTheOperatorAddress(): void
    {
        $validatorId = $this->seedClaimedPage();

        $before = (string) json_encode($this->cardFor(0));
        self::assertStringNotContainsString(self::VALOPER, $before);

        $created = $this->post(self::PAGE, $validatorId, '1');
        $patched = $this->patch(self::PAGE, '2');
        $refused = $this->post(self::PAGE, $validatorId, '1');
        self::assertSame(409, $refused->get_status());

        foreach ([
            'card, anonymous' => $this->cardFor(0),
            'card, operator'  => $this->cardFor(self::OPERATOR),
            'POST'            => $created->get_data(),
            'PATCH'           => $patched->get_data(),
            'conflict'        => $refused->get_data(),
        ] as $surface => $payload) {
            $json = (string) json_encode($payload);
            self::assertStringNotContainsString(self::VALOPER, $json, $surface);
            self::assertStringNotContainsString('valoper', $json, $surface);
            self::assertStringNotContainsString('operator_address', $json, $surface);
        }
    }

    public function testAListSurfacePaysNoPerCardQueries(): void
    {
        global $wpdb;
        $rowsByPage = [];
        for ($i = 0; $i < 20; $i++) {
            $page = 8000 + $i;
            $this->insertPage($page, 'Grid ' . $i);
            $validatorId = $this->insertValidator($this->cosmosChain, null, self::VALOPER . 'g' . $i, 'Grid ' . $i);
            $this->bindPlaceholder($page, $validatorId);
            $this->insertClaim(self::OPERATOR, $validatorId);
            $GLOBALS['__bcc_current_user'] = self::OPERATOR;
            self::assertSame(201, $this->post($page, $validatorId, '1')->get_status());
        }

        $pageIds = range(8000, 8019);
        foreach (ValidatorRepository::findCardRowsByPageIds($pageIds) as $page => $rows) {
            $rowsByPage[$page] = $rows;
        }
        self::assertCount(20, $rowsByPage);

        $wpdb->resetQueryCount();
        DelegatorGroupView::groupsForPages([8000 => $rowsByPage[8000]]);
        $oneCard = $wpdb->queryCount;

        $wpdb->resetQueryCount();
        $groups = DelegatorGroupView::groupsForPages($rowsByPage);
        $twenty = $wpdb->queryCount;
        self::assertCount(20, $groups);

        // The harness's meta shim reads the database six times per group where
        // WordPress reads the cache update_meta_cache() warmed. Net of that,
        // twenty cards and one card run the same statements.
        self::assertSame($oneCard - 6, $twenty - 6 * 20);

        $wpdb->resetQueryCount();
        foreach ($pageIds as $page) {
            DelegatorGroupView::forCard($page, true, $rowsByPage[$page], $groups);
        }
        self::assertSame(0, $wpdb->queryCount, 'with the bundle in hand the card build reads nothing');
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function post(int $pageId, int $validatorId, string $minStake): \WP_REST_Response
    {
        return $this->dispatchPost($pageId, ['validator_id' => $validatorId, 'min_stake' => $minStake]);
    }

    /** @param array<string, mixed> $body */
    private function dispatchPost(int $pageId, array $body): \WP_REST_Response
    {
        return (new PageDelegatorGroupEndpoint())->create(new \WP_REST_Request(['id' => $pageId] + $body));
    }

    private function patch(int $pageId, string $minStake): \WP_REST_Response
    {
        return (new PageDelegatorGroupEndpoint())->updateMinStake(
            new \WP_REST_Request(['id' => $pageId, 'min_stake' => $minStake])
        );
    }

    private function join(int $groupId): \WP_REST_Response
    {
        return (new ValidatorGroupsEndpoint())->postJoin(new \WP_REST_Request(['id' => $groupId]));
    }

    /** @return array<string, mixed> */
    private function cardFor(int $viewerId): array
    {
        return $this->cardForPage(self::PAGE, $viewerId);
    }

    /** @return array<string, mixed> */
    private function cardForPage(int $pageId, int $viewerId): array
    {
        return DelegatorGroupView::forCard(
            $pageId,
            ValidatorPageOperatorAuthority::isOperator($pageId, $viewerId),
            null,
            null
        );
    }

    private function createdGroup(string $minStake): int
    {
        $validatorId = $this->seedClaimedPage();
        $response    = $this->post(self::PAGE, $validatorId, $minStake);
        self::assertSame(201, $response->get_status());

        return $response->get_data()['data']['group']['id'];
    }

    private function joinDelegating(int $userId, float $amount, int $groupId): void
    {
        $this->giveWalletDelegating($userId, $amount);

        $operator = $GLOBALS['__bcc_current_user'];
        $GLOBALS['__bcc_current_user'] = $userId;
        self::assertSame(200, $this->join($groupId)->get_status());
        $GLOBALS['__bcc_current_user'] = $operator;

        self::assertSame('member', $this->memberStatus($userId, $groupId));
    }

    /**
     * Real member rows, each with a wallet delegating $amount.
     *
     * @return list<int> the user ids
     */
    private function addMembers(int $groupId, int $firstUser, int $count, float $amount): array
    {
        global $wpdb;
        $users = [];
        for ($user = $firstUser; $user < $firstUser + $count; $user++) {
            $this->giveWalletDelegating($user, $amount);
            $wpdb->insert($wpdb->prefix . 'peepso_group_members', [
                'gm_group_id' => $groupId, 'gm_user_id' => $user, 'gm_user_status' => 'member',
            ]);
            $users[] = $user;
        }

        return $users;
    }

    /**
     * Delegator communities written straight to the tables, for the walk across
     * many. All gate on $validatorId, which the walk doesn't care about.
     *
     * @return list<int>
     */
    private function insertGroups(int $count, int $validatorId): array
    {
        global $wpdb;
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $wpdb->insert($wpdb->posts, [
                'post_title'  => 'Delegators: Bulk ' . $i,
                'post_name'   => 'bulk-' . $i,
                'post_type'   => 'peepso-group',
                'post_status' => 'publish',
            ]);
            $id    = (int) $wpdb->insert_id;
            $ids[] = $id;
            ValidatorGroupRepository::writeGateConfig($id, $this->cosmosChain, $validatorId, self::VALOPER, '1');
        }

        return $ids;
    }

    /** @return list<object> */
    private function storedNotices(): array
    {
        global $wpdb;

        return $wpdb->get_results(
            "SELECT not_user_id, not_type, not_external_id, not_message FROM `{$wpdb->prefix}peepso_notifications`
              WHERE not_type = 'bcc_delegator_group_removed' ORDER BY not_id ASC"
        );
    }

    private function auditCountAll(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->prefix}bcc_trust_activity`");
    }

    private function giveWalletDelegating(int $userId, float $amount): void
    {
        $this->insertWallet($userId, $this->cosmosChain, 'cosmos1wallet' . $userId);
        $this->delegate($userId, $amount);
    }

    private function delegateNew(int $userId, float $amount): void
    {
        $this->giveWalletDelegating($userId, $amount);
    }

    private function delegate(int $userId, float $amount): void
    {
        $GLOBALS['__bcc_lcd']['cosmos1wallet' . $userId] = $amount > 0.0
            ? [['validator_address' => self::VALOPER, 'shares' => (string) $amount, 'amount' => $amount]]
            : [];
    }

    /** What five minutes passing does to the per-wallet delegation cache. */
    private function expireDelegationCache(): void
    {
        unset($GLOBALS['__bcc_test_object_cache']['bcc_onchain']);
    }

    /**
     * One tick of the real `bcc_gated_group_revoke_sweep` handler, with the real
     * opt-out listener bound, in the state cron runs in: no current user.
     */
    private function runRevokeTick(): void
    {
        self::assertSame(1, $this->bindFromBootstrap('peepso_action_group_user_delete'));
        self::assertSame(1, $this->bindFromBootstrap('bcc_gated_group_revoke_sweep', 'NftGroupRevokeService::CRON_HOOK'));

        $viewer = $GLOBALS['__bcc_current_user'];
        $GLOBALS['__bcc_current_user'] = 0;
        do_action('bcc_gated_group_revoke_sweep');
        $GLOBALS['__bcc_current_user'] = $viewer;

        $GLOBALS['__bcc_hooks'] = [];
    }

    /**
     * Run bcc-trust.php's own top-level `add_action(...)` statements for a hook,
     * so the handler under test is the shipped one. Loading the whole file would
     * drag in WordPress, which is why this lifts statements instead.
     *
     * @param string|null $constantForm The hook's name as bcc-trust.php spells
     *                                  it, when it names it through a constant.
     * @return int How many statements were bound.
     */
    private function bindFromBootstrap(string $hook, ?string $constantForm = null): int
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/bcc-trust.php');
        $tokens = token_get_all($source);
        $count  = count($tokens);
        $bound  = 0;

        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_STRING || $tokens[$i][1] !== 'add_action') {
                continue;
            }

            $text  = '';
            $depth = 0;
            $j     = $i + 1;
            for (; $j < $count; $j++) {
                $piece = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                $text .= $piece;
                if ($piece === '(') {
                    $depth++;
                } elseif ($piece === ')') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                }
            }

            $head = ltrim(substr(ltrim($text), 1));
            $matches = str_starts_with($head, "'" . $hook . "'");
            if ($constantForm !== null && !$matches) {
                $matches = str_contains(strtok($head, ','), $constantForm);
            }
            if ($matches) {
                eval('add_action' . $text . ';');
                $bound++;
            }
            $i = $j;
        }

        return $bound;
    }

    private function seedClaimedPage(): int
    {
        $validatorId = $this->insertValidator($this->cosmosChain, null, self::VALOPER, 'Fixture Moniker');
        $this->bindPlaceholder(self::PAGE, $validatorId);
        $this->insertClaim(self::OPERATOR, $validatorId);

        return $validatorId;
    }

    /**
     * Two validators on one page, through the wallet-link leg of the binding,
     * which is the only leg that can hold more than one.
     *
     * @return array{0: int, 1: int}
     */
    private function seedPageWithTwoValidators(): array
    {
        $one = $this->insertValidator($this->cosmosChain, $this->insertLinkWallet(self::OPERATOR, self::PAGE, $this->cosmosChain), self::VALOPER . '1', 'Moniker One');
        $two = $this->insertValidator($this->otherCosmosChain, $this->insertLinkWallet(self::OPERATOR, self::PAGE, $this->otherCosmosChain), self::VALOPER . '2', 'Moniker Two');
        $this->insertClaim(self::OPERATOR, $one);
        $this->insertClaim(self::OPERATOR, $two);

        return [$one, $two];
    }

    private function insertPage(int $pageId, string $title): void
    {
        $post              = new \WP_Post();
        $post->ID          = $pageId;
        $post->post_type   = 'peepso-page';
        $post->post_status = 'publish';
        $post->post_title  = $title;

        $GLOBALS['__bcc_posts'][$pageId] = $post;
    }

    private function bindPlaceholder(int $pageId, int $validatorId): void
    {
        self::assertTrue(update_post_meta($pageId, '_bcc_onchain_validator_id', (string) $validatorId));
    }

    private function insertValidator(int $chainId, ?int $walletLinkId, string $address, string $moniker): int
    {
        global $wpdb;
        $ok = $wpdb->insert($wpdb->prefix . 'bcc_onchain_validators', [
            'wallet_link_id'   => $walletLinkId,
            'operator_address' => $address,
            'chain_id'         => $chainId,
            'moniker'          => $moniker,
            'expires_at'       => gmdate('Y-m-d H:i:s', time() + 86400),
        ]);
        self::assertNotFalse($ok, 'validator fixture: ' . $wpdb->last_error);

        return (int) $wpdb->insert_id;
    }

    private function insertClaim(int $userId, int $validatorId): void
    {
        global $wpdb;
        $ok = $wpdb->insert($wpdb->prefix . 'bcc_onchain_claims', [
            'user_id'        => $userId,
            'entity_type'    => 'validator',
            'entity_id'      => $validatorId,
            'wallet_address' => 'cosmos1claim' . $userId,
            'chain_id'       => $this->cosmosChain,
            'claim_role'     => 'operator',
            'status'         => 'verified',
            'verified_at'    => gmdate('Y-m-d H:i:s'),
        ]);
        self::assertNotFalse($ok, 'claim fixture: ' . $wpdb->last_error);
    }

    private function insertWallet(int $userId, int $chainId, string $address): void
    {
        global $wpdb;
        $ok = $wpdb->insert($wpdb->prefix . 'bcc_wallet_links', [
            'user_id'        => $userId,
            'post_id'        => 0,
            'wallet_address' => $address,
            'chain_id'       => $chainId,
            'wallet_type'    => 'user',
            'verified_at'    => gmdate('Y-m-d H:i:s'),
        ]);
        self::assertNotFalse($ok, 'wallet fixture: ' . $wpdb->last_error);
    }

    private function insertLinkWallet(int $userId, int $pageId, int $chainId): int
    {
        global $wpdb;
        $ok = $wpdb->insert($wpdb->prefix . 'bcc_wallet_links', [
            'user_id'        => $userId,
            'post_id'        => $pageId,
            'wallet_address' => 'cosmos1link' . $chainId,
            'chain_id'       => $chainId,
            'wallet_type'    => 'validator',
            'verified_at'    => gmdate('Y-m-d H:i:s'),
        ]);
        self::assertNotFalse($ok, 'link wallet fixture: ' . $wpdb->last_error);

        return (int) $wpdb->insert_id;
    }

    private function memberStatus(int $userId, int $groupId): ?string
    {
        global $wpdb;
        $status = $wpdb->get_var($wpdb->prepare(
            "SELECT gm_user_status FROM `{$wpdb->prefix}peepso_group_members` WHERE gm_user_id = %d AND gm_group_id = %d",
            $userId,
            $groupId
        ));

        return $status === null ? null : (string) $status;
    }

    /** @return list<object> */
    private function auditRows(string $action): array
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, target_id, meta FROM `{$wpdb->prefix}bcc_trust_activity` WHERE action = %s ORDER BY id ASC",
            $action
        ));
    }

    private function auditCount(string $action): int
    {
        return count($this->auditRows($action));
    }

    private function assertNoGroupExists(): void
    {
        global $wpdb;
        self::assertSame([], $GLOBALS['__bcc_group_creates'], 'PeepSoGroup must not have been constructed');
        self::assertSame(
            0,
            (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM `{$wpdb->postmeta}` WHERE meta_key = %s",
                ValidatorGroupRepository::META_KIND
            ))
        );
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function withoutAmbiguityAudit(array $snapshot): array
    {
        global $wpdb;
        $table            = $wpdb->prefix . 'bcc_trust_activity';
        $snapshot[$table] = array_values(array_filter(
            $snapshot[$table],
            static fn (array $row): bool => $row['action'] !== 'validator_operator_ambiguous'
        ));

        return $snapshot;
    }

    /**
     * Every table a write here could touch, whole.
     *
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        global $wpdb;
        $out = [];
        foreach ([
            $wpdb->posts                                => 'ID',
            $wpdb->postmeta                             => 'meta_id',
            $wpdb->prefix . 'peepso_group_members'      => 'gm_id',
            $wpdb->prefix . 'bcc_trust_activity'        => 'id',
            $wpdb->prefix . 'peepso_notifications'      => 'not_id',
        ] as $table => $order) {
            $rows = $wpdb->get_results("SELECT * FROM `{$table}` ORDER BY `{$order}` ASC", 'ARRAY_A');
            self::assertSame('', (string) $wpdb->last_error, "snapshot of {$table} must really run");
            $out[$table] = $rows;
        }
        $out['usermeta'] = $GLOBALS['__bcc_usermeta'];

        return $out;
    }

    private function peerConnection(): \mysqli
    {
        global $wpdb;
        $peer = mysqli_connect(
            getenv('BCC_TEST_DB_HOST') ?: '127.0.0.1',
            getenv('BCC_TEST_DB_USER') ?: 'root',
            getenv('BCC_TEST_DB_PASS') ?: 'root',
            (string) $wpdb->get_var('SELECT DATABASE()'),
            (int) (getenv('BCC_TEST_DB_PORT') ?: 10005)
        );
        self::assertInstanceOf(\mysqli::class, $peer);

        return $peer;
    }

    /**
     * The AMBIGUOUS fixtures need the shipped claims schema, without the
     * optional verified-operator constraint another suite leaves applied. See
     * VerifiedOperatorResolverStatesIntegrationTest::normaliseClaimsSchema().
     */
    private function normaliseClaimsSchema(): void
    {
        global $wpdb;
        $claims = $wpdb->prefix . 'bcc_onchain_claims';
        $has    = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'verified_operator_slot'",
            $claims
        ));
        if ($has > 0) {
            $wpdb->query("ALTER TABLE `{$claims}` DROP INDEX uq_verified_operator, DROP COLUMN verified_operator_slot");
        }
    }
}
