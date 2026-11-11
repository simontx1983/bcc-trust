<?php
/**
 * Device-sharing penalty ledger (F-103).
 *
 * DeviceFingerprinter::storeFingerprint() runs on EVERY vote. When the
 * voter's device (browser or server fingerprint) had also been used by
 * another account it added to fraud_score — every time, with no memory. Two
 * accounts behind one family laptop or office IP climbed ~26 points per vote,
 * hit the voting block (60) after about three votes, and because the daily
 * decay never goes below half the peak, stayed down-weighted for good.
 *
 * This ledger makes the penalty an EVENT, not a per-vote tax:
 *   - the same (reason, device) pair penalises an account at most once per
 *     BCC_TRUST_DEVICE_SHARING_WINDOW_DAYS;
 *   - all shared-device penalties together add at most
 *     BCC_TRUST_DEVICE_SHARING_MAX_POINTS to an account within the window.
 *
 * Stored per account in user meta (written only when a penalty is actually
 * applied — rare), pruned to the window on every write, so it stays tiny.
 * Device keys are hashed so the meta never holds a raw fingerprint.
 *
 * @package BCC\Trust\Core\Security
 */

namespace BCC\Trust\Core\Security;

if (!defined('ABSPATH')) {
    exit;
}

final class DeviceSharingPenaltyLedger
{
    public const META_KEY = '_bcc_device_sharing_penalties';

    /**
     * How many of the requested points may be applied now (0 = none).
     * Does NOT record anything — call record() with the amount applied.
     */
    public static function allowance(int $userId, string $reason, string $deviceKey, int $requested, ?int $now = null): int
    {
        if ($userId <= 0 || $requested <= 0) {
            return 0;
        }
        $now     = $now ?? time();
        $entries = self::inWindow(self::load($userId), $now);
        $key     = self::entryKey($reason, $deviceKey);

        $used = 0;
        foreach ($entries as $e) {
            if ($e['k'] === $key) {
                return 0; // this device already penalised this account in the window
            }
            $used += $e['p'];
        }

        return max(0, min($requested, self::maxPoints() - $used));
    }

    /** Record an applied penalty; prunes entries older than the window. */
    public static function record(int $userId, string $reason, string $deviceKey, int $points, ?int $now = null): void
    {
        if ($userId <= 0 || $points <= 0) {
            return;
        }
        $now       = $now ?? time();
        $entries   = self::inWindow(self::load($userId), $now);
        $entries[] = ['t' => $now, 'k' => self::entryKey($reason, $deviceKey), 'p' => $points];
        update_user_meta($userId, self::META_KEY, $entries);
    }

    /** Forget every recorded penalty (used by the admin fraud-score reset). */
    public static function clear(int $userId): void
    {
        if ($userId > 0) {
            delete_user_meta($userId, self::META_KEY);
        }
    }

    private static function windowSeconds(): int
    {
        return max(1, (int) BCC_TRUST_DEVICE_SHARING_WINDOW_DAYS) * 86400;
    }

    private static function maxPoints(): int
    {
        return max(0, (int) BCC_TRUST_DEVICE_SHARING_MAX_POINTS);
    }

    private static function entryKey(string $reason, string $deviceKey): string
    {
        return $reason . ':' . substr(hash('sha256', $deviceKey), 0, 32);
    }

    /** @return list<array{t: int, k: string, p: int}> */
    private static function load(int $userId): array
    {
        $raw = get_user_meta($userId, self::META_KEY, true);
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $e) {
            if (is_array($e) && isset($e['t'], $e['k'], $e['p'])) {
                $out[] = ['t' => (int) $e['t'], 'k' => (string) $e['k'], 'p' => (int) $e['p']];
            }
        }
        return $out;
    }

    /**
     * @param list<array{t: int, k: string, p: int}> $entries
     * @return list<array{t: int, k: string, p: int}>
     */
    private static function inWindow(array $entries, int $now): array
    {
        $cutoff = $now - self::windowSeconds();
        return array_values(array_filter($entries, static fn (array $e): bool => $e['t'] > $cutoff));
    }
}
