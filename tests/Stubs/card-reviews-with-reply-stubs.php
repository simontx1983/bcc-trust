<?php

declare(strict_types=1);

/**
 * CardReviewsWithReplyTest double: Plugin::reviewReplyService()->withAuthor()
 * swaps `author_id` for a recognisable `author` and records each call, so the
 * test sees which reply objects the row mapper hydrated. Load only in a
 * separate process.
 */

namespace BCC\Trust\Core {
    if (!class_exists(__NAMESPACE__ . '\\Plugin', false)) {
        final class Plugin
        {
            private static ?self $instance = null;

            /** @var list<array{author_id: int, viewer: int, prefetched: array<string, mixed>|null}> */
            public static array $withAuthorCalls = [];

            public static function instance(): self
            {
                return self::$instance ??= new self();
            }

            public function reviewReplyService(): object
            {
                return new class {
                    /**
                     * @param array<string, mixed> $reply
                     * @param array<string, mixed>|null $prefetched
                     * @return array<string, mixed>
                     */
                    public function withAuthor(array $reply, int $viewerId, ?array $prefetched): array
                    {
                        Plugin::$withAuthorCalls[] = ['author_id' => (int) $reply['author_id'], 'viewer' => $viewerId, 'prefetched' => $prefetched];
                        $out           = $reply;
                        $out['author'] = ['id' => $reply['author_id']];
                        unset($out['author_id']);
                        return $out;
                    }
                };
            }
        }
    }
}
