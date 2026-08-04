<?php
/**
 * Shared unit-test stubs for the Wave 3 dispute-vote layer
 * (DisputePanelRetirementTest).
 *
 * TestableDisputeVoteService overrides every delegate seam so the REAL
 * eligibility ordering, vocabulary mapping, and poll-close routing run
 * against the in-memory poll engine (poll-engine-stubs.php) with no
 * WP/DB. Guarded FQN stubs cover the bcc-core statics the unit
 * bootstrap never loads (house convention: "fake-at-FQN, guarded").
 *
 * Depends on rank-unit-stubs.php (Logger + do_action fakes) and
 * poll-engine-stubs.php (in-memory poll repositories) — require all.
 *
 * @package BCC\Trust\Tests
 */

declare(strict_types=1);

// ── Guarded FQN stubs for bcc-core statics ───────────────────────────

namespace BCC\Core\DTO {
    if (!class_exists(DTOAssert::class, false)) {
        /**
         * Minimal mirror of the canonical bcc-core assertions — same
         * throw-on-violation contract, enough surface for the dispute
         * DTOs the retirement suite constructs.
         */
        class DTOAssert
        {
            public static function positiveInt(int $value, string $dto, string $field): void
            {
                if ($value <= 0) {
                    throw new \LogicException("{$dto}: {$field} must be a positive int (got {$value})");
                }
            }

            public static function nonNegativeInt(int $value, string $dto, string $field): void
            {
                if ($value < 0) {
                    throw new \LogicException("{$dto}: {$field} must be non-negative (got {$value})");
                }
            }

            public static function datetime(string $value, string $dto, string $field): void
            {
                if (strtotime($value) === false) {
                    throw new \LogicException("{$dto}: {$field} must be a datetime (got {$value})");
                }
            }

            public static function nullableDatetime(?string $value, string $dto, string $field): void
            {
                if ($value !== null) {
                    self::datetime($value, $dto, $field);
                }
            }
        }
    }
}

// ── The testable service ─────────────────────────────────────────────

namespace BCC\Trust\Tests\Stubs {

    use BCC\Trust\Disputes\DTO\DisputeCoreDTO;
    use BCC\Trust\Disputes\Services\DisputeVoteService;

    class TestableDisputeVoteService extends DisputeVoteService
    {
        /** @var array<int, DisputeCoreDTO> */
        public array $disputes = [];

        /** @var array<int, object> user_id => rank_state-shaped row */
        public array $rankRows = [];

        /** @var array<int, string> user_id => reputation tier */
        public array $tiers = [];

        /** @var list<int> */
        public array $suspended = [];

        /** @var array<int, int> page_id => owner user_id */
        public array $pageOwners = [];

        /** @var array<int, array<string, float|string|null>> user_id => §16.6 snapshot override */
        public array $snapshots = [];

        public bool $claimResult = true;

        /** @var list<int> */
        public array $released = [];

        public bool $enqueueResult = true;

        /** @var list<list<int|string>> Every enqueued async-resolve arg list. */
        public array $enqueued = [];

        protected function getDispute(int $disputeId): ?DisputeCoreDTO
        {
            return $this->disputes[$disputeId] ?? null;
        }

        protected function rankRow(int $userId): ?object
        {
            return $this->rankRows[$userId] ?? null;
        }

        protected function tierOf(int $userId): string
        {
            return $this->tiers[$userId] ?? 'neutral';
        }

        protected function notSuspended(int $userId): bool
        {
            return !in_array($userId, $this->suspended, true);
        }

        protected function pageOwnerOf(int $pageId): int
        {
            return $this->pageOwners[$pageId] ?? 0;
        }

        /**
         * @return array{rank_slug: string|null, maturity: float, rank_multiplier: float, trust_multiplier: float, trust_score: float, fraud_discount: float, effective_weight: float}
         */
        protected function weightSnapshot(int $userId): array
        {
            /** @var array{rank_slug: string|null, maturity: float, rank_multiplier: float, trust_multiplier: float, trust_score: float, fraud_discount: float, effective_weight: float} */
            return $this->snapshots[$userId] ?? [
                'rank_slug'        => 'apprentice',
                'maturity'         => 1.0,
                'rank_multiplier'  => 1.0,
                'trust_multiplier' => 1.0,
                'trust_score'      => 50.0,
                'fraud_discount'   => 1.0,
                'effective_weight' => 1.0,
            ];
        }

        protected function claimResolution(int $disputeId): bool
        {
            return $this->claimResult;
        }

        protected function releaseResolution(int $disputeId): void
        {
            $this->released[] = $disputeId;
        }

        protected function enqueueResolve(array $args): bool
        {
            $this->enqueued[] = $args;
            return $this->enqueueResult;
        }
    }
}
