<?php

namespace BCC\Trust\Disputes\DTO;

use BCC\Trust\Disputes\Domain\ReportStatus;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * User-report row — produced by getReportById() and getReportsForAdminList().
 *
 * Fail-soft display / fail-fast logic:
 *   - LOGIC (strict): id, reported_id, reporter_id, status, reason_key, created_at.
 *   - DISPLAY (tolerant): reason_detail (NOT NULL but can be empty string per
 *                          schema), reviewed_at (nullable until admin acts).
 */
final class UserReportDTO
{
    public function __construct(
        public readonly int     $id,
        public readonly int     $reported_id,
        public readonly int     $reporter_id,
        public readonly string  $reason_key,
        public readonly string  $reason_detail,
        public readonly string  $status,
        public readonly string  $created_at,
        public readonly ?string $reviewed_at,
    ) {
        $dto = 'UserReportDTO';
        DTOAssert::positiveInt($id,          $dto, 'id');
        DTOAssert::positiveInt($reported_id, $dto, 'reported_id');
        DTOAssert::positiveInt($reporter_id, $dto, 'reporter_id');
        // reason_key is a strict logic field (per class doc) — enforce non-empty.
        // reason_detail is NOT checked: the schema allows empty strings and the
        // class doc marks it as display-tolerant.
        DTOAssert::nonEmptyString($reason_key, $dto, 'reason_key');
        ReportStatus::assert($status);
        DTOAssert::datetime($created_at,          $dto, 'created_at');
        DTOAssert::nullableDatetime($reviewed_at, $dto, 'reviewed_at');
    }
}
