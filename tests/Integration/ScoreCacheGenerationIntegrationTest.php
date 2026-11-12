<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Core\Repositories\ScoreRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-87 L2: on a host WITH a persistent object cache, losing the page-score
 * generation key (expiry, eviction, restart) must not point readers back at
 * an entry cached under an earlier generation.
 *
 * Before the fix a missing key read as generation 0 and a bump re-created it
 * as 1 (with a one-hour expiry), so each eviction restarted the counter and
 * the next reader got whatever was still cached under g0 / g1 — up to
 * CACHE_TTL old — as the current score.
 *
 * Eviction is modelled by deleting the key from the bootstrap's in-memory
 * object cache; $GLOBALS['__bcc_test_ext_object_cache'] puts ScoreRepository
 * on its external-cache branch.
 */
#[Group('integration')]
#[CoversClass(ScoreRepository::class)]
final class ScoreCacheGenerationIntegrationTest extends TestCase
{
    private const PAGE = 970801;

    protected function setUp(): void
    {
        $GLOBALS['wpdb']->clearFaultInjection();
        $GLOBALS['__bcc_test_object_cache']    = [];
        $GLOBALS['__bcc_test_ext_object_cache'] = true;
        $this->deleteFixture();
        $wpdb = $GLOBALS['wpdb'];
        self::assertNotFalse($wpdb->insert(TableRegistry::scores(), [
            'page_id'            => self::PAGE,
            'category_id'        => 0,
            'page_owner_id'      => 970890,
            'total_score'        => 50,
            'endorsement_count'  => 1,
            'last_calculated_at' => gmdate('Y-m-d H:i:s'),
        ]), (string) $wpdb->last_error);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__bcc_test_ext_object_cache'], $GLOBALS['__bcc_test_object_cache_incr_creates']);
        $GLOBALS['__bcc_test_object_cache'] = [];
        $this->deleteFixture();
    }

    private function deleteFixture(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query($wpdb->prepare('DELETE FROM `' . TableRegistry::scores() . '` WHERE page_id = %d', self::PAGE));
    }

    /** A committed write + its invalidation, as every score writer does it. */
    private function write(int $endorsements): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query($wpdb->prepare(
            'UPDATE `' . TableRegistry::scores() . '` SET endorsement_count = %d WHERE page_id = %d',
            $endorsements,
            self::PAGE
        ));
        (new ScoreRepository())->invalidateCache(self::PAGE);
    }

    /** A request's read — a NEW repository, so no request-scoped memo. */
    private function read(): int
    {
        $score = (new ScoreRepository())->getByPageId(self::PAGE, 0);
        self::assertNotNull($score);

        return $score->getEndorsementCount();
    }

    private function evictGenerationKey(): void
    {
        unset($GLOBALS['__bcc_test_object_cache']['bcc_trust_scores']['score_gen_' . self::PAGE]);
    }

    public function testABumpAfterEvictionDoesNotReviveAnOlderGeneration(): void
    {
        $this->write(2);
        self::assertSame(2, $this->read(), 'precondition: cached under the generation the bump set');

        $this->write(3);
        self::assertSame(3, $this->read());

        $this->evictGenerationKey();
        $this->write(4);

        self::assertSame(4, $this->read(), 'served an entry cached under an earlier generation');
    }

    public function testAReadAfterEvictionDoesNotReviveAnOlderGeneration(): void
    {
        // Pre-fix: the first read ran with NO key, cached 1 under "g0", and an
        // eviction later sent readers back to g0 without any bump at all.
        self::assertSame(1, $this->read(), 'precondition');

        $this->write(5);
        self::assertSame(5, $this->read());

        $this->evictGenerationKey();

        self::assertSame(5, $this->read(), 'served an entry cached before the last write');
    }

    public function testABumpAfterEvictionOnARedisBackendDoesNotReviveAnOlderGeneration(): void
    {
        // Redis Object Cache's incr CREATES a missing key at 1 instead of
        // failing, so "incr returned false" never fires there.
        $GLOBALS['__bcc_test_object_cache_incr_creates'] = true;

        $this->evictGenerationKey();
        $this->write(2);
        self::assertSame(2, $this->read(), 'precondition');

        $this->evictGenerationKey();
        $this->write(3);
        self::assertSame(3, $this->read());

        $this->evictGenerationKey();
        $this->write(4);

        self::assertSame(4, $this->read(), 'Redis re-created the key at 1 and an older generation was served');
        // ...and the bump left a USABLE generation: a key stuck below the
        // floor is never keyed on (get()), so the page would never hit cache.
        self::assertGreaterThanOrEqual(
            \BCC\Trust\Core\Support\CacheGeneration::SEED_FLOOR,
            $GLOBALS['__bcc_test_object_cache']['bcc_trust_scores']['score_gen_' . self::PAGE] ?? 0,
            'the bump left the key at the small value Redis created'
        );
    }

    public function testASmallLegacyGenerationIsNeverKeyedOn(): void
    {
        // A pre-F-87 counter (or one Redis just re-created at 1, before the
        // bump re-seeds it) can equal a generation already used. Model the
        // stale entry left under it: a cached "no row".
        $GLOBALS['__bcc_test_object_cache']['bcc_trust_scores']['score_gen_' . self::PAGE]   = 1;
        $GLOBALS['__bcc_test_object_cache']['bcc_trust_scores']['page_score_' . self::PAGE . '_0_g1'] = 'null';

        self::assertNotNull((new ScoreRepository())->getByPageId(self::PAGE, 0), 'read the stale entry under the legacy g1');
    }

    public function testAReSeededKeyStartsFromTheClockNotFromOne(): void
    {
        // The value that makes the two tests above hold: a re-seed lands past
        // anything the counter held before, never on a fixed small number.
        $this->evictGenerationKey();
        $this->write(6);

        $gen = $GLOBALS['__bcc_test_object_cache']['bcc_trust_scores']['score_gen_' . self::PAGE] ?? null;
        self::assertIsInt($gen);
        self::assertGreaterThan(1_000_000_000_000_000, $gen, 'seeded from the clock (µs), not from a fixed small value');
    }
}
