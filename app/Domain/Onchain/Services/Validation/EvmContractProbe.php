<?php

namespace BCC\Trust\Onchain\Services\Validation;

use BCC\Trust\Onchain\Fetchers\EvmFetcher;
use BCC\Trust\Onchain\Support\ProviderRequestBudget;
use BCC\Trust\Onchain\ValueObjects\ContractValidationVerdict;
use BCC\Trust\Onchain\ValueObjects\IntakeMetadata;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Validate ONE submitted EVM contract address.
 *
 * ── LAUNCH SCOPE (DECISION 7, settled) ──────────────────────────────────
 * **Ethereum and Base only, both Alchemy-keyed.** The standard is proved by
 * ERC-165 `supportsInterface`, which any RPC can answer; name, symbol, image
 * and supply come from Alchemy, so a launch chain without a key cannot
 * complete an intake. That is treated as a configuration fault — UNAVAILABLE —
 * and never as an accepted collection with empty metadata.
 *
 * ── ⚠⚠ ERC-1155 IS DETECTED AND REFUSED, NOT SILENTLY ACCEPTED ──────────
 * DECISION 9 defers 1155 gating until per-contract ownership can be proven
 * safely: a 1155 balance is per-token-id, so the ERC-721 ownership path would
 * admit a holder who owns a different token id in the same contract. Treating
 * a 1155 as a 721 would therefore provision a community that lets the wrong
 * people in. It resolves to UNSUPPORTED with the standard recorded, so the
 * admin screen can say *why*, and so a later PR can find these rows.
 *
 * ── BOUNDED COST ────────────────────────────────────────────────────────
 * At most two `eth_call`s (721 then, only if that says no, 1155) plus at most
 * one Alchemy metadata call. Every one is charged to the caller's budget.
 */
final class EvmContractProbe
{
    /** ERC-165 interface id for ERC-721. */
    private const IFACE_ERC721 = '0x80ac58cd';

    /** ERC-165 interface id for ERC-1155. */
    private const IFACE_ERC1155 = '0xd9b67a26';

    /** `supportsInterface(bytes4)` selector. */
    private const SELECTOR_SUPPORTS_INTERFACE = '0x01ffc9a7';

    public function __construct(private EvmFetcher $fetcher)
    {
    }

    public function validate(string $contract, ProviderRequestBudget $budget): ContractValidationVerdict
    {
        if (!$budget->canSpend(1)) {
            return ContractValidationVerdict::unavailable([
                ContractValidationVerdict::EV_BUDGET_EXHAUSTED,
            ]);
        }

        // ── 1. ERC-721? ─────────────────────────────────────────────────
        $is721 = $this->supportsInterface($contract, self::IFACE_ERC721);
        $budget->spend(1);

        if ($is721['kind'] !== 'none') {
            return ContractValidationVerdict::unavailable([$this->evidenceFor($is721['kind'])]);
        }

        if ($is721['supported'] === true) {
            $metadata = $this->collectMetadata($contract, $budget);

            return ContractValidationVerdict::valid('ERC-721', $metadata, [
                ContractValidationVerdict::EV_INTERFACE_CONFIRMED,
            ]);
        }

        // ── 2. Not 721. ERC-1155? ───────────────────────────────────────
        if (!$budget->canSpend(1)) {
            return ContractValidationVerdict::unavailable([
                ContractValidationVerdict::EV_BUDGET_EXHAUSTED,
            ]);
        }

        $is1155 = $this->supportsInterface($contract, self::IFACE_ERC1155);
        $budget->spend(1);

        if ($is1155['kind'] !== 'none') {
            return ContractValidationVerdict::unavailable([$this->evidenceFor($is1155['kind'])]);
        }

        if ($is1155['supported'] === true) {
            // ⚠ DECIDED, and deliberately NOT valid. See the class docblock.
            return ContractValidationVerdict::unsupported('ERC-1155', [
                ContractValidationVerdict::EV_INTERFACE_CONFIRMED,
                ContractValidationVerdict::EV_STANDARD_DEFERRED,
            ]);
        }

        // ── 3. Answered both, supports neither ──────────────────────────
        // An EOA (no code at the address) also lands here: `eth_call` against
        // an address with no code returns `0x`, which `supportsInterface()`
        // reports as an answered "no". That is a correct decided negative —
        // there is no NFT contract at this address.
        return ContractValidationVerdict::invalid([
            ContractValidationVerdict::EV_INTERFACE_DENIED,
        ]);
    }

    /**
     * ERC-165 `supportsInterface(bytes4)`.
     *
     * @return array{supported: bool, kind: string} `kind` is `none` when the
     *         call was answered; otherwise a transport/config token and
     *         `supported` is meaningless.
     */
    private function supportsInterface(string $contract, string $interfaceId): array
    {
        // bytes4 argument, left-aligned and right-padded to 32 bytes.
        $arg  = substr($interfaceId, 2) . str_repeat('0', 56);
        $data = self::SELECTOR_SUPPORTS_INTERFACE . $arg;

        $r = $this->fetcher->ethCallResult($contract, $data);
        if (!$r['ok']) {
            return ['supported' => false, 'kind' => $r['kind']];
        }

        $hex = strtolower(ltrim((string) $r['result'], '0x'));

        // `0x` with no payload = no code at the address. Answered, and the
        // answer is "no such interface".
        if ($hex === '') {
            return ['supported' => false, 'kind' => 'none'];
        }

        // ABI bool: 32 bytes, 1 = true. Anything else is false.
        return ['supported' => ltrim($hex, '0') === '1', 'kind' => 'none'];
    }

    /**
     * Name, symbol, image and supply from Alchemy.
     *
     * ⚠ FAILS CLOSED INTO UNKNOWN, NEVER INTO ABSENT. A missing key or a
     * timeout leaves every field UNKNOWN, so `metadata_state` reports
     * `unavailable` and the row is not mistaken for a collection that has no
     * name and no image. **No price, floor, volume or listed-count field is
     * read from the response**, even though Alchemy returns some of them.
     */
    private function collectMetadata(string $contract, ProviderRequestBudget $budget): IntakeMetadata
    {
        $metadata = IntakeMetadata::unknown();

        // Description is not offered by this endpoint in a form BCC trusts as
        // collection-authored, so it is never attempted on EVM — it stays
        // UNKNOWN rather than being recorded as absent.
        if (!$budget->canSpend(1)) {
            return $metadata;
        }

        $r = $this->fetcher->contractMetadataResult($contract);
        $budget->spend(1);

        if (!$r['ok'] || !is_array($r['data'])) {
            return $metadata;
        }

        $json = $r['data'];
        $os   = is_array($json['openSeaMetadata'] ?? null) ? $json['openSeaMetadata'] : [];

        // The payload parsed, so these fields are ANSWERED — present means
        // KNOWN, missing means ABSENT.
        return $metadata
            ->withAnswered('name', $this->stringOrNull($json['name'] ?? ($os['collectionName'] ?? null)))
            ->withAnswered('symbol', $this->stringOrNull($json['symbol'] ?? null))
            ->withAnswered('image_url', $this->stringOrNull($os['imageUrl'] ?? null))
            ->withAnswered('total_supply', $this->intOrNull($json['totalSupply'] ?? null));
    }

    private function stringOrNull(mixed $v): ?string
    {
        return is_string($v) && trim($v) !== '' ? $v : null;
    }

    private function intOrNull(mixed $v): ?int
    {
        if (is_int($v)) {
            return $v >= 0 ? $v : null;
        }
        if (is_string($v) && $v !== '' && ctype_digit($v)) {
            return (int) $v;
        }

        return null;
    }

    private function evidenceFor(string $kind): string
    {
        return match ($kind) {
            'credentials_missing' => ContractValidationVerdict::EV_CREDENTIALS_MISSING,
            'transport'           => ContractValidationVerdict::EV_PROVIDER_TIMEOUT,
            'malformed'           => ContractValidationVerdict::EV_MALFORMED_RESPONSE,
            default               => ContractValidationVerdict::EV_PROVIDER_ERROR,
        };
    }
}
