<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\REST\MeValidatorPagesEndpoint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * `GET /me/validator-pages` (the operator dashboard's data) is never
 * stored by any cache.
 *
 * It sent no Cache-Control at all, so caching was left to defaults for a
 * response listing the caller's own verified claims. It now sends
 * `private, no-store`, like the other /me reads.
 *
 * The real handler and the real ClaimRepository read run over a fake
 * `$wpdb` (tests/Stubs/cards-search-throttle-stubs.php).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(MeValidatorPagesEndpoint::class)]
final class MeValidatorPagesCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/cards-search-throttle-stubs.php';
        $GLOBALS['__bcc_cst'] = ['user_id' => 7, 'rows' => []];
        $GLOBALS['wpdb']      = new \BccCardsSearchFakeWpdb();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__bcc_cst'], $GLOBALS['wpdb']);
        parent::tearDown();
    }

    private function get(): \WP_REST_Response
    {
        return (new MeValidatorPagesEndpoint())->handle(new \WP_REST_Request());
    }

    public function testTheListIsNeverStored(): void
    {
        $GLOBALS['__bcc_cst']['rows'] = [(object) [
            'claim_id'         => '11',
            'claim_role'       => 'operator',
            'claim_status'     => 'verified',
            'validator_id'     => '21',
            'chain_id'         => '1',
            'chain_slug'       => 'cosmos',
            'chain_name'       => 'Cosmos Hub',
            'chain_type'       => 'cosmos',
            'moniker'          => 'Example Node',
            'operator_address' => 'cosmosvaloper1example',
            'validator_status' => 'active',
            'page_id'          => '31',
            'page_slug'        => 'example-node',
        ]];

        $res = $this->get();

        self::assertSame(200, $res->get_status());
        self::assertSame(1, $res->get_data()['data']['total'] ?? null, 'control: the row came through');
        self::assertSame('private, no-store', $res->get_headers()['Cache-Control'] ?? null);
    }

    public function testAnEmptyListIsNeverStoredEither(): void
    {
        $res = $this->get();

        self::assertSame(0, $res->get_data()['data']['total'] ?? null);
        self::assertSame('private, no-store', $res->get_headers()['Cache-Control'] ?? null);
    }
}
