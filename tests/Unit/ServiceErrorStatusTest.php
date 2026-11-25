<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\REST\BlogCoverImageEndpoint;
use BCC\Trust\Core\Support\ApiResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * ONE code → status map for a domain service's error, in ApiResponse.
 *
 * PostsEndpoint and BlogCoverImageEndpoint each kept a private copy, and
 * BlogCoverImage's was a strict subset of Posts'. Both now answer through
 * ApiResponse::fromServiceError(), so BlogCoverImage answers 403 (not the
 * subset's default 400) for `bcc_permission_denied` and
 * `bcc_feature_disabled`. Approved; its writer emits neither today.
 *
 * The endpoint cases run the REAL BlogCoverImageEndpoint::upload() over the
 * doubles in tests/Stubs/service-error-status-stubs.php.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ServiceErrorStatusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__bcc_ses'] = ['user' => 7, 'result' => [], 'calls' => []];
        require_once __DIR__ . '/../Stubs/service-error-status-stubs.php';
    }

    /** @return iterable<string, array{0: string, 1: int}> */
    public static function serviceCodes(): iterable
    {
        yield 'bcc_unauthorized'           => ['bcc_unauthorized', 401];
        yield 'bcc_forbidden'              => ['bcc_forbidden', 403];
        yield 'bcc_permission_denied'      => ['bcc_permission_denied', 403];
        yield 'bcc_not_found'              => ['bcc_not_found', 404];
        yield 'bcc_invalid_request'        => ['bcc_invalid_request', 400];
        yield 'bcc_invalid_mention_target' => ['bcc_invalid_mention_target', 400];
        yield 'bcc_too_many_mentions'      => ['bcc_too_many_mentions', 400];
        yield 'bcc_rate_limited'           => ['bcc_rate_limited', 429];
        yield 'bcc_unavailable'            => ['bcc_unavailable', 503];
        yield 'bcc_feature_disabled'       => ['bcc_feature_disabled', 403];
        yield 'an unknown code is a caller problem, never a 500' => ['bcc_something_new', 400];
    }

    #[DataProvider('serviceCodes')]
    public function testTheSharedMapAnswersEachCode(string $code, int $status): void
    {
        self::assertSame($status, ApiResponse::statusForServiceCode($code));

        $res = ApiResponse::fromServiceError(['error' => $code, 'message' => 'Nope.']);
        self::assertSame($status, $res->get_status());
        self::assertSame(
            ['error' => ['code' => $code, 'message' => 'Nope.', 'status' => $status], '_meta' => ['version' => 'v1']],
            $res->get_data()
        );
    }

    public function testFromServiceErrorForwardsADataBlock(): void
    {
        $res = ApiResponse::fromServiceError(['error' => 'bcc_too_many_mentions', 'message' => 'Too many.', 'data' => ['max' => 10]]);

        self::assertSame(['max' => 10], $res->get_data()['error']['data']);
    }

    public function testFromServiceErrorWithNoCodeIsUnavailable(): void
    {
        $res = ApiResponse::fromServiceError([]);

        self::assertSame(503, $res->get_status());
        self::assertSame(['code' => 'bcc_unavailable', 'message' => '', 'status' => 503], $res->get_data()['error']);
    }

    // ── BlogCoverImageEndpoint answers through the shared map ─────────────

    /** @return iterable<string, array{0: string, 1: int}> */
    public static function coverWriterCodes(): iterable
    {
        // What BlogCoverImageWriter::upload() emits today: unchanged.
        yield 'bcc_unauthorized (unchanged)'    => ['bcc_unauthorized', 401];
        yield 'bcc_invalid_request (unchanged)' => ['bcc_invalid_request', 400];
        yield 'bcc_rate_limited (unchanged)'    => ['bcc_rate_limited', 429];
        yield 'bcc_unavailable (unchanged)'     => ['bcc_unavailable', 503];
        // The approved change: these were the subset's default 400.
        yield 'bcc_permission_denied is now 403' => ['bcc_permission_denied', 403];
        yield 'bcc_feature_disabled is now 403'  => ['bcc_feature_disabled', 403];
    }

    #[DataProvider('coverWriterCodes')]
    public function testACoverUploadServiceErrorAnswersTheSharedStatus(string $code, int $status): void
    {
        $GLOBALS['__bcc_ses']['result'] = ['error' => $code, 'message' => 'Refused.'];

        $res = (new BlogCoverImageEndpoint())->upload(new \WP_REST_Request(['cover_image' => ['name' => 'c.png']]));

        self::assertSame([[7, 'c.png']], $GLOBALS['__bcc_ses']['calls'], 'the writer answered');
        self::assertSame($status, $res->get_status());
        self::assertSame(['code' => $code, 'message' => 'Refused.', 'status' => $status], $res->get_data()['error']);
    }

    public function testACoverUploadErrorKeepsItsDataBlock(): void
    {
        $GLOBALS['__bcc_ses']['result'] = ['error' => 'bcc_invalid_request', 'message' => 'Too big.', 'data' => ['max_bytes' => 8388608]];

        $res = (new BlogCoverImageEndpoint())->upload(new \WP_REST_Request(['cover_image' => ['name' => 'c.png']]));

        self::assertSame(400, $res->get_status());
        self::assertSame(['max_bytes' => 8388608], $res->get_data()['error']['data']);
    }
}
