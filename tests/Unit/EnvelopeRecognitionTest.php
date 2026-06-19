<?php

declare(strict_types=1);

namespace BCC\Trust\Core\REST\Tests;

use BCC\Trust\Core\REST\Envelope;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Locks Envelope::isAlreadyEnveloped() — the predicate that decides whether
 * a handler's return value is ALREADY in an API envelope (and must be passed
 * through untouched) or a raw payload (and must be wrapped). A false positive
 * skips wrapping → contract break; a false negative double-wraps. Both are
 * caught here.
 *
 * Recognizes three shapes: canonical success { data, _meta }, canonical error
 * { error: {...} }, and the legacy trust { success: true, data } shape. The
 * strictness of those rules (=== true, _meta presence, data sibling) is the
 * point — hence the false-positive guards.
 *
 * Converted from a standalone CLI harness to a PHPUnit TestCase so it runs in
 * the suite + CI. isAlreadyEnveloped is private by design; exercised via
 * reflection because it is a pure function of its input.
 */
#[CoversMethod(Envelope::class, 'isAlreadyEnveloped')]
final class EnvelopeRecognitionTest extends TestCase
{
    private static function isEnveloped(mixed $data): bool
    {
        $m = new ReflectionMethod(Envelope::class, 'isAlreadyEnveloped');
        $m->setAccessible(true);
        return (bool) $m->invoke(null, $data);
    }

    /** @param array<string, mixed> $input */
    #[DataProvider('recognizedCases')]
    public function testRecognizedEnvelopesPassThrough(array $input): void
    {
        self::assertTrue(self::isEnveloped($input));
    }

    #[DataProvider('notRecognizedCases')]
    public function testRawPayloadsAreNotRecognized(mixed $input): void
    {
        self::assertFalse(self::isEnveloped($input));
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function recognizedCases(): array
    {
        return [
            'canonical success'                 => [['data' => ['ok' => true], '_meta' => ['request_id' => 'abc']]],
            'canonical success, null data'      => [['data' => null, '_meta' => []]],
            'canonical success, extra siblings' => [['data' => [], '_meta' => [], 'extra' => 'irrelevant']],
            'canonical error'                   => [['error' => ['code' => 'bcc_unauthorized', 'message' => 'Sign in.', 'status' => 401]]],
            'canonical error with data'         => [['error' => ['code' => 'bcc_invalid_request', 'message' => 'Bad.', 'status' => 422, 'data' => ['field' => 'email']]]],
            'legacy trust success'              => [['success' => true, 'data' => ['auth_url' => 'https://x.com/oauth']]],
            'legacy trust success, null data'   => [['success' => true, 'data' => null]],
            'canonical + legacy markers'        => [['success' => true, 'data' => [], '_meta' => []]],
        ];
    }

    /** @return array<string, array{0: mixed}> */
    public static function notRecognizedCases(): array
    {
        return [
            'non-array string'                => ['hello'],
            'non-array null'                  => [null],
            'non-array int'                   => [42],
            'empty array'                     => [[]],
            'success=false'                   => [['success' => false, 'data' => ['anything']]],
            'success=1 (int, not bool)'       => [['success' => 1, 'data' => ['anything']]],
            'success="true" (string)'         => [['success' => 'true', 'data' => ['anything']]],
            'WP_Error raw shape'              => [['code' => 'x_error', 'message' => 'Boom.', 'data' => ['status' => 500]]],
            'arbitrary handler payload'       => [['some_field' => 'value', 'list' => [1, 2, 3]]],
            'success=true without data'       => [['success' => true, 'message' => 'ok']],
            'data alone without _meta/success' => [['data' => [1, 2, 3]]],
        ];
    }
}
