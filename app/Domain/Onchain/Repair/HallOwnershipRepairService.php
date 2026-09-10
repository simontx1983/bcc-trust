<?php
/**
 * Repair the orphaned `member_owner` rows on existing Halls.
 *
 * ── WHAT IS ACTUALLY BROKEN ─────────────────────────────────────────────
 * Every Hall created by the retired `bcc_hall_provision` cron carries a
 * membership row `(gm_user_id = 0, gm_user_status = 'member_owner')`.
 * PeepSo's `PeepSoGroup::create()` calls `new PeepSoGroupUser($id)` with no
 * user id, and that constructor defaults to `get_current_user_id()` — which
 * under cron is 0. The resolved administrator id only ever reached
 * `post_author`.
 *
 * A read-only audit of staging and production (2026-09-09) measured the
 * same shape in both: every Hall owned by user 0, `post_author` already
 * correct, and **user 0 present on no other group of any kind**. So
 * `post_author` needs no change; only the ledger row does.
 *
 * ── WHY THIS IS NOT A MIGRATION ─────────────────────────────────────────
 * It writes PeepSo's membership graph. It runs when a named administrator
 * runs it and watches the output — never on activation, never on a
 * schedule, never from a browser. There is deliberately no migration entry,
 * no cron hook, no REST route, no admin-post handler and no AJAX action
 * that reaches this class; its only caller is
 * {@see \BCC\Trust\Onchain\CLI\HallOwnershipRepairCommand}.
 *
 * ── WHY IT RE-POINTS RATHER THAN GOING THROUGH PeepSoGroupWriter ────────
 * The writer models real users. The row being fixed names a user that does
 * not exist, so every writer method refuses or misfires:
 * `transferOwnership()` requires the from-user to own on both books (here
 * `post_author` is already the intended owner, so it refuses every argument
 * order), `join()` would add a SECOND row beside the orphan, and `leave()`
 * refuses to remove an owner. Re-pointing the orphan in place is the only
 * operation that describes the actual defect, and it is confined to
 * {@see HallOwnershipRepairRepository::repointOwnerRow()}.
 *
 * ── THE SAFETY MODEL ────────────────────────────────────────────────────
 *  1. Dry run is the default and writes nothing.
 *  2. A verified file backup of every affected row is taken BEFORE the
 *     first write, and re-read and compared against the live rows. If it
 *     cannot be written, re-read, or matched, the ENTIRE apply is refused —
 *     not the one Hall, all of them.
 *  3. Each Hall is repaired in its own transaction, under row locks, with a
 *     checked audit row inside the transaction. A lost audit row rolls the
 *     repair back: an unattributable ownership change is not allowed to
 *     exist.
 *  4. Postconditions are re-read under the same lock — the new owner row,
 *     the absence of the orphan, EVERY pre-existing real member still
 *     present, and the recomputed member count.
 *  5. A rollback artifact is written from rows ACTUALLY changed, after the
 *     fact, never from the plan.
 *  6. Re-running finds nothing to do.
 *
 * @package BCC\Trust\Onchain\Repair
 */

namespace BCC\Trust\Onchain\Repair;

use BCC\Core\Log\Logger;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Core\Security\TransactionManager;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\HallOwnershipRepairRepository as Repo;
use BCC\Trust\Onchain\Repositories\HallRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class HallOwnershipRepairService
{
    /** Fits VARCHAR(50). */
    public const AUDIT_ACTION      = 'admin_hall_owner_repaired';
    public const AUDIT_TARGET_TYPE = 'chain';

    public const RESULT_WOULD_REPAIR         = 'would_repair';
    public const RESULT_REPAIRED             = 'repaired';
    public const RESULT_ALREADY_CORRECT      = 'already_correct';
    public const RESULT_REFUSED_PRECONDITION = 'refused_precondition';
    public const RESULT_FAILED_ROLLED_BACK   = 'failed_rolled_back';

    /**
     * Plan, and optionally apply, the repair.
     *
     * @param  int $ownerId the intended first active administrator, already
     *                      validated by the caller
     * @return array{results: list<array<string, mixed>>, backup: array<string, mixed>|null, rollback: array<string, mixed>|null}
     */
    public function run(bool $apply, int $ownerId, int $operatorId, string $runId, string $artifactDir): array
    {
        $plan = $this->plan($ownerId);

        if (!$apply) {
            return ['results' => $plan, 'backup' => null, 'rollback' => null];
        }

        $repairable = array_values(array_filter(
            $plan,
            static fn(array $p): bool => $p['result'] === self::RESULT_WOULD_REPAIR
        ));

        if ($repairable === []) {
            return ['results' => $plan, 'backup' => null, 'rollback' => null];
        }

        // ── THE BACKUP GATE ─────────────────────────────────────────────
        // Written, re-read and compared against the live rows before a
        // single write happens. A failure here refuses the WHOLE apply.
        $backup = $this->writeVerifiedBackup($repairable, $runId, $artifactDir);
        if ($backup['verified'] !== true) {
            throw new \RuntimeException(
                'backup could not be written and verified (' . (string) $backup['error'] . '); '
                . 'refusing the entire apply — no row was touched'
            );
        }

        $results = [];
        $changed = [];

        foreach ($plan as $entry) {
            if ($entry['result'] !== self::RESULT_WOULD_REPAIR) {
                $results[] = $entry;
                continue;
            }

            $outcome = $this->applyOne($entry, $ownerId, $operatorId, $runId);
            $results[] = $outcome;

            if ($outcome['result'] === self::RESULT_REPAIRED) {
                // Recorded from the row that was actually verified changed.
                $changed[] = [
                    'gm_id'          => $outcome['row_id'],
                    'group_id'       => $outcome['group_id'],
                    'chain_id'       => $outcome['chain_id'],
                    'status'         => Repo::OWNER_STATUS,
                    'restore_to'     => Repo::ORPHAN_USER_ID,
                    'was_changed_to' => $ownerId,
                    'members_count_before' => $outcome['members_count_before'],
                    'members_count_after'  => $outcome['members_count_after'],
                ];
            }
        }

        $rollback = $this->writeRollbackArtifact($changed, $runId, $artifactDir, $ownerId);

        return ['results' => $results, 'backup' => $backup, 'rollback' => $rollback];
    }

    /**
     * The intended owner: the FIRST ACTIVE administrator, by user id.
     *
     * ── WHY "ACTIVE" IS PART OF THE RULE ────────────────────────────────
     * `HallProvisioningService::resolveOwnerId()` takes the lowest-id
     * administrator and checks existence + `manage_options`. This adds the
     * suspension check, because handing 21 official spaces to a suspended
     * account is a different kind of wrong from handing them to a deleted
     * one, and only the second fails on its own.
     *
     * Deliberately NOT reusing the provisioner's private method: that one
     * answers "who should own a Hall being created now", and coupling a
     * bulk repair of existing rows to it would mean a future change to
     * creation silently re-targets a repair. Two callers, two decisions,
     * one documented overlap.
     *
     * Returns 0 when no eligible administrator exists — the caller must
     * refuse rather than fall back to anyone.
     */
    public static function resolveIntendedOwner(): int
    {
        $ids = get_users([
            'role'    => 'administrator',
            'orderby' => 'ID',
            'order'   => 'ASC',
            'number'  => 50,
            'fields'  => 'ID',
        ]);

        if (!is_array($ids)) {
            return 0;
        }

        foreach ($ids as $raw) {
            $id = (int) $raw;
            if ($id <= 0) {
                continue;
            }
            if (get_userdata($id) === false) {
                continue;
            }
            // Checked against the NAMED user — the CLI has no ambient one.
            if (!user_can($id, 'manage_options')) {
                continue;
            }
            $suspended = get_user_meta($id, 'bcc_trust_suspended', true);
            if ($suspended !== '' && $suspended !== '0' && $suspended !== false && $suspended !== null) {
                continue;
            }

            return $id;
        }

        return 0;
    }

    // ──────────────────────────────────────────────────────────────────
    // Planning
    // ──────────────────────────────────────────────────────────────────

    /**
     * Every Hall, with its verdict. Unlocked reads — the locked re-check
     * inside the transaction is what the write actually depends on.
     *
     * @return list<array<string, mixed>>
     */
    public function plan(int $ownerId): array
    {
        $out = [];

        foreach (HallRepository::listAllHallIds() as $groupId) {
            $out[] = $this->planOne((int) $groupId, $ownerId);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function planOne(int $groupId, int $ownerId): array
    {
        $meta  = Repo::readMarkerMeta($groupId);
        $rows  = Repo::readMembershipRows($groupId);
        $check = $this->assertEligible($groupId, $ownerId, $meta, $rows);

        $chainId = isset($meta[HallRepository::META_CHAIN_TAG][0])
            ? (int) $meta[HallRepository::META_CHAIN_TAG][0]
            : 0;

        $base = [
            'group_id' => $groupId,
            'chain_id' => $chainId,
            'row_id'   => 0,
            'members_count_before' => null,
            'members_count_after'  => null,
        ];

        if ($check['eligible'] !== true) {
            return $base + ['result' => $check['result'], 'detail' => $check['detail']];
        }

        return $base + [
            'result' => self::RESULT_WOULD_REPAIR,
            'detail' => '',
            'row_id' => $check['row_id'],
        ];
    }

    /**
     * EVERY guard. This is the product.
     *
     * Each one is separate and named so a mutation control can prove it is
     * load-bearing: a guard that can be deleted without a test failing is
     * not protecting anything.
     *
     * @param  array<string, list<string>> $meta
     * @param  list<object{gm_id: string, gm_user_id: string, gm_user_status: string}> $rows
     * @return array{eligible: bool, result: string, detail: string, row_id: int}
     */
    private function assertEligible(int $groupId, int $ownerId, array $meta, array $rows): array
    {
        $no = static fn(string $detail): array => [
            'eligible' => false,
            'result'   => self::RESULT_REFUSED_PRECONDITION,
            'detail'   => $detail,
            'row_id'   => 0,
        ];

        // ── 1. It is a Hall, exactly ────────────────────────────────────
        // Strict comparison: wp_postmeta collation is case-insensitive, so
        // an SQL `= 'hall'` would also accept 'Hall'.
        $kinds = $meta[HallRepository::META_KIND] ?? [];
        if (count($kinds) !== 1)              { return $no('kind_meta_not_single'); }
        if ($kinds[0] !== HallRepository::KIND_HALL) { return $no('not_a_hall'); }

        // ── 2. NOT another kind of community ────────────────────────────
        // Belt and braces over guard 1: a group carrying gate config is a
        // holder or validator community whatever its kind meta says.
        if (isset($meta['_bcc_gate_collection_id'])) { return $no('has_collection_gate'); }
        if (isset($meta['_bcc_gate_validator_id']))  { return $no('has_validator_gate'); }

        // ── 3. Bound to exactly one real chain ──────────────────────────
        $tags = $meta[HallRepository::META_CHAIN_TAG] ?? [];
        if (count($tags) !== 1)                { return $no('chain_tag_not_single'); }
        $chainId = (int) $tags[0];
        if ($chainId <= 0)                     { return $no('chain_tag_not_numeric'); }
        if (ChainRepository::getById($chainId) === null) { return $no('chain_not_found'); }

        // ── 4. Published, open, and actually a group post ───────────────
        $privacy = $meta['peepso_group_privacy'] ?? [];
        if (count($privacy) !== 1)             { return $no('privacy_meta_not_single'); }
        if ((int) $privacy[0] !== 0)           { return $no('not_open_privacy'); }

        // ── 5. Exactly ONE orphan owner row ─────────────────────────────
        $orphans = [];
        $ownerRows = [];
        foreach ($rows as $row) {
            $uid    = (int) $row->gm_user_id;
            $status = (string) $row->gm_user_status;

            if ($status === Repo::OWNER_STATUS) {
                $ownerRows[] = $uid;
                if ($uid === Repo::ORPHAN_USER_ID) {
                    $orphans[] = (int) $row->gm_id;
                }
            }
        }

        if (count($ownerRows) === 0)           { return $no('no_owner_row'); }
        if (count($ownerRows) > 1)             { return $no('multiple_owner_rows'); }
        if ($orphans === []) {
            // The single owner row belongs to a real user. If that user is
            // the intended owner this Hall is simply fine; if not, it is a
            // DIFFERENT problem than the one this repair models and is left
            // alone rather than quietly re-assigned.
            return $ownerRows[0] === $ownerId
                ? ['eligible' => false, 'result' => self::RESULT_ALREADY_CORRECT, 'detail' => '', 'row_id' => 0]
                : $no('owner_is_a_different_real_user');
        }
        if (count($orphans) > 1)               { return $no('multiple_orphan_owner_rows'); }

        // ── 6. No collision: the incoming owner holds NO row here ───────
        // `peepso_group_members` has no unique key on (user, group), so a
        // re-point onto a user who already has a row would silently create
        // a duplicate membership.
        foreach ($rows as $row) {
            if ((int) $row->gm_user_id === $ownerId) {
                return $no('owner_already_has_a_row');
            }
        }

        // ── 7. The orphan really is orphaned ────────────────────────────
        // If user 0 somehow existed, this would not be the defect being
        // repaired and the row would belong to a real account.
        if (Repo::userExists(Repo::ORPHAN_USER_ID)) { return $no('orphan_user_exists'); }

        // ── 8. The incoming owner is real, and countable by PeepSo ──────
        if (!Repo::userExists($ownerId))            { return $no('owner_user_missing'); }
        if (!Repo::isPeepSoCountableUser($ownerId)) { return $no('owner_not_peepso_countable'); }

        return ['eligible' => true, 'result' => self::RESULT_WOULD_REPAIR, 'detail' => '', 'row_id' => $orphans[0]];
    }

    // ──────────────────────────────────────────────────────────────────
    // Apply
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function applyOne(array $entry, int $ownerId, int $operatorId, string $runId): array
    {
        $groupId = (int) $entry['group_id'];

        try {
            /** @var array<string, mixed> $outcome */
            $outcome = TransactionManager::run(
                fn(): array => $this->applyOneLocked($entry, $ownerId, $operatorId, $runId)
            );
        } catch (\Throwable $e) {
            // TransactionManager has already rolled back. Record the class
            // and a bounded message — never raw prose in the operator's
            // terminal, which can carry SQL and absolute paths.
            Logger::error('[bcc-trust] Hall ownership repair rolled back', [
                'run_id'    => $runId,
                'group_id'  => $groupId,
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
            ]);

            return array_merge($entry, [
                'result' => self::RESULT_FAILED_ROLLED_BACK,
                'detail' => (new \ReflectionClass($e))->getShortName(),
            ]);
        }

        // Caches only AFTER commit — invalidating inside would publish a
        // state a rollback could un-happen.
        $this->invalidateAfterCommit($groupId);

        return array_merge($entry, $outcome);
    }

    /**
     * The transaction body. Every read here is locked.
     *
     * @param  array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function applyOneLocked(array $entry, int $ownerId, int $operatorId, string $runId): array
    {
        $groupId = (int) $entry['group_id'];
        $chainId = (int) $entry['chain_id'];

        $post = Repo::lockPost($groupId);
        if ($post === null || (string) $post->post_type !== 'peepso-group') {
            throw new \RuntimeException('group post missing or wrong type under lock');
        }
        if ((string) $post->post_status !== 'publish') {
            throw new \RuntimeException('group post is not published under lock');
        }

        $rows = Repo::lockMembershipRows($groupId);
        $meta = Repo::readMarkerMeta($groupId);

        // ── EVERY GUARD, RE-EVALUATED UNDER THE LOCK ────────────────────
        // The plan was read without locks and may be stale. Re-checking is
        // what makes a concurrent change a refusal rather than a corruption.
        $check = $this->assertEligible($groupId, $ownerId, $meta, $rows);
        if ($check['eligible'] !== true) {
            throw new \RuntimeException('precondition changed under lock: ' . $check['detail']);
        }

        $rowId = (int) $check['row_id'];

        // Every real member, recorded BEFORE the write so the postcondition
        // can prove not one of them moved.
        $membersBefore = [];
        foreach ($rows as $row) {
            if ((int) $row->gm_user_id !== Repo::ORPHAN_USER_ID) {
                $membersBefore[(int) $row->gm_id] = (int) $row->gm_user_id . ':' . (string) $row->gm_user_status;
            }
        }

        $countBefore = Repo::computePeepSoMemberCount($groupId);

        // ── THE WRITE: one row, by primary key, fully qualified ─────────
        $affected = Repo::repointOwnerRow(
            $rowId,
            $groupId,
            Repo::ORPHAN_USER_ID,
            $ownerId,
            Repo::OWNER_STATUS
        );

        if ($affected !== 1) {
            throw new \RuntimeException('owner row update affected ' . $affected . ' rows, expected exactly 1');
        }

        // ── Postconditions, re-read under the SAME lock ─────────────────
        $after = Repo::lockMembershipRows($groupId);

        $ownerNow  = [];
        $orphanNow = 0;
        $membersAfter = [];
        foreach ($after as $row) {
            $uid = (int) $row->gm_user_id;
            if ((string) $row->gm_user_status === Repo::OWNER_STATUS) {
                $ownerNow[] = $uid;
            }
            if ($uid === Repo::ORPHAN_USER_ID) {
                $orphanNow++;
            }
            if ($uid !== $ownerId) {
                $membersAfter[(int) $row->gm_id] = $uid . ':' . (string) $row->gm_user_status;
            }
        }

        if ($ownerNow !== [$ownerId]) {
            throw new \RuntimeException('owner row did not survive its postcondition re-read');
        }
        if ($orphanNow !== 0) {
            throw new \RuntimeException('an orphan row is still present after the repair');
        }

        // ⚠ EVERY PRE-EXISTING REAL MEMBER, UNCHANGED. Not a count — the
        // exact (row id => user:status) set. A count would pass if one
        // member were swapped for another.
        if ($membersAfter !== $membersBefore) {
            throw new \RuntimeException('a pre-existing member row changed; rolling back');
        }

        // ── PeepSo's stored count, recomputed its own way ───────────────
        $countAfter = Repo::computePeepSoMemberCount($groupId);
        update_post_meta($groupId, 'peepso_group_members_count', $countAfter);

        $stored = get_post_meta($groupId, 'peepso_group_members_count', true);
        if ((int) $stored !== $countAfter) {
            throw new \RuntimeException('member count did not survive its postcondition re-read');
        }

        // ── Checked audit, INSIDE the transaction ───────────────────────
        $auditMeta = [
            'run_id'               => $runId,
            'group_id'             => $groupId,
            'chain_id'             => $chainId,
            'row_id'               => $rowId,
            'field'                => 'gm_user_id',
            'before'               => Repo::ORPHAN_USER_ID,
            'after'                => $ownerId,
            'status'               => Repo::OWNER_STATUS,
            'operator_user_id'     => $operatorId,
            'members_count_before' => $countBefore,
            'members_count_after'  => $countAfter,
            'preserved_member_rows'=> count($membersBefore),
        ];

        $auditId = AuditLogger::logChecked(
            self::AUDIT_ACTION,
            $chainId,
            $auditMeta,
            self::AUDIT_TARGET_TYPE,
            $operatorId
        );

        if ($auditId === null || $auditId <= 0) {
            // No honest record of an ownership change means the change does
            // not happen.
            throw new \RuntimeException('checked audit write failed; rolling back the repair');
        }

        $this->verifyAuditRow($auditId, $auditMeta);

        return [
            'result'               => self::RESULT_REPAIRED,
            'detail'               => '',
            'row_id'               => $rowId,
            'members_count_before' => $countBefore,
            'members_count_after'  => $countAfter,
            'audit_id'             => $auditId,
        ];
    }

    /**
     * Prove the audit row says what the repair meant.
     *
     * @param array<string, mixed> $expected
     */
    private function verifyAuditRow(int $auditId, array $expected): void
    {
        $stored = Repo::readAuditRow($auditId);

        if ($stored === null) {
            throw new \RuntimeException('audit row not readable after insert');
        }
        if ((string) $stored->action !== self::AUDIT_ACTION) {
            throw new \RuntimeException('audit row action mismatch');
        }
        if ($stored->meta === null || $stored->meta === '') {
            throw new \RuntimeException('audit row stored no metadata');
        }

        $decoded = json_decode((string) $stored->meta, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('audit metadata is not decodable JSON');
        }

        foreach (['run_id', 'group_id', 'chain_id', 'row_id', 'before', 'after', 'operator_user_id'] as $key) {
            if (!array_key_exists($key, $decoded)) {
                throw new \RuntimeException('audit metadata missing key: ' . $key);
            }
            if ($decoded[$key] !== $expected[$key]) {
                throw new \RuntimeException('audit metadata value differs for key: ' . $key);
            }
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // Artifacts
    // ──────────────────────────────────────────────────────────────────

    /**
     * Write the backup, then PROVE it — re-read from disk and compare to
     * the live rows. "We called file_put_contents" is not a backup.
     *
     * @param  list<array<string, mixed>> $repairable
     * @return array<string, mixed>
     */
    private function writeVerifiedBackup(array $repairable, string $runId, string $dir): array
    {
        $payload = [
            'kind'       => 'bcc-hall-ownership-backup',
            'run_id'     => $runId,
            'created_at' => gmdate('c'),
            'groups'     => [],
        ];

        foreach ($repairable as $entry) {
            $groupId = (int) $entry['group_id'];
            $rows    = [];
            foreach (Repo::readMembershipRows($groupId) as $row) {
                $rows[] = [
                    'gm_id'          => (int) $row->gm_id,
                    'gm_group_id'    => (int) $row->gm_group_id,
                    'gm_user_id'     => (int) $row->gm_user_id,
                    'gm_user_status' => (string) $row->gm_user_status,
                ];
            }
            $payload['groups'][] = [
                'group_id'            => $groupId,
                'chain_id'            => (int) $entry['chain_id'],
                'membership_rows'     => $rows,
                'members_count_meta'  => (string) get_post_meta($groupId, 'peepso_group_members_count', true),
            ];
        }

        $path = rtrim($dir, "/\\") . '/hall-ownership-backup-' . $runId . '.json';

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return ['verified' => false, 'error' => 'backup could not be encoded', 'path' => $path];
        }

        if (!is_dir($dir) || !is_writable($dir)) {
            return ['verified' => false, 'error' => 'artifact directory is not writable', 'path' => $path];
        }

        if (file_put_contents($path, $json) === false) {
            return ['verified' => false, 'error' => 'backup write failed', 'path' => $path];
        }

        // ── THE VERIFICATION ────────────────────────────────────────────
        $readBack = @file_get_contents($path);
        if (!is_string($readBack) || $readBack === '') {
            return ['verified' => false, 'error' => 'backup unreadable after write', 'path' => $path];
        }

        $decoded = json_decode($readBack, true);
        if (!is_array($decoded) || !isset($decoded['groups']) || !is_array($decoded['groups'])) {
            return ['verified' => false, 'error' => 'backup did not decode', 'path' => $path];
        }
        if (count($decoded['groups']) !== count($payload['groups'])) {
            return ['verified' => false, 'error' => 'backup group count mismatch', 'path' => $path];
        }

        // And it must still describe the LIVE rows, not a stale snapshot.
        foreach ($decoded['groups'] as $g) {
            $live = [];
            foreach (Repo::readMembershipRows((int) $g['group_id']) as $row) {
                $live[] = (int) $row->gm_id . ':' . (int) $row->gm_user_id . ':' . (string) $row->gm_user_status;
            }
            $saved = array_map(
                static fn(array $r): string => $r['gm_id'] . ':' . $r['gm_user_id'] . ':' . $r['gm_user_status'],
                $g['membership_rows']
            );
            if ($live !== $saved) {
                return ['verified' => false, 'error' => 'backup does not match live rows', 'path' => $path];
            }
        }

        return [
            'verified'   => true,
            'error'      => '',
            'path'       => $path,
            'groups'     => count($payload['groups']),
            'sha256'     => hash('sha256', $readBack),
        ];
    }

    /**
     * The rollback artifact, built from rows ACTUALLY changed.
     *
     * Never from the plan: a plan entry that failed, refused or rolled back
     * did not change anything, and a rollback that tried to "restore" it
     * would be writing a state that never existed.
     *
     * @param  list<array<string, mixed>> $changed
     * @return array<string, mixed>
     */
    private function writeRollbackArtifact(array $changed, string $runId, string $dir, int $ownerId): array
    {
        $path = rtrim($dir, "/\\") . '/hall-ownership-rollback-' . $runId . '.json';

        $payload = [
            'kind'          => 'bcc-hall-ownership-rollback',
            'run_id'        => $runId,
            'created_at'    => gmdate('c'),
            'repaired_to'   => $ownerId,
            'row_count'     => count($changed),
            'rows'          => $changed,
            'how_to_apply'  => 'Each row re-points gm_id back to restore_to, guarded on '
                . '(gm_id, gm_group_id, gm_user_id=was_changed_to, gm_user_status). '
                . 'Restore members_count_before alongside it.',
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || file_put_contents($path, $json) === false) {
            // The repair itself already committed and is audited; say so
            // loudly rather than implying it can be undone from a file that
            // does not exist.
            Logger::error('[bcc-trust] Hall ownership rollback artifact could not be written', [
                'run_id' => $runId,
                'rows'   => count($changed),
            ]);

            return ['written' => false, 'path' => $path, 'row_count' => count($changed)];
        }

        return [
            'written'   => true,
            'path'      => $path,
            'row_count' => count($changed),
            'sha256'    => hash('sha256', $json),
        ];
    }

    private function invalidateAfterCommit(int $groupId): void
    {
        if ($groupId <= 0) {
            return;
        }

        wp_cache_delete($groupId, 'post_meta');
        clean_post_cache($groupId);
    }
}
