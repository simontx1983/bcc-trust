<?php

namespace BCC\Trust\Onchain\Services;

use BCC\Trust\Onchain\Fetchers\CosmosFetcher;
use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;
use BCC\Trust\Onchain\Repositories\CollectionRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmCodeFamilyRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmContractRepository;
use BCC\Trust\Onchain\Support\CosmwasmDiscoveryGate;
use BCC\Trust\Onchain\Support\CosmwasmTickBudget;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * CosmWasm CW-721 discovery — the step library.
 *
 * Each public method is ONE bounded step: ingest a page of the code
 * listing, classify one family, enumerate one family's contracts,
 * classify one contract, emit the classified CW-721s toward the
 * collections table, check one family for migrations. The worker
 * sequences them under a budget and a lock; nothing here schedules
 * itself, opens a transaction, or loops without a ceiling.
 *
 * ── DENY IS THREADED THROUGH EVERY PATH ─────────────────────────────────
 * The bug this fixes: the old `fetchTopCollectionsViaCodeIdEnumeration`
 * filtered candidates on COLLECTION-ROW PRESENCE only. A contract an
 * operator had explicitly DENIED — but whose collection row had been
 * deleted — had no row, so it looked "new" and was re-inserted on every
 * sweep, while `VerifyCollectionsPage`'s own docblock promised "a DENY
 * rule is what survives rediscovery." Row-presence is not a deny check.
 *
 * Now every path that could introduce or reintroduce a candidate
 * consults {@see NftSpamFilter::isSpam()} (which reads the operator
 * rule table, `RULE_ALLOW` beating the name heuristics and `RULE_DENY`
 * beating everything) BEFORE the candidate can go anywhere:
 *
 *   1. {@see recordContracts()} — enumeration/backfill intake. A denied
 *      address is still RECORDED (so it stops looking "new" on every
 *      sweep and stops being logged as a fresh candidate forever) but is
 *      stored with `denied = 1`, which every downstream query excludes.
 *   2. {@see classifyContract()} — the work queue itself excludes denied
 *      rows, so a denied contract never even burns a probe.
 *   3. {@see emitCollections()} — the ONLY path that can create a
 *      user-facing row re-checks the LIVE rule per contract, because a
 *      rule can land between two sweeps. Deny is enforced twice here on
 *      purpose.
 *   4. {@see syncDenyFlags()} — an operator hide/unhide is reflected on
 *      the inventory, so unhiding genuinely permits later discovery
 *      rather than leaving the candidate permanently suppressed. Called
 *      from
 *      {@see \BCC\Trust\Onchain\Admin\VerifyCollectionsPage::handleHideToggle()}
 *      immediately after the rule write succeeds — with the one affected
 *      contract, never a sweep.
 *
 * `RULE_ALLOW` overrides the name heuristics and nothing else: an
 * allowed contract still lands `is_verified = 0` (the collections schema
 * default) and this service never calls a provisioning service, so
 * discovery cannot verify or provision anything.
 *
 * @phpstan-import-type CodeFamilyRow from CosmwasmCodeFamilyRepository
 * @phpstan-import-type CosmwasmContractRow from CosmwasmContractRepository
 */
final class CosmwasmDiscoveryService
{
    /**
     * Ingest one FORWARD (ascending) page of the wasm code listing.
     *
     * The historical backfill's primitive. `$pageKey` is the opaque
     * `pagination.key`; null starts at the beginning.
     *
     * @return array{ok: bool, families: int, next_key: string|null, max_code_id: int, http_code: int, error_kind: string, message: string}
     */
    public static function ingestCodePage(
        int $chainId,
        CosmosFetcher $fetcher,
        ?string $pageKey,
        CosmwasmTickBudget $budget
    ): array {
        if (!$budget->canSpend()) {
            return [
                'ok'          => false,
                'families'    => 0,
                'next_key'    => null,
                'max_code_id' => 0,
                'http_code'   => 0,
                'error_kind'  => CosmwasmClassifier::KIND_TRANSPORT,
                'message'     => 'budget exhausted',
            ];
        }

        $budget->spend();
        $page = $fetcher->listCodeFamilies($pageKey, false, CosmwasmDiscoveryGate::CODE_PAGE_SIZE);

        if (!$page['ok']) {
            return [
                'ok'          => false,
                'families'    => 0,
                'next_key'    => null,
                'max_code_id' => 0,
                'http_code'   => $page['http_code'],
                'error_kind'  => $page['error_kind'],
                'message'     => $page['message_excerpt'],
            ];
        }

        CosmwasmCodeFamilyRepository::recordDiscovered($chainId, $page['families']);

        return [
            'ok'          => true,
            'families'    => count($page['families']),
            'next_key'    => $page['next_key'],
            'max_code_id' => $page['max_code_id'],
            'http_code'   => 200,
            'error_kind'  => CosmwasmClassifier::KIND_NONE,
            'message'     => '',
        ];
    }

    /**
     * INCREMENTAL discovery of newly-uploaded code ids — a REVERSE walk
     * against a numeric watermark.
     *
     * ── WHY REVERSE, AND NOT OFFSET ─────────────────────────────────────
     * The first implementation tailed the listing with
     * `pagination.offset = knownFamilyCount`. MEASURED 2026-08-06: any
     * non-zero offset returns an EMPTY list with HTTP 200 on cosmoshub,
     * juno, osmosis and injective (only jackal honours it). An empty 200
     * is not an error, so no retry fires and the pass concludes "nothing
     * new" forever — daily discovery would have been permanently dead on
     * the four biggest chains while every health signal read green. The
     * full measurement is recorded on
     * {@see CosmosFetcher::listCodeFamilies()}; offset must not come back.
     *
     * ── THE WALK ────────────────────────────────────────────────────────
     * Newest-first pages of {@see CosmwasmDiscoveryGate::CODE_PAGE_SIZE}.
     * Ingest each page FULLY, then stop as soon as the page reached a
     * `code_id <= $watermark` — everything at or below the watermark is
     * already inventoried. The comparison is NUMERIC, not positional, so
     * it is robust to gaps in the id sequence.
     *
     * ── THE WATERMARK IS CONTIGUOUS, SO IT ONLY MOVES ON PROOF ──────────
     * `reached_watermark` is the caller's licence to advance. If the walk
     * ran out of pages or budget first, the ids between the watermark and
     * this walk's lowest page are STILL MISSING; advancing would strand
     * them permanently. So the caller must not advance, and must not call
     * the pass a success.
     *
     * ── AN EMPTY PAGE IS NOT AUTHORITATIVE ──────────────────────────────
     * With a watermark of 0 the chain has nothing inventoried and an
     * empty first page genuinely means "no code ids" — fine. With a
     * watermark ABOVE zero we KNOW code ids exist, so an empty page
     * contradicts the stored state. It is reported as
     * `reached_watermark = false` with `anomaly = true`, never as
     * "nothing new".
     *
     * @return array{ok: bool, pages: int, ingested: int, newest_code_id: int, lowest_code_id: int, reached_watermark: bool, anomaly: bool, http_code: int, error_kind: string, message: string}
     */
    public static function ingestNewCodeFamilies(
        int $chainId,
        CosmosFetcher $fetcher,
        int $watermark,
        CosmwasmTickBudget $budget,
        int $maxPages
    ): array {
        $result = [
            'ok'                => false,
            'pages'             => 0,
            'ingested'          => 0,
            'newest_code_id'    => 0,
            'lowest_code_id'    => 0,
            'reached_watermark' => false,
            'anomaly'           => false,
            'http_code'         => 0,
            'error_kind'        => CosmwasmClassifier::KIND_TRANSPORT,
            'message'           => 'budget exhausted',
        ];

        $maxPages = max(1, $maxPages);
        $pageKey  = null;

        for ($page = 0; $page < $maxPages; $page++) {
            if (!$budget->canSpend()) {
                break;
            }

            $budget->spend();
            // First page starts the reverse walk; later pages continue it
            // with the key the node handed back.
            $response = $fetcher->listCodeFamilies($pageKey, $pageKey === null, CosmwasmDiscoveryGate::CODE_PAGE_SIZE);

            if (!$response['ok']) {
                $result['http_code']  = $response['http_code'];
                $result['error_kind'] = $response['error_kind'];
                $result['message']    = $response['message_excerpt'];

                return $result;
            }

            $result['ok']        = true;
            $result['http_code'] = 200;
            $result['error_kind'] = CosmwasmClassifier::KIND_NONE;
            $result['message']   = '';
            $result['pages']++;

            if ($response['families'] === []) {
                // Empty page. Only trustworthy when we had nothing to find.
                if ($watermark > 0) {
                    $result['anomaly'] = true;
                    $result['message'] = 'reverse tail read returned an empty page while watermark=' . $watermark;
                } else {
                    $result['reached_watermark'] = true;
                }

                return $result;
            }

            // Ingest the page FULLY before considering the watermark, so a
            // stop decision never leaves half a page unrecorded.
            CosmwasmCodeFamilyRepository::recordDiscovered($chainId, $response['families']);
            $result['ingested'] += count($response['families']);

            if ($response['max_code_id'] > $result['newest_code_id']) {
                $result['newest_code_id'] = $response['max_code_id'];
            }
            $result['lowest_code_id'] = $response['min_code_id'];

            if ($watermark > 0 && $response['min_code_id'] <= $watermark) {
                $result['reached_watermark'] = true;

                return $result;
            }

            if ($watermark === 0) {
                // Nothing inventoried yet: one page is enough to learn the
                // newest ids and seed the watermark. The HISTORICAL
                // BACKFILL owns the rest of the history.
                $result['reached_watermark'] = true;

                return $result;
            }

            $pageKey = $response['next_key'];
            if ($pageKey === null) {
                // Walked the entire listing without meeting the watermark:
                // the chain's ids only go down to here, so we have in fact
                // seen everything.
                $result['reached_watermark'] = true;

                return $result;
            }
        }

        // Ran out of pages or budget with the watermark still un-met.
        return $result;
    }

    /**
     * Classify ONE code family.
     *
     * Order of attempts, cheapest first:
     *
     *   A. CHECKSUM TWIN — if another family on this chain shares the
     *      checksum and is already settled, inherit its verdict for ZERO
     *      requests. Identical checksum means identical binary, so its
     *      answer about the BINARY is this family's answer. It is allowed
     *      to short-circuit nothing else: no collection is verified, no
     *      per-contract probe is skipped (contracts under this family are
     *      still probed one by one), no deny rule is bypassed, and no
     *      collection metadata is copied.
     *   B. SAMPLE — otherwise pull the family's FIRST contracts page and
     *      probe up to {@see CosmwasmDiscoveryGate::FAMILY_SAMPLE_SIZE}
     *      of its contracts. A family that comes back decisively
     *      non-NFT is CLOSED without enumerating the rest of its
     *      contracts — that is the whole point of sampling.
     *
     * The sampled contracts' own verdicts are persisted too, so the
     * per-contract pass never re-probes them.
     *
     * @param  CodeFamilyRow $family
     * @return array{classification: string, requests: int}
     */
    public static function classifyFamily(
        int $chainId,
        CosmosFetcher $fetcher,
        object $family,
        CosmwasmTickBudget $budget
    ): array {
        $codeId     = (int) $family->code_id;
        $checksum   = is_string($family->checksum ?? null) ? (string) $family->checksum : '';
        $retryCount = (int) $family->retry_count;

        // ── A. checksum twin ────────────────────────────────────────────
        if ($checksum !== '') {
            $twin = CosmwasmCodeFamilyRepository::findClassifiedTwinByChecksum(
                $chainId,
                $checksum,
                $codeId,
                CosmwasmClassifier::VERSION
            );
            if ($twin !== null) {
                $verdict = CosmwasmClassifier::checksumTwinVerdict(
                    (string) $twin->classification,
                    (int) $twin->code_id
                );
                // sample_contract stays NULL: the twin's sample belongs to
                // the twin. Nothing about the OTHER family's instances —
                // its addresses, its names, its artwork — is inherited.
                CosmwasmCodeFamilyRepository::recordClassification(
                    $chainId,
                    $codeId,
                    $verdict,
                    null,
                    $retryCount
                );
                if ($verdict['classification'] === CosmwasmClassifier::NOT_CW721) {
                    CosmwasmCodeFamilyRepository::closeEnumeration($chainId, $codeId);
                }

                return ['classification' => $verdict['classification'], 'requests' => 0];
            }
        }

        // ── B. sample ───────────────────────────────────────────────────
        if (!$budget->canSpend()) {
            return ['classification' => (string) $family->classification, 'requests' => 0];
        }

        $budget->spend();
        $page = $fetcher->listContractsForCodeId($codeId, null, false);

        if (!$page['ok']) {
            // The NODE failed, which says nothing about the family. Record
            // the attempt and back off; never settle a verdict on this.
            CosmwasmCodeFamilyRepository::recordAttemptFailure(
                $chainId,
                $codeId,
                $page['message_excerpt'] !== '' ? $page['message_excerpt'] : $page['error_kind'],
                $retryCount + 1
            );

            return ['classification' => CosmwasmClassifier::UNREACHABLE, 'requests' => 1];
        }

        // Never waste a page: everything it returned is inventoried (and
        // deny-filtered) before we sample from it.
        self::recordContracts($chainId, $codeId, $page['contracts']);

        if ($page['contracts'] === []) {
            $verdict = CosmwasmClassifier::noContractsVerdict();
            CosmwasmCodeFamilyRepository::recordClassification(
                $chainId,
                $codeId,
                $verdict,
                null,
                $retryCount + 1
            );
            CosmwasmCodeFamilyRepository::closeEnumeration($chainId, $codeId);

            return ['classification' => $verdict['classification'], 'requests' => 1];
        }

        $samples   = array_slice($page['contracts'], 0, CosmwasmDiscoveryGate::FAMILY_SAMPLE_SIZE);
        $requests  = 1;
        $verdicts  = [];
        $sampledAt = null;

        foreach ($samples as $contract) {
            // Three probes worst case; do not start one we cannot finish.
            if (!$budget->canSpend(3)) {
                break;
            }
            $outcomes = $fetcher->probeCw721($contract);
            $budget->spend(count($outcomes));
            $requests += count($outcomes);

            $verdict    = CosmwasmClassifier::classify($outcomes);
            $verdicts[] = $verdict['classification'];
            $sampledAt  = $sampledAt ?? $contract;

            // The sample is a contract in its own right — persist its
            // verdict so the per-contract pass never re-probes it.
            CosmwasmContractRepository::recordClassification($chainId, $contract, $verdict, 0);

            // A single confirmed instance settles the family; stop paying
            // for more samples.
            if ($verdict['classification'] === CosmwasmClassifier::CONFIRMED) {
                break;
            }
        }

        if ($verdicts === []) {
            return ['classification' => (string) $family->classification, 'requests' => $requests];
        }

        $familyClassification = self::reduceSampleVerdicts($verdicts);
        $familyVerdict        = [
            'classification'     => $familyClassification,
            'reason'             => 'sampled:' . count($verdicts),
            'probes_ok'          => '',
            'probes_failed'      => '',
            'last_error'         => '',
            'classifier_version' => CosmwasmClassifier::VERSION,
        ];

        CosmwasmCodeFamilyRepository::recordClassification(
            $chainId,
            $codeId,
            $familyVerdict,
            $sampledAt,
            $retryCount + 1
        );

        if ($familyClassification === CosmwasmClassifier::NOT_CW721) {
            // THE SETTLE-WITHOUT-SCANNING GUARANTEE: the remaining
            // contracts under this family are never requested.
            CosmwasmCodeFamilyRepository::closeEnumeration($chainId, $codeId);
        }

        return ['classification' => $familyClassification, 'requests' => $requests];
    }

    /**
     * PURE. Reduce per-sample verdicts to ONE family verdict.
     *
     * Precedence, strongest first:
     *   confirmed > probable > temporarily_unreachable > inconclusive >
     *   not_cw721
     *
     * `not_cw721` is LAST, not first: one live CW-721 instance proves the
     * family implements CW-721 even if a sibling instantiation is dead,
     * and a node failure on one sample must not out-vote a positive
     * answer from another. A family only settles negative when EVERY
     * sample said so decisively.
     *
     * @param list<string> $verdicts
     */
    public static function reduceSampleVerdicts(array $verdicts): string
    {
        if ($verdicts === []) {
            return CosmwasmClassifier::INCONCLUSIVE;
        }
        foreach ([
            CosmwasmClassifier::CONFIRMED,
            CosmwasmClassifier::PROBABLE,
            CosmwasmClassifier::UNREACHABLE,
            CosmwasmClassifier::INCONCLUSIVE,
        ] as $preferred) {
            if (in_array($preferred, $verdicts, true)) {
                return $preferred;
            }
        }

        return CosmwasmClassifier::NOT_CW721;
    }

    /**
     * Walk one FORWARD page of a family's contract listing and inventory
     * it. The historical (and resumable) direction.
     *
     * ── STALE OPAQUE CURSOR ─────────────────────────────────────────────
     * `pagination.key` is minted BY A NODE, and `rest.cosmos.directory`
     * round-robins across nodes, so a key stored from one node may be
     * rejected or misread by the next. A resumed page that comes back
     * FAILED, or EMPTY-and-final, is therefore not evidence that the walk
     * finished — accepting it as `complete` would silently truncate the
     * family. Both cases clear the cursor and report `restarted`, so the
     * next pass re-walks the family from the beginning. That is cheap and
     * safe: every write is idempotent under `uk_chain_contract`.
     *
     * @param  CodeFamilyRow $family
     * @return array{ok: bool, seen: int, fresh: int, next_key: string|null, complete: bool, restarted: bool}
     */
    public static function enumerateFamilyPage(
        int $chainId,
        CosmosFetcher $fetcher,
        object $family,
        CosmwasmTickBudget $budget
    ): array {
        $codeId = (int) $family->code_id;
        $cursor = is_string($family->contracts_cursor ?? null) && $family->contracts_cursor !== ''
            ? (string) $family->contracts_cursor
            : null;
        $position = (int) $family->contracts_enumerated;

        $idle = [
            'ok'        => false,
            'seen'      => 0,
            'fresh'     => 0,
            'next_key'  => $cursor,
            'complete'  => false,
            'restarted' => false,
        ];

        if (!$budget->canSpend()) {
            return $idle;
        }

        $budget->spend();
        $page = $fetcher->listContractsForCodeId($codeId, $cursor, false);

        if (!$page['ok']) {
            if ($cursor !== null) {
                // A resumed key that the node would not accept. Drop it and
                // restart from the beginning next pass rather than leaving a
                // poisoned cursor that fails forever.
                CosmwasmCodeFamilyRepository::recordEnumerationProgress($chainId, $codeId, null, $position, false);
                \BCC\Core\Log\Logger::warning('[CosmwasmDiscovery] contract cursor rejected — restarting family walk', [
                    'chain_id' => $chainId,
                    'code_id'  => $codeId,
                    'error'    => $page['message_excerpt'],
                ]);
                $idle['next_key']  = null;
                $idle['restarted'] = true;
            }

            return $idle;
        }

        // A RESUMED page that is empty AND final is indistinguishable on
        // the wire from a key the new node simply could not interpret. We
        // already knew this family had contracts (the cursor only exists
        // because an earlier page returned some), so treat it as a stale
        // key and restart rather than declaring the family drained.
        if ($cursor !== null && $page['contracts'] === [] && $page['next_key'] === null && $position > 0) {
            CosmwasmCodeFamilyRepository::recordEnumerationProgress($chainId, $codeId, null, $position, false);
            \BCC\Core\Log\Logger::warning('[CosmwasmDiscovery] resumed contract page was empty — treating cursor as stale', [
                'chain_id' => $chainId,
                'code_id'  => $codeId,
                'position' => $position,
            ]);

            return [
                'ok'        => true,
                'seen'      => 0,
                'fresh'     => 0,
                'next_key'  => null,
                'complete'  => false,
                'restarted' => true,
            ];
        }

        $seen  = count($page['contracts']);
        $fresh = self::recordContracts($chainId, $codeId, $page['contracts']);

        $nextKey  = $page['next_key'];
        $complete = $nextKey === null;

        // SAFE PROGRESS, WRITTEN NOW. The absolute high-water mark plus the
        // cursor are persisted before the tick can be cut short, so the
        // next invocation resumes here instead of restarting the family.
        CosmwasmCodeFamilyRepository::recordEnumerationProgress(
            $chainId,
            $codeId,
            $nextKey,
            $position + $seen,
            $complete
        );

        return [
            'ok'        => true,
            'seen'      => $seen,
            'fresh'     => $fresh,
            'next_key'  => $nextKey,
            'complete'  => $complete,
            'restarted' => false,
        ];
    }

    /**
     * INCREMENTAL check for newly-instantiated contracts under a family
     * whose forward walk already DRAINED — a REVERSE walk stopping at the
     * first address we already hold.
     *
     * wasmd's contracts-by-code index is ordered by instantiation, so
     * `pagination.reverse=true` yields the newest instantiations first.
     * Because the family's forward walk completed, everything older than
     * the first already-known address is necessarily inventoried too, so
     * meeting one known address is a sound stop condition — the exact
     * analogue of the numeric code-id watermark.
     *
     * `pagination.offset` is NOT used, here or anywhere: it returns an
     * empty 200 on four of the five chains measured (see
     * {@see CosmosFetcher::listCodeFamilies()}).
     *
     * ── AN EMPTY PAGE IS NOT AUTHORITATIVE ──────────────────────────────
     * A drained family with `contracts_enumerated > 0` HAS contracts. If
     * the reverse read comes back empty, the node did not answer the
     * question we asked (unsupported `reverse`, a shard with no data, a
     * round-robin peer). Concluding "no new contracts" would be the same
     * fake-healthy failure the offset bug had. Instead the family is
     * REOPENED for a forward re-walk, which is idempotent under
     * `uk_chain_contract`.
     *
     * @param  CodeFamilyRow $family
     * @return array{ok: bool, seen: int, fresh: int, reached_known: bool, anomaly: bool, pages: int}
     */
    public static function enumerateFamilyTail(
        int $chainId,
        CosmosFetcher $fetcher,
        object $family,
        CosmwasmTickBudget $budget,
        int $maxPages
    ): array {
        $codeId   = (int) $family->code_id;
        $position = (int) $family->contracts_enumerated;
        $result   = ['ok' => false, 'seen' => 0, 'fresh' => 0, 'reached_known' => false, 'anomaly' => false, 'pages' => 0];

        $maxPages = max(1, $maxPages);
        $pageKey  = null;

        for ($page = 0; $page < $maxPages; $page++) {
            if (!$budget->canSpend()) {
                break;
            }

            $budget->spend();
            $response = $fetcher->listContractsForCodeId($codeId, $pageKey, $pageKey === null);

            if (!$response['ok']) {
                return $result;
            }

            $result['ok'] = true;
            $result['pages']++;

            if ($response['contracts'] === []) {
                if ($position > 0) {
                    // Contradicts what we already know — do NOT call this
                    // "nothing new".
                    $result['anomaly'] = true;
                    self::reopenFamilyEnumeration($chainId, $codeId, $position, 'reverse tail read returned an empty page');
                } else {
                    $result['reached_known'] = true;
                }

                return $result;
            }

            $known = CosmwasmContractRepository::knownMap($chainId, $response['contracts']);

            // Ingest the page FULLY before deciding to stop.
            $result['seen']  += count($response['contracts']);
            $result['fresh'] += self::recordContracts($chainId, $codeId, $response['contracts']);

            if ($known !== []) {
                $result['reached_known'] = true;

                return $result;
            }

            $pageKey = $response['next_key'];
            if ($pageKey === null) {
                // Walked the whole (short) listing — everything is seen.
                $result['reached_known'] = true;

                return $result;
            }
        }

        // Budget/pages exhausted without meeting a known address (every
        // path that DOES meet one returns above): the family gained more
        // contracts than one pass can tail. Reopen the forward walk, which
        // is the mechanism built for volume.
        if ($result['ok']) {
            self::reopenFamilyEnumeration($chainId, $codeId, $position, 'reverse tail read did not reach a known contract');
        }

        return $result;
    }

    /**
     * Put a family back into forward-walk mode.
     *
     * Clears `enumeration_complete` and the cursor so the ordinary
     * incomplete-family query picks it up and re-walks it from the start.
     * Re-walking is safe by construction: `uk_chain_contract` makes every
     * write idempotent, and the deny flag is re-resolved on the way
     * through {@see recordContracts()}.
     */
    private static function reopenFamilyEnumeration(int $chainId, int $codeId, int $position, string $reason): void
    {
        CosmwasmCodeFamilyRepository::recordEnumerationProgress($chainId, $codeId, null, $position, false);
        \BCC\Core\Log\Logger::warning('[CosmwasmDiscovery] reopening family forward walk', [
            'chain_id' => $chainId,
            'code_id'  => $codeId,
            'reason'   => $reason,
        ]);
    }

    /**
     * Inventory a batch of contract addresses under a code family.
     *
     * DENY POINT 1. Every address is resolved against the operator rule
     * table before it is written. A denied address is stored with
     * `denied = 1` rather than dropped, deliberately: dropping it would
     * make it look brand-new on the next sweep, so it would be
     * rediscovered and re-logged as a fresh candidate forever. Stored-
     * and-suppressed is what makes the rule survive rediscovery.
     *
     * At this point we have no collection NAME, so only the rule table
     * can speak — the name heuristics get their turn at emit time, when
     * a name exists.
     *
     * @param  list<string> $contracts
     * @return int how many were NOT already inventoried
     */
    public static function recordContracts(int $chainId, int $codeId, array $contracts): int
    {
        if ($contracts === [] || $chainId <= 0) {
            return 0;
        }

        $known = CosmwasmContractRepository::knownMap($chainId, $contracts);

        $rows  = [];
        $fresh = 0;
        foreach ($contracts as $address) {
            $lower = strtolower(trim($address));
            if ($lower === '') {
                continue;
            }
            if (!isset($known[$lower])) {
                $fresh++;
            }
            $rows[] = [
                'contract_address' => $lower,
                'denied'           => NftSpamFilter::isSpam($chainId, $lower, null),
            ];
        }

        if ($rows !== []) {
            CosmwasmContractRepository::recordDiscovered($chainId, $codeId, $rows);
        }

        return $fresh;
    }

    /**
     * Classify ONE contract.
     *
     * The caller supplies rows from
     * {@see CosmwasmContractRepository::findPendingClassification()},
     * which already excludes denied rows — DENY POINT 2. A denied
     * contract never burns a probe.
     *
     * @param  CosmwasmContractRow $row
     * @return array{classification: string, requests: int}
     */
    public static function classifyContract(
        int $chainId,
        CosmosFetcher $fetcher,
        object $row,
        CosmwasmTickBudget $budget
    ): array {
        $contract   = (string) $row->contract_address;
        $retryCount = (int) $row->retry_count;

        if (!$budget->canSpend(3)) {
            return ['classification' => (string) $row->classification, 'requests' => 0];
        }

        $outcomes = $fetcher->probeCw721($contract);
        $budget->spend(max(1, count($outcomes)));

        if ($outcomes === []) {
            CosmwasmContractRepository::recordAttemptFailure(
                $chainId,
                $contract,
                'probe produced no outcomes',
                $retryCount + 1
            );

            return ['classification' => CosmwasmClassifier::UNREACHABLE, 'requests' => 1];
        }

        $verdict = CosmwasmClassifier::classify($outcomes);
        CosmwasmContractRepository::recordClassification($chainId, $contract, $verdict, $retryCount + 1);

        return ['classification' => $verdict['classification'], 'requests' => count($outcomes)];
    }

    /**
     * Emit classified CW-721 contracts toward `wp_bcc_onchain_collections`.
     *
     * THE ONLY PATH that can create a user-facing row, and therefore the
     * one that carries the strongest guarantees:
     *
     *   - DENY POINT 3. The queue already excludes `denied = 1`, and each
     *     row is re-checked against the LIVE rule table WITH its resolved
     *     collection name (so the name heuristics apply and `RULE_ALLOW`
     *     can override them). A rule that landed since the row was
     *     inventoried takes effect immediately, and the inventory's
     *     cached flag is corrected.
     *   - ANTI-CLOBBER. A contract that already has a collection row is
     *     skipped. `CollectionRepository::bulkUpsert` assigns
     *     `collection_name = VALUES(collection_name)` and
     *     `image_url = VALUES(image_url)`; a discovery row carries the
     *     freshly-probed name but no artwork, so re-emitting an
     *     operator-curated row would wipe its image.
     *   - UNVERIFIED. Rows land on the schema default `is_verified = 0`.
     *     Nothing here sets the flag and nothing here calls a
     *     provisioning service — discovery cannot verify, and it cannot
     *     provision.
     *
     * @return array{emitted: int, denied: int, skipped_known: int}
     */
    public static function emitCollections(
        int $chainId,
        CosmosFetcher $fetcher,
        CosmwasmTickBudget $budget,
        int $limit
    ): array {
        $result = ['emitted' => 0, 'denied' => 0, 'skipped_known' => 0];

        $candidates = CosmwasmContractRepository::findEmittable($chainId, $limit);
        if ($candidates === []) {
            return $result;
        }

        $addresses = [];
        foreach ($candidates as $row) {
            $addresses[] = (string) $row->contract_address;
        }
        $knownCollections = CollectionRepository::verifiedMapForContracts($chainId, $addresses);
        $knownNormalized  = [];
        foreach ($knownCollections as $address => $_ignored) {
            $knownNormalized[strtolower((string) $address)] = true;
        }

        $rows = [];

        /** @var array<string, string> $pendingDescriptions contract => sanitized description */
        $pendingDescriptions = [];
        foreach ($candidates as $row) {
            if ($budget->exhausted()) {
                break;
            }

            $contract = strtolower((string) $row->contract_address);

            if (isset($knownNormalized[$contract])) {
                // Already curated/known — mark so we stop re-considering it,
                // and never re-upsert over the operator's name + artwork.
                CosmwasmContractRepository::markCollectionRowWritten($chainId, $contract);
                $result['skipped_known']++;
                continue;
            }

            $budget->spend();
            $info = $fetcher->fetchContractInfo($contract);
            $name = is_array($info) && is_string($info['name'] ?? null) ? (string) $info['name'] : null;

            // Live re-check WITH the name: rule table first (DENY wins,
            // ALLOW beats the heuristics), then the name heuristics.
            if (NftSpamFilter::isSpam($chainId, $contract, $name)) {
                CosmwasmContractRepository::setDenied($chainId, $contract, true);
                $result['denied']++;
                continue;
            }

            if ($info === null) {
                // The contract did not answer right now. Leave it queued —
                // no row, no mark, retried next pass.
                continue;
            }

            // ── ⚠ PR 7: CAPTURED FROM THE RESPONSE ALREADY FETCHED ──────
            // `$info` is the return of the single `fetchContractInfo()` call
            // above. Symbol, description and image were already in it and
            // were being discarded; keeping them costs ZERO extra provider
            // requests, which is the condition this capture had to meet.
            //
            // They describe the EXACT contract queried, so they are
            // collection-level by construction — never sampled from a member
            // token, never matched by name similarity.
            //
            // ⚠ `total_supply` stays NULL. `num_tokens` is probed by
            // CosmwasmClassifier during CLASSIFICATION, but that runs per
            // code family, discards the count, and is a different pass — so
            // reading a size here would mean a NEW request per contract.
            // "Do not add provider requests merely to enrich metadata" wins;
            // an unknown size stays unknown rather than becoming a guess.
            $rows[] = [
                'contract_address'   => $contract,
                'chain_id'           => $chainId,
                'collection_name'    => $name,
                'collection_symbol'  => $info['symbol'] ?? null,
                'token_standard'     => 'CW-721',
                'total_supply'       => null,
                'floor_price'        => null,
                'floor_currency'     => null,
                'unique_holders'     => null,
                'total_volume'       => null,
                'listed_percentage'  => null,
                'royalty_percentage' => null,
                'metadata_storage'   => null,
                'image_url'          => $info['image_url'] ?? null,
            ];

            // The description is imported SEPARATELY, after the upsert, so it
            // lands as `pending` review rather than as a public field. It is
            // untrusted evidence written by a contract author.
            if (($info['description'] ?? null) !== null) {
                $pendingDescriptions[$contract] = (string) $info['description'];
            }
        }

        if ($rows === []) {
            return $result;
        }

        CollectionRepository::bulkUpsert($rows, 4 * HOUR_IN_SECONDS);

        foreach ($rows as $row) {
            $contractAddress = (string) $row['contract_address'];

            // ⚠ PR 7: the imported description lands as PENDING review, never
            // as public text. It is resolved AFTER the upsert because the row
            // id only exists once the upsert has run, and it is deliberately
            // best-effort: a description that cannot be stored must not fail
            // the discovery pass that produced a perfectly good collection.
            if (isset($pendingDescriptions[$contractAddress])) {
                $existing     = CollectionRepository::findByChainContract($chainId, $contractAddress);
                $collectionId = $existing !== null ? (int) $existing->id : 0;
                if ($collectionId > 0) {
                    CollectionRepository::importChainDescription(
                        $collectionId,
                        $pendingDescriptions[$contractAddress],
                        'cosmwasm'
                    );
                }
            }

            CosmwasmContractRepository::markCollectionRowWritten($chainId, $contractAddress);
            $result['emitted']++;
        }

        return $result;
    }

    /**
     * Re-sync the cached deny flag for a batch of contracts.
     *
     * DENY POINT 4. Hiding a collection writes `RULE_DENY`; that must
     * suppress the candidate on the inventory too, or the next sweep
     * would re-emit it. Unhiding writes `RULE_ALLOW`, which clears the
     * flag and genuinely PERMITS later discovery / an explicit retry
     * rather than leaving the contract permanently suppressed.
     *
     * Bounded to 200 contracts by the slice below, which is why the
     * hide/unhide caller can pass its one affected contract straight in
     * and no caller ever needs a full-inventory pass.
     *
     * THE RETURN VALUE IS NOT A SUCCESS SIGNAL. 0 legitimately means
     * "every flag was already correct". A caller that must know whether
     * the cache actually agrees with the rule has to read the flag back —
     * see
     * {@see \BCC\Trust\Onchain\Admin\VerifyCollectionsPage::syncScannerDenyFlag()}.
     *
     * @param  list<string> $contracts
     * @return int rows whose flag changed
     */
    public static function syncDenyFlags(int $chainId, array $contracts): int
    {
        $changed = 0;
        foreach (array_slice($contracts, 0, 200) as $contract) {
            $lower = strtolower(trim($contract));
            if ($lower === '') {
                continue;
            }
            $row = CosmwasmContractRepository::find($chainId, $lower);
            if ($row === null) {
                continue;
            }
            $denied = NftSpamFilter::isSpam($chainId, $lower, null);
            if (((int) $row->denied === 1) !== $denied) {
                CosmwasmContractRepository::setDenied($chainId, $lower, $denied);
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * "Monthly" migration check for ONE family's sampled contract.
     *
     * A CosmWasm contract can be MIGRATED to a different code id while
     * keeping its address. When that happens we re-point the inventory
     * row — we do NOT create a second contract row and we do NOT create a
     * second collection: the address is the identity, and the collection
     * for that address already exists.
     *
     * @param  CodeFamilyRow $family
     * @return array{migrated: bool, requests: int}
     */
    public static function checkFamilyMigration(
        int $chainId,
        CosmosFetcher $fetcher,
        object $family,
        CosmwasmTickBudget $budget
    ): array {
        $sample = is_string($family->sample_contract ?? null) ? (string) $family->sample_contract : '';
        $codeId = (int) $family->code_id;

        if ($sample === '' || !$budget->canSpend()) {
            CosmwasmCodeFamilyRepository::touchMetadataChecked($chainId, $codeId);

            return ['migrated' => false, 'requests' => 0];
        }

        $budget->spend();
        $currentCodeId = $fetcher->fetchContractCodeId($sample);

        CosmwasmCodeFamilyRepository::touchMetadataChecked($chainId, $codeId);

        if ($currentCodeId === null || $currentCodeId === $codeId) {
            return ['migrated' => false, 'requests' => 1];
        }

        CosmwasmContractRepository::recordMigration($chainId, $sample, $currentCodeId);

        return ['migrated' => true, 'requests' => 1];
    }

    /**
     * Translate a code-listing failure into a durable per-chain state.
     *
     * `not_implemented` is the ONLY durable negative: cryptoorgchain
     * answers the wasmd endpoints with HTTP 501 because it has no wasm
     * module (measured), and retrying it on every pass forever would
     * burn budget on a guaranteed failure. Everything else — a 502 from
     * a down LCD (kujira, measured), a timeout, a VM error — leaves the
     * chain's state alone so it is retried.
     */
    public static function isUnsupportedChainError(string $errorKind, int $httpCode): bool
    {
        return $httpCode === 501 || $errorKind === 'not_implemented';
    }

    /**
     * Per-chain progress summary for an admin surface.
     *
     * Every number here comes from a BOUNDED AGGREGATE — a handful of
     * indexed COUNT/GROUP BY reads — never a per-row recompute, and
     * never a percentage: `pagination.count_total` is honoured by only
     * one of the nine cosmos chains (measured), so there is no
     * trustworthy denominator to divide by. Progress is expressed as a
     * cursor position, a max-code-id watermark and counts.
     *
     * @return array{
     *     state: string,
     *     max_code_id: int,
     *     backfill_completed_at: string|null,
     *     last_discovery_at: string|null,
     *     metadata_refreshed_at: string|null,
     *     last_error: string|null,
     *     families_total: int,
     *     families_pending: int,
     *     families_by_classification: array<string, int>,
     *     contracts_total: int,
     *     contracts_denied: int,
     *     contracts_by_classification: array<string, int>
     * }
     */
    public static function chainSummary(int $chainId): array
    {
        $checkpoint = ChainCheckpointRepository::get($chainId);

        return [
            'state'                       => $checkpoint !== null
                ? (string) $checkpoint->cw_discovery_state
                : ChainCheckpointRepository::CW_STATE_IDLE,
            'max_code_id'                 => $checkpoint !== null ? (int) $checkpoint->cw_max_code_id : 0,
            'backfill_completed_at'       => $checkpoint !== null
                ? (is_string($checkpoint->cw_backfill_completed_at) ? $checkpoint->cw_backfill_completed_at : null)
                : null,
            'last_discovery_at'           => $checkpoint !== null
                ? (is_string($checkpoint->cw_last_discovery_at) ? $checkpoint->cw_last_discovery_at : null)
                : null,
            'metadata_refreshed_at'       => $checkpoint !== null
                ? (is_string($checkpoint->cw_metadata_refreshed_at) ? $checkpoint->cw_metadata_refreshed_at : null)
                : null,
            'last_error'                  => $checkpoint !== null
                ? (is_string($checkpoint->cw_last_error) ? $checkpoint->cw_last_error : null)
                : null,
            'families_total'              => CosmwasmCodeFamilyRepository::countForChain($chainId),
            'families_pending'            => CosmwasmCodeFamilyRepository::countPendingClassification(
                $chainId,
                CosmwasmClassifier::VERSION
            ),
            'families_by_classification'  => CosmwasmCodeFamilyRepository::countsByClassification($chainId),
            'contracts_total'             => CosmwasmContractRepository::countForChain($chainId),
            'contracts_denied'            => CosmwasmContractRepository::countDenied($chainId),
            'contracts_by_classification' => CosmwasmContractRepository::countsByClassification($chainId),
        ];
    }
}
