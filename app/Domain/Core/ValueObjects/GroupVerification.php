<?php
/**
 * Public verification badge for a group.
 *
 * Today only "On-Chain Verified" exists; future kinds (e.g.
 * `creator_verified`) extend this without API shape changes.
 *
 * Copy is server-authoritative — the frontend renders `label` verbatim
 * and may not abbreviate "On-Chain Verified" to "Verified" alone.
 *
 * @package BCC\Trust\Core\ValueObjects
 */

namespace BCC\Trust\Core\ValueObjects;

if (!defined('ABSPATH')) {
    exit;
}

final class GroupVerification {

    public function __construct(
        public readonly string $kind,
        public readonly string $label,
    ) {}

    public static function onChain(): self {
        return new self('on_chain', 'On-Chain Verified');
    }

    /**
     * @return array{kind: string, label: string}
     */
    public function toApiResponse(): array {
        return [
            'kind'  => $this->kind,
            'label' => $this->label,
        ];
    }
}
