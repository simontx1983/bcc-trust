<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Core\Repositories\AttestationRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-143: the slow-ring detector must see every mutual-vouch pair exactly once.
 *
 * AttestationRepository::getMutualVouchPairsInWindow() is walked page by
 * page by the TrustGraph cron. It paged with LIMIT/OFFSET over a GROUP BY
 * with NO ORDER BY, so the group order was unspecified and could differ
 * between pages: pairs were skipped and others seen twice, and ring
 * detection silently missed rings. It now orders by (user_a, user_b) and
 * pages with a keyset cursor on that pair.
 *
 * Also pins the F-142-family fix in the same query: the card-target owner
 * join reads the canonical category_id = 0 score row, so a page carrying
 * category rows no longer multiplies mutual_count.
 *
 * Real MySQL.
 */
#[Group('integration')]
#[CoversClass(AttestationRepository::class)]
final class MutualVouchPairsPagingIntegrationTest extends TestCase
{
    private const BASE = 993000;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/database/schema-trust-attestations.php';
        bcc_trust_create_trust_attestations_table();

        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query('TRUNCATE TABLE `' . TableRegistry::trustAttestations() . '`');
        $wpdb->query('DELETE FROM `' . TableRegistry::scores() . '` WHERE page_owner_id BETWEEN ' . self::BASE . ' AND ' . (self::BASE + 99));
    }

    private function vouch(int $attestor, string $targetKind, int $targetId): void
    {
        $wpdb = $GLOBALS['wpdb'];
        self::assertNotFalse($wpdb->insert(TableRegistry::trustAttestations(), [
            'attestor_user_id' => $attestor,
            'target_kind'      => $targetKind,
            'target_id'        => $targetId,
            'kind'             => 'vouch',
            'weight_at_time'   => 1.0,
            'created_at'       => gmdate('Y-m-d H:i:s'),
        ]), $wpdb->last_error);
    }

    /** Seven reciprocal profile-vouch pairs → fourteen ordered (user_a, user_b) rows. */
    private function seedRing(): void
    {
        for ($i = 0; $i < 7; $i++) {
            $a = self::BASE + 2 * $i;
            $b = $a + 1;
            $this->vouch($a, 'user_profile', $b);
            $this->vouch($b, 'user_profile', $a);
        }
    }

    public function testAKeysetWalkSeesEveryPairExactlyOnceInOrder(): void
    {
        $this->seedRing();
        $repo = new AttestationRepository();

        $seen    = [];
        [$a, $b] = [0, 0];
        for ($guard = 0; $guard < 20; $guard++) {
            $page = $repo->getMutualVouchPairsInWindow(14, 1, 3, $a, $b);
            foreach ($page as $row) {
                $seen[] = $row->user_a . '>' . $row->user_b;
            }
            if (count($page) < 3) {
                break;
            }
            $last    = end($page);
            [$a, $b] = [$last->user_a, $last->user_b];
        }

        $expected = [];
        for ($i = 0; $i < 7; $i++) {
            $x          = self::BASE + 2 * $i;
            $expected[] = $x . '>' . ($x + 1);
            $expected[] = ($x + 1) . '>' . $x;
        }
        sort($expected);
        $sorted = $seen;
        sort($sorted);

        self::assertSame($expected, $sorted, 'every ordered pair, none twice');
        self::assertCount(14, array_unique($seen));
        self::assertSame($sorted, $seen, 'rows arrive ordered by (user_a, user_b)');
    }

    public function testACategoryRowDoesNotMultiplyTheMutualCount(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $a     = self::BASE + 50;
        $b     = self::BASE + 51;
        $pageA = 993901;
        $pageB = 993900;
        // Each page has its canonical row AND a category row, so BOTH owner
        // joins (s1 for A's vouch, s2 for B's) are exercised.
        foreach ([[$pageA, $a], [$pageB, $b]] as [$page, $owner]) {
            foreach ([0, 5] as $category) {
                self::assertNotFalse($wpdb->insert(TableRegistry::scores(), [
                    'page_id'       => $page,
                    'category_id'   => $category,
                    'page_owner_id' => $owner,
                ]), $wpdb->last_error);
            }
        }
        $this->vouch($a, 'validator', $pageB);   // A vouches B's page
        $this->vouch($b, 'validator', $pageA);   // B vouches A's page

        $rows = (new AttestationRepository())->getMutualVouchPairsInWindow(14, 1, 50);
        $ab   = array_values(array_filter($rows, static fn (object $r): bool => $r->user_a === $a && $r->user_b === $b));

        self::assertCount(1, $ab);
        self::assertSame(1, $ab[0]->mutual_count, 'one reciprocal vouch, not one per category row');
    }
}
