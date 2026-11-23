<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\REST\PagesEndpoint;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * F-515: POST /pages/:id/claim answers an unclaimable validator with the
 * card's own code and hint: 403 bcc_forbidden, reason `not_claimable`,
 * `unclaimable_reason_code`, and the hint as the message. Never a wallet
 * mismatch.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ClaimNotClaimableMappingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/page-claim-dispatch-stubs.php';
        if (!class_exists('WP_REST_Response', false)) {
            eval('class WP_REST_Response {
                public function __construct(private mixed $data = null, private int $status = 200) {}
                public function get_data(): mixed { return $this->data; }
                public function get_status(): int { return $this->status; }
            }');
        }
    }

    /**
     * @param array<string, mixed> $result
     * @return array{int, array<string, mixed>}
     */
    private static function map(array $result): array
    {
        $m = new ReflectionMethod(PagesEndpoint::class, 'mapClaimFailure');
        /** @var \WP_REST_Response $response */
        $response = $m->invoke(null, $result);
        /** @var array{error: array<string, mixed>} $body */
        $body = $response->get_data();

        return [$response->get_status(), $body['error']];
    }

    public function testAnUnclaimableValidatorIsAFinal403WithTheCardsCodeAndHint(): void
    {
        $hint = "Claiming isn't available on THORChain yet, because BCC can't prove operator authority on it from the data it stores.";

        [$status, $error] = self::map([
            'success'     => false,
            'error'       => 'not_claimable',
            'reason_code' => 'operator_unprovable',
            'message'     => $hint,
        ]);

        self::assertSame(403, $status);
        self::assertSame('bcc_forbidden', $error['code']);
        self::assertSame($hint, $error['message']);
        self::assertSame(['reason' => 'not_claimable', 'unclaimable_reason_code' => 'operator_unprovable'], $error['data']);
    }

    /** Control: a genuine wallet mismatch keeps its own answer. */
    public function testAWalletMismatchIsStillNotOperator(): void
    {
        [$status, $error] = self::map(['success' => false, 'message' => 'no match']);

        self::assertSame(403, $status);
        self::assertSame(['reason' => 'not_operator'], $error['data']);
    }
}
