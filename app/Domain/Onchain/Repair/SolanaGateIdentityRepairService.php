<?php
/**
 * Executes the eight-row Solana gate-identity repair, one transaction per
 * mapping.
 *
 * ── NO PROVIDER, EVER ───────────────────────────────────────────────────
 * This file imports no fetcher and makes no network call. Every value it
 * writes comes from {@see SolanaGateIdentityManifest}, which is a frozen
 * constant table. A test asserts the import graph, and the dry run is
 * byte-identical with the RPC endpoints pointed at a dead port.
 *
 * ── ONE TRANSACTION PER MAPPING, NOT ONE FOR THE BATCH ──────────────────
 * Eight independent repairs. A precondition failure on row 5 must not
 * discard rows 1–4, and must not leave row 5 half-applied. Each mapping
 * gets its own `TransactionManager::run()`; the loop records an outcome
 * and continues.
 *
 * ── "ALREADY APPLIED" IS A NARROW CLAIM ─────────────────────────────────
 * A row counts as already applied only when BOTH halves match the manifest
 * exactly: `canonical_identifier` equals the new address AND the gate meta
 * equals it too. One-of-two is a PARTIALLY APPLIED state, which is
 * refused and reported — never quietly "finished". A half-repaired row is
 * evidence something went wrong, and swallowing it would destroy the only
 * signal that says so.
 *
 * ── THE AUDIT ROW IS PART OF THE TRANSACTION ────────────────────────────
 * `AuditLogger::logChecked()` writes inside the same transaction and
 * returns the inserted id, or null if the metadata could not be recorded
 * in full. Null means the repair rolls back: an audit row that says a
 * repair happened but cannot say what changed is worse than no row,
 * because in forensics it is indistinguishable from a complete one. The
 * row is then re-read and its metadata compared field-by-field BEFORE the
 * commit, so "the audit is correct" is verified, not assumed.
 *
 * @package BCC\Trust\Onchain\Repair
 * @since PR 5b — Solana holder-gate identity repair
 */

namespace BCC\Trust\Onchain\Repair;

use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Core\Security\TransactionManager;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\GateIdentityRepairRepository;
use BCC\Trust\Onchain\Repositories\GatedGroupRepository;
use BCC\Trust\Onchain\Support\NftCollectionIdentifier;

if (!defined('ABSPATH')) {
    exit;
}

final class SolanaGateIdentityRepairService
{
    /**
     * The one action name a SUCCESSFUL repair records.
     *
     * 32 characters; `wp_bcc_trust_activity.action` is VARCHAR(50). The
     * outcome is NOT encoded in the action name — failures roll back and
     * therefore write no row at all, so a row with this action always
     * means one complete, verified repair.
     */
    public const AUDIT_ACTION = 'nft_collection_identity_repaired';

    /** Audit `target_type` — the thing repaired is a collection. */
    public const AUDIT_TARGET_TYPE = 'collection';

    // ── Result codes. The runner prints only these. ─────────────────────
    public const RESULT_WOULD_REPAIR        = 'would_repair';
    public const RESULT_REPAIRED            = 'repaired';
    public const RESULT_ALREADY_APPLIED     = 'already_applied';
    public const RESULT_REFUSED_PRECONDITION = 'refused_precondition';
    public const RESULT_FAILED_ROLLED_BACK  = 'failed_rolled_back';

    /**
     * Plan or apply the whole manifest.
     *
     * @param bool   $apply      false = dry run: zero writes, zero audit
     *                           rows, zero cache invalidations.
     * @param int    $operatorId verified administrator user id.
     * @param string $runId      unique per invocation.
     *
     * @return list<array{collection_id: int, post_id: int, alias: string, result: string, detail: string}>
     */
    public function run(bool $apply, int $operatorId, string $runId): array
    {
        $results = [];

        // Resolved ONCE, by slug. `solana` is 20 in production and has been
        // 13 locally; neither number appears in this file.
        $chainId = ChainRepository::resolveIdAnyState(SolanaGateIdentityManifest::CHAIN_SLUG);

        foreach (SolanaGateIdentityManifest::entries() as $entry) {
            if ($chainId === null || $chainId <= 0) {
                $results[] = self::outcome($entry, self::RESULT_REFUSED_PRECONDITION, 'chain_slug_unresolved');
                continue;
            }

            $results[] = $apply
                ? $this->applyOne($entry, $chainId, $operatorId, $runId)
                : $this->planOne($entry, $chainId);
        }

        return $results;
    }

    // ──────────────────────────────────────────────────────────────────
    // Dry run
    // ──────────────────────────────────────────────────────────────────

    /**
     * Evaluate every precondition WITHOUT a transaction and WITHOUT writes.
     *
     * Reads are unlocked here on purpose: a dry run must not hold row locks
     * on a live site, and the apply path re-checks everything under lock
     * anyway. A dry run is a report, not a reservation.
     *
     * @param array<string, mixed> $entry
     * @return array{collection_id: int, post_id: int, alias: string, result: string, detail: string}
     */
    private function planOne(array $entry, int $chainId): array
    {
        $state = $this->readStateUnlocked($entry, $chainId);

        if ($state['status'] === 'already_applied') {
            return self::outcome($entry, self::RESULT_ALREADY_APPLIED, '');
        }

        if ($state['status'] !== 'repairable') {
            return self::outcome($entry, self::RESULT_REFUSED_PRECONDITION, $state['detail']);
        }

        return self::outcome($entry, self::RESULT_WOULD_REPAIR, '');
    }

    // ──────────────────────────────────────────────────────────────────
    // Apply
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $entry
     * @return array{collection_id: int, post_id: int, alias: string, result: string, detail: string}
     */
    private function applyOne(array $entry, int $chainId, int $operatorId, string $runId): array
    {
        $collectionId = (int) $entry['collection_id'];

        try {
            // The callback returns an array, never a bare `false`:
            // TransactionManager treats a `false` return as the legacy
            // "roll back and retry" sentinel, which would turn a deliberate
            // refusal into a retry loop. Refusals return a result array;
            // real failures throw.
            /** @var array{result: string, detail: string} $outcome */
            $outcome = TransactionManager::run(
                function () use ($entry, $chainId, $operatorId, $runId): array {
                    return $this->applyOneLocked($entry, $chainId, $operatorId, $runId);
                }
            );
        } catch (\Throwable $e) {
            // TransactionManager has already rolled back. Record the class,
            // not the message: exception prose can carry SQL fragments,
            // absolute paths and quoted values, and this string is printed
            // to an operator's terminal.
            \BCC\Core\Log\Logger::error(
                '[bcc-trust] gate-identity repair rolled back',
                [
                    'run_id'        => $runId,
                    'collection_id' => $collectionId,
                    'exception'     => $e->getMessage(),
                ]
            );

            return self::outcome(
                $entry,
                self::RESULT_FAILED_ROLLED_BACK,
                (new \ReflectionClass($e))->getShortName()
            );
        }

        // Caches are invalidated ONLY here — after the transaction has
        // committed. Doing it inside would publish a state that a rollback
        // could then un-happen, leaving readers caching a repair that no
        // longer exists in the database.
        if ($outcome['result'] === self::RESULT_REPAIRED) {
            $this->invalidateAfterCommit((int) $entry['post_id']);
        }

        return self::outcome($entry, $outcome['result'], $outcome['detail']);
    }

    /**
     * The transaction body. Everything here is inside ONE transaction and
     * every read is locked.
     *
     * @param array<string, mixed> $entry
     * @return array{result: string, detail: string}
     */
    private function applyOneLocked(array $entry, int $chainId, int $operatorId, string $runId): array
    {
        $collectionId = (int) $entry['collection_id'];
        $postId       = (int) $entry['post_id'];
        $newCanonical = (string) $entry['new_canonical_identifier'];

        // ── Lock and re-read ────────────────────────────────────────────
        $row = GateIdentityRepairRepository::lockCollection($collectionId);
        if ($row === null) {
            return ['result' => self::RESULT_REFUSED_PRECONDITION, 'detail' => 'collection_row_missing'];
        }

        $gateMetaRows = [];
        foreach (self::requiredMetaKeys() as $key) {
            $gateMetaRows[$key] = GateIdentityRepairRepository::lockPostMeta($postId, $key);
        }

        $check = $this->assertPreconditions($entry, $chainId, $row, $gateMetaRows);
        if ($check['status'] === 'already_applied') {
            // No write, no audit row, no cache invalidation.
            return ['result' => self::RESULT_ALREADY_APPLIED, 'detail' => ''];
        }
        if ($check['status'] !== 'repairable') {
            return ['result' => self::RESULT_REFUSED_PRECONDITION, 'detail' => $check['detail']];
        }

        $oldCanonical = $row->canonical_identifier;
        $gateMetaRow  = $gateMetaRows[GatedGroupRepository::META_CONTRACT][0];
        $oldGateValue = (string) $gateMetaRow->meta_value;

        // ── Write 1: the collection's canonical identity ────────────────
        if (!GateIdentityRepairRepository::setCanonicalIdentifier($collectionId, $newCanonical)) {
            throw new \RuntimeException('canonical_identifier update did not affect exactly one row');
        }

        // ── Write 2: the gate meta, for compatibility ───────────────────
        if (!GateIdentityRepairRepository::updatePostMetaById((int) $gateMetaRow->meta_id, $newCanonical)) {
            throw new \RuntimeException('gate contract-address postmeta update failed');
        }

        // ── The checked audit row, inside the transaction ───────────────
        $meta = [
            'run_id'                 => $runId,
            'manifest_version'       => (int) $entry['manifest_version'],
            'chain_slug'             => (string) $entry['chain_slug'],
            'chain_id'               => $chainId,
            'collection_id'          => $collectionId,
            'post_id'                => $postId,
            'field'                  => 'canonical_identifier',
            'before'                 => $oldCanonical,
            'after'                  => $newCanonical,
            'gate_meta_before'       => $oldGateValue,
            'gate_meta_after'        => $newCanonical,
            'contract_address'       => (string) $row->contract_address,
            'operator_user_id'       => $operatorId,
            'evidence'               => (string) $entry['evidence'],
            'result'                 => self::RESULT_REPAIRED,
        ];

        $auditId = AuditLogger::logChecked(
            self::AUDIT_ACTION,
            $collectionId,
            $meta,
            self::AUDIT_TARGET_TYPE,
            $operatorId
        );

        if ($auditId === null || $auditId <= 0) {
            // Metadata could not be encoded, or the insert failed. Either
            // way there is no honest record of this repair, so it does not
            // happen.
            throw new \RuntimeException('checked audit write failed; rolling back the repair');
        }

        // ── Verify the audit row actually says what we meant ────────────
        $this->verifyAuditRow($auditId, $meta);

        // ── Postconditions, re-read under the same lock ─────────────────
        $this->verifyPostconditions($collectionId, $postId, $newCanonical, (string) $row->contract_address);

        return ['result' => self::RESULT_REPAIRED, 'detail' => ''];
    }

    // ──────────────────────────────────────────────────────────────────
    // Preconditions
    // ──────────────────────────────────────────────────────────────────

    /** @return list<string> */
    private static function requiredMetaKeys(): array
    {
        return [
            GatedGroupRepository::META_KIND,
            GatedGroupRepository::META_CHAIN_ID,
            GatedGroupRepository::META_CONTRACT,
            GatedGroupRepository::META_MIN_BAL,
            GatedGroupRepository::META_COLLECTION,
        ];
    }

    /**
     * Every manifest precondition, checked against rows the caller has
     * already locked (apply) or just read (dry run).
     *
     * Returns one of:
     *   repairable      — safe to write
     *   already_applied — both halves already match, exactly
     *   refused         — anything else, with a machine-readable detail
     *
     * @param array<string, mixed> $entry
     * @param object{
     *     id: string,
     *     chain_id: string,
     *     contract_address: string,
     *     canonical_identifier: string|null,
     *     is_verified: string,
     *     source: string
     * } $row
     * @param array<string, list<object{meta_id: string, meta_value: string}>> $gateMetaRows
     * @return array{status: string, detail: string}
     */
    private function assertPreconditions(array $entry, int $chainId, object $row, array $gateMetaRows): array
    {
        $newCanonical = (string) $entry['new_canonical_identifier'];
        $alias        = (string) $entry['alias'];
        $postId       = (int) $entry['post_id'];

        // Exactly one value for every required key. A duplicate makes the
        // gate's real configuration ambiguous — there is no "the" value to
        // repair, so it is refused rather than guessed.
        foreach (self::requiredMetaKeys() as $key) {
            $count = count($gateMetaRows[$key] ?? []);
            if ($count === 0) {
                return ['status' => 'refused', 'detail' => 'missing_meta:' . $key];
            }
            if ($count > 1) {
                return ['status' => 'refused', 'detail' => 'duplicate_meta:' . $key];
            }
        }

        $kind        = (string) $gateMetaRows[GatedGroupRepository::META_KIND][0]->meta_value;
        $gateChainId = (int) $gateMetaRows[GatedGroupRepository::META_CHAIN_ID][0]->meta_value;
        $gateContract = (string) $gateMetaRows[GatedGroupRepository::META_CONTRACT][0]->meta_value;
        $gateMinBal  = (int) $gateMetaRows[GatedGroupRepository::META_MIN_BAL][0]->meta_value;
        $gateCollId  = (int) $gateMetaRows[GatedGroupRepository::META_COLLECTION][0]->meta_value;

        // The back-reference must point at the collection we are repairing.
        // Without this, a gate could be repaired using another gate's row.
        if ($gateCollId !== (int) $entry['collection_id']) {
            return ['status' => 'refused', 'detail' => 'gate_collection_id_mismatch'];
        }

        if ($kind !== GatedGroupRepository::KIND_HOLDERS) {
            return ['status' => 'refused', 'detail' => 'not_a_holder_gate'];
        }

        if ($gateChainId !== $chainId) {
            return ['status' => 'refused', 'detail' => 'gate_chain_mismatch'];
        }

        if ((int) $row->chain_id !== $chainId) {
            return ['status' => 'refused', 'detail' => 'collection_chain_mismatch'];
        }

        if ($gateMinBal !== (int) $entry['expected_gate_min_balance']) {
            return ['status' => 'refused', 'detail' => 'min_balance_mismatch'];
        }

        // Post identity — type and status, exactly as reviewed.
        $post = GateIdentityRepairRepository::readPost($postId);
        if ($post === null) {
            return ['status' => 'refused', 'detail' => 'post_missing'];
        }
        if ((string) $post->post_type !== SolanaGateIdentityManifest::EXPECTED_POST_TYPE) {
            return ['status' => 'refused', 'detail' => 'post_type_mismatch'];
        }
        if ((string) $post->post_status !== SolanaGateIdentityManifest::EXPECTED_POST_STATUS) {
            return ['status' => 'refused', 'detail' => 'post_status_mismatch'];
        }

        // Verification state and source must be what was reviewed — they are
        // preserved, so a difference means this is not the row we approved.
        if ((int) $row->is_verified !== (int) $entry['expected_is_verified']) {
            return ['status' => 'refused', 'detail' => 'is_verified_mismatch'];
        }
        if ((string) $row->source !== (string) $entry['expected_source']) {
            return ['status' => 'refused', 'detail' => 'source_mismatch'];
        }

        // The target address must still be a real 32-byte Solana key. The
        // manifest is reviewed, but validating here means a typo introduced
        // in a later edit cannot be written to the identity column.
        $identity = NftCollectionIdentifier::canonicalize(
            NftCollectionIdentifier::FAMILY_SOLANA,
            $newCanonical
        );
        if (!$identity->isAccepted() || $identity->canonical() !== $newCanonical) {
            return ['status' => 'refused', 'detail' => 'manifest_address_invalid'];
        }

        // ── Applied / partially-applied / repairable ────────────────────
        $canonicalDone = ((string) ($row->canonical_identifier ?? '')) === $newCanonical;
        $gateDone      = $gateContract === $newCanonical;

        if ($canonicalDone && $gateDone) {
            return ['status' => 'already_applied', 'detail' => ''];
        }

        // Exactly one half done. NOT "already applied" — this is a state no
        // successful run can produce, so reporting it as finished would hide
        // a real failure. Refuse and make a human look.
        if ($canonicalDone !== $gateDone) {
            return ['status' => 'refused', 'detail' => 'partially_applied'];
        }

        // Neither half done: both must still be exactly the reviewed alias.
        if ($row->canonical_identifier !== null) {
            return ['status' => 'refused', 'detail' => 'canonical_identifier_not_null'];
        }
        if ((string) $row->contract_address !== (string) $entry['expected_contract_address']) {
            return ['status' => 'refused', 'detail' => 'contract_address_mismatch'];
        }
        if ($gateContract !== $alias) {
            return ['status' => 'refused', 'detail' => 'gate_contract_address_mismatch'];
        }

        return ['status' => 'repairable', 'detail' => ''];
    }

    /**
     * Unlocked read of the same state, for the dry run.
     *
     * @param array<string, mixed> $entry
     * @return array{status: string, detail: string}
     */
    private function readStateUnlocked(array $entry, int $chainId): array
    {
        $collectionId = (int) $entry['collection_id'];
        $postId       = (int) $entry['post_id'];

        $row = GateIdentityRepairRepository::readCollection($collectionId);
        if ($row === null) {
            return ['status' => 'refused', 'detail' => 'collection_row_missing'];
        }

        $gateMetaRows = [];
        foreach (self::requiredMetaKeys() as $key) {
            $gateMetaRows[$key] = GateIdentityRepairRepository::readPostMeta($postId, $key);
        }

        return $this->assertPreconditions($entry, $chainId, $row, $gateMetaRows);
    }

    // ──────────────────────────────────────────────────────────────────
    // Verification
    // ──────────────────────────────────────────────────────────────────

    /**
     * Prove the audit row exists and carries exactly the metadata intended.
     *
     * `AuditMeta` legitimately transforms some values (it masks, truncates
     * and replaces free text), so this compares only the fields this repair
     * owns — all of which are short, structured and must survive verbatim.
     * If one of them ever stops surviving, that is a redaction-policy change
     * this repair must not silently absorb.
     *
     * @param array<string, mixed> $expected
     * @throws \RuntimeException when the row is missing or its metadata differs.
     */
    private function verifyAuditRow(int $auditId, array $expected): void
    {
        $stored = GateIdentityRepairRepository::readAuditRow($auditId);

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

        foreach (['run_id', 'manifest_version', 'chain_slug', 'chain_id', 'collection_id',
                  'post_id', 'field', 'before', 'after', 'gate_meta_before',
                  'gate_meta_after', 'contract_address', 'operator_user_id'] as $key) {
            if (!array_key_exists($key, $decoded)) {
                throw new \RuntimeException('audit metadata missing key: ' . $key);
            }
            if ($decoded[$key] !== $expected[$key]) {
                throw new \RuntimeException('audit metadata value differs for key: ' . $key);
            }
        }
    }

    /**
     * Re-read both written values and prove the preserved ones are intact.
     *
     * @throws \RuntimeException when any postcondition fails.
     */
    private function verifyPostconditions(
        int $collectionId,
        int $postId,
        string $expectedCanonical,
        string $expectedContractAddress
    ): void {
        $row = GateIdentityRepairRepository::lockCollection($collectionId);

        if ($row === null) {
            throw new \RuntimeException('postcondition: collection row vanished');
        }
        if ((string) ($row->canonical_identifier ?? '') !== $expectedCanonical) {
            throw new \RuntimeException('postcondition: canonical_identifier not written');
        }
        // The legacy alias must be untouched — this is the guarantee that
        // the repair adds an identity rather than rewriting history.
        if ((string) $row->contract_address !== $expectedContractAddress) {
            throw new \RuntimeException('postcondition: contract_address was modified');
        }

        $meta = GateIdentityRepairRepository::lockPostMeta($postId, GatedGroupRepository::META_CONTRACT);
        if (count($meta) !== 1) {
            throw new \RuntimeException('postcondition: gate contract meta is not exactly one row');
        }
        if ((string) $meta[0]->meta_value !== $expectedCanonical) {
            throw new \RuntimeException('postcondition: gate contract meta not written');
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // Post-commit
    // ──────────────────────────────────────────────────────────────────

    /**
     * Invalidate the caches this repair invalidates — and only after commit.
     *
     * The postmeta rows were written with raw SQL under lock, deliberately
     * (that is what allows `FOR UPDATE` and addressing a row by `meta_id`).
     * The consequence is that WordPress's meta cache was NOT maintained for
     * us the way `update_post_meta()` would have, so it must be dropped
     * explicitly — otherwise this request, and any process sharing a
     * persistent object cache, keeps serving the pre-repair alias.
     *
     * `GatedGroupRepository` has no cache of its own; its correctness
     * dependency runs through the database.
     */
    private function invalidateAfterCommit(int $postId): void
    {
        if ($postId > 0) {
            wp_cache_delete($postId, 'post_meta');
        }

        wp_cache_delete('collection_counts_by_chain', 'bcc_onchain');
    }

    // ──────────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $entry
     * @return array{collection_id: int, post_id: int, alias: string, result: string, detail: string}
     */
    private static function outcome(array $entry, string $result, string $detail): array
    {
        return [
            'collection_id' => (int) $entry['collection_id'],
            'post_id'       => (int) $entry['post_id'],
            'alias'         => (string) $entry['alias'],
            'result'        => $result,
            'detail'        => $detail,
        ];
    }
}
