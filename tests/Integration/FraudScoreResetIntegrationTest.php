<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Repositories\UserInfoRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-103 (user decision): the admin "Reset fraud score" clears BOTH the
 * current score and the peak. The peak is the anti-oscillation floor — the
 * daily decay never goes below half of it — so a reset that left the peak
 * would be undone by the very next decay pass. Real MySQL.
 */
#[Group('integration')]
#[CoversClass(UserInfoRepository::class)]
final class FraudScoreResetIntegrationTest extends TestCase
{
    private const USER = 9951;

    protected function setUp(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query('TRUNCATE TABLE `' . $wpdb->prefix . 'bcc_trust_user_info`');
        $GLOBALS['__bcc_test_object_cache'] = [];
    }

    private function seed(int $score, int $peak): void
    {
        $wpdb = $GLOBALS['wpdb'];
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'bcc_trust_user_info', [
            'user_id'          => self::USER,
            'user_login'       => 'reset',
            'user_email'       => 'reset@example.test',
            'display_name'     => 'Reset',
            'fraud_score'      => $score,
            'peak_fraud_score' => $peak,
        ]), $wpdb->last_error);
    }

    /** @return array{0:int,1:int} */
    private function scores(): array
    {
        $row = $GLOBALS['wpdb']->get_row($GLOBALS['wpdb']->prepare(
            'SELECT fraud_score, peak_fraud_score FROM `' . $GLOBALS['wpdb']->prefix . 'bcc_trust_user_info` WHERE user_id = %d',
            self::USER
        ));
        self::assertIsObject($row);
        return [(int) $row->fraud_score, (int) $row->peak_fraud_score];
    }

    public function testResetClearsCurrentAndPeakAndReportsWhatItReplaced(): void
    {
        $this->seed(42, 66);

        $previous = (new UserInfoRepository())->resetFraudScore(self::USER);

        self::assertSame(['fraud_score' => 42, 'peak_fraud_score' => 66], $previous, 'the audit trail gets the replaced values');
        self::assertSame([0, 0], $this->scores(), 'peak cleared too, or the decay floor would restore half of it');
    }

    public function testResettingAnUnknownUserReportsNothingToReset(): void
    {
        self::assertNull((new UserInfoRepository())->resetFraudScore(self::USER));
    }
}
