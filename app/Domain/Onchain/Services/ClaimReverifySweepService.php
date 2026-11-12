<?php

declare(strict_types=1);

/**
 * F-14 PR B2 — the weekly re-verify of verified claims.
 *
 * Walks every verified validator/collection claim and decides, per claim:
 *
 *   KEEP     — ownership still holds;
 *   REVOKE   — ownership is DEFINITELY gone (a closed list of reasons);
 *   UNKNOWN  — anything it cannot decide for certain: a provider hiccup, a
 *              creator claim (no budgeted check yet — F-14 PR C), any throw.
 *              UNKNOWN NEVER REVOKES; it is counted and retried next week.
 *
 * MODE (`bcc_claim_reverify_mode`, product decision 2026-11-11):
 *   report  (default) — writes nothing but an audit row
 *                       `claim_revoke_would_apply` per REVOKE decision, so an
 *                       admin can review a week's decisions first;
 *   enforce           — ClaimService::revokeClaim(), then a bell to the
 *                       claimant.
 *
 * What counts as "gone":
 *   validator  — the validator row is gone, or the claim's wallet is no
 *                longer a VERIFIED link of the claimant on that chain, or that
 *                link no longer proves the operator address. A jailed,
 *                inactive or out-of-set validator is KEPT.
 *   holder     — HoldingsService::eligibilityVerdict() says INELIGIBLE across
 *                the claimant's verified wallets on the chain (any wallet that
 *                still holds keeps the badge). One SURFACE_REVOKE budget per
 *                tick; an exhausted budget stops the tick on that claim.
 *
 * Shape (F-13): a zero-wait advisory lock, a persisted id cursor, a 30 s
 * budget, one same-tick continuation on the deadline, and the cursor kept on
 * a failed read. The collaborators are protected hooks so a test subclass
 * can replace them.
 *
 * @package BCC\Trust\Onchain\Services
 */

namespace BCC\Trust\Onchain\Services;

use BCC\Trust\Onchain\Repositories\ClaimRepository;
use BCC\Trust\Onchain\Repositories\WalletRepository;
use BCC\Trust\Onchain\Support\ProviderRequestBudget;
use BCC\Trust\Onchain\ValueObjects\EligibilityVerdict;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-type ReverifyRow object{id: numeric-string|int, user_id: numeric-string|int, entity_type: string, entity_id: numeric-string|int, wallet_address: string, chain_id: numeric-string|int, claim_role: string, entity_row_id: numeric-string|int|null, operator_address: string|null, contract_address: string|null, entity_name: string|null, chain_slug: string|null, chain_type: string|null}
 * @phpstan-import-type WalletWithChain from WalletRepository
 */
class ClaimReverifySweepService
{
    public const CRON_HOOK     = 'bcc_onchain_claim_reverify_sweep';
    public const CONTINUE_HOOK = 'bcc_onchain_claim_reverify_continue';
    public const MODE_OPTION   = 'bcc_claim_reverify_mode';

    public const KEEP    = 'keep';
    public const REVOKE  = 'revoke';
    public const UNKNOWN = 'unknown';
    public const STOP    = 'stop';

    private const CURSOR_OPTION      = 'bcc_claim_reverify_cursor';
    private const LOCK               = 'claim_reverify_sweep';
    private const BATCH_SIZE         = 100;
    private const TIME_BUDGET        = 30;
    private const CONTINUATION_DELAY = 60;

    /**
     * Run one tick.
     *
     * @return array{mode: string, checked: int, kept: int, would_revoke: int, revoked: int, already_revoked: int, skipped_unknown: int, skipped_reasons: array<string, int>, stopped_on_budget: bool, continued: bool, complete: bool, locked: bool}
     */
    public function sweep(): array
    {
        $enforce = $this->enforcing();
        $stats = [
            'mode' => $enforce ? 'enforce' : 'report',
            'checked' => 0, 'kept' => 0, 'would_revoke' => 0, 'revoked' => 0, 'already_revoked' => 0,
            'skipped_unknown' => 0, 'skipped_reasons' => [],
            'stopped_on_budget' => false, 'continued' => false, 'complete' => false, 'locked' => false,
        ];

        if (!$this->acquireLock()) {
            $stats['locked'] = true;
            return $stats; // Another run is in flight.
        }

        try {
            $deadline = $this->now() + self::TIME_BUDGET;
            $cursor   = $this->readCursor();
            $budget   = $this->newBudget();
            $decidedThisTick = 0;

            while (true) {
                // A failed read THROWS: the cursor stays where it was.
                $rows = $this->listClaims($cursor, self::BATCH_SIZE);
                if ($rows === []) {
                    $this->clearCursor();
                    $stats['complete'] = true;
                    break;
                }

                foreach ($rows as $row) {
                    [$decision, $reason] = $this->decide($row, $budget);

                    if ($decision === self::STOP) {
                        if ($decidedThisTick > 0) {
                            // Budget spent: retry THIS claim on a fresh budget.
                            $this->writeCursor($cursor);
                            $this->scheduleContinuation();
                            $stats['stopped_on_budget'] = true;
                            $stats['continued'] = true;
                            $this->logStats($stats);
                            return $stats;
                        }
                        // One claim alone exhausts a fresh budget: skip it as
                        // unknown rather than loop on it every minute.
                        $decision = self::UNKNOWN;
                        $reason   = EligibilityVerdict::REASON_BUDGET_EXHAUSTED;
                    }

                    $stats['checked']++;
                    $decidedThisTick++;
                    $this->apply($row, $decision, $reason, $enforce, $stats);
                    $cursor = (int) $row->id;

                    if ($this->now() >= $deadline) {
                        $this->writeCursor($cursor);
                        $this->scheduleContinuation();
                        $stats['continued'] = true;
                        $this->logStats($stats);
                        return $stats;
                    }
                }

                if (count($rows) < self::BATCH_SIZE) {
                    $this->clearCursor();
                    $stats['complete'] = true;
                    break;
                }
            }
        } finally {
            $this->releaseLock();
        }

        $this->logStats($stats);
        return $stats;
    }

    /**
     * @param ReverifyRow $row
     * @return array{0: string, 1: string}
     */
    public function decide(object $row, ProviderRequestBudget $budget): array
    {
        try {
            if ($row->entity_type === 'validator' && $row->claim_role === 'operator') {
                return $this->decideValidator($row);
            }
            if ($row->entity_type === 'collection' && $row->claim_role === 'holder') {
                return $this->decideHolder($row, $budget);
            }
            if ($row->entity_type === 'collection' && $row->claim_role === 'creator') {
                return [self::UNKNOWN, 'creator_check_pending'];
            }
            return [self::UNKNOWN, 'unsupported_role'];
        } catch (\Throwable $e) {
            \BCC\Core\Log\Logger::warning('[bcc-trust] claim re-verify: decision failed, treated as unknown', [
                'claim_id' => (int) $row->id,
                'error'    => $e->getMessage(),
            ]);
            return [self::UNKNOWN, 'error'];
        }
    }

    /**
     * @param ReverifyRow $row
     * @return array{0: string, 1: string}
     */
    private function decideValidator(object $row): array
    {
        if ($row->entity_row_id === null) {
            return [self::REVOKE, 'validator_row_gone'];
        }

        $chainType = (string) ($row->chain_type ?? '');
        $link = null;
        // FAIL-CLOSED: a failed wallet read throws and becomes UNKNOWN.
        foreach ($this->verifiedWallets((int) $row->user_id) as $wallet) {
            if ((int) $wallet->chain_id === (int) $row->chain_id
                && self::sameAddress((string) $wallet->wallet_address, (string) $row->wallet_address, $chainType)) {
                $link = $wallet;
                break;
            }
        }
        if ($link === null) {
            return [self::REVOKE, 'wallet_link_gone'];
        }

        if (!ClaimService::walletProvesOperator($link, (string) ($row->operator_address ?? ''), $chainType)) {
            return [self::REVOKE, 'wallet_link_gone'];
        }

        return [self::KEEP, ''];
    }

    /**
     * @param ReverifyRow $row
     * @return array{0: string, 1: string}
     */
    private function decideHolder(object $row, ProviderRequestBudget $budget): array
    {
        if ($row->entity_row_id === null || (string) ($row->contract_address ?? '') === '' || (string) ($row->chain_slug ?? '') === '') {
            // A holder claim on a vanished collection already earns no bonus
            // (computePageClaimBonus joins the entity); not a definite revoke.
            return [self::UNKNOWN, 'collection_unresolved'];
        }

        $verdict = $this->holdingVerdict((int) $row->user_id, (string) $row->chain_slug, (string) $row->contract_address, $budget);
        if ($verdict->isBudgetExhausted()) {
            return [self::STOP, EligibilityVerdict::REASON_BUDGET_EXHAUSTED];
        }
        if ($verdict->isEligible()) {
            return [self::KEEP, ''];
        }
        if ($verdict->isIneligible()) {
            return [self::REVOKE, 'no_longer_holder'];
        }
        return [self::UNKNOWN, $verdict->reason !== '' ? $verdict->reason : 'unknown'];
    }

    /**
     * @param ReverifyRow $row
     * @param array{mode: string, checked: int, kept: int, would_revoke: int, revoked: int, already_revoked: int, skipped_unknown: int, skipped_reasons: array<string, int>, stopped_on_budget: bool, continued: bool, complete: bool, locked: bool} $stats
     */
    private function apply(object $row, string $decision, string $reason, bool $enforce, array &$stats): void
    {
        if ($decision === self::KEEP) {
            $stats['kept']++;
            return;
        }
        if ($decision !== self::REVOKE) {
            $stats['skipped_unknown']++;
            $stats['skipped_reasons'][$reason] = ($stats['skipped_reasons'][$reason] ?? 0) + 1;
            return;
        }

        if (!$enforce) {
            $stats['would_revoke']++;
            $this->auditWouldRevoke($row, $reason);
            return;
        }

        try {
            if ($this->revoke((int) $row->id, $reason)) {
                $stats['revoked']++;
                $this->notify((int) $row->user_id, (int) $row->id, (string) ($row->entity_name ?? ''), $reason);
            } else {
                $stats['already_revoked']++;
            }
        } catch (\Throwable $e) {
            // Before the commit: nothing was revoked. Retried next week.
            $stats['skipped_unknown']++;
            $stats['skipped_reasons']['revoke_failed'] = ($stats['skipped_reasons']['revoke_failed'] ?? 0) + 1;
            \BCC\Core\Log\Logger::warning('[bcc-trust] claim re-verify: revoke failed', [
                'claim_id' => (int) $row->id,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /** EVM addresses are case-insensitive; every other family is exact. */
    private static function sameAddress(string $a, string $b, string $chainType): bool
    {
        $a = trim($a);
        $b = trim($b);
        if ($a === '' || $b === '') {
            return false;
        }
        return $chainType === 'evm' ? hash_equals(strtolower($a), strtolower($b)) : hash_equals($a, $b);
    }

    // ── collaborators (protected so a test subclass can replace them) ─────

    public function enforcing(): bool
    {
        return get_option(self::MODE_OPTION, 'report') === 'enforce';
    }

    protected function now(): int
    {
        return time();
    }

    protected function acquireLock(): bool
    {
        return \BCC\Core\DB\AdvisoryLock::acquire(self::lockName(), 0);
    }

    protected function releaseLock(): void
    {
        \BCC\Core\DB\AdvisoryLock::release(self::lockName());
    }

    /** `{prefix}bcc_claim_reverify_sweep` — GET_LOCK names are server-wide. */
    public static function lockName(): string
    {
        return substr(\BCC\Core\DB\DB::table(self::LOCK), 0, 64);
    }

    protected function readCursor(): int
    {
        return (int) get_option(self::CURSOR_OPTION, 0);
    }

    protected function writeCursor(int $claimId): void
    {
        update_option(self::CURSOR_OPTION, $claimId, false);
    }

    protected function clearCursor(): void
    {
        delete_option(self::CURSOR_OPTION);
    }

    protected function scheduleContinuation(): void
    {
        if (!wp_next_scheduled(self::CONTINUE_HOOK)) {
            wp_schedule_single_event(time() + self::CONTINUATION_DELAY, self::CONTINUE_HOOK);
        }
    }

    /** @return list<ReverifyRow> */
    protected function listClaims(int $afterId, int $limit): array
    {
        return ClaimRepository::listVerifiedEntityClaimsAfter($afterId, $limit);
    }

    /** @return list<WalletWithChain> verified links only, fail-closed */
    protected function verifiedWallets(int $userId): array
    {
        return WalletRepository::getForUserOrThrow($userId, null, true);
    }

    protected function newBudget(): ProviderRequestBudget
    {
        return HoldingsService::verificationBudget(HoldingsService::SURFACE_REVOKE);
    }

    protected function holdingVerdict(int $userId, string $chainSlug, string $contract, ProviderRequestBudget $budget): EligibilityVerdict
    {
        return HoldingsService::eligibilityVerdict($userId, $chainSlug, $contract, 1, $budget);
    }

    protected function revoke(int $claimId, string $reason): bool
    {
        return ClaimService::revokeClaim($claimId, $reason);
    }

    protected function notify(int $userId, int $claimId, string $entityName, string $reason): void
    {
        try {
            \BCC\Trust\Core\Plugin::instance()->notificationDispatcher()->onClaimBadgeRemoved($userId, $claimId, $entityName, $reason);
        } catch (\Throwable $e) {
            \BCC\Core\Log\Logger::warning('[bcc-trust] claim re-verify: bell failed', [
                'claim_id' => $claimId,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param ReverifyRow $row
     */
    protected function auditWouldRevoke(object $row, string $reason): void
    {
        \BCC\Trust\Core\Security\AuditLogger::log('claim_revoke_would_apply', (int) $row->id, [
            'reason'      => $reason,
            'entity_type' => (string) $row->entity_type,
            'entity_id'   => (int) $row->entity_id,
            'role'        => (string) $row->claim_role,
            'via'         => 'reverify_sweep',
            'mode'        => 'report',
        ], 'claim', (int) $row->user_id);
    }

    /** @param array<string, mixed> $stats */
    protected function logStats(array $stats): void
    {
        \BCC\Core\Log\Logger::info('[bcc-trust] claim re-verify sweep tick', $stats);
    }
}
