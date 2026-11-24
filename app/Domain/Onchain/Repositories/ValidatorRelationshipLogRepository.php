<?php

namespace BCC\Trust\Onchain\Repositories;

use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Onchain\Support\RelationshipLogHasher;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The ONLY writer (and the reader) of the hash-chained, append-only
 * validator relationship log. See schema-validator-relationship-log.php.
 *
 * ## Appending
 *
 * The caller (ValidatorRelationshipService) takes the named lock
 * {@see LOCK_NAME} BEFORE it opens the transaction that writes the
 * relationship change, calls {@see append()} inside that transaction, and
 * releases the lock after COMMIT. append() refuses to run without the lock
 * held by this connection, reads the head fail-closed, and assigns
 * seq = head.seq + 1 and prev_hash = head.row_hash. So two appends can never
 * fork the chain, and the log row commits or rolls back with the change it
 * records.
 *
 * Rows are never updated or deleted here or anywhere else; a guard test
 * fails on any other write to the table.
 *
 * @phpstan-type LogRow object{id: numeric-string, seq: numeric-string, created_at: string, action: string, page_id: numeric-string, subject_user_id: numeric-string|null, actor_kind: string, actor_user_id: numeric-string|null, payload: string, evidence_note: string|null, evidence_ref: string|null, evidence_note_sha256: string|null, evidence_ref_sha256: string|null, key_id: numeric-string, prev_hash: string, row_hash: string}
 *
 * @package BCC\Trust\Onchain\Repositories
 */
final class ValidatorRelationshipLogRepository
{
    use GuardsReadFailures;

    /** The named lock every append runs under. */
    public const LOCK_NAME = 'bcc_vrl_append';

    public const ACTOR_KINDS = ['staff', 'member', 'system'];

    public const MAX_ACTION_LENGTH        = 40;
    public const MAX_EVIDENCE_NOTE_LENGTH = 2000;
    public const MAX_EVIDENCE_REF_LENGTH  = 2048;
    public const MAX_PAYLOAD_BYTES        = 16000;

    /** Most rows one listing or verification batch returns. */
    public const MAX_PAGE = 500;

    private const COLUMNS = 'id, seq, created_at, action, page_id, subject_user_id, actor_kind, actor_user_id,
                             payload, evidence_note, evidence_ref, evidence_note_sha256, evidence_ref_sha256,
                             key_id, prev_hash, row_hash';

    public static function table(): string
    {
        return TableRegistry::validatorRelationshipLog();
    }

    /**
     * Append one event to the chain. Must run inside the caller's
     * transaction, with {@see LOCK_NAME} held by this connection.
     *
     * @param array{action: string, page_id: int, subject_user_id: int|null, actor_kind: string, actor_user_id: int|null, payload: array<mixed>, evidence_note?: string|null, evidence_ref?: string|null} $event
     * @return array{id: int, seq: int, row_hash: string}
     * @throws \InvalidArgumentException for an event that does not fit the table
     * @throws RepositoryReadFailure when the lock check or the head read fails
     * @throws \RuntimeException when the lock is not held or the insert fails
     */
    public static function append(array $event): array
    {
        $row = self::validated($event);

        if (!self::lockHeldHere()) {
            throw new \RuntimeException('Relationship log append without the ' . self::LOCK_NAME . ' lock held.');
        }

        $head       = self::head();
        $row['seq'] = $head['seq'] + 1;
        $row['created_at'] = gmdate('Y-m-d H:i:s');
        $row['key_id']     = RelationshipLogHasher::KEY_ID;
        $rowHash    = RelationshipLogHasher::rowHash($row, $head['row_hash']);

        global $wpdb;
        $ok = $wpdb->insert(self::table(), [
            'seq'             => $row['seq'],
            'created_at'      => $row['created_at'],
            'action'          => $row['action'],
            'page_id'         => $row['page_id'],
            'subject_user_id' => $row['subject_user_id'],
            'actor_kind'      => $row['actor_kind'],
            'actor_user_id'   => $row['actor_user_id'],
            'payload'         => RelationshipLogHasher::canonical($row['payload']),
            'evidence_note'   => $row['evidence_note'],
            'evidence_ref'    => $row['evidence_ref'],
            'evidence_note_sha256' => $row['evidence_note_sha256'],
            'evidence_ref_sha256'  => $row['evidence_ref_sha256'],
            'key_id'          => $row['key_id'],
            'prev_hash'       => $head['row_hash'],
            'row_hash'        => $rowHash,
        ], ['%d', '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']);

        if ($ok !== 1) {
            throw new \RuntimeException('Relationship log append failed: ' . (string) $wpdb->last_error);
        }

        return ['id' => (int) $wpdb->insert_id, 'seq' => $row['seq'], 'row_hash' => $rowHash];
    }

    /**
     * The chain head: the last row's seq and row_hash, or seq 0 and the
     * genesis hash for an empty log. Read fail-closed.
     *
     * @return array{seq: int, row_hash: string}
     * @throws RepositoryReadFailure
     */
    public static function head(): array
    {
        global $wpdb;
        $table = self::table();

        /** @var list<object{seq: numeric-string, row_hash: string}> $rows */
        $rows = self::runRead("SELECT seq, row_hash FROM {$table} ORDER BY seq DESC LIMIT 1", __FUNCTION__, true);
        if ($rows === []) {
            return ['seq' => 0, 'row_hash' => RelationshipLogHasher::GENESIS];
        }

        return ['seq' => (int) $rows[0]->seq, 'row_hash' => (string) $rows[0]->row_hash];
    }

    /**
     * Rows with seq > $afterSeq, oldest first: the verifier's walk. Read
     * fail-closed.
     *
     * @return list<LogRow>
     * @throws RepositoryReadFailure
     */
    public static function rowsAfter(int $afterSeq, int $limit): array
    {
        global $wpdb;
        $table = self::table();

        /** @var list<LogRow> */
        return self::runRead($wpdb->prepare(
            'SELECT ' . self::COLUMNS . " FROM {$table} WHERE seq > %d ORDER BY seq ASC LIMIT %d",
            max(0, $afterSeq),
            self::bounded($limit)
        ), __FUNCTION__, true);
    }

    /**
     * History, newest first, optionally for one page and/or one user (as
     * subject or actor), before a seq cursor. Read fail-closed.
     *
     * @return list<LogRow>
     * @throws RepositoryReadFailure
     */
    public static function history(?int $pageId, ?int $userId, ?int $beforeSeq, int $limit): array
    {
        global $wpdb;
        $table = self::table();

        $where = ['1 = 1'];
        $args  = [];
        if ($pageId !== null) {
            $where[] = 'page_id = %d';
            $args[]  = $pageId;
        }
        if ($userId !== null) {
            $where[] = '(subject_user_id = %d OR actor_user_id = %d)';
            $args[]  = $userId;
            $args[]  = $userId;
        }
        if ($beforeSeq !== null) {
            $where[] = 'seq < %d';
            $args[]  = $beforeSeq;
        }
        $args[] = self::bounded($limit);

        /** @var list<LogRow> */
        return self::runRead($wpdb->prepare(
            'SELECT ' . self::COLUMNS . " FROM {$table} WHERE " . implode(' AND ', $where) . ' ORDER BY seq DESC LIMIT %d',
            ...$args
        ), __FUNCTION__, true);
    }

    /**
     * The hash input of a stored row, so a row read back can be re-hashed.
     *
     * @param LogRow $row
     * @return array{seq: int, created_at: string, action: string, page_id: int, subject_user_id: int|null, actor_kind: string, actor_user_id: int|null, payload: array<mixed>, evidence_note_sha256: string|null, evidence_ref_sha256: string|null, key_id: int}
     * @throws \UnexpectedValueException when the stored payload is not a JSON object or list
     */
    public static function hashInput(object $row): array
    {
        $payload = json_decode((string) $row->payload, true);
        if (!is_array($payload)) {
            throw new \UnexpectedValueException('Relationship log row ' . (string) $row->seq . ' has an unreadable payload.');
        }

        return [
            'seq'             => (int) $row->seq,
            'created_at'      => (string) $row->created_at,
            'action'          => (string) $row->action,
            'page_id'         => (int) $row->page_id,
            'subject_user_id' => $row->subject_user_id === null ? null : (int) $row->subject_user_id,
            'actor_kind'      => (string) $row->actor_kind,
            'actor_user_id'   => $row->actor_user_id === null ? null : (int) $row->actor_user_id,
            'payload'         => $payload,
            'evidence_note_sha256' => $row->evidence_note_sha256 === null ? null : (string) $row->evidence_note_sha256,
            'evidence_ref_sha256'  => $row->evidence_ref_sha256 === null ? null : (string) $row->evidence_ref_sha256,
            'key_id'          => (int) $row->key_id,
        ];
    }

    /**
     * Is LOCK_NAME held by THIS connection? Read fail-closed.
     *
     * @throws RepositoryReadFailure
     */
    private static function lockHeldHere(): bool
    {
        global $wpdb;

        /** @var list<object{held: numeric-string|null}> $rows */
        $rows = self::runRead($wpdb->prepare(
            'SELECT (IS_USED_LOCK(%s) = CONNECTION_ID()) AS held',
            self::LOCK_NAME
        ), __FUNCTION__, true);

        return $rows !== [] && (string) $rows[0]->held === '1';
    }

    /**
     * Normalise and size-check an event BEFORE it is hashed, so the stored
     * row can never differ from what was hashed (a truncated column would
     * break the chain).
     *
     * @param array<string, mixed> $event
     * @return array{action: string, page_id: int, subject_user_id: int|null, actor_kind: string, actor_user_id: int|null, payload: array<mixed>, evidence_note: string|null, evidence_ref: string|null, evidence_note_sha256: string|null, evidence_ref_sha256: string|null, seq: int, created_at: string, key_id: int}
     */
    private static function validated(array $event): array
    {
        $action = (string) ($event['action'] ?? '');
        if ($action === '' || strlen($action) > self::MAX_ACTION_LENGTH || preg_match('/^[a-z0-9_]+$/', $action) !== 1) {
            throw new \InvalidArgumentException('Relationship log action must be 1-' . self::MAX_ACTION_LENGTH . ' characters of [a-z0-9_].');
        }
        $actorKind = (string) ($event['actor_kind'] ?? '');
        if (!in_array($actorKind, self::ACTOR_KINDS, true)) {
            throw new \InvalidArgumentException('Unknown relationship log actor kind: ' . $actorKind);
        }
        $payload = $event['payload'] ?? [];
        if (!is_array($payload)) {
            throw new \InvalidArgumentException('Relationship log payload must be an array.');
        }
        if (strlen(RelationshipLogHasher::canonical($payload)) > self::MAX_PAYLOAD_BYTES) {
            throw new \InvalidArgumentException('Relationship log payload is too large.');
        }
        $note = isset($event['evidence_note']) ? (string) $event['evidence_note'] : null;
        if ($note !== null && mb_strlen($note) > self::MAX_EVIDENCE_NOTE_LENGTH) {
            throw new \InvalidArgumentException('Evidence note is too long.');
        }
        $ref = isset($event['evidence_ref']) ? (string) $event['evidence_ref'] : null;
        if ($ref !== null && mb_strlen($ref) > self::MAX_EVIDENCE_REF_LENGTH) {
            throw new \InvalidArgumentException('Evidence reference is too long.');
        }

        return [
            'action'          => $action,
            'page_id'         => max(0, (int) ($event['page_id'] ?? 0)),
            'subject_user_id' => self::optionalId($event['subject_user_id'] ?? null),
            'actor_kind'      => $actorKind,
            'actor_user_id'   => self::optionalId($event['actor_user_id'] ?? null),
            'payload'         => $payload,
            'evidence_note'   => $note,
            'evidence_ref'    => $ref,
            'evidence_note_sha256' => RelationshipLogHasher::digest($note),
            'evidence_ref_sha256'  => RelationshipLogHasher::digest($ref),
            'seq'             => 0,
            'created_at'      => '',
            'key_id'          => RelationshipLogHasher::KEY_ID,
        ];
    }

    private static function optionalId(mixed $id): ?int
    {
        $id = is_numeric($id) ? (int) $id : 0;

        return $id > 0 ? $id : null;
    }

    /** @return positive-int */
    private static function bounded(int $limit): int
    {
        return max(1, min(self::MAX_PAGE, $limit));
    }
}
