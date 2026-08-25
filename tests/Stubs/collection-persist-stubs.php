<?php

/**
 * Fake-at-FQN CollectionRepository for CollectionPersistBatch tests.
 *
 * Scripted per call so a batch can mix created / updated / failed outcomes —
 * which is the whole point: a PARTIAL failure must be distinguishable from a
 * total one, and both from complete success.
 */

declare(strict_types=1);

namespace BCC\Trust\Onchain\Repositories {

    if (!class_exists(__NAMESPACE__ . '\\CollectionRepository', false)) {
        final class CollectionRepository
        {
            /**
             * Statuses handed out in order, one per upsert() call. Runs out →
             * 'created', so a test only scripts what it cares about.
             *
             * @var list<string>
             */
            public static array $scriptedStatuses = [];

            /** @var list<array{data: array<string, mixed>, wallet: int, ttl: int}> */
            public static array $calls = [];

            public static function reset(): void
            {
                self::$scriptedStatuses = [];
                self::$calls            = [];
            }

            /**
             * @param  array<string, mixed> $data
             * @return array{status: string, id: int}
             */
            public static function upsert(array $data, int $walletLinkId, int $ttlSeconds = 14400): array
            {
                self::$calls[] = ['data' => $data, 'wallet' => $walletLinkId, 'ttl' => $ttlSeconds];

                $status = array_shift(self::$scriptedStatuses) ?? 'created';

                return [
                    'status' => $status,
                    'id'     => $status === 'failed' ? 0 : count(self::$calls),
                ];
            }
        }
    }
}
