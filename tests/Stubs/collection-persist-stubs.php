<?php

/**
 * Fakes-at-FQN for CollectionPersistBatch tests: the collection repository
 *
 * Scripted per call so a batch can mix created / updated / failed outcomes —
 * which is the whole point: a PARTIAL failure must be distinguishable from a
 * total one, and both from complete success.
 *
 * Plus a DegradationMetrics SPY. #221 records the loss on the health
 * surface from inside persist(), so without this stub every existing case
 * in CollectionPersistBatchTest would fatal on a missing class the moment a
 * batch failed — and a spy, not a no-op, because the point is to assert
 * WHICH (subsystem, event) pairs were recorded and how many times. Shape
 * copied verbatim from tests/Stubs/provisioning-intent-stubs.php so the two
 * cannot drift.
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

namespace BCC\Core\Observability {

    if (!class_exists(__NAMESPACE__ . '\\DegradationMetrics', false)) {
        final class DegradationMetrics
        {
            /** @var list<array{subsystem: string, event: string}> */
            public static array $recorded = [];

            public static function record(string $subsystem, string $event): void
            {
                self::$recorded[] = ['subsystem' => $subsystem, 'event' => $event];
            }

            public static function reset(): void
            {
                self::$recorded = [];
            }

            /**
             * Event names recorded for one subsystem, in order.
             *
             * @return list<string>
             */
            public static function eventsFor(string $subsystem): array
            {
                $out = [];
                foreach (self::$recorded as $row) {
                    if ($row['subsystem'] === $subsystem) {
                        $out[] = $row['event'];
                    }
                }

                return $out;
            }
        }
    }
}
