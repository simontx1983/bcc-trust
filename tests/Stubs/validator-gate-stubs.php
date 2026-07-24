<?php
/**
 * Fixture-backed stubs for ValidatorGroupGateTest.
 *
 * Loaded ONLY inside a PHPUnit subprocess (RunTestsInSeparateProcesses),
 * never in the main test process, so the fake FQN classes below cannot
 * shadow the real bcc-core / bcc-trust classes anywhere else. Each fake is
 * defined BEFORE the autoloader is asked for the FQN, so the real class
 * file is never loaded.
 *
 * Fixture shape ($GLOBALS['__bcc_valgate_fixture']):
 *   config        ValidatorGatedGroupConfig|null  what the repo returns
 *   opted_out     bool                            opt-out map answer
 *   is_member     bool                            existing membership row
 *   chain_type    string|null                     null → chain row missing
 *   verdict       DelegationVerdict|null          eligibility answer
 *   join_result   bool                            PeepSoGroupWriter::join return
 *   join_calls    list<array{int,int}>            recorded join call sites
 *   cleared       list<array{int,int}>            recorded clearOptOut calls
 */

declare(strict_types=1);

namespace BCC\Core\Repositories {
    if (!class_exists(PeepSoGroupRepository::class, false)) {
        class PeepSoGroupRepository
        {
            /**
             * @param list<int> $groupIds
             * @return array<int, object>
             */
            public static function findUserMemberships(int $userId, array $groupIds): array
            {
                if (empty($GLOBALS['__bcc_valgate_fixture']['is_member'])) {
                    return [];
                }
                $out = [];
                foreach ($groupIds as $gid) {
                    $out[$gid] = (object) ['joined_at' => '2026-07-01 00:00:00'];
                }
                return $out;
            }
        }
    }
}

namespace BCC\Core\PeepSo {
    if (!class_exists(PeepSoGroupWriter::class, false)) {
        class PeepSoGroupWriter
        {
            public static function join(int $userId, int $groupId): bool
            {
                $GLOBALS['__bcc_valgate_fixture']['join_calls'][] = [$userId, $groupId];
                return (bool) ($GLOBALS['__bcc_valgate_fixture']['join_result'] ?? true);
            }
        }
    }
}

namespace BCC\Trust\Onchain\Repositories {
    if (!class_exists(ValidatorGroupRepository::class, false)) {
        class ValidatorGroupRepository
        {
            public static function getGateConfig(int $groupId): ?\BCC\Trust\Onchain\ValueObjects\ValidatorGatedGroupConfig
            {
                return $GLOBALS['__bcc_valgate_fixture']['config'] ?? null;
            }
        }
    }

    if (!class_exists(ChainRepository::class, false)) {
        class ChainRepository
        {
            public static function getById(int $chainId): ?object
            {
                // array_key_exists, NOT `??`: a present null means "chain row
                // missing" and `??` would collapse it back to the default —
                // the same fail-open trap the production code guards against.
                $type = array_key_exists('chain_type', $GLOBALS['__bcc_valgate_fixture'])
                    ? $GLOBALS['__bcc_valgate_fixture']['chain_type']
                    : 'cosmos';
                if ($type === null) {
                    return null;
                }
                return (object) ['id' => (string) $chainId, 'slug' => 'cosmos', 'chain_type' => $type];
            }
        }
    }
}

namespace BCC\Trust\Onchain\Services {
    if (!class_exists(DelegationEligibilityService::class, false)) {
        class DelegationEligibilityService
        {
            public function verdictFor(
                int $userId,
                \BCC\Trust\Onchain\ValueObjects\ValidatorGatedGroupConfig $config
            ): \BCC\Trust\Onchain\ValueObjects\DelegationVerdict {
                $verdict = $GLOBALS['__bcc_valgate_fixture']['verdict'] ?? null;
                if ($verdict === null) {
                    return \BCC\Trust\Onchain\ValueObjects\DelegationVerdict::unknown($config->minStake, null);
                }
                return $verdict;
            }
        }
    }

    if (!class_exists(NftGroupGateService::class, false)) {
        class NftGroupGateService
        {
            public function isOptOutActive(int $userId, int $groupId): bool
            {
                return (bool) ($GLOBALS['__bcc_valgate_fixture']['opted_out'] ?? false);
            }

            public function clearOptOut(int $userId, int $groupId): void
            {
                $GLOBALS['__bcc_valgate_fixture']['cleared'][] = [$userId, $groupId];
            }
        }
    }
}
