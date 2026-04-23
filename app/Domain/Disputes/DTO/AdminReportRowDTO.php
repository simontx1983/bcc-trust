<?php

namespace BCC\Trust\Disputes\DTO;

use BCC\Trust\Disputes\Domain\ReportStatus;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin user-report-list row with joined display names.
 *
 * Separate from UserReportDTO because the admin-list SQL additionally joins
 * wp_users to fetch `reported_name` and `reporter_name`. Single-row lookup
 * (getReportById) does not select these. Forcing one DTO to cover both would
 * mean permanently-null display fields on getReportById results — the "maybe
 * null but actually not" lie.
 *
 * Fail-soft display / fail-fast logic: IDs/status strict; display names
 * nullable (LEFT JOIN may fail for deleted users).
 */
final class AdminReportRowDTO
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
        public readonly ?string $reported_name,
        public readonly ?string $reporter_name,
    ) {
        $dto = 'AdminReportRowDTO';
        DTOAssert::positiveInt($id,          $dto, 'id');
        DTOAssert::positiveInt($reported_id, $dto, 'reported_id');
        DTOAssert::positiveInt($reporter_id, $dto, 'reporter_id');
        // reason_key is a strict logic field — mirrors UserReportDTO's enforcement.
        // reason_detail is display-tolerant and may be empty per schema.
        DTOAssert::nonEmptyString($reason_key, $dto, 'reason_key');
        ReportStatus::assert($status);
        DTOAssert::datetime($created_at,          $dto, 'created_at');
        DTOAssert::nullableDatetime($reviewed_at, $dto, 'reviewed_at');
    }
}
