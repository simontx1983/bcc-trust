<?php

namespace BCC\Trust\Disputes\DTO;

use BCC\Trust\Disputes\Domain\DisputeStatus;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin dispute-list row.
 *
 * Deliberately kept separate from DisputeDetailDTO: the admin list SQL omits
 * `evidence_url`. Unifying the shapes would require forcing `evidence_url` to
 * be optional on DisputeDetailDTO — a false nullable that would mask future
 * select-list drift.
 *
 * Same fail-fast logic rules (id/status/IDs strict, display names
 * tolerant).
 */
final class AdminDisputeRowDTO
{
    public function __construct(
        public readonly int     $id,
        public readonly int     $vote_id,
        public readonly int     $page_id,
        public readonly int     $reporter_id,
        public readonly int     $voter_id,
        public readonly string  $reason,
        public readonly string  $status,
        public readonly string  $created_at,
        public readonly ?string $resolved_at,
        public readonly ?string $page_title,
        public readonly ?string $reporter_name,
        public readonly ?string $voter_name,
    ) {
        $dto = 'AdminDisputeRowDTO';
        DTOAssert::positiveInt($id,          $dto, 'id');
        DTOAssert::positiveInt($vote_id,     $dto, 'vote_id');
        DTOAssert::positiveInt($page_id,     $dto, 'page_id');
        DTOAssert::positiveInt($voter_id,    $dto, 'voter_id');
        DTOAssert::positiveInt($reporter_id, $dto, 'reporter_id');
        // reason is display-only here (admin list mb_strimwidth); NOT NULL but
        // DEFAULT '' per schema — no non-empty check.
        DisputeStatus::assert($status);
        DTOAssert::datetime($created_at,          $dto, 'created_at');
        DTOAssert::nullableDatetime($resolved_at, $dto, 'resolved_at');
    }
}
