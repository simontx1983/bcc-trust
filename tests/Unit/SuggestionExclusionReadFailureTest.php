<?php
/**
 * F-454: member suggestions EXCLUDE the §O4.1 caution/risky members and both
 * directions of the viewer's blocks, so a list that could not be read means
 * no suggestions (null from buildExclusionSet(), [] from getSuggestions()),
 * never suggestions without it. Security accepted the empty answer (F-454
 * review, Q5): suggestions are an optional panel and the next request
 * retries.
 *
 * - The caution list comes from the EXCLUDING reader, which serves its
 *   last-good list (24 h at most) and throws only with none
 *   (CautionListFailClosedTest).
 * - The block lists come from bcc-core's OrUnknown readers.
 *
 * getSuggestions()'s `null → []` branch is held by PHPStan too: without it,
 * a nullable set would reach coldStartTopUp()'s array parameter.
 */

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Exceptions\RepositoryException;
use BCC\Trust\Core\Repositories\ReputationRepository;
use BCC\Trust\Core\Repositories\SuspensionRepository;
use BCC\Trust\Core\Services\SuggestionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[CoversClass(SuggestionService::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SuggestionExclusionReadFailureTest extends TestCase
{
    private const VIEWER = 7;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/suggestion-exclusion-stubs.php';
        $GLOBALS['__bcc_sx'] = [
            'caution'       => [40, 41],
            'suspended'     => [50],
            'blocked'       => [60],
            'blockers'      => [61],
            'caution_throws' => false,
            'warnings'      => [],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__bcc_sx']);
        parent::tearDown();
    }

    public function testEveryListIsExcludedWhenItCanBeRead(): void
    {
        $exclude = $this->exclusionSet([30]);

        self::assertIsArray($exclude);
        foreach ([self::VIEWER, 30, 40, 41, 50, 60, 61] as $id) {
            self::assertArrayHasKey($id, $exclude, "member {$id} is excluded");
        }
        self::assertSame([], $GLOBALS['__bcc_sx']['warnings']);
    }

    public function testAnUnreadableCautionListMeansNoSuggestions(): void
    {
        $GLOBALS['__bcc_sx']['caution_throws'] = true;

        self::assertNull($this->exclusionSet([]));
        self::assertCount(1, $GLOBALS['__bcc_sx']['warnings'], 'logged');
    }

    public function testAnUnreadableBlockedListMeansNoSuggestions(): void
    {
        $GLOBALS['__bcc_sx']['blocked_fails'] = true;

        self::assertNull($this->exclusionSet([]));
        self::assertCount(1, $GLOBALS['__bcc_sx']['warnings'], 'logged');
    }

    public function testAnUnreadableBlockerListMeansNoSuggestions(): void
    {
        $GLOBALS['__bcc_sx']['blockers_fails'] = true;

        self::assertNull($this->exclusionSet([]));
    }

    /**
     * @param list<int> $following
     * @return array<int, true>|null
     */
    private function exclusionSet(array $following): ?array
    {
        $service = (new \ReflectionClass(SuggestionService::class))->newInstanceWithoutConstructor();

        $reputation = new class extends ReputationRepository {
            public function __construct()
            {
            }

            /** @return list<int> */
            public function getCautionAndRiskyUserIdsForExclusion(): array
            {
                if ($GLOBALS['__bcc_sx']['caution_throws']) {
                    throw new RepositoryException('caution list unreadable, no usable last-good copy');
                }

                return $GLOBALS['__bcc_sx']['caution'];
            }

            /** @return list<int> */
            public function getCautionAndRiskyUserIds(int $limit = 1000): array
            {
                unset($limit);
                throw new \LogicException('suggestions must use the excluding reader');
            }
        };
        $suspensions = new class extends SuspensionRepository {
            public function __construct()
            {
            }

            /** @return list<int> */
            public function getActiveSuspendedUserIds(int $limit = 1000): array
            {
                unset($limit);

                return $GLOBALS['__bcc_sx']['suspended'];
            }
        };

        foreach (['reputationRepository' => $reputation, 'suspensionRepository' => $suspensions] as $name => $value) {
            $property = new \ReflectionProperty(SuggestionService::class, $name);
            $property->setValue($service, $value);
        }

        /** @var array<int, true>|null $exclude */
        $exclude = (new \ReflectionMethod(SuggestionService::class, 'buildExclusionSet'))->invoke($service, self::VIEWER, $following);

        return $exclude;
    }
}
