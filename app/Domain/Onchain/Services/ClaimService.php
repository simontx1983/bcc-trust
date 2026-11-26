<?php
/**
 * Claim Service
 *
 * Verifies on-chain claims: a user asserts they operate a validator,
 * created a collection, or hold tokens. The service checks:
 *
 *   1. User has a verified wallet
 *   2. Wallet matches the on-chain entity's ownership/operator address
 *   3. Records the verified claim
 *
 * Delegates RPC verification to bcc-trust's Core BlockchainQueryService.
 *
 * @package BCC\Trust\Onchain\Services
 */

namespace BCC\Trust\Onchain\Services;

use BCC\Trust\Core\Services\wallet\BlockchainQueryService;
use BCC\Trust\Onchain\Repositories\ClaimRepository;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\ValidatorRepository;
use BCC\Trust\Onchain\Repositories\CollectionRepository;
use BCC\Trust\Onchain\Repositories\WalletRepository;
use BCC\Trust\Onchain\Fetchers\SolanaFetcher;
use BCC\Core\Crypto\Bech32;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-import-type ValidatorIdWithChain from ValidatorRepository
 * @phpstan-import-type CollectionIdWithChain from CollectionRepository
 * @phpstan-import-type WalletWithChain from WalletRepository
 *
 * Unified entity shape consumed by matchWalletToEntity / matchValidator / matchCollection.
 * Represents the superset of columns fetched by either
 * ValidatorRepository::getByIdWithChain or CollectionRepository::getByIdWithChain
 * — the mutually-exclusive fields are optional.
 *
 * @phpstan-type EntityForClaim object{
 *     id: string,
 *     wallet_link_id: string|null,
 *     chain_id: string,
 *     chain_slug: string,
 *     chain_type: string,
 *     chain_name?: string,
 *     operator_address?: string,
 *     moniker?: string|null,
 *     contract_address?: string,
 *     collection_name?: string|null
 * }
 */
class ClaimService {

    /** @var string[] Valid entity types. */
    private const ENTITY_TYPES = ['validator', 'collection'];

    /**
     * Exclusive roles — only ONE verified claim allowed per entity.
     * Operators/creators are exclusive, and they are the ONLY roles
     * claim() accepts (F-303): a holder result is refused, never recorded.
     */
    private const EXCLUSIVE_ROLES = ['operator', 'creator'];

    /**
     * F-339: a collection match whose creator the chain could not settle.
     * Neither is an exclusive role, so a caller that forgot to handle them
     * would still refuse the claim; claim() answers each with its own error.
     *   creator_unverifiable      the chain cannot prove anyone is the creator
     *                             (renounced authority, delegated scope only,
     *                             not-found mint) - final
     *   verification_unavailable  no usable answer this time - retryable
     */
    public const ROLE_CREATOR_UNVERIFIABLE     = 'creator_unverifiable';
    public const ROLE_VERIFICATION_UNAVAILABLE = 'verification_unavailable';

    /**
     * F-14: why a claim was revoked. A closed list — it is written to the
     * audit log and handed to `bcc_onchain_claim_revoked` listeners.
     */
    public const REVOKE_REASONS = [
        'wallet_link_gone', 'validator_row_gone', 'no_longer_holder', 'no_longer_creator', 'wallet_unlinked', 'account_deleted',
        // Validator relationship changes (ValidatorRelationshipService):
        // a staff revoke's reasons, a staff link cancelled, and a staff or
        // node-key owner replaced by a wallet proof.
        'linked_in_error', 'evidence_withdrawn', 'owner_requested', 'ownership_disputed',
        'link_cancelled', 'superseded_by_proof',
    ];

    /**
     * Max wallets checked per claim match (Phase 8). Each wallet on the
     * entity's chain costs a synchronous on-chain RPC in matchCollection;
     * the linked-wallet count is attacker-controllable, so this caps the
     * per-request fan-out. Generous — real users rarely link >25 wallets
     * to one chain.
     */
    private const MAX_WALLETS_PER_CLAIM_MATCH = 25;

    /**
     * F-303: the much tighter cap for COLLECTION matching, which the fix makes
     * LIVE for the first time. Validator matching is a local address
     * comparison; a collection check is synchronous RPC per wallet —
     * getEthRole() is an owner() eth_call (15 s timeout) then, when that is
     * not the wallet, a holder lookup (10–15 s). At 25 wallets one claim
     * request could hold a PHP worker for minutes; at 3 the worst case is
     * bounded and a creator's wallet on the collection's chain is still
     * found (wallets are already filtered to that chain). The provider
     * circuit breakers stop sustained failures; this bounds a slow-but-up
     * provider, which they do not.
     */
    private const MAX_WALLETS_PER_COLLECTION_MATCH = 3;

    /**
     * Attempt to claim an on-chain entity.
     *
     * Checks if the user has a connected wallet that matches the entity's
     * on-chain owner/operator. If matched, records a verified claim.
     *
     * A VALIDATOR operator claim goes through
     * ValidatorRelationshipService::claimByWallet(): the relationship lock
     * order, the log rows, the supersede of a staff-approved owner, and the
     * page mirror for $pageId in the same run (`mirror_written`). A
     * collection claim keeps createExclusiveClaim(), and its caller mirrors it.
     *
     * @return array{success: bool, message: string, claim_id?: int, role?: string, needs_wallet?: bool, chain_slug?: string, error?: string, is_primary?: bool, reason_code?: string, mirror_written?: bool}
     */
    public static function claim(int $userId, string $entityType, int $entityId, int $pageId = 0): array {
        // §M suspension gate at the SERVICE boundary (F-109), admin bypass
        // OFF. PagesEndpoint gates first; this holds for any future caller.
        if (!\BCC\Core\Permissions\Permissions::is_not_suspended($userId, false)) {
            // An explicit sentinel (v1.NEXT): the claim route maps exactly this
            // to reason `suspended`, so a future forbidden cause can never be
            // mislabelled as a suspension.
            return ['success' => false, 'error' => 'suspended', 'message' => 'Your account is suspended.'];
        }

        if (!in_array($entityType, self::ENTITY_TYPES, true)) {
            return ['success' => false, 'message' => 'Invalid entity type.'];
        }

        // Check for existing claim by this user.
        $existing = ClaimRepository::getUserClaim($userId, $entityType, $entityId, PageClaimRolePolicy::ENTITY_CLAIM_ROLES);
        if ($existing && $existing->status === 'verified') {
            return ['success' => false, 'message' => 'You have already claimed this.'];
        }

        // Load entity data.
        $entity = self::loadEntity($entityType, $entityId);
        if (!$entity) {
            return ['success' => false, 'error' => 'entity_not_found', 'message' => 'Entity not found.'];
        }

        $entityChainSlug = $entity->chain_slug ?? '';

        // F-515: a validator BCC cannot prove an operator for is refused up
        // front, with the same code and hint the card shows
        // (ValidatorClaimSupport), never as "connect a wallet" or a wallet
        // mismatch. The card and this write read the one rule.
        if ($entityType === 'validator') {
            $support = ValidatorClaimSupport::assess(
                (string) ($entity->chain_type ?? ''),
                (string) $entityChainSlug,
                (string) ($entity->chain_name ?? ''),
                (string) ($entity->operator_address ?? '')
            );
            if (!$support['claimable']) {
                return [
                    'success'     => false,
                    'error'       => 'not_claimable',
                    'reason_code' => (string) $support['reason_code'],
                    'message'     => (string) $support['hint'],
                ];
            }
        }

        // Get user's verified wallets only — unverified wallets must not
        // be eligible for claims (prevents address-squatting attacks).
        $wallets = WalletRepository::getForUser($userId, null, true);
        if (empty($wallets)) {
            return [
                'success'      => false,
                'message'      => 'Connect a wallet first to claim this.',
                'needs_wallet' => true,
                'chain_slug'   => $entityChainSlug,
            ];
        }

        // Try to match a wallet to the entity.
        $match = self::matchWalletToEntity($wallets, $entity, $entityType);
        if (!$match) {
            // Check whether the user has ANY wallet on the entity's chain.
            $entityChainId    = (int) $entity->chain_id;
            $hasChainWallet   = false;
            foreach ($wallets as $w) {
                if ((int) $w->chain_id === $entityChainId) {
                    $hasChainWallet = true;
                    break;
                }
            }

            return [
                'success'      => false,
                'message'      => self::noMatchMessage($entityType, $entity),
                'needs_wallet' => !$hasChainWallet,
                'chain_slug'   => $entityChainSlug,
            ];
        }

        // ── Exclusivity gate: only one operator/creator per entity ────────
        if (in_array($match['role'], self::EXCLUSIVE_ROLES, true)) {
            $result = ($entityType === 'validator' && $match['role'] === 'operator')
                ? ValidatorRelationshipService::claimByWallet($userId, $entityId, $match['wallet_address'], $match['chain_id'], $pageId)
                : ClaimRepository::createExclusiveClaim(
                    $userId,
                    $entityType,
                    $entityId,
                    $match['wallet_address'],
                    $match['chain_id'],
                    $match['role']
                );

            if (!$result['success']) {
                return $result;
            }

            $claimId = $result['claim_id'];

            // Fire the verified event for exclusive claims (operator/creator).
            do_action('bcc_onchain_claim_verified', $userId, $entityType, $entityId, $match['role']);

            return [
                'success'        => true,
                'message'        => self::successMessage($entityType, $match['role']),
                'claim_id'       => $claimId,
                'role'           => $match['role'],
                'is_primary'     => true,
                'mirror_written' => (bool) ($result['mirror_written'] ?? false),
            ];
        }

        // F-339: the chain could not settle who the collection's creator is.
        // Never a claim; the two unknowns read differently (user decision):
        // an outage is retryable, an unprovable creator is final.
        if ($match['role'] === self::ROLE_VERIFICATION_UNAVAILABLE) {
            return [
                'success' => false,
                'error'   => self::ROLE_VERIFICATION_UNAVAILABLE,
                'message' => "We couldn't verify this collection's creator on-chain right now. Try again in a few minutes.",
            ];
        }
        if ($match['role'] === self::ROLE_CREATOR_UNVERIFIABLE) {
            return [
                'success' => false,
                'error'   => self::ROLE_CREATOR_UNVERIFIABLE,
                'message' => "We can't confirm this collection's creator on-chain.",
            ];
        }

        // ⛔ F-303: a NON-exclusive role (holder) is REFUSED here, and nothing
        // is recorded.
        //
        // This method's only caller is the page-claim route (PagesEndpoint),
        // which mirrors whatever succeeds as an EXCLUSIVE page claim and fires
        // `bcc_page_claimed` — and PeepSoIntegration::onPageClaimed then hands
        // an unowned page (post_author 0, how placeholder pages are minted)
        // and its followers to the claimer. A holder result reaching that path
        // would let ANY wallet holding one NFT of a collection take its page
        // and block the real creator. Holding is not owning: holder status is
        // served by the holder-group flows, never by a page claim. Only
        // operator and creator may claim a page.
        return [
            'success' => false,
            'error'   => 'holder_not_owner',
            'message' => "Holding an NFT doesn't make you this collection's creator.",
        ];
    }

    /**
     * Is this on-chain entity actually attached to this page?
     *
     * `claim()` proves a wallet controls an ENTITY and knows nothing about
     * pages. A caller that goes on to act on a page must prove the entity
     * belongs to it first, or a valid signature for validator A grants
     * validator B's page.
     *
     * Validators resolve through the same two-leg binding the card read
     * model uses to build `claim_target`, so every target a card offers
     * passes. Collections have no placeholder leg: only a wallet link binds
     * one to a page. Anything else is refused.
     */
    public static function isEntityBoundToPage(string $entityType, int $entityId, int $pageId): bool {
        if ($entityId <= 0 || $pageId <= 0) {
            return false;
        }

        if ($entityType === 'validator') {
            foreach (ValidatorRepository::findAllByPageId($pageId) as $row) {
                if ($row->validator_id === $entityId) {
                    return true;
                }
            }
            return false;
        }

        if ($entityType === 'collection') {
            return WalletRepository::getPostIdForEntity(CollectionRepository::table(), $entityId) === $pageId;
        }

        return false;
    }

    /**
     * Load entity data by type and ID. Delegates to repository.
     *
     * @return EntityForClaim|null
     */
    private static function loadEntity(string $entityType, int $entityId): ?object {
        if ($entityType === 'validator') {
            /** @var EntityForClaim|null */
            return ValidatorRepository::getByIdWithChain($entityId);
        }

        if ($entityType === 'collection') {
            /** @var EntityForClaim|null */
            return CollectionRepository::getByIdWithChain($entityId);
        }

        return null;
    }

    /**
     * Match a user's wallets against an on-chain entity's ownership.
     *
     * For validators: wallet's derived operator address matches validator's operator_address.
     * For collections: wallet address is the contract owner (creator) or holds tokens (holder).
     *
     * @param list<WalletWithChain> $wallets
     * @param EntityForClaim $entity
     * @return array{wallet_address: string, chain_id: int, role: string}|null
     */
    private static function matchWalletToEntity(array $wallets, object $entity, string $entityType): ?array {
        $entityChainId = (int) $entity->chain_id;

        // Filter to wallets on the same chain.
        $chainWallets = array_values(array_filter($wallets, function ($w) use ($entityChainId) {
            return (int) $w->chain_id === $entityChainId;
        }));

        if (empty($chainWallets)) {
            return null;
        }

        // Bound the synchronous on-chain RPC fan-out (Phase 8): matchCollection
        // issues one getEthRole/getSolanaRole RPC per wallet, and a user's
        // linked-wallet count is attacker-controllable. Cap the wallets checked
        // per claim so a 100-wallet user can't force 100+ synchronous RPC calls
        // in a single request. A user with more than the cap on this entity's
        // chain has only their first N checked (rare in practice).
        if (count($chainWallets) > self::MAX_WALLETS_PER_CLAIM_MATCH) {
            $chainWallets = array_slice($chainWallets, 0, self::MAX_WALLETS_PER_CLAIM_MATCH);
        }

        if ($entityType === 'validator') {
            return self::matchValidator($chainWallets, $entity);
        }

        if ($entityType === 'collection') {
            return self::matchCollection(
                array_slice($chainWallets, 0, self::MAX_WALLETS_PER_COLLECTION_MATCH),
                $entity
            );
        }

        return null;
    }

    /**
     * Does one of the viewer's VERIFIED wallets prove operator authority
     * over this validator?
     *
     * ⚠⚠ CASE FOLDING IS PER CHAIN FAMILY, AND THAT IS A SECURITY
     * PROPERTY, NOT A STYLE CHOICE. The previous version lowercased both
     * sides before every comparison. That is right for EVM — hex is
     * case-insensitive and EIP-55 checksum casing carries no identity —
     * and WRONG for base58 and SS58, where upper and lower case are
     * DISTINCT characters. Folding a base58 address loses information, so
     * two different addresses can compare equal after folding. Solana
     * (base58) and Polkadot (SS58) are both in that family, so the
     * comparison is now case-sensitive everywhere except EVM.
     *
     * ⛔ Nothing here may match on a label, a moniker, or a substring.
     * Authority is only ever: the same key, proven by a signature that
     * WalletVerifier already checked before the wallet was linked.
     *
     * Per family:
     *
     *   cosmos   — the wallet is `cosmos1…` and the operator address is
     *              `cosmosvaloper1…`. Same 20 bytes, different bech32 HRP,
     *              and the checksum differs by HRP, so a suffix compare is
     *              invalid. Decode both and compare raw bytes.
     *   polkadot — `operator_address` is the validator's STASH account
     *              (PolkadotFetcher::stashAddress / mapValidator), which is
     *              an account the operator holds — unlike a Solana identity
     *              key, which lives on the validator host. A stash key can
     *              be signed with from a browser extension, so this is a
     *              legitimate proof. Compared case-sensitively as SS58.
     *              ⚠ ASSUMPTION STATED: both sides must already be the SAME
     *              SS58 ENCODING. WalletIdentityService canonicalises a
     *              linked Polkadot address to prefix-0 via
     *              PolkadotSignatureVerifier::$lastCanonicalAddress, and the
     *              indexer stores whatever Subscan returned. If those two
     *              encodings ever differ, this comparison FAILS CLOSED —
     *              the claim is refused, never granted. PHP has no SS58
     *              decoder (the verifier delegates sr25519 to the Next.js
     *              route), so a public-key-level compare is not available
     *              here; refusing is the correct behaviour until it is.
     *   evm      — case-insensitive hex. Retained exactly as before, so no
     *              existing EVM match changes behaviour.
     *   solana   — `operator_address` is the vote account's `nodePubkey`,
     *              the validator IDENTITY key, and the authority that
     *              actually controls a vote account — `authorizedWithdrawer`
     *              — is not stored anywhere. A base58 exact compare is kept
     *              deliberately: it cannot be satisfied by an ordinary
     *              Phantom wallet, which is the honest outcome. ⛔ Do NOT
     *              relax this to make Solana claims "work"; it would mean
     *              asking operators to load a hot signing key into a
     *              browser wallet. See the Solana note in ClaimFlow.
     *
     * @param list<WalletWithChain> $wallets
     * @param EntityForClaim $entity
     * @return array{wallet_address: string, chain_id: int, role: string}|null
     */
    private static function matchValidator(array $wallets, object $entity): ?array {
        $operatorRaw = trim((string) ($entity->operator_address ?? ''));
        if ($operatorRaw === '') {
            return null;
        }

        $chainType = (string) ($entity->chain_type ?? '');
        // EVM is the ONLY family whose addresses may be case-folded.
        $foldCase = ($chainType === 'evm');

        $operatorCmp = $foldCase ? strtolower($operatorRaw) : $operatorRaw;
        // bech32 is canonically lower-case, so the Cosmos byte decode is
        // fed the folded form regardless of the comparison rule above.
        $operatorBech32 = strtolower($operatorRaw);

        foreach ($wallets as $wallet) {
            $walletRaw = trim((string) $wallet->wallet_address);
            if ($walletRaw === '') {
                continue;
            }

            $walletCmp = $foldCase ? strtolower($walletRaw) : $walletRaw;

            // Direct match — chains where the operator address IS the
            // account address (EVM, and Polkadot's stash).
            if (hash_equals($operatorCmp, $walletCmp)) {
                return [
                    'wallet_address' => $wallet->wallet_address,
                    'chain_id'       => (int) $wallet->chain_id,
                    'role'           => 'operator',
                ];
            }

            // Cosmos: same 20 bytes under a different bech32 HRP.
            if ($chainType === 'cosmos') {
                $walletBytes  = self::bech32DecodeToBytes(strtolower($walletRaw));
                $valoperBytes = self::bech32DecodeToBytes($operatorBech32);
                // Both must decode to exactly 20 bytes (standard Cosmos address length).
                if ($walletBytes !== null && $valoperBytes !== null
                    && strlen($walletBytes) === 20 && strlen($valoperBytes) === 20
                    && hash_equals($valoperBytes, $walletBytes)) {
                    return [
                        'wallet_address' => $wallet->wallet_address,
                        'chain_id'       => (int) $wallet->chain_id,
                        'role'           => 'operator',
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Match wallet to collection ownership via RPC.
     * Checks owner() first (creator), then balanceOf (holder).
     *
     * @param list<WalletWithChain> $wallets
     * @param EntityForClaim $entity
     * @return array{wallet_address: string, chain_id: int, role: string}|null
     */
    private static function matchCollection(array $wallets, object $entity): ?array {
        $contractAddr = $entity->contract_address ?? '';
        if (!$contractAddr) {
            return null;
        }

        // Delegate to Core's BlockchainQueryService.
        //
        // ⚠ F-303: this used to name `\BCC\Trust\Services\wallet\…`, a class
        // that does not exist, so class_exists() was false and every
        // collection claim fell through to "no match". The real class lives
        // under Core. Fixing it is only safe together with claim()'s refusal
        // of non-exclusive roles — see the comment there.
        $queryClass = '\\BCC\\Trust\\Core\\Services\\wallet\\BlockchainQueryService';
        if (!class_exists($queryClass)) {
            return null;
        }

        // CREATOR wins across ALL wallets. This loop used to return on the
        // first wallet with ANY role, so a holder wallet listed before the
        // user's creator wallet answered "holder" — which claim() now
        // refuses — and the creator could never claim their own collection.
        $holder    = null;
        $authority = null; // Solana: one authority reader (one getAsset) for every wallet
        $unknown   = null; // Solana: why the creator could not be settled, if it couldn't
        foreach ($wallets as $wallet) {
            $addr      = $wallet->wallet_address;
            $chainType = $entity->chain_type ?? 'evm';

            $role = match ($chainType) {
                'evm'    => self::evmCollectionRole($addr, $contractAddr, $entity),
                'solana' => self::solanaCollectionRole($addr, $contractAddr, $entity, $authority, $unknown),
                default  => 'none',
            };

            if ($role === 'creator') {
                return [
                    'wallet_address' => $addr,
                    'chain_id'       => (int) $wallet->chain_id,
                    'role'           => 'creator',
                ];
            }

            if ($role === 'holder' && $holder === null) {
                $holder = [
                    'wallet_address' => $addr,
                    'chain_id'       => (int) $wallet->chain_id,
                    'role'           => 'holder',
                ];
            }
        }

        // F-339: no wallet proved creatorship and the chain could not say
        // who the creator is. That, not "you only hold it" or "no match",
        // is the answer (a creator whose check failed should hear "retry").
        if ($unknown !== null) {
            return [
                'wallet_address' => '',
                'chain_id'       => (int) $entity->chain_id,
                'role'           => $unknown,
            ];
        }

        return $holder;
    }

    /**
     * F-339: a Solana wallet's role in a collection.
     *
     * Creator ONLY when the wallet is the collection mint's CURRENT full
     * update authority ({@see SolanaCollectionAuthority}, one DAS getAsset
     * shared by every wallet in the claim). An unknown verdict is never a
     * match: it is recorded in $unknown (first one wins; every wallet sees
     * the same asset, so they agree) and the holder lookup is skipped,
     * because claim() refuses with the unknown regardless. Otherwise
     * holder/none comes from the existing holder lookup, and claim()
     * refuses holders (F-303).
     *
     * @param EntityForClaim $entity
     */
    private static function solanaCollectionRole(string $wallet, string $mint, object $entity, ?SolanaCollectionAuthority &$authority, ?string &$unknown): string {
        if ($authority === null) {
            $chain = ChainRepository::getBySlug((string) $entity->chain_slug);
            if ($chain === null) {
                $unknown ??= self::ROLE_VERIFICATION_UNAVAILABLE;
                return BlockchainQueryService::ROLE_NONE;
            }
            $authority = new SolanaCollectionAuthority(new SolanaFetcher($chain));
        }

        $verdict = $authority->verdict($wallet, $mint);
        if ($verdict === SolanaCollectionAuthority::VERDICT_AUTHORITY) {
            return BlockchainQueryService::ROLE_CREATOR;
        }
        if ($verdict === SolanaCollectionAuthority::VERDICT_UNAVAILABLE) {
            $unknown ??= self::ROLE_VERIFICATION_UNAVAILABLE;
            return BlockchainQueryService::ROLE_NONE;
        }
        if ($verdict === SolanaCollectionAuthority::VERDICT_UNVERIFIABLE) {
            $unknown ??= self::ROLE_CREATOR_UNVERIFIABLE;
            return BlockchainQueryService::ROLE_NONE;
        }

        return BlockchainQueryService::getSolanaRole($wallet, $mint) === BlockchainQueryService::ROLE_HOLDER
            ? BlockchainQueryService::ROLE_HOLDER
            : BlockchainQueryService::ROLE_NONE;
    }

    /**
     * F-14 PR C: an EVM wallet's role in a collection.
     *
     * Creator ONLY when owner() on the collection's OWN chain names this
     * wallet (BlockchainQueryService::evmContractOwnerVerdict). The old
     * lookup always asked Ethereum mainnet and read any failure as "not
     * the creator"; a renounced (zero) owner is unknown, so it is never a
     * match. Holder still comes from the legacy lookup, and claim() refuses
     * holders (F-303); a creator answer from that mainnet-only lookup is
     * not trusted.
     *
     * @param EntityForClaim $entity
     */
    private static function evmCollectionRole(string $wallet, string $contract, object $entity): string {
        $chain = ChainRepository::getBySlug((string) $entity->chain_slug);
        if ($chain !== null
            && BlockchainQueryService::evmContractOwnerVerdict($chain, $wallet, $contract) === BlockchainQueryService::OWNER_VERDICT_OWNER
        ) {
            return BlockchainQueryService::ROLE_CREATOR;
        }

        return BlockchainQueryService::getEthRole($wallet, $contract) === BlockchainQueryService::ROLE_HOLDER
            ? BlockchainQueryService::ROLE_HOLDER
            : BlockchainQueryService::ROLE_NONE;
    }

    /**
     * Decode a bech32 address to its raw address bytes (binary string).
     * Returns null on invalid input (including failed checksum). Pure function — no instance state.
     *
     * Delegates to bcc-core's strict Crypto\Bech32 decoder: checksum, case,
     * length and padding are all verified, so only one spelling of an
     * address decodes (a non-canonical spelling no encoder writes does not).
     */
    private static function bech32DecodeToBytes(string $bech32): ?string {
        return Bech32::decode($bech32)['bytes'] ?? null;
    }

    /**
     * @param EntityForClaim $entity
     */
    private static function noMatchMessage(string $entityType, object $entity): string {
        if ($entityType === 'validator') {
            $moniker = $entity->moniker ?? 'this validator';
            return "None of your connected wallets match the operator address for {$moniker}. Connect the wallet that runs this validator.";
        }
        $name = $entity->collection_name ?? 'this collection';
        return "None of your connected wallets own or hold tokens from {$name}.";
    }

    private static function successMessage(string $entityType, string $role): string {
        $labels = [
            'operator' => 'Verified as operator',
            'creator'  => 'Verified as creator',
            'holder'   => 'Verified as holder',
        ];
        return $labels[$role] ?? 'Claim verified';
    }

    /**
     * F-14: revoke a verified claim and undo what it granted.
     *
     *  - the claim and its bound page mirror flip to `revoked`
     *    ({@see ClaimRepository::revoke()}, one transaction, fail-closed);
     *  - the trust bonus is recomputed from the claims that remain;
     *  - `bcc_trust_verification_changed` resyncs the page read model, so the
     *    card's `is_claim_verified` badge (and its rank composite) drops;
     *  - `bcc_onchain_claim_revoked` is fired for listeners, and the revoke
     *    is audited with its reason.
     *
     * What stays, by design: the page's `post_author` (reassignment is an
     * explicit admin action) and the original feed activity row, which the
     * feed now hides at read time (F-89).
     *
     * Returns false when there was nothing to revoke (unknown id, or already
     * not verified). A failed read or write BEFORE the commit THROWS — the
     * caller must treat that as UNKNOWN and never as a completed revoke.
     * Once the revoke has committed it returns true: every follow-up step
     * (audit, bonus, read model, events) is isolated, logged on failure and
     * never thrown (F-14 PR B1). The claimed page's read model is marked
     * dirty for every page bound to the entity, not only the claimant's own.
     *
     * ⚠ Must not be called inside TransactionManager::run() — see
     * ClaimRepository::revoke(), which refuses it.
     *
     * @throws \InvalidArgumentException for a reason outside REVOKE_REASONS
     * @param array<string, mixed> $payloadExtra added to a validator claim's owner_revoked log payload
     * @throws \BCC\Trust\Onchain\Repositories\RepositoryReadFailure|\RuntimeException
     */
    public static function revokeClaim(int $claimId, string $reason, string $actorKind = 'system', ?int $actorId = null, array $payloadExtra = [], ?string $evidenceNote = null): bool
    {
        if (!in_array($reason, self::REVOKE_REASONS, true)) {
            throw new \InvalidArgumentException('Unknown claim revoke reason: ' . $reason);
        }

        // A VALIDATOR owner claim ends under the relationship lock order with
        // its log row, naming the initiator. Collection claims keep the
        // path below.
        $claim = ClaimRepository::claimEntityStrict($claimId);
        if ($claim === null) {
            return false;
        }
        if ($claim['entity_type'] === 'validator' && in_array($claim['claim_role'], PageClaimRolePolicy::OWNER_ROLES, true)) {
            return ValidatorRelationshipService::endOwnerClaim($claimId, $reason, $actorKind, $actorId, $payloadExtra, $evidenceNote);
        }

        $result = ClaimRepository::revoke($claimId);
        if (!$result['revoked']) {
            return false;
        }

        self::afterRevokeCommitted($claimId, $result, $reason, true);

        return true;
    }

    /**
     * The post-commit steps of a revoke: audit, page release, bonus, read
     * model, hooks. Shared by revokeClaim() and by revokes committed inside
     * a larger transaction (ValidatorRelationshipService).
     *
     * $releasePagesInline false leaves the page OWNERSHIP move (PeepSo owner
     * role, post_author, score rows, the unowned-page bonus clear) to the
     * PageOwnershipAligner, which runs it asynchronously with no current
     * user: a release run inline from a staff request would fire the page
     * save handlers as that editor.
     *
     * @param array{revoked: bool, user_id: int, entity_type: string, entity_id: int, claim_role: string, mirror_page_ids: list<int>, bound_page_ids: list<int>} $result
     */
    public static function afterRevokeCommitted(int $claimId, array $result, string $reason, bool $releasePagesInline): void
    {
        // ── COMMITTED. Nothing below may report the revoke as failed. ──
        // A throw here used to escape after the commit: the next sweep sees
        // the claim as already revoked and returns early, so the lost event
        // and audit row were never retried (#561 review). Each step is now
        // isolated — logged and swallowed — like ModerationService::afterCommit.
        $userId = $result['user_id'];

        // Accountability first.
        self::afterCommit('audit', $claimId, static function () use ($claimId, $reason, $result, $userId): void {
            \BCC\Trust\Core\Security\AuditLogger::log('claim_revoked', $claimId, [
                'reason'          => $reason,
                'entity_type'     => $result['entity_type'],
                'entity_id'       => $result['entity_id'],
                'role'            => $result['claim_role'],
                'mirror_page_ids' => $result['mirror_page_ids'],
            ], 'claim', $userId);
        });

        // F-334 (user decision): free each page the user LOST (revoke()'s
        // mirror_page_ids already exclude pages they still hold through
        // another claim), so the next verified operator's claim takes it
        // over. One isolated step per page; it re-checks that no verified
        // claim of ANY user still binds the page before touching it, and
        // moves the page's score rows off the user (owner-0).
        //
        // ⚠ BEFORE the bonus step: with the rows moved, getPageForOwner()
        // no longer resolves the user to a page they lost, so their bonus is
        // recomputed onto a page they still own.
        foreach ($releasePagesInline ? $result['mirror_page_ids'] : [] as $lostPageId) {
            self::afterCommit('page_release', $claimId, static function () use ($lostPageId, $userId): void {
                \BCC\Trust\Core\Plugin::instance()->peepSoIntegration()->releaseClaimedPage((int) $lostPageId, $userId);
            });
            // A page nobody owns now carries no on-chain bonus. Its own step:
            // it runs even when the release was partial, and only acts on a
            // page whose author is 0 (a skipped or failed release leaves the
            // author in place; an admin-reassigned page keeps its owner).
            self::afterCommit('page_bonus_clear', $claimId, static function () use ($lostPageId): void {
                if ((int) get_post_field('post_author', (int) $lostPageId) === 0) {
                    BonusService::clearForUnownedPage((int) $lostPageId);
                }
            });
        }

        // The bonus is claimant-centric: it lives on the claimant's own page.
        self::afterCommit('bonus', $claimId, static function () use ($userId): void {
            $pageId = \BCC\Core\ServiceLocator::resolvePageOwnerResolver()->getPageForOwner($userId);
            if ($pageId) {
                BonusService::recomputeAndApply($pageId, $userId);
            }
        });

        // The CLAIMED page's `has_verified_claim` (card badge, rank composite)
        // lives on the bound page, which `bcc_trust_verification_changed`
        // does not reach — it resyncs only the claimant's own page.
        foreach ($result['bound_page_ids'] as $boundPageId) {
            self::afterCommit('read_model', $claimId, static function () use ($boundPageId): void {
                \BCC\Trust\Core\Services\PageReadModelSync::markDirty($boundPageId);
            });
        }

        self::afterCommit('verification_changed', $claimId, static function () use ($userId): void {
            do_action('bcc_trust_verification_changed', $userId);
        });

        self::afterCommit('claim_revoked_event', $claimId, static function () use ($claimId, $userId, $result, $reason): void {
            do_action(
                'bcc_onchain_claim_revoked',
                $claimId,
                $userId,
                $result['entity_type'],
                $result['entity_id'],
                $result['claim_role'],
                $reason
            );
        });
    }

    /**
     * F-521: revoke EVERY verified validator/collection claim the user holds,
     * or only those on one wallet (and chain) when given, through
     * {@see revokeClaim()}. So each one is audited (`claim_revoked` with the
     * reason), frees the page it granted (PeepSo owner and author, F-334),
     * recomputes the bonus and fires `bcc_onchain_claim_revoked`, exactly as
     * the re-verify sweep's revokes do. Used by a wallet unlink
     * (`wallet_unlinked`) and an account deletion (`account_deleted`) before
     * they purge the rows.
     *
     * The id list is read FAIL-CLOSED and bounded (see
     * ClaimRepository::verifiedEntityClaimIdsForUser()); a failed read or a
     * failed revoke THROWS, and the claims revoked before it stay revoked.
     *
     * ⚠ Must not be called inside TransactionManager::run(), like revokeClaim().
     *
     * @return int how many claims were revoked
     * @throws \InvalidArgumentException for a reason outside REVOKE_REASONS
     * @throws \BCC\Trust\Onchain\Repositories\RepositoryReadFailure|\RuntimeException
     */
    public static function revokeAllForUser(int $userId, string $reason, ?string $walletAddress = null, ?int $chainId = null, string $actorKind = 'system', ?int $actorId = null): int
    {
        if (!in_array($reason, self::REVOKE_REASONS, true)) {
            throw new \InvalidArgumentException('Unknown claim revoke reason: ' . $reason);
        }

        $revoked = 0;
        foreach (ClaimRepository::verifiedEntityClaimIdsForUser($userId, $walletAddress, $chainId) as $claimId) {
            if (self::revokeClaim($claimId, $reason, $actorKind, $actorId)) {
                ++$revoked;
            }
        }

        return $revoked;
    }

    /**
     * Revoke up to $limit of the user's verified claims (or one wallet's),
     * oldest first, through revokeClaim(): for a user with more than
     * REVOKE_ALL_MAX claims, worked through in batches before an unlink or
     * a deletion. A failed read or revoke throws; earlier revokes stand.
     *
     * @param array<string, mixed> $payloadExtra added to each validator claim's owner_revoked log payload
     * @return int how many were revoked
     */
    public static function revokeBatchForUser(int $userId, string $reason, int $limit, ?string $walletAddress = null, ?int $chainId = null, string $actorKind = 'system', ?int $actorId = null, array $payloadExtra = [], ?string $evidenceNote = null): int
    {
        if (!in_array($reason, self::REVOKE_REASONS, true)) {
            throw new \InvalidArgumentException('Unknown claim revoke reason: ' . $reason);
        }

        $revoked = 0;
        foreach (ClaimRepository::verifiedEntityClaimIdsBatch($userId, $limit, $walletAddress, $chainId) as $claimId) {
            if (self::revokeClaim($claimId, $reason, $actorKind, $actorId, $payloadExtra, $evidenceNote)) {
                ++$revoked;
            }
        }

        return $revoked;
    }

    /**
     * F-521: an account deletion's claim purge. Every verified claim is
     * revoked through {@see revokeClaim()} with `account_deleted` first, so
     * each is audited and frees the page it granted; then all of the user's
     * claim rows are deleted, as before.
     *
     * Each claim is revoked on its own: one that fails is logged and the
     * rest are still revoked, so a single bad claim never leaves the user's
     * other pages unreleased. (A wallet unlink, by contrast, stops at the
     * first failure and purges nothing: see revokeAllForUser().) Like the
     * rest of the account-deletion cleanup, no failure blocks the WordPress
     * deletion: the rows are still purged.
     */
    public static function purgeForDeletedUser(int $userId, string $actorKind = 'system', ?int $actorId = null): void
    {
        if ($userId <= 0) {
            return;
        }
        $readFailed = false;
        try {
            $claimIds = ClaimRepository::verifiedEntityClaimIdsForUser($userId);
        } catch (\Throwable $e) {
            $claimIds   = [];
            $readFailed = true;
            \BCC\Core\Log\Logger::error('[bcc-onchain] account deletion: claim read failed, claims purged without revoke', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
        }
        $notRevoked = 0;
        foreach ($claimIds as $claimId) {
            try {
                self::revokeClaim($claimId, 'account_deleted', $actorKind, $actorId);
            } catch (\Throwable $e) {
                ++$notRevoked;
                \BCC\Core\Log\Logger::error('[bcc-onchain] account deletion: claim revoke failed', [
                    'user_id'  => $userId,
                    'claim_id' => $claimId,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        // Claims about to be purged without their audited revoke (their pages
        // may still name the deleted user): counted and put on the record,
        // so the admin health view can find them. Best-effort.
        if ($readFailed || $notRevoked > 0) {
            self::afterCommit('purge_without_revoke_audit', 0, static function () use ($userId, $notRevoked, $readFailed): void {
                \BCC\Trust\Core\Security\AuditLogger::log('claims_purged_without_revoke', $userId, [
                    'count'       => $readFailed ? null : $notRevoked,
                    'read_failed' => $readFailed,
                ], 'user', 0);
            });
            self::afterCommit('purge_without_revoke_metric', 0, static function (): void {
                \BCC\Core\Observability\DegradationMetrics::record('validator_relationships', 'deletion_revoke_failed');
            });
        }
        ClaimRepository::deleteForUser($userId);
    }

    /**
     * Who is ending a member's claims, as the relationship log names them:
     * the member themselves, a staff member acting on them, or the system
     * (cron, CLI: no current user). Read once at the request boundary and
     * passed down explicitly.
     *
     * @return array{0: 'member'|'staff'|'system', 1: int|null}
     */
    public static function initiatorFor(int $subjectUserId, int $currentUserId): array
    {
        if ($currentUserId <= 0) {
            return ['system', null];
        }

        return $currentUserId === $subjectUserId ? ['member', $subjectUserId] : ['staff', $currentUserId];
    }

    /** One post-commit step of a revoke: a failure is logged, never thrown. */
    private static function afterCommit(string $step, int $claimId, \Closure $work): void
    {
        try {
            $work();
        } catch (\Throwable $e) {
            \BCC\Core\Log\Logger::error('[bcc-trust] claim_revoke_followup_failed', [
                'step'     => $step,
                'claim_id' => $claimId,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * F-14 PR B2: does this ONE wallet still prove control of a validator's
     * operator address? The same per-family rule as claim time
     * (matchValidator: EVM case-folded, Cosmos same 20 bytes under any HRP,
     * exact otherwise), so the re-verify sweep can never be stricter or
     * looser than the claim was.
     *
     * @param WalletWithChain $wallet
     */
    public static function walletProvesOperator(object $wallet, string $operatorAddress, string $chainType): bool
    {
        // matchValidator() reads only operator_address and chain_type; the
        // other EntityForClaim keys are present but unused here.
        $entity = (object) [
            'id'               => '',
            'wallet_link_id'   => null,
            'chain_id'         => '',
            'chain_slug'       => '',
            'chain_type'       => $chainType,
            'operator_address' => $operatorAddress,
        ];
        return self::matchValidator([$wallet], $entity) !== null;
    }
}
