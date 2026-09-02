<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Services\ManualCollectionIntakeService;
use BCC\Trust\Onchain\Support\Base58;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The one manual Add Collection path.
 *
 * ── WHAT THESE TESTS ARE REALLY PROTECTING ──────────────────────────────
 * Three things a manual intake form gets wrong easily:
 *
 *   1. It creates something. This one must create a collection ROW and
 *      nothing else — no community, no capability, no verification.
 *   2. It over-claims. EVM and Solana adds are accepted as ENTERED, and the
 *      audit has to say so, because "valid address" is not "NFT collection".
 *   3. It reports a provider outage as a negative result. A CW-721 probe
 *      that gets no answer means "could not confirm", never "not a CW-721".
 */
#[CoversClass(ManualCollectionIntakeService::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ManualCollectionIntakeServiceTest extends TestCase
{
    private const OPERATOR = 7;

    /** Canonical EVM: lowercase 0x + 20 bytes. */
    private const EVM_ADDRESS = '0x1234567890abcdef1234567890abcdef12345678';

    /** A real Solana mint. Mixed case, and it MUST survive byte-exact. */
    private const SOLANA_MINT = '4fKR1UC2UA5R5m3ZGJwisZD4tkqQ2ZEPgGeZn51bB8uy';

    private ManualCollectionIntakeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/verify-collections-stubs.php';

        \BccAdminTestState::reset();
        \BCC\Core\Log\Logger::reset();
        \BCC\Trust\Core\Security\AuditLogger::reset();
        \BCC\Trust\Core\Security\TransactionManager::reset();
        \BCC\Trust\Onchain\Repositories\CollectionRepository::reset();
        \BCC\Trust\Onchain\Repositories\GatedGroupRepository::reset();
        \BCC\Trust\Onchain\Repositories\ChainRepository::reset();
        \BCC\Trust\Onchain\Fetchers\CosmosFetcher::reset();

        $this->service = new ManualCollectionIntakeService();
    }

    /**
     * Seed a chain with BOTH capability flags on, so a test that is not
     * about capability does not have to think about it.
     */
    private function seedChain(int $id, string $slug, string $family, bool $product = true, bool $manual = true): void
    {
        \BCC\Trust\Onchain\Repositories\ChainRepository::seed($id, $slug, $family);
        $chain = \BCC\Trust\Onchain\Repositories\ChainRepository::$chains[$id];
        $chain->bcc_supports_nft_collections        = $product ? 1 : 0;
        $chain->manual_collection_discovery_enabled = $manual ? 1 : 0;
    }

    // ── Chain locking ───────────────────────────────────────────────────

    public function testAFamilyOutsideTheAllowlistIsRefused(): void
    {
        $this->seedChain(9, 'thorchain', 'thorchain');

        $result = $this->service->add('thorchain', 9, self::EVM_ADDRESS, self::OPERATOR);

        self::assertFalse($result['ok']);
        self::assertSame(ManualCollectionIntakeService::REFUSED_BAD_FAMILY, $result['reason']);
        self::assertSame([], \BCC\Trust\Onchain\Repositories\CollectionRepository::$added);
    }

    /**
     * The nonce is bound to the chain and the family comes from the tab. If
     * the two disagree, one of them is forged — so neither is trusted over
     * the other and the request is refused outright.
     */
    public function testASubmittedChainThatDoesNotBelongToTheFamilyIsRefused(): void
    {
        $this->seedChain(4, 'ethereum', 'evm');

        $result = $this->service->add('solana', 4, self::SOLANA_MINT, self::OPERATOR);

        self::assertFalse($result['ok']);
        self::assertSame(ManualCollectionIntakeService::REFUSED_FAMILY_MISMATCH, $result['reason']);
        self::assertSame([], \BCC\Trust\Onchain\Repositories\CollectionRepository::$added);
    }

    public function testAnUnknownChainIsRefused(): void
    {
        $result = $this->service->add('evm', 999, self::EVM_ADDRESS, self::OPERATOR);

        self::assertFalse($result['ok']);
        self::assertSame(ManualCollectionIntakeService::REFUSED_CHAIN_NOT_FOUND, $result['reason']);
    }

    public function testAnInactiveChainIsRefused(): void
    {
        $this->seedChain(4, 'ethereum', 'evm');
        \BCC\Trust\Onchain\Repositories\ChainRepository::$chains[4]->is_active = 0;

        $result = $this->service->add('evm', 4, self::EVM_ADDRESS, self::OPERATOR);

        self::assertFalse($result['ok']);
        self::assertSame(ManualCollectionIntakeService::REFUSED_CHAIN_INACTIVE, $result['reason']);
    }

    // ── Capability gates ────────────────────────────────────────────────

    public function testProductSupportIsRequired(): void
    {
        $this->seedChain(4, 'ethereum', 'evm', product: false, manual: true);

        $result = $this->service->add('evm', 4, self::EVM_ADDRESS, self::OPERATOR);

        self::assertFalse($result['ok']);
        self::assertSame(ManualCollectionIntakeService::REFUSED_NO_PRODUCT, $result['reason']);
        self::assertSame([], \BCC\Trust\Onchain\Repositories\CollectionRepository::$added);
    }

    public function testManualDiscoveryPermissionIsRequired(): void
    {
        $this->seedChain(4, 'ethereum', 'evm', product: true, manual: false);

        $result = $this->service->add('evm', 4, self::EVM_ADDRESS, self::OPERATOR);

        self::assertFalse($result['ok']);
        self::assertSame(ManualCollectionIntakeService::REFUSED_NO_MANUAL, $result['reason']);
    }

    /**
     * Product support is reported FIRST when both are off. Telling an
     * operator "permission disabled" for a chain the product does not
     * support at all would send them to enable the wrong switch.
     */
    public function testProductSupportIsReportedBeforeThePermission(): void
    {
        $this->seedChain(4, 'ethereum', 'evm', product: false, manual: false);

        $result = $this->service->add('evm', 4, self::EVM_ADDRESS, self::OPERATOR);

        self::assertSame(ManualCollectionIntakeService::REFUSED_NO_PRODUCT, $result['reason']);
    }

    /**
     * An ABSENT capability column reads as `null`, not `false`. It must fail
     * CLOSED — an unreadable capability store is not permission.
     */
    public function testAnUnreadableCapabilityColumnFailsClosed(): void
    {
        \BCC\Trust\Onchain\Repositories\ChainRepository::seed(4, 'ethereum', 'evm');
        // Neither flag is set at all — the shape a chain row has before the
        // capability migration has run.

        $result = $this->service->add('evm', 4, self::EVM_ADDRESS, self::OPERATOR);

        self::assertFalse($result['ok']);
        self::assertSame(ManualCollectionIntakeService::REFUSED_NO_PRODUCT, $result['reason']);
        self::assertSame([], \BCC\Trust\Onchain\Repositories\CollectionRepository::$added);
    }

    // ── Identity ────────────────────────────────────────────────────────

    public function testAnEmptyIdentifierIsRefused(): void
    {
        $this->seedChain(4, 'ethereum', 'evm');

        $result = $this->service->add('evm', 4, '   ', self::OPERATOR);

        self::assertFalse($result['ok']);
        self::assertSame(ManualCollectionIntakeService::REFUSED_BAD_IDENTIFIER, $result['reason']);
    }

    /** @return list<array{0: string, 1: string}> */
    public static function malformedIdentifiers(): array
    {
        return [
            'evm too short'      => ['evm', '0x1234'],
            'evm no prefix'      => ['evm', '1234567890abcdef1234567890abcdef12345678'],
            'evm not hex'        => ['evm', '0xZZZZ567890abcdef1234567890abcdef12345678'],
            'solana non-base58'  => ['solana', '0OIl0OIl0OIl0OIl0OIl0OIl0OIl0OIl0OIl0OIl0OIl'],
            'solana wrong bytes' => ['solana', 'aaaa'],
            'cosmos bad csum'    => ['cosmos', 'cosmos1abcdefghijklmnopqrstuvwxyz0123456789'],
            'cosmos not bech32'  => ['cosmos', 'not-an-address'],
        ];
    }

    #[DataProvider('malformedIdentifiers')]
    public function testAMalformedIdentifierIsRefusedForItsFamily(string $family, string $identifier): void
    {
        $this->seedChain(4, $family === 'evm' ? 'ethereum' : $family, $family);

        $result = $this->service->add($family, 4, $identifier, self::OPERATOR);

        self::assertFalse($result['ok'], $identifier . ' should not be accepted');
        self::assertSame(ManualCollectionIntakeService::REFUSED_BAD_IDENTIFIER, $result['reason']);
        self::assertSame([], \BCC\Trust\Onchain\Repositories\CollectionRepository::$added);
    }

    /**
     * Solana base58 is CASE-SENSITIVE. Folding it produces a different key,
     * which is the whole defect PR 5a and 5b existed to remove — so the
     * value that reaches the writer must be byte-identical to what was typed.
     */
    public function testASolanaMintIsStoredByteExactWithItsCasePreserved(): void
    {
        $this->seedChain(20, 'solana', 'solana');

        $result = $this->service->add('solana', 20, self::SOLANA_MINT, self::OPERATOR);

        self::assertTrue($result['ok']);

        $written = \BCC\Trust\Onchain\Repositories\CollectionRepository::$added[0][0];
        self::assertSame(
            0,
            strcmp(self::SOLANA_MINT, (string) $written['contract_address']),
            'the mint must reach the writer byte-for-byte'
        );
        self::assertNotSame(
            strtolower(self::SOLANA_MINT),
            (string) $written['contract_address'],
            'a lower-cased mint is a DIFFERENT key, not the same one'
        );
    }

    /** The fixture is a real 32-byte key, so the test above proves something. */
    public function testTheSolanaFixtureIsGenuinelyAThirtyTwoByteKey(): void
    {
        self::assertSame(32, Base58::decodedLength(self::SOLANA_MINT));
    }

    public function testAnEvmAddressIsCanonicalisedToLowercase(): void
    {
        $this->seedChain(4, 'ethereum', 'evm');

        $result = $this->service->add('evm', 4, strtoupper(substr(self::EVM_ADDRESS, 2)) === ''
            ? self::EVM_ADDRESS
            : '0x' . strtoupper(substr(self::EVM_ADDRESS, 2)), self::OPERATOR);

        self::assertTrue($result['ok']);
        self::assertSame(
            self::EVM_ADDRESS,
            (string) \BCC\Trust\Onchain\Repositories\CollectionRepository::$added[0][0]['contract_address']
        );
    }

    // ── Duplicates ──────────────────────────────────────────────────────

    public function testADuplicateCanonicalIdentityIsRefusedAndNamesTheExistingRow(): void
    {
        $this->seedChain(4, 'ethereum', 'evm');
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$rows[77] = (object) [
            'id'               => 77,
            'chain_id'         => 4,
            'contract_address' => self::EVM_ADDRESS,
        ];

        $result = $this->service->add('evm', 4, self::EVM_ADDRESS, self::OPERATOR);

        self::assertFalse($result['ok']);
        self::assertSame(ManualCollectionIntakeService::REFUSED_DUPLICATE, $result['reason']);
        self::assertSame(77, $result['duplicate_of']);
        self::assertSame([], \BCC\Trust\Onchain\Repositories\CollectionRepository::$added);
    }

    // ── Cosmos validation, and its honesty ──────────────────────────────

    /** A real bech32 address with a valid checksum, from production data. */
    private const COSMOS_CONTRACT =
        'dungeon1spyp9jnlqvw8u3hgaasxkjspvvx9kuks0x00t96ummekyf63keasa3dpuh';

    public function testACosmosAddIsValidatedAgainstTheContractAndRecordsThat(): void
    {
        $this->seedChain(17, 'dungeon', 'cosmos');
        \BCC\Trust\Onchain\Fetchers\CosmosFetcher::$contractInfo = ['name' => 'Dungeon Heroes'];

        $result = $this->service->add('cosmos', 17, self::COSMOS_CONTRACT, self::OPERATOR);

        self::assertTrue($result['ok']);
        self::assertSame(ManualCollectionIntakeService::VALIDATION_CW721, $result['validation']);

        $written = \BCC\Trust\Onchain\Repositories\CollectionRepository::$added[0][0];
        self::assertSame('CW-721', $written['token_standard']);
        self::assertSame('Dungeon Heroes', $written['collection_name'], 'the name is taken from the contract');
    }

    /**
     * ⚠ The property this whole PR keeps repeating: a provider that does not
     * answer is NOT evidence against the contract.
     *
     * `testCw721ContractInfo()` returns null for a transport failure AND for
     * a shape mismatch, so the refusal must be phrased as "could not
     * confirm". Reporting a provider outage as "not an NFT collection" is
     * issue #225's defect relocated to a surface a human acts on.
     */
    public function testAnUnanswerableCosmosProbeRefusesWithoutClaimingTheContractIsInvalid(): void
    {
        $this->seedChain(17, 'dungeon', 'cosmos');
        \BCC\Trust\Onchain\Fetchers\CosmosFetcher::$contractInfo = null;

        $result = $this->service->add('cosmos', 17, self::COSMOS_CONTRACT, self::OPERATOR);

        self::assertFalse($result['ok']);
        self::assertSame(ManualCollectionIntakeService::REFUSED_NOT_CW721, $result['reason']);
        self::assertSame([], \BCC\Trust\Onchain\Repositories\CollectionRepository::$added);

        $message = ManualCollectionIntakeService::refusalMessage($result['reason']);
        self::assertStringContainsString('could not be confirmed', $message);
        self::assertStringContainsString('not proof either way', $message);
    }

    public function testAFetcherThatThrowsIsTreatedAsUnconfirmedNotAsInvalid(): void
    {
        $this->seedChain(17, 'dungeon', 'cosmos');
        \BCC\Trust\Onchain\Fetchers\CosmosFetcher::$throws = new \RuntimeException('LCD 503');

        $result = $this->service->add('cosmos', 17, self::COSMOS_CONTRACT, self::OPERATOR);

        self::assertFalse($result['ok']);
        self::assertSame(ManualCollectionIntakeService::REFUSED_NOT_CW721, $result['reason']);
    }

    // ── EVM and Solana are accepted as entered, and say so ──────────────

    /** @return list<array{0: string, 1: int, 2: string, 3: string}> */
    public static function unvalidatedFamilies(): array
    {
        return [
            'evm'    => ['evm', 4, 'ethereum', self::EVM_ADDRESS],
            'solana' => ['solana', 20, 'solana', self::SOLANA_MINT],
        ];
    }

    #[DataProvider('unvalidatedFamilies')]
    public function testEvmAndSolanaAddsAreRecordedAsAcceptedAsEntered(
        string $family,
        int $chainId,
        string $slug,
        string $identifier
    ): void {
        $this->seedChain($chainId, $slug, $family);

        $result = $this->service->add($family, $chainId, $identifier, self::OPERATOR);

        self::assertTrue($result['ok']);
        self::assertSame(
            ManualCollectionIntakeService::VALIDATION_NONE,
            $result['validation'],
            'nothing in this build proves an EVM or Solana address is an NFT contract'
        );

        $audit = \BCC\Trust\Core\Security\AuditLogger::$rows[0];
        self::assertSame(ManualCollectionIntakeService::AUDIT_ADDED, $audit['action']);
        self::assertSame(ManualCollectionIntakeService::VALIDATION_NONE, $audit['meta']['validation']);
    }

    #[DataProvider('unvalidatedFamilies')]
    public function testNoProviderIsContactedForAnEvmOrSolanaAdd(
        string $family,
        int $chainId,
        string $slug,
        string $identifier
    ): void {
        $this->seedChain($chainId, $slug, $family);

        $this->service->add($family, $chainId, $identifier, self::OPERATOR);

        self::assertSame(
            [],
            \BCC\Trust\Onchain\Fetchers\CosmosFetcher::$probes,
            'no chain is asked anything for a family with no validation driver'
        );
    }

    // ── What an add does and does not create ────────────────────────────

    public function testAnAddCreatesAnUnverifiedManualRowWithNoCommunityAndNoIntent(): void
    {
        $this->seedChain(4, 'ethereum', 'evm');

        $result = $this->service->add('evm', 4, self::EVM_ADDRESS, self::OPERATOR);

        self::assertTrue($result['ok']);

        // `addManual()` forces is_verified = 0 and source = 'manual' in its
        // own INSERT — neither is passed in, so no caller can talk it into
        // landing a pre-verified row.
        $written = \BCC\Trust\Onchain\Repositories\CollectionRepository::$added[0][0];
        self::assertArrayNotHasKey('is_verified', $written);
        self::assertArrayNotHasKey('source', $written);
        self::assertArrayNotHasKey('provisioning_state', $written);

        // No community, and no recorded intent to create one.
        self::assertSame([], \BCC\Trust\Onchain\Repositories\GatedGroupRepository::$groups);
        self::assertSame([], \BCC\Trust\Onchain\Repositories\CollectionRepository::$stateWrites);
    }

    public function testAnAddEnablesNoCapability(): void
    {
        $this->seedChain(4, 'ethereum', 'evm');
        $before = clone \BCC\Trust\Onchain\Repositories\ChainRepository::$chains[4];

        $this->service->add('evm', 4, self::EVM_ADDRESS, self::OPERATOR);

        self::assertEquals(
            $before,
            \BCC\Trust\Onchain\Repositories\ChainRepository::$chains[4],
            'adding a collection must not change any chain capability'
        );
    }

    // ── Cache invalidation ──────────────────────────────────────────────

    /**
     * Adding a row changes the per-chain census, so the cached counts must
     * go. This is the ONE write in PR 6 that legitimately busts that key.
     */
    public function testASuccessfulAddDropsThePerChainCountsCache(): void
    {
        $this->seedChain(4, 'ethereum', 'evm');
        \BccObjectCacheSpy::reset();

        $this->service->add('evm', 4, self::EVM_ADDRESS, self::OPERATOR);

        $keys = array_map(
            static fn (array $d): string => (string) $d['key'],
            \BccObjectCacheSpy::$deleted
        );
        self::assertContains('collection_counts_by_chain', $keys);
    }

    /**
     * A REFUSED add changed nothing, so it must not churn the cache either.
     * A bust on every rejected form submission would quietly turn a
     * one-hour cache into no cache at all.
     */
    public function testARefusedAddDropsNothing(): void
    {
        $this->seedChain(4, 'ethereum', 'evm', product: false);
        \BccObjectCacheSpy::reset();

        $this->service->add('evm', 4, self::EVM_ADDRESS, self::OPERATOR);

        self::assertSame([], \BccObjectCacheSpy::$deleted);
    }

    // ── Atomicity ───────────────────────────────────────────────────────

    /**
     * An unattributable manual add is exactly what the checked-audit
     * contract exists to prevent, so a failed audit must take the row with
     * it rather than leaving an orphan nobody can trace.
     */
    public function testAFailedCheckedAuditRollsTheInsertBack(): void
    {
        $this->seedChain(4, 'ethereum', 'evm');
        \BCC\Trust\Core\Security\AuditLogger::$failChecked = true;

        $result = $this->service->add('evm', 4, self::EVM_ADDRESS, self::OPERATOR);

        self::assertFalse($result['ok']);
        self::assertSame(ManualCollectionIntakeService::REFUSED_WRITE_FAILED, $result['reason']);
        self::assertSame(1, \BCC\Trust\Core\Security\TransactionManager::$rollbacks);
        self::assertSame(
            [],
            array_filter(
                \BCC\Trust\Core\Security\AuditLogger::$rows,
                static fn (array $r): bool => $r['action'] === ManualCollectionIntakeService::AUDIT_ADDED
            ),
            'no add may be recorded as having succeeded'
        );
    }

    public function testAFailedInsertIsReportedAndAuditedAsARefusal(): void
    {
        $this->seedChain(4, 'ethereum', 'evm');
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$addManualResult = 0;

        $result = $this->service->add('evm', 4, self::EVM_ADDRESS, self::OPERATOR);

        self::assertFalse($result['ok']);
        self::assertSame(ManualCollectionIntakeService::REFUSED_WRITE_FAILED, $result['reason']);
    }

    // ── Audit hygiene ───────────────────────────────────────────────────

    /**
     * A refusal must NOT echo the operator's raw input into a durable row —
     * that would be a write primitive for anyone who can reach the form.
     */
    public function testARefusalAuditNeverCarriesTheSubmittedIdentifier(): void
    {
        $this->seedChain(4, 'ethereum', 'evm');
        $hostile = '0x<script>alert(1)</script>';

        $this->service->add('evm', 4, $hostile, self::OPERATOR);

        $audit = \BCC\Trust\Core\Security\AuditLogger::$rows[0];
        self::assertSame(ManualCollectionIntakeService::AUDIT_REFUSED, $audit['action']);

        $encoded = json_encode($audit['meta']);
        self::assertIsString($encoded);
        self::assertStringNotContainsString('script', $encoded);
        self::assertStringNotContainsString('alert', $encoded);
    }

    public function testEveryRefusalReasonHasOperatorCopyThatIsNotTheRawToken(): void
    {
        foreach ([
            ManualCollectionIntakeService::REFUSED_BAD_FAMILY,
            ManualCollectionIntakeService::REFUSED_CHAIN_NOT_FOUND,
            ManualCollectionIntakeService::REFUSED_CHAIN_INACTIVE,
            ManualCollectionIntakeService::REFUSED_FAMILY_MISMATCH,
            ManualCollectionIntakeService::REFUSED_NO_PRODUCT,
            ManualCollectionIntakeService::REFUSED_NO_MANUAL,
            ManualCollectionIntakeService::REFUSED_BAD_IDENTIFIER,
            ManualCollectionIntakeService::REFUSED_DUPLICATE,
            ManualCollectionIntakeService::REFUSED_NOT_CW721,
            ManualCollectionIntakeService::REFUSED_WRITE_FAILED,
        ] as $reason) {
            $message = ManualCollectionIntakeService::refusalMessage($reason);
            self::assertNotSame('', $message, $reason);
            self::assertStringNotContainsString('_', $message, $reason . ' renders its raw token');
        }
    }
}
