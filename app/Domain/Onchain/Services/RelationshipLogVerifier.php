<?php

namespace BCC\Trust\Onchain\Services;

use BCC\Trust\Onchain\Repositories\ValidatorRelationshipLogRepository;
use BCC\Trust\Onchain\Support\RelationshipLogHasher;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Re-walks the validator relationship log's hash chain in bounded batches
 * and reports the FIRST row that does not verify. It never repairs.
 *
 * A row verifies when:
 *  - its seq is exactly the previous row's seq + 1 (seq 1 first);
 *  - its prev_hash is the previous row's row_hash (the genesis value for
 *    seq 1);
 *  - recomputing row_hash from the stored fields gives the stored value;
 *  - any evidence text still stored matches the digest the hash covers.
 *
 * The cron health check and the admin health view call this. Raising the
 * metric and the all-admin alert on a failure is the caller's job.
 *
 * @package BCC\Trust\Onchain\Services
 */
final class RelationshipLogVerifier
{
    public const DEFAULT_BATCH = 500;

    /** Hard ceiling on rows one call walks. */
    public const MAX_ROWS = 100000;

    /**
     * @return array{ok: bool, rows_checked: int, head_seq: int, first_bad_seq: int|null, reason: string|null}
     *   `ok` is true only when every row up to the head verified. A read
     *   failure is NOT ok: it reports reason 'read_failed' and no bad seq.
     */
    public static function verify(int $batch = self::DEFAULT_BATCH, int $maxRows = self::MAX_ROWS): array
    {
        $batch    = max(1, min(ValidatorRelationshipLogRepository::MAX_PAGE, $batch));
        $checked  = 0;
        $prevSeq  = 0;
        $prevHash = RelationshipLogHasher::GENESIS;

        try {
            while ($checked < $maxRows) {
                $rows = ValidatorRelationshipLogRepository::rowsAfter($prevSeq, $batch);
                if ($rows === []) {
                    return self::result(true, $checked, $prevSeq, null, null);
                }
                foreach ($rows as $row) {
                    $seq = (int) $row->seq;
                    if ($seq !== $prevSeq + 1) {
                        return self::result(false, $checked, $prevSeq, $seq, 'seq_gap');
                    }
                    if (!hash_equals($prevHash, (string) $row->prev_hash)) {
                        return self::result(false, $checked, $prevSeq, $seq, 'prev_hash_mismatch');
                    }
                    try {
                        $expected = RelationshipLogHasher::rowHash(ValidatorRelationshipLogRepository::hashInput($row), $prevHash);
                    } catch (\UnexpectedValueException | \InvalidArgumentException) {
                        return self::result(false, $checked, $prevSeq, $seq, 'unreadable_row');
                    }
                    if (!hash_equals($expected, (string) $row->row_hash)) {
                        return self::result(false, $checked, $prevSeq, $seq, 'row_hash_mismatch');
                    }
                    if (!self::evidenceMatches($row->evidence_note, $row->evidence_note_sha256)
                        || !self::evidenceMatches($row->evidence_ref, $row->evidence_ref_sha256)
                    ) {
                        return self::result(false, $checked, $prevSeq, $seq, 'evidence_mismatch');
                    }
                    $prevSeq  = $seq;
                    $prevHash = (string) $row->row_hash;
                    ++$checked;
                }
            }
        } catch (\BCC\Trust\Onchain\Repositories\RepositoryReadFailure) {
            return self::result(false, $checked, $prevSeq, null, 'read_failed');
        }

        return self::result(false, $checked, $prevSeq, null, 'row_limit_reached');
    }

    /**
     * Evidence text still present must match the digest the chain covers. A
     * cleared text (a logged redaction) keeps its digest and still verifies;
     * text without a digest does not.
     */
    private static function evidenceMatches(?string $text, ?string $digest): bool
    {
        if ($text === null) {
            return true;
        }

        return $digest !== null && hash_equals($digest, hash('sha256', $text));
    }

    /** @return array{ok: bool, rows_checked: int, head_seq: int, first_bad_seq: int|null, reason: string|null} */
    private static function result(bool $ok, int $checked, int $headSeq, ?int $badSeq, ?string $reason): array
    {
        return ['ok' => $ok, 'rows_checked' => $checked, 'head_seq' => $headSeq, 'first_bad_seq' => $badSeq, 'reason' => $reason];
    }
}
