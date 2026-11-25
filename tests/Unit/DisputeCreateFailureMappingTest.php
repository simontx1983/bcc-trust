<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Disputes\Controllers\DisputeController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-598: what POST /disputes answers when DisputeRepository::createDispute()
 * created nothing, by its `db_error` code.
 *
 * ⭐ `tx_failed` is the transient case (the transaction could not start or
 * commit, or a read inside it did not run) and must stay a retryable 503,
 * not fall through to the generic 500. The refusals keep their statuses.
 *
 * DisputeController::createDisputeFailure() is the mapping submit() uses
 * (same reflection seam as DisputePanelRetirementTest's disputeVoteError).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(DisputeController::class)]
final class DisputeCreateFailureMappingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/dispute-create-failure-stubs.php';
    }

    /** @return array<string, array{?string, int, string}> */
    public static function failures(): array
    {
        return [
            'transaction failure'   => ['tx_failed', 503, 'db_transient'],
            'page limit'            => ['dispute_limit_reached', 429, 'dispute_limit_reached'],
            'reporter limit'        => ['reporter_limit_reached', 429, 'reporter_limit_reached'],
            'vote no longer active' => ['vote_no_longer_active', 410, 'vote_no_longer_active'],
            'already disputed'      => ['already_disputed', 409, 'already_disputed'],
            'unexpected failure'    => ['create_failed', 500, 'db_error'],
            'the INSERT\'s error'   => ['Data too long for column', 500, 'db_error'],
            'no code'               => [null, 500, 'db_error'],
        ];
    }

    #[DataProvider('failures')]
    public function testEachFailureCodeGetsItsResponse(?string $dbError, int $status, string $code): void
    {
        $response = $this->map($dbError);

        self::assertSame($status, $response->get_status());
        self::assertSame($code, $response->get_data()['error']['code'] ?? null);
    }

    /** The codes the old raw transaction returned no longer exist; nothing maps them to 503 any more. */
    public function testTheRetiredTransactionCodesAreNotTransient(): void
    {
        foreach (['commit_failed', 'tx_begin_failed'] as $retired) {
            self::assertSame(500, $this->map($retired)->get_status(), $retired);
        }
    }

    private function map(?string $dbError): \WP_REST_Response
    {
        $controller = (new \ReflectionClass(DisputeController::class))->newInstanceWithoutConstructor();
        $method     = new \ReflectionMethod(DisputeController::class, 'createDisputeFailure');

        /** @var \WP_REST_Response $response */
        $response = $method->invoke($controller, $dbError);

        return $response;
    }
}
