<?php
/**
 * H6 ChatPrefsReadFailClosedTest: the one ValidatorMsgQueueRepository read
 * the queue path makes before its sender gate (the shared rate-limit
 * count). Anything else throws, so a test cannot silently reach further
 * into the queue than it means to. Load in a separate process only, before
 * MessagesService first touches the class.
 */

declare(strict_types=1);

namespace BCC\Trust\Onchain\Repositories {
    if (!class_exists(__NAMESPACE__ . '\\ValidatorMsgQueueRepository', false)) {
        final class ValidatorMsgQueueRepository
        {
            public static function countRecentBySender(int $senderId, int $windowSeconds): int
            {
                return 0;
            }

            /** @param list<mixed> $args */
            public static function __callStatic(string $name, array $args): never
            {
                throw new \LogicException('ValidatorMsgQueueRepository::' . $name . ' is not stubbed for this test.');
            }
        }
    }
}
