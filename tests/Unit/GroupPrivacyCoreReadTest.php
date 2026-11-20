<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Core\Repositories\PeepSoGroupPrivacy;
use BCC\Trust\Core\Services\GroupContextResolver;
use BCC\Trust\Core\ValueObjects\PeepSoPrivacy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-460: a group's privacy comes from bcc-core's probed TABLE read
 * (`PeepSoGroupPrivacy::privacyForGroup(s)`), never from `get_post_meta()`.
 *
 * Core's read answers null when it cannot be trusted. What bcc-trust owns,
 * and what this file pins:
 *   - the mapping, with UNKNOWN (null) → Secret, so a failed read fails closed;
 *   - that it DELEGATES: core's answer wins even where the meta says
 *     otherwise. The `get_post_meta` shim below contradicts every answer, so
 *     a revert to reading meta goes red;
 *   - that GroupContextResolver reads a page's privacy in ONE batch, and
 *     primes unknown rather than falling back to one query per group when
 *     that batch fails.
 *
 * The strictest-row rule, the CAST, and the pre-flush bail are bcc-core's,
 * tested there on SQLite and MySQL. This suite uses the unit double
 * (tests/Stubs/core-group-privacy-double.php).
 *
 * Separate processes: this file defines namespaced WordPress shims.
 */
#[CoversClass(PeepSoPrivacy::class)]
#[CoversClass(GroupContextResolver::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class GroupPrivacyCoreReadTest extends TestCase
{
    private const GROUP = 77;

    protected function setUp(): void
    {
        parent::setUp();
        self::defineShims();
        PeepSoGroupPrivacy::reset();
        // The meta every test sees says the OPPOSITE of core's answer, so a
        // resolver that read meta instead of core would visibly disagree.
        $GLOBALS['__f460_meta'] = [];
        $GLOBALS['__f460_posts'] = [];
    }

    // ── the mapping ────────────────────────────────────────────────────

    /** @return iterable<string, array{0: int|null, 1: PeepSoPrivacy}> */
    public static function answers(): iterable
    {
        yield 'secret'                  => [2, PeepSoPrivacy::Secret];
        yield 'closed'                  => [1, PeepSoPrivacy::Closed];
        yield 'open'                    => [0, PeepSoPrivacy::Open];
        yield 'unknown fails CLOSED'    => [null, PeepSoPrivacy::Secret];
        yield 'an unexpected value'     => [7, PeepSoPrivacy::Open];
    }

    #[DataProvider('answers')]
    public function testEachAnswerOfTheCoreReadMapsToItsEnum(?int $answer, PeepSoPrivacy $expected): void
    {
        self::assertSame($expected, PeepSoPrivacy::fromStoredValue($answer));
    }

    // ── it delegates to core, never get_post_meta() ────────────────────

    #[DataProvider('answers')]
    public function testFromGroupPostIdFollowsCoreNotTheMeta(?int $answer, PeepSoPrivacy $expected): void
    {
        PeepSoGroupPrivacy::$answers = [self::GROUP => $answer];
        // Meta that contradicts core: secret where core says open, open
        // everywhere else.
        $GLOBALS['__f460_meta'][self::GROUP]['peepso_group_privacy'] = [$answer === 0 ? '2' : '0'];

        self::assertSame($expected, PeepSoPrivacy::fromGroupPostId(self::GROUP));
    }

    /**
     * ⚠ The F-460 case itself. Core's read could not be trusted, so the
     * group is Secret, even though the meta stub holds no privacy row.
     */
    public function testAFailedReadIsSecretEvenThoughTheMetaLooksOpen(): void
    {
        PeepSoGroupPrivacy::$failAll = true;
        $GLOBALS['__f460_meta'][self::GROUP]['peepso_group_privacy'] = [];

        self::assertSame(PeepSoPrivacy::Secret, PeepSoPrivacy::fromGroupPostId(self::GROUP));
    }

    // ── GroupContextResolver ───────────────────────────────────────────

    private function seedGroup(int $groupId, ?int $coreAnswer): void
    {
        $GLOBALS['__f460_posts'][$groupId] = 'peepso-group';
        $GLOBALS['__f460_meta'][$groupId]  = [
            '_bcc_group_kind'      => [''],
            // Contradicts core, as above.
            'peepso_group_privacy' => [$coreAnswer === 0 ? '2' : '0'],
        ];
        PeepSoGroupPrivacy::$answers[$groupId] = $coreAnswer;
    }

    public function testASinglePrivacyReadBacksForGroup(): void
    {
        $this->seedGroup(self::GROUP, 2);

        $ctx = (new GroupContextResolver())->forGroup(self::GROUP);

        self::assertNotNull($ctx);
        self::assertSame(PeepSoPrivacy::Secret, $ctx->privacy);
        self::assertSame([[self::GROUP]], PeepSoGroupPrivacy::$batchCalls);
    }

    /**
     * A feed page resolves many groups. Their privacy must cost ONE read,
     * not one per group, and forGroup() must then use the primed answer.
     */
    public function testAPageOfGroupsCostsOnePrivacyRead(): void
    {
        $this->seedGroup(10, 2);
        $this->seedGroup(11, 1);
        $this->seedGroup(12, 0);

        $out = (new GroupContextResolver())->forManyGroups([10, 11, 12]);

        self::assertSame([[10, 11, 12]], PeepSoGroupPrivacy::$batchCalls, 'one batch, no per-group reads');
        self::assertSame(PeepSoPrivacy::Secret, $out[10]->privacy);
        self::assertSame(PeepSoPrivacy::Closed, $out[11]->privacy);
        self::assertSame(PeepSoPrivacy::Open, $out[12]->privacy);
    }

    /**
     * ⚠ A failed batch primes UNKNOWN for every group (so Secret). It must
     * not leave them unprimed, which would fall back to one query per group
     * against a database that is already failing.
     */
    public function testAFailedBatchPrimesSecretAndDoesNotFallBackPerGroup(): void
    {
        $this->seedGroup(10, 0);
        $this->seedGroup(11, 0);
        PeepSoGroupPrivacy::$failAll = true;

        $out = (new GroupContextResolver())->forManyGroups([10, 11]);

        self::assertSame([[10, 11]], PeepSoGroupPrivacy::$batchCalls, 'exactly the one failed batch');
        self::assertSame(PeepSoPrivacy::Secret, $out[10]->privacy);
        self::assertSame(PeepSoPrivacy::Secret, $out[11]->privacy);
    }

    /** Over the core cap, the page is read in chunks, still not per group. */
    public function testALargePageIsReadInBoundedChunks(): void
    {
        $ids = range(1, PeepSoGroupPrivacy::BATCH_MAX + 3);
        foreach ($ids as $id) {
            $this->seedGroup($id, 1);
        }

        $out = (new GroupContextResolver())->forManyGroups($ids);

        self::assertCount(2, PeepSoGroupPrivacy::$batchCalls);
        self::assertCount(PeepSoGroupPrivacy::BATCH_MAX, PeepSoGroupPrivacy::$batchCalls[0]);
        self::assertCount(3, PeepSoGroupPrivacy::$batchCalls[1]);
        self::assertSame(PeepSoPrivacy::Closed, $out[PeepSoGroupPrivacy::BATCH_MAX + 3]->privacy);
    }

    private static function defineShims(): void
    {
        if (function_exists('BCC\\Trust\\Core\\Services\\get_post_meta')) {
            return;
        }
        eval(<<<'PHP'
            namespace {
                if (!class_exists('WP_Post', false)) {
                    final class WP_Post
                    {
                        public int $ID = 0;
                        public string $post_type = '';
                    }
                }
            }
            namespace BCC\Trust\Core\Services {
                function get_post($id)
                {
                    $type = $GLOBALS['__f460_posts'][(int) $id] ?? null;
                    if ($type === null) {
                        return null;
                    }
                    $post = new \WP_Post();
                    $post->ID = (int) $id;
                    $post->post_type = $type;
                    return $post;
                }
                function get_post_meta($id, $key = '', $single = false)
                {
                    $rows = $GLOBALS['__f460_meta'][(int) $id][$key] ?? [];
                    return $single ? ($rows[0] ?? '') : $rows;
                }
                function _prime_post_caches(array $ids, bool $updateTermCache = true, bool $updateMetaCache = true): void
                {
                }
            }
            namespace BCC\Trust\Core\ValueObjects {
                function get_post_meta($id, $key = '', $single = false)
                {
                    return \BCC\Trust\Core\Services\get_post_meta($id, $key, $single);
                }
            }
            PHP);
    }
}
