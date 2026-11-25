<?php

namespace BCC\Trust\Core\ValueObjects;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Public badge for how a page's owner proved it, e.g. on the validator card.
 *
 * Only the KIND reaches the public: `wallet` (a wallet signature, a Solana
 * sign-in, or a self-send from the operator account), `node_key` (the
 * validator's identity key) or `staff` (approved by BCC staff). Never the
 * underlying method and never a date (Phillip's rule: no verification time
 * is shown to other users), so a self-send claim reads "Wallet Verified" like
 * any wallet proof. Copy is server-authoritative, as for GroupVerification:
 * the frontend renders `label` and `description` verbatim.
 *
 * @package BCC\Trust\Core\ValueObjects
 */
final class OwnerVerification
{
    /** Verification method (the claim row's) → public kind. Unknown → no badge. */
    private const KIND_BY_METHOD = [
        'wallet'          => 'wallet',
        'siws'            => 'wallet',
        'memo_proof'      => 'wallet',
        'solana_identity' => 'node_key',
        'staff'           => 'staff',
    ];

    /** When the owner holds several claims on the page, the strongest proof is shown. */
    private const RANK = ['wallet' => 3, 'node_key' => 2, 'staff' => 1];

    private const COPY = [
        'wallet'   => ['Wallet Verified', 'Cryptographic proof'],
        'node_key' => ['Node Key Verified', 'Cryptographic proof'],
        'staff'    => ['Verified by BCC', 'Staff verification'],
    ];

    private function __construct(
        public readonly string $kind,
        public readonly string $label,
        public readonly string $description,
    ) {}

    /**
     * The badge for an owner whose claims on the page carry $methods, or
     * null (no badge) when none of them is a known method.
     *
     * @param list<string> $methods
     */
    public static function fromMethods(array $methods): ?self
    {
        $best = null;
        foreach ($methods as $method) {
            $kind = self::KIND_BY_METHOD[$method] ?? null;
            if ($kind !== null && ($best === null || self::RANK[$kind] > self::RANK[$best])) {
                $best = $kind;
            }
        }
        if ($best === null) {
            return null;
        }
        [$label, $description] = self::COPY[$best];

        return new self($best, $label, $description);
    }

    /** @return array{kind: string, label: string, description: string} */
    public function toApiResponse(): array
    {
        return [
            'kind'        => $this->kind,
            'label'       => $this->label,
            'description' => $this->description,
        ];
    }
}
