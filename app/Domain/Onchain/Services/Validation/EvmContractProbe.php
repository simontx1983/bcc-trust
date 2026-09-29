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

    /**
     * Why the last metadata read failed, for the UNAVAILABLE evidence token.
     * Set only on the failing path; meaningless otherwise.
     */
    private string $lastMetadataKind = 'none';

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
            // ⚠⚠⚠ METADATA IS PART OF A VALID EVM INTAKE, NOT A BONUS.
            // DECISION 7 makes both launch chains Alchemy-keyed, so a metadata
            // failure here is a configuration or provider fault — never a
            // collection that happens to have no name and no image. Returning
            // VALID would let the intake service persist a row for a contract
            // whose metadata BCC never read: an empty row that looks reviewed.
            $metadata = $this->collectMetadata($contract, $budget);
            if ($metadata === null) {
                return ContractValidationVerdict::unavailable([
                    ContractValidationVerdict::EV_INTERFACE_CONFIRMED,
                    $this->evidenceFor($this->lastMetadataKind),
                ]);
            }

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

        // ── 3. Answered both canonically, supports neither ──────────────
        // ⚠ An address with no code does NOT land here. `eth_call` returns a
        // bare `0x` for it, which is not a canonical ABI boolean, so
        // `supportsInterface()` reports `malformed` and the call above already
        // returned UNAVAILABLE. That is deliberate: `0x` is also what a node
        // returns for a call it could not execute, so it cannot decide
        // anything about the address.
        return ContractValidationVerdict::invalid([
            ContractValidationVerdict::EV_INTERFACE_DENIED,
        ]);
    }

    /**
     * ERC-165 `supportsInterface(bytes4)`.
     *
     * ── ⚠⚠⚠ ONLY A CANONICAL ABI BOOLEAN IS AN ANSWER ──────────────────
     * This used to accept anything: `ltrim($hex, '0') === '1'` read `0x2`,
     * a truncated word and a bare `0x` as an answered **false**. Two such
     * reads — 721 then 1155 — produced INVALID, a negative authenticity
     * verdict manufactured out of data BCC could not parse. `0x` in
     * particular is what a node returns both for an address with no code AND
     * for a call it could not execute, so it cannot carry a verdict either.
     *
     * An ABI `bool` is exactly 32 bytes (64 hex characters), all zeroes except
     * a final `0` or `1`. Anything else — short, long, non-hex, or a
     * noncanonical value like `…02` — is malformed and resolves to
     * UNAVAILABLE.
     *
     * @return array{supported: bool, kind: string} `kind` is `none` when the
     *         call was answered canonically; otherwise a bounded token and
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

        $raw = strtolower((string) $r['result']);
        if (!str_starts_with($raw, '0x')) {
            return ['supported' => false, 'kind' => 'malformed'];
        }

        $hex = substr($raw, 2);

        // Exactly one 32-byte word, and every character a hex digit.
        if (strlen($hex) !== 64 || !ctype_xdigit($hex)) {
            return ['supported' => false, 'kind' => 'malformed'];
        }

        // Canonical booleans only: 63 zeroes then 0 or 1.
        if ($hex === str_repeat('0', 64)) {
            return ['supported' => false, 'kind' => 'none'];
        }
        if ($hex === str_repeat('0', 63) . '1') {
            return ['supported' => true, 'kind' => 'none'];
        }

        // A 32-byte word that is neither — `…02`, a packed struct, junk.
        return ['supported' => false, 'kind' => 'malformed'];
    }

    /**
     * Name, symbol, image and supply from Alchemy.
     *
     * ⚠⚠ RETURNS NULL WHEN THE METADATA COULD NOT BE OBTAINED — a missing
     * key, an exhausted budget, a timeout, a non-2xx or an unparseable body.
     * The caller turns that into UNAVAILABLE and writes nothing. A response
     * that ARRIVED and simply lacks optional fields returns an IntakeMetadata
     * with those fields ABSENT, which is a perfectly good VALID collection.
     *
     * That is the distinction the brief names: "the provider answered and the
     * field is absent" is not "metadata could not be obtained".
     *
     * **No price, floor, volume or listed-count field is read**, even though
     * Alchemy returns some of them.
     */
    private function collectMetadata(string $contract, ProviderRequestBudget $budget): ?IntakeMetadata
    {
        $metadata = IntakeMetadata::unknown();

        if (!$budget->canSpend(1)) {
            $this->lastMetadataKind = 'budget_exhausted';
            return null;
        }

        $r = $this->fetcher->contractMetadataResult($contract);
        $budget->spend(1);

        if (!$r['ok'] || !is_array($r['data'])) {
            $this->lastMetadataKind = is_string($r['kind'] ?? null) ? $r['kind'] : 'malformed';
            return null;
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
            'budget_exhausted'    => ContractValidationVerdict::EV_BUDGET_EXHAUSTED,
            default               => ContractValidationVerdict::EV_PROVIDER_ERROR,
        };
    }
}
