<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Admin\VerifyCollectionsPage;
use BCC\Trust\Onchain\Services\ContractValidator;
use BCC\Trust\Onchain\Services\ManualCollectionIntakeService;
use BCC\Trust\Onchain\ValueObjects\ChainDescriptionState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The PR E fail-closed boundaries, through the real services.
 *
 * The per-probe suites prove each verdict in isolation. These prove the
 * consequences an operator actually sees: **no provider request, and no
 * collection row.**
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PrEFailClosedBoundariesTest extends TestCase
{
    private const OPERATOR = 7;
    private const EVM_ADDRESS = '0x1234567890abcdef1234567890abcdef12345678';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/verify-collections-stubs.php';

        \BccAdminTestState::reset();
        \BccTransientStore::reset();
        \BCC\Trust\Onchain\Fetchers\CosmosFetcher::reset();
        \BCC\Trust\Onchain\Fetchers\EvmFetcher::reset();
        \BCC\Trust\Onchain\Fetchers\SolanaFetcher::reset();
        \BCC\Trust\Onchain\Repositories\CollectionRepository::reset();
        \BCC\Trust\Core\Security\AuditLogger::reset();
        \BCC\Trust\Onchain\Repositories\ChainRepository::$chains = [];

        $_POST = $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
    }

    private function seedChain(int $id, string $slug, string $family): void
    {
        \BCC\Trust\Onchain\Repositories\ChainRepository::seed($id, $slug, $family);
        $chain = \BCC\Trust\Onchain\Repositories\ChainRepository::$chains[$id];
        $chain->bcc_supports_nft_collections        = 1;
        $chain->manual_collection_discovery_enabled = 1;
    }

    // ── Blocker 2: the launch allowlist, end to end ─────────────────────

    /** @return array<string, array{0: int, 1: string}> */
    public static function nonLaunchEvmChains(): array
    {
        return [
            'polygon'   => [40, 'polygon'],
            'arbitrum'  => [41, 'arbitrum'],
            'optimism'  => [42, 'optimism'],
            'bsc'       => [43, 'bsc'],
            'avalanche' => [44, 'avalanche'],
        ];
    }

    /**
     * ⚠⚠⚠ A NON-LAUNCH EVM CHAIN REACHES NO PROVIDER AT ALL.
     *
     * The gate sits before the fetcher is built, so an unapproved chain costs
     * zero requests — it does not fail late, at metadata time, after the calls
     * have already been made and charged.
     */
    #[DataProvider('nonLaunchEvmChains')]
    public function testANonLaunchEvmChainNeverReachesTransport(int $chainId, string $slug): void
    {
        $this->seedChain($chainId, $slug, 'evm');

        $verdict = (new ContractValidator())->validate(
            \BCC\Trust\Onchain\Repositories\ChainRepository::$chains[$chainId],
            self::EVM_ADDRESS
        );

        self::assertFalse($verdict->mayPersist());
        self::assertSame(
            0,
            \BCC\Trust\Onchain\Fetchers\EvmFetcher::$calls,
            "{$slug} must not cost a single provider request"
        );
    }

    #[DataProvider('nonLaunchEvmChains')]
    public function testANonLaunchEvmChainCannotCreateACollection(int $chainId, string $slug): void
    {
        $this->seedChain($chainId, $slug, 'evm');

        $result = (new ManualCollectionIntakeService())
            ->add('evm', $chainId, self::EVM_ADDRESS, self::OPERATOR);

        self::assertFalse($result['ok'], "{$slug} must not accept a collection");
        self::assertSame(
            [],
            \BCC\Trust\Onchain\Repositories\CollectionRepository::$added,
            'no row may be written for an unapproved chain'
        );
        self::assertSame(0, \BCC\Trust\Onchain\Fetchers\EvmFetcher::$calls);
    }

    /** @return array<string, array{0: int, 1: string}> */
    public static function launchEvmChains(): array
    {
        return [
            'ethereum' => [4, 'ethereum'],
            'base'     => [5, 'base'],
        ];
    }

    #[DataProvider('launchEvmChains')]
    public function testTheTwoLaunchChainsDoReachTransportAndCanBeAccepted(int $chainId, string $slug): void
    {
        $this->seedChain($chainId, $slug, 'evm');

        $result = (new ManualCollectionIntakeService())
            ->add('evm', $chainId, self::EVM_ADDRESS, self::OPERATOR);

        self::assertTrue($result['ok'], "{$slug} is an approved launch chain");
        self::assertGreaterThan(0, \BCC\Trust\Onchain\Fetchers\EvmFetcher::$calls);
        self::assertCount(1, \BCC\Trust\Onchain\Repositories\CollectionRepository::$added);
    }

    // ── Blocker 1: EVM metadata failure persists nothing ────────────────

    public function testAnEvmMetadataFailureCreatesNoCollection(): void
    {
        $this->seedChain(4, 'ethereum', 'evm');
        \BCC\Trust\Onchain\Fetchers\EvmFetcher::$metadataKind = 'credentials_missing';

        $result = (new ManualCollectionIntakeService())
            ->add('evm', 4, self::EVM_ADDRESS, self::OPERATOR);

        self::assertFalse($result['ok']);
        self::assertSame(ManualCollectionIntakeService::REFUSED_UNAVAILABLE, $result['reason']);
        self::assertSame(
            [],
            \BCC\Trust\Onchain\Repositories\CollectionRepository::$added,
            'an unreadable metadata response must not leave an empty row that looks reviewed'
        );
    }

    // ── Blocker 6: the review decision and its audit are atomic ─────────

    private function primeReview(int $collectionId): void
    {
        \BCC\Trust\Core\Security\TransactionManager::$runs      = 0;
        \BCC\Trust\Core\Security\TransactionManager::$rollbacks = 0;

        $_POST['collection_id']    = (string) $collectionId;
        $_REQUEST['collection_id'] = (string) $collectionId;
        \BccAdminTestState::$validNonceAction =
            VerifyCollectionsPage::ACTION_DESC_APPROVE . '_' . $collectionId;
    }

    /**
     * Run the approve handler. It finishes with a PRG redirect, which the
     * admin stubs raise as an exception to stop execution exactly as
     * `wp_safe_redirect() + exit` would in production.
     */
    private function runApprove(): void
    {
        try {
            VerifyCollectionsPage::handleDescriptionApprovePost();
        } catch (\BccAdminRedirect) {
            // expected — the handler always ends in a redirect
        }
    }

    /** @return list<array{type: string, message: string}> */
    private function notices(): array
    {
        return \BccTransientStore::$data['bcc_vc_notices_' . \BccAdminTestState::$userId] ?? [];
    }

    /**
     * ⚠⚠⚠ A FAILED AUDIT MUST ROLL THE STATE BACK.
     *
     * Before this, the state moved and THEN the audit was written, so a failed
     * audit left a description approved with no record of who decided it. An
     * unattributable review decision is exactly what the checked-audit
     * contract exists to prevent.
     */
    public function testAFailedAuditRollsTheDescriptionStateBack(): void
    {
        $this->primeReview(99);
        \BCC\Trust\Core\Security\AuditLogger::$failChecked = true;

        $this->runApprove();

        self::assertSame(
            1,
            \BCC\Trust\Core\Security\TransactionManager::$rollbacks,
            'the audit failure must roll the transaction back'
        );
        self::assertSame(
            [],
            \BCC\Trust\Onchain\Repositories\CollectionRepository::$descriptionTransitions,
            'the state change must not survive a failed audit'
        );
    }

    public function testAFailedAuditReportsNoSuccessToTheOperator(): void
    {
        $this->primeReview(99);
        \BCC\Trust\Core\Security\AuditLogger::$failChecked = true;

        $this->runApprove();

        foreach ($this->notices() as $n) {
            self::assertNotSame(
                'success',
                $n['type'] ?? '',
                'nothing may report success when the decision could not be attributed'
            );
        }
    }

    public function testASuccessfulReviewMovesTheStateOnceFromPending(): void
    {
        $this->primeReview(99);

        $this->runApprove();

        $transitions = \BCC\Trust\Onchain\Repositories\CollectionRepository::$descriptionTransitions;
        self::assertCount(1, $transitions);
        self::assertSame(ChainDescriptionState::PENDING, $transitions[0][1]);
        self::assertSame(ChainDescriptionState::APPROVED, $transitions[0][2]);
        self::assertSame(0, \BCC\Trust\Core\Security\TransactionManager::$rollbacks);
    }

    // ── Blocker 8: approval does not publish (DECISION 17) ──────────────

    public function testApprovalIsNotDescribedAsPublication(): void
    {
        $this->primeReview(99);

        $this->runApprove();

        $text = strtolower(implode(' ', array_column($this->notices(), 'message')));
        self::assertNotSame('', $text, 'the operator must be told what happened');
        self::assertStringContainsString('not published', $text);
        self::assertStringNotContainsString('is shown as', $text);
    }

    /**
     * `findApprovedChainDescription()` having no public consumer is the
     * SETTLED design (DECISION 17), not an oversight. Pinned so a future
     * reader does not "fix" it by wiring a public reader.
     */
    public function testTheApprovedDescriptionReaderHasNoPublicConsumerByDesign(): void
    {
        $root = dirname(__DIR__, 2);
        $hits = [];

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/app', \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if (!$f->isFile() || strtolower($f->getExtension()) !== 'php') {
                continue;
            }
            // Strip comments so a {@see} reference is not mistaken for a call.
            $code = '';
            foreach (token_get_all((string) file_get_contents($f->getPathname())) as $t) {
                if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= is_array($t) ? $t[1] : $t;
            }
            if (str_contains($code, 'findApprovedChainDescription(')
                && !str_contains($f->getPathname(), 'CollectionRepository.php')
            ) {
                $hits[] = $f->getPathname();
            }
        }

        self::assertSame(
            [],
            $hits,
            'DECISION 17 keeps the description unpublished during retirement; '
            . 'a public reader is not to be built merely to stop the field looking unused'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    //  THE PER-FAMILY CEILINGS ARE THE BOUND — ASSERT THEM (round 4)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * ⚠⚠⚠ SOLANA NEEDS THREE, AND A CEILING OF TWO SILENTLY DISABLES THE cNFT
     * EXCLUSION.
     *
     * The three are: `getAssetsByGroup` (verified membership + the group
     * total), `searchAssets` with the documented `compressed` filter (does ANY
     * compressed member exist?), and `getAsset` on the mint (the collection's
     * own name and image). Drop the ceiling to two and the exclusion query
     * becomes unaffordable — which the probe correctly reports as UNAVAILABLE
     * rather than an implied pass, so nothing is persisted and manual intake
     * on Solana stops working entirely. That failure mode is quiet, hence this
     * assertion.
     *
     * Approved in review round 4: three calls are acceptable because this path
     * runs ONLY for one administrator-submitted collection — never from cron,
     * page render, enumeration or any fan-out.
     */
    public function testTheSolanaBudgetPermitsAllThreeDocumentedCalls(): void
    {
        self::assertSame(3, ContractValidator::BUDGET_SOLANA);
    }

    public function testTheOtherFamilyCeilingsAreUnchanged(): void
    {
        self::assertSame(4, ContractValidator::BUDGET_COSMOS, 'Cosmos: 3 probes + 1 contract-info');
        self::assertSame(3, ContractValidator::BUDGET_EVM, 'EVM: 2 eth_calls + 1 metadata');
    }

    /**
     * Every ceiling stays SMALL. This is the property that makes "one
     * submission cannot fan out" true of the budget as well as of the shape:
     * there is no family whose ceiling could absorb a pagination loop.
     */
    public function testNoFamilyCeilingIsLargeEnoughToPaginate(): void
    {
        foreach (
            [
                'cosmos' => ContractValidator::BUDGET_COSMOS,
                'evm'    => ContractValidator::BUDGET_EVM,
                'solana' => ContractValidator::BUDGET_SOLANA,
            ] as $family => $ceiling
        ) {
            self::assertLessThanOrEqual(
                5,
                $ceiling,
                "{$family}'s ceiling must stay small enough that no walk can hide inside it"
            );
        }
    }
}
