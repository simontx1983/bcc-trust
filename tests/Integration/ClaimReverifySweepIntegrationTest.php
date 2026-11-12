<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\ClaimRepository;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
use BCC\Trust\Onchain\Services\ClaimReverifySweepService;
use BCC\Trust\Onchain\Support\Bech32;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-14 PR B2, against real MySQL / MariaDB: the re-verify sweep's keyset read
 * and its validator decisions over seeded rows (report mode — what it WOULD
 * revoke).
 *
 * The suite uses users 915001/915002 and deletes only its own rows.
 */
#[Group('integration')]
#[Group('mariadb')]
#[CoversClass(ClaimRepository::class)]
#[CoversClass(ClaimReverifySweepService::class)]
final class ClaimReverifySweepIntegrationTest extends TestCase
{
    private const USER  = 915001;
    private const OTHER = 915002;

    private int $chain = 0;
    /** @var list<int> */
    private array $validatorIds = [];
    /** @var list<int> */
    private array $walletIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $this->chain = (int) $wpdb->get_var("SELECT id FROM `{$wpdb->prefix}bcc_chains` WHERE chain_type = 'cosmos' ORDER BY id ASC LIMIT 1");
        self::assertGreaterThan(0, $this->chain, 'the bootstrap must seed a Cosmos chain');
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']->clearFaultInjection();
        $this->cleanUp();
        parent::tearDown();
    }

    private function cleanUp(): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare('DELETE FROM `' . ClaimRepository::table() . '` WHERE user_id IN (%d, %d)', self::USER, self::OTHER));
        foreach ($this->validatorIds as $id) {
            $wpdb->delete($wpdb->prefix . 'bcc_onchain_validators', ['id' => $id]);
        }
        foreach ($this->walletIds as $id) {
            $wpdb->delete($wpdb->prefix . 'bcc_wallet_links', ['id' => $id]);
        }
        $this->validatorIds = $this->walletIds = [];
    }

    private static function address(string $hrp, string $hex): string
    {
        return Bech32::encode($hrp, (string) hex2bin($hex));
    }

    private function validator(string $hex): int
    {
        global $wpdb;
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'bcc_onchain_validators', [
            'operator_address' => self::address('cosmosvaloper', $hex),
            'chain_id'         => $this->chain,
            'moniker'          => 'F-14 ' . substr($hex, 0, 6),
            'expires_at'       => gmdate('Y-m-d H:i:s', time() + 86400),
        ]), $wpdb->last_error);
        return $this->validatorIds[] = (int) $wpdb->insert_id;
    }

    private function wallet(string $hex, bool $verified = true): string
    {
        global $wpdb;
        $address = self::address('cosmos', $hex);
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'bcc_wallet_links', [
            'user_id'        => self::USER,
            'post_id'        => 0,
            'wallet_address' => $address,
            'chain_id'       => $this->chain,
            'wallet_type'    => 'user',
            'verified_at'    => $verified ? gmdate('Y-m-d H:i:s') : null,
        ]), $wpdb->last_error);
        $this->walletIds[] = (int) $wpdb->insert_id;
        return $address;
    }

    private function claim(int $validatorId, string $wallet, int $userId = self::USER): int
    {
        $r = ClaimRepository::upsert($userId, 'validator', $validatorId, $wallet, $this->chain, 'operator');
        self::assertIsArray($r);
        return $r['id'];
    }

    // ── the keyset read ────────────────────────────────────────────────

    public function testTheReadIsKeysetOrderedVerifiedOnlyAndJoinsTheEntity(): void
    {
        $hexA = '0123456789abcdef0123456789abcdef01234567';
        $vA = $this->validator($hexA);
        $c1 = $this->claim($vA, $this->wallet($hexA));
        $c2 = $this->claim($vA, 'cosmos1other', self::OTHER);
        ClaimRepository::upsert(self::USER, 'page', 915010, 'w', $this->chain, 'operator'); // mirrors are excluded
        $revoked = ClaimRepository::upsert(self::OTHER, 'collection', 915011, '0xrev', $this->chain, 'holder');
        self::assertIsArray($revoked);
        global $wpdb;
        $wpdb->update(ClaimRepository::table(), ['status' => 'revoked'], ['id' => $revoked['id']]); // revoked claims are excluded

        $rows = array_values(array_filter(
            ClaimRepository::listVerifiedEntityClaimsAfter(0, 1000),
            static fn (object $r): bool => in_array((int) $r->user_id, [self::USER, self::OTHER], true)
        ));

        self::assertSame([$c1, $c2], array_map(static fn (object $r): int => (int) $r->id, $rows));
        self::assertSame((string) $vA, (string) $rows[0]->entity_row_id);
        self::assertSame(self::address('cosmosvaloper', $hexA), $rows[0]->operator_address);
        self::assertSame('cosmos', $rows[0]->chain_type);

        $after = array_map(static fn (object $r): int => (int) $r->id, ClaimRepository::listVerifiedEntityClaimsAfter($c1, 1000));
        self::assertNotContains($c1, $after, 'strictly after the cursor');
        self::assertContains($c2, $after);
    }

    public function testAMissingValidatorRowReadsAsAbsentNotAsAnError(): void
    {
        $id = $this->claim(999999990, 'cosmos1gone');

        $row = null;
        foreach (ClaimRepository::listVerifiedEntityClaimsAfter($id - 1, 10) as $r) {
            if ((int) $r->id === $id) {
                $row = $r;
            }
        }

        self::assertNotNull($row);
        self::assertNull($row->entity_row_id, 'a returned row with no entity is a DEFINITE "row gone"');
    }

    public function testAFailedReadThrows(): void
    {
        $GLOBALS['wpdb']->failQueriesMatching = '/' . preg_quote(ClaimRepository::table(), '/') . '/';

        $this->expectException(RepositoryReadFailure::class);
        ClaimRepository::listVerifiedEntityClaimsAfter(0, 10);
    }

    // ── decisions on real rows (report mode) ───────────────────────────

    public function testValidatorDecisionsOnRealRows(): void
    {
        $hexKeep = '0123456789abcdef0123456789abcdef01234567';
        $hexGone = 'fedcba9876543210fedcba9876543210fedcba98';
        $hexUnv  = '00112233445566778899aabbccddeeff00112233';

        $keep    = $this->claim($this->validator($hexKeep), $this->wallet($hexKeep));
        $noLink  = $this->claim($this->validator($hexGone), self::address('cosmos', $hexGone)); // never linked
        $unverif = $this->claim($this->validator($hexUnv), $this->wallet($hexUnv, false));      // link not verified
        $noRow   = $this->claim(999999991, 'cosmos1norowfixture'); // the row check comes first

        $sweep = new RecordingSweep();
        $sweep->sweep();

        $mine = array_values(array_filter(RecordingSweep::$wouldRevoke, static fn (array $d): bool => in_array($d[0], [$keep, $noLink, $unverif, $noRow], true)));
        usort($mine, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        self::assertSame([
            [$noLink, 'wallet_link_gone'],
            [$unverif, 'wallet_link_gone'],
            [$noRow, 'validator_row_gone'],
        ], $mine, 'the kept claim is absent; the others are definite');
        self::assertSame('verified', ClaimRepository::getUserClaim(self::USER, 'validator', (int) ClaimRepository::findManyByIds([$keep])[$keep]->entity_id)?->status);
    }
}

/** The real sweep with real reads; only the side effects are recorded. */
final class RecordingSweep extends ClaimReverifySweepService
{
    /** @var list<array{int, string}> */
    public static array $wouldRevoke = [];

    public function __construct()
    {
        self::$wouldRevoke = [];
    }

    public function enforcing(): bool { return false; }
    protected function acquireLock(): bool { return true; }
    protected function releaseLock(): void {}
    protected function readCursor(): int { return 0; }
    protected function writeCursor(int $claimId): void {}
    protected function clearCursor(): void {}
    protected function scheduleContinuation(): void {}
    protected function auditWouldRevoke(object $row, string $reason): void { self::$wouldRevoke[] = [(int) $row->id, $reason]; }
    protected function logStats(array $stats): void {}
}
