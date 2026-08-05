<?php
/**
 * Helpful-Mark Repository
 *
 * Owns reads + writes against `bcc_trust_helpful_marks` — one row per
 * (act_id, user_id), enforced by a unique key, so the row's mere
 * existence IS the deliberate §9.2 "Mark helpful" endorsement (X-"like"
 * model: one mark per person, no counter).
 *
 * Deliberately its OWN table, NOT a reuse of `bcc_trust_stokes` or the
 * PeepSo reaction store: a Helpful mark is the sanctioned, credibility-
 * gated Rank "helping" evidence route (§9.2). Cosmetic Stoke / like /
 * solid carry no Rank weight; this mark does (from a credible marker).
 * Keeping the storage distinct is what preserves that §8.5 line.
 *
 * The row `id` is the STABLE source id the Rank evidence ledger keys on
 * (`helpful_mark:{id}:{author}`) — {@see addMark} returns it (existing or
 * fresh, idempotently) so the ADD subscriber can mint evidence, and
 * {@see removeMark} returns it so the REMOVE subscriber can reverse
 * exactly that evidence. Combined read+write in one repository, matching
 * the convention for simple per-user-per-target signal tables
 * ({@see StokeRepository}).
 *
 * @package BCC\Trust\Core\Repositories
 * @since Rank redesign (helping emitters)
 */

namespace BCC\Trust\Core\Repositories;

use BCC\Trust\Core\Database\TableRegistry;

if (!defined('ABSPATH')) {
    exit;
}

class HelpfulMarkRepository
{
    private string $table;

    public function __construct()
    {
        $this->table = TableRegistry::helpfulMarks();
    }

    /**
     * Record $userId's one Helpful mark on $actId and return the mark
     * row id. Idempotent: `INSERT … ON DUPLICATE KEY UPDATE
     * id = LAST_INSERT_ID(id)` re-selects the EXISTING row's id on a
     * duplicate (act_id, user_id) rather than inserting a second row, so
     * a re-mark returns the same stable id and mints no new evidence —
     * one mark per person.
     *
     * @return int The mark row id, or 0 on DB error.
     */
    public function addMark(int $actId, int $userId): int
    {
        if ($actId <= 0 || $userId <= 0) {
            return 0;
        }

        global $wpdb;
        $now = current_time('mysql', true);

        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$this->table} (act_id, user_id, created_at)
                  VALUES (%d, %d, %s)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)",
            $actId,
            $userId,
            $now
        ));

        if ($result === false) {
            return 0;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Remove $userId's Helpful mark on $actId, returning the removed
     * row's id (so the caller can reverse its Rank evidence). Idempotent:
     * returns 0 when the viewer has no mark on this act. Reads the id
     * BEFORE deleting so the reversal targets the exact source event.
     *
     * @return int The removed mark row id, or 0 when there was none / on error.
     */
    public function removeMark(int $actId, int $userId): int
    {
        if ($actId <= 0 || $userId <= 0) {
            return 0;
        }

        global $wpdb;

        $markId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table}
              WHERE act_id = %d AND user_id = %d
              LIMIT 1",
            $actId,
            $userId
        ));

        if ($markId <= 0) {
            return 0;
        }

        $deleted = $wpdb->delete(
            $this->table,
            ['id' => $markId],
            ['%d']
        );

        return $deleted === false ? 0 : $markId;
    }

    /**
     * Does $userId have a Helpful mark on $actId. Drives the mark
     * affordance's filled/empty state.
     */
    public function viewerHasMarked(int $actId, int $userId): bool
    {
        if ($actId <= 0 || $userId <= 0) {
            return false;
        }

        global $wpdb;

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$this->table} WHERE act_id = %d AND user_id = %d LIMIT 1",
            $actId,
            $userId
        ));

        return $exists !== null;
    }

    /**
     * Total distinct markers on one activity (the public count shown
     * beside the affordance). All-time.
     */
    public function countForActivity(int $actId): int
    {
        if ($actId <= 0) {
            return 0;
        }

        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE act_id = %d",
            $actId
        ));
    }
}
