<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Core\Repositories\PeepSoGroupPrivacy;
use BCC\Core\Repositories\PostMetaRepository;
use BCC\Trust\Core\Services\GroupContextResolver;
use BCC\Trust\Core\ValueObjects\GroupType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-463: a group's kind and trust weighting come from bcc-core's
 * `PostMetaRepository::valuesOrUnknown()` (read through ProbesReads, never
 * get_post_meta()); a read that cannot be trusted answers null, and the
 * resolver gives NO context, which every consumer treats as "no such group".
 *
 * What this file pins:
 *   - the decision on raw rows: no kind row is a plain group; duplicate kind
 *     rows must AGREE ('' beside 'holders' is unknown, so '' never wins);
 *     weighting is on only for 1, and disagreeing rows are unknown;
 *   - that the resolver DELEGATES: the meta stub below holds a poisoned
 *     '' / 0 while core's answer (the table) says otherwise, and core wins;
 *   - one guarded read per page in forManyGroups(), and a failed batch gives
 *     no context with no per-group fallback.
 *
 * The read itself (the table, the pre-flush bail, the bounds) is bcc-core's,
 * tested there on SQLite and MySQL. This suite uses the unit double
 * (tests/Stubs/core-post-meta-double.php).
 *
 * Separate processes: this file defines namespaced WordPress shims.
 */
#[CoversClass(GroupContextResolver::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class GroupMetaCoreReadTest extends TestCase
{
    private const GROUP = 88;

    protected function setUp(): void
    {
        parent::setUp();
        self::defineShims();
        PostMetaRepository::reset();
        PeepSoGroupPrivacy::reset();
        PeepSoGroupPrivacy::$answers[self::GROUP] = PeepSoGroupPrivacy::OPEN;
        // The meta stub every test sees is POISONED: no kind, weighting 0.
        // A resolver that still read get_post_meta() would answer "plain,
        // unweighted" whatever core says.
        $GLOBALS['__f463_meta']  = [];
        $GLOBALS['__f463_posts'] = [self::GROUP => 'peepso-group'];
    }

    /** @param array<string, list<string>>|null $rows */
    private static function core(int $groupId, ?array $rows): void
    {
        PostMetaRepository::$answers[$groupId] = $rows;
    }

    // ── the decision on raw rows ───────────────────────────────────────

    /** @return iterable<string, array{0: array<string, list<string>>, 1: GroupType|null, 2: bool|null}> */
    public static function rows(): iterable
    {
        yield 'holders'                          => [['_bcc_group_kind' => ['holders']], GroupType::Nft, false];
        yield 'hall'                             => [['_bcc_group_kind' => ['hall']], GroupType::Hall, false];
        yield 'delegators'                       => [['_bcc_group_kind' => ['delegators']], GroupType::Validator, false];
        yield 'no kind row is plain'             => [[], GroupType::User, false];
        yield 'agreeing duplicates'              => [['_bcc_group_kind' => ['hall', 'hall']], GroupType::Hall, false];
        yield '"" beside holders is unknown'     => [['_bcc_group_kind' => ['', 'holders']], null, null];
        yield 'holders beside "" is unknown'     => [['_bcc_group_kind' => ['holders', '']], null, null];
        yield 'two gated kinds disagree'         => [['_bcc_group_kind' => ['holders', 'hall']], null, null];
        yield 'weighting on'                     => [['_bcc_trust_weighting_enabled' => ['1']], GroupType::User, true];
        yield 'weighting off'                    => [['_bcc_trust_weighting_enabled' => ['0']], GroupType::User, false];
        yield 'weighting rows agree'             => [['_bcc_trust_weighting_enabled' => ['1', '1']], GroupType::User, true];
        yield 'weighting rows disagree: unknown' => [['_bcc_trust_weighting_enabled' => ['1', '0']], null, null];
    }

    /** @param array<string, list<string>> $rows */
    #[DataProvider('rows')]
    public function testTheResolverDecidesOnCoresRawRows(array $rows, ?GroupType $type, ?bool $weighting): void
    {
        self::core(self::GROUP, $rows);

        $ctx = (new GroupContextResolver())->forGroup(self::GROUP);

        if ($type === null) {
            self::assertNull($ctx, 'ambiguous rows give no context');

            return;
        }
        self::assertNotNull($ctx);
        self::assertSame($type, $ctx->type);
        self::assertSame($weighting, $ctx->trustWeightingEnabled);
    }

    // ── it delegates to core, never get_post_meta() ────────────────────

    /**
     * ⚠ The F-463 case. The meta stub answers a plain, unweighted group (''
     * and 0), as a failed meta load would; the table says holders, weighted.
     */
    public function testCoreWinsOverAPoisonedMetaStub(): void
    {
        $GLOBALS['__f463_meta'][self::GROUP] = ['_bcc_group_kind' => [''], '_bcc_trust_weighting_enabled' => ['0']];
        self::core(self::GROUP, ['_bcc_group_kind' => ['holders'], '_bcc_trust_weighting_enabled' => ['1']]);

        $ctx = (new GroupContextResolver())->forGroup(self::GROUP);

        self::assertNotNull($ctx);
        self::assertSame(GroupType::Nft, $ctx->type);
        self::assertTrue($ctx->trustWeightingEnabled);
    }

    public function testAReadThatCannotBeTrustedGivesNoContext(): void
    {
        $GLOBALS['__f463_meta'][self::GROUP] = ['_bcc_group_kind' => ['']];
        self::core(self::GROUP, null);

        self::assertNull((new GroupContextResolver())->forGroup(self::GROUP));
    }

    public function testTheSingleReadAsksForKindAndWeightingInOneCall(): void
    {
        self::core(self::GROUP, ['_bcc_group_kind' => ['hall']]);

        (new GroupContextResolver())->forGroup(self::GROUP);

        self::assertSame(
            [['ids' => [self::GROUP], 'keys' => ['_bcc_group_kind', '_bcc_trust_weighting_enabled']]],
            PostMetaRepository::$calls
        );
    }

    // ── a page reads once ──────────────────────────────────────────────

    public function testAPageIsOneGuardedReadNotOnePerGroup(): void
    {
        $this->seedGroup(10, ['_bcc_group_kind' => ['holders']]);
        $this->seedGroup(11, []);

        $out = (new GroupContextResolver())->forManyGroups([10, 11]);

        self::assertCount(1, PostMetaRepository::$calls, 'one read for the page');
        self::assertSame([10, 11], PostMetaRepository::$calls[0]['ids']);
        self::assertSame(GroupType::Nft, $out[10]->type);
        self::assertSame(GroupType::User, $out[11]->type);
    }

    /**
     * ⚠ A failed batch gives no context for any group on the page, and does
     * NOT fall back to one read per group against a failing database.
     */
    public function testAFailedBatchGivesNoContextAndNoPerGroupFallback(): void
    {
        $this->seedGroup(10, ['_bcc_group_kind' => ['holders']]);
        $this->seedGroup(11, []);
        PostMetaRepository::$failAll = true;

        $out = (new GroupContextResolver())->forManyGroups([10, 11]);

        self::assertSame([], $out);
        self::assertCount(1, PostMetaRepository::$calls, 'exactly the one failed batch');
    }

    public function testALargePageIsReadInBoundedChunks(): void
    {
        $ids = range(1, PostMetaRepository::BATCH_MAX + 2);
        foreach ($ids as $id) {
            $this->seedGroup($id, ['_bcc_group_kind' => ['hall']]);
        }

        $out = (new GroupContextResolver())->forManyGroups($ids);

        self::assertCount(2, PostMetaRepository::$calls);
        self::assertCount(PostMetaRepository::BATCH_MAX, PostMetaRepository::$calls[0]['ids']);
        self::assertCount(2, PostMetaRepository::$calls[1]['ids']);
        self::assertSame(GroupType::Hall, $out[PostMetaRepository::BATCH_MAX + 2]->type);
    }

    /** @param array<string, list<string>> $rows */
    private function seedGroup(int $id, array $rows): void
    {
        $GLOBALS['__f463_posts'][$id] = 'peepso-group';
        PeepSoGroupPrivacy::$answers[$id] = PeepSoGroupPrivacy::OPEN;
        self::core($id, $rows);
    }

    private static function defineShims(): void
    {
        if (function_exists('BCC\\Trust\\Core\\Services\\get_post')) {
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
                    $type = $GLOBALS['__f463_posts'][(int) $id] ?? null;
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
                    $rows = $GLOBALS['__f463_meta'][(int) $id][$key] ?? [];
                    return $single ? ($rows[0] ?? '') : $rows;
                }
                function _prime_post_caches(array $ids, bool $updateTermCache = true, bool $updateMetaCache = true): void
                {
                }
            }
            PHP);
    }
}
