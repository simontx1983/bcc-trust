<?php

namespace BCC\Trust\Onchain\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The last known head of the validator relationship log — (seq, row_hash) —
 * kept OUTSIDE the log table, in an option.
 *
 * A chain walk cannot see rows deleted from the END of the log, nor a table
 * emptied outright (both still walk cleanly to a shorter head). The anchor
 * can: the verifier fails when the table's head is behind it, or when the
 * row at the anchor's seq no longer carries the anchored hash.
 *
 * The anchor only moves forward, and only an APPENDER moves it: after its
 * append COMMITS, while it still holds the bcc_vrl_append lock. Appends are
 * serialized by that lock, so the read-compare-write below cannot race
 * another append, and a seq that rolled back is never anchored.
 *
 * The option is a tripwire for rows deleted from the end, not a defence
 * against a database writer: a deleted option reads as null, which the
 * health check reports as a warning when the log is not empty.
 *
 * @package BCC\Trust\Onchain\Support
 */
final class RelationshipLogAnchor
{
    public const OPTION = 'bcc_vrl_anchor';

    /** @return array{seq: int, row_hash: string}|null null when no anchor has been recorded */
    public static function get(): ?array
    {
        $stored = get_option(self::OPTION, null);
        if (!is_array($stored) || !isset($stored['seq'], $stored['row_hash'])) {
            return null;
        }
        $seq  = (int) $stored['seq'];
        $hash = (string) $stored['row_hash'];
        if ($seq <= 0 || preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
            return null;
        }

        return ['seq' => $seq, 'row_hash' => $hash];
    }

    /**
     * Move the anchor to ($seq, $rowHash) when that is ahead of the stored
     * one. Never moves it back. Call ONLY after a successful COMMIT, while
     * holding the bcc_vrl_append lock (RelationshipLogLockOrderGuardTest).
     */
    public static function advance(int $seq, string $rowHash): void
    {
        if ($seq <= 0 || preg_match('/^[0-9a-f]{64}$/', $rowHash) !== 1) {
            return;
        }
        $current = self::get();
        if ($current !== null && $current['seq'] >= $seq) {
            return;
        }
        update_option(self::OPTION, ['seq' => $seq, 'row_hash' => $rowHash], false);
    }
}
