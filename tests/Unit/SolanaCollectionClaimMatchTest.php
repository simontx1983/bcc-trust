<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Services\ClaimService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * F-339: a Solana collection's creator claim is matched by the collection
 * mint's CURRENT full update authority. Runs the real
 * ClaimService::matchCollection() and the real SolanaCollectionAuthority over
 * scripted ChainRepository / SolanaFetcher / BlockchainQueryService stubs.
 *
 *   authority wallet            → creator (claimable)
 *   another wallet holds full   → holder / none (claim() refuses holders)
 *   renounced / not found       → creator_unverifiable (final)
 *   outage / unreadable / no chain row → verification_unavailable (retry)
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SolanaCollectionClaimMatchTest extends TestCase
{
    private const MINT  = 'J1S9H3QjnRtBbbuD4HjPV6RpRhwuk4zKbxsnCHuTgh9w';
    private const ME    = '7xKXtg2CW87d97TXJSDpbD5jBkheTqA83TZRuJosgAsU';
    private const ALT   = '5ZWj7a1f8tWkjBESHKgrLmXshuXxqeY9SYcfbshpAqPG';
    private const OTHER = '9WzDXwBbmkg8ZTbNMqUxvQRAyrZzDsGYdLVL9zYtAWWM';

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/blockchain-query-role-stub.php';
        require_once dirname(__DIR__) . '/Stubs/solana-claim-stubs.php';
        $GLOBALS['__bcc_bqs_roles'] = [];
        $GLOBALS['__bcc_bqs_calls'] = [];
        $GLOBALS['__bcc_sc']        = [
            'chain'       => (object) ['id' => 9, 'slug' => 'solana', 'chain_type' => 'solana'],
            'asset'       => null,
            'asset_kind'  => 'none',
            'asset_calls' => [],
            'slugs'       => [],
        ];
    }

    /** @param list<string> $authorities addresses holding `full` */
    private static function asset(array $authorities): void
    {
        $GLOBALS['__bcc_sc']['asset'] = [
            'id'          => self::MINT,
            'authorities' => array_map(static fn (string $a): array => ['address' => $a, 'scopes' => ['full']], $authorities),
        ];
    }

    /**
     * @param list<string> $wallets
     * @return array{wallet_address: string, chain_id: int, role: string}|null
     */
    private static function match(array $wallets): ?array
    {
        $m = new ReflectionMethod(ClaimService::class, 'matchCollection');
        $rows = array_map(static fn (string $w): object => (object) ['wallet_address' => $w, 'chain_id' => '9'], $wallets);
        $entity = (object) [
            'id' => '1', 'wallet_link_id' => null, 'chain_id' => '9', 'chain_slug' => 'solana',
            'chain_type' => 'solana', 'contract_address' => self::MINT, 'collection_name' => 'Fixture',
        ];
        /** @var array{wallet_address: string, chain_id: int, role: string}|null */
        return $m->invoke(null, $rows, $entity);
    }

    public function testTheAuthorityWalletIsTheCreator(): void
    {
        self::asset([self::ME]);

        self::assertSame(['wallet_address' => self::ME, 'chain_id' => 9, 'role' => 'creator'], self::match([self::ME]));
        self::assertSame([self::MINT], $GLOBALS['__bcc_sc']['asset_calls']);
        self::assertSame(['solana'], $GLOBALS['__bcc_sc']['slugs'], "the collection's own chain");
    }

    public function testEveryWalletIsAnsweredFromOneGetAsset(): void
    {
        self::asset([self::ME]);

        self::assertSame(self::ME, self::match([self::ALT, self::OTHER, self::ME])['wallet_address'] ?? null, 'the creator wins whichever wallet lists first');
        self::assertCount(1, $GLOBALS['__bcc_sc']['asset_calls'], 'one DAS read for the whole claim');
    }

    public function testAnotherWalletsAuthorityLeavesHolderOrNone(): void
    {
        self::asset([self::OTHER]);
        $GLOBALS['__bcc_bqs_roles'][strtolower(self::ME)] = 'holder';

        self::assertSame('holder', self::match([self::ME])['role'] ?? null, 'claim() refuses it as holder_not_owner');

        $GLOBALS['__bcc_bqs_roles'] = [];
        self::assertNull(self::match([self::ME]), 'no role at all: the no-match answer');
    }

    public function testTheLegacyLookupCannotMakeACreator(): void
    {
        self::asset([self::OTHER]);
        $GLOBALS['__bcc_bqs_roles'][strtolower(self::ME)] = 'creator';

        self::assertNotSame('creator', self::match([self::ME])['role'] ?? null);
    }

    public function testARenouncedAuthorityIsCreatorUnverifiable(): void
    {
        self::asset([]);

        self::assertSame(ClaimService::ROLE_CREATOR_UNVERIFIABLE, self::match([self::ME])['role'] ?? null);
        self::assertSame([], $GLOBALS['__bcc_bqs_calls'], 'no holder lookup: the unknown answers the claim');

        self::asset(['11111111111111111111111111111111']);
        self::assertSame(ClaimService::ROLE_CREATOR_UNVERIFIABLE, self::match([self::ME])['role'] ?? null);
    }

    public function testANotFoundMintIsCreatorUnverifiable(): void
    {
        $GLOBALS['__bcc_sc']['asset_kind'] = 'not_found';

        self::assertSame(ClaimService::ROLE_CREATOR_UNVERIFIABLE, self::match([self::ME])['role'] ?? null);
    }

    public function testAnOutageIsVerificationUnavailable(): void
    {
        foreach (['transport', 'credentials_missing', 'rate_limited', 'http_error', 'malformed'] as $kind) {
            $GLOBALS['__bcc_sc']['asset_kind'] = $kind;
            self::assertSame(ClaimService::ROLE_VERIFICATION_UNAVAILABLE, self::match([self::ME])['role'] ?? null, $kind);
        }
    }

    public function testAnUnknownOutranksAHolderWallet(): void
    {
        $GLOBALS['__bcc_sc']['asset_kind'] = 'transport';
        $GLOBALS['__bcc_bqs_roles'][strtolower(self::ME)] = 'holder';

        self::assertSame(
            ClaimService::ROLE_VERIFICATION_UNAVAILABLE,
            self::match([self::ME])['role'] ?? null,
            'a creator whose check failed must hear "retry", not "you only hold it"'
        );
    }

    public function testNoChainRowIsVerificationUnavailable(): void
    {
        $GLOBALS['__bcc_sc']['chain'] = null;

        self::assertSame(ClaimService::ROLE_VERIFICATION_UNAVAILABLE, self::match([self::ME])['role'] ?? null);
        self::assertSame([], $GLOBALS['__bcc_sc']['asset_calls']);
    }

    public function testTheUnknownSentinelIsNeverAClaimableRole(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Domain/Onchain/Services/ClaimService.php');

        self::assertStringContainsString("private const EXCLUSIVE_ROLES = ['operator', 'creator'];", $src, 'the unknowns are not exclusive roles');
        // claim() answers each with its own error before the holder refusal.
        $claim  = substr($src, (int) strpos($src, 'public static function claim('));
        $unavail = strpos($claim, "'error'   => self::ROLE_VERIFICATION_UNAVAILABLE,");
        $unverif = strpos($claim, "'error'   => self::ROLE_CREATOR_UNVERIFIABLE,");
        $holder  = strpos($claim, "'error'   => 'holder_not_owner',");
        self::assertNotFalse($unavail);
        self::assertNotFalse($unverif);
        self::assertNotFalse($holder);
        self::assertLessThan($holder, $unavail);
        self::assertLessThan($holder, $unverif);
    }
}
