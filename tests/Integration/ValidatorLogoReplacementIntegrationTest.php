<?php

declare(strict_types=1);

namespace {
    // Separate processes only: a minimal WP_Post, as other suites declare.
    if (!class_exists('WP_Post', false)) {
        class WP_Post
        {
            public int $ID = 0;
            public string $post_type = 'post';
            public int $post_parent = 0;
        }
    }
}

namespace BCC\Trust\Onchain\Services {
    // WordPress media calls are unqualified in ValidatorLogoService, so these
    // resolve first. Separate processes guarantee they are declared before
    // any call site in this namespace is first executed.
    if (!function_exists(__NAMESPACE__ . '\\wp_delete_attachment')) {
        function wp_delete_attachment(int $id, bool $force = false)
        {
            $GLOBALS['__logo_deleted'][] = $id;
            unset($GLOBALS['__logo_media'][$id]);
            return (object) ['ID' => $id];
        }
    }
    if (!function_exists(__NAMESPACE__ . '\\attachment_url_to_postid')) {
        function attachment_url_to_postid(string $url): int
        {
            foreach ($GLOBALS['__logo_media'] ?? [] as $id => $m) {
                if ($m['url'] === $url) {
                    return (int) $id;
                }
            }
            return 0;
        }
    }
    if (!function_exists(__NAMESPACE__ . '\\get_attached_file')) {
        /** @return string|false */
        function get_attached_file(int $id)
        {
            return $GLOBALS['__logo_media'][$id]['file'] ?? false;
        }
    }
    if (!function_exists(__NAMESPACE__ . '\\get_post')) {
        function get_post(int $id): ?\WP_Post
        {
            $m = $GLOBALS['__logo_media'][$id] ?? null;
            if ($m === null) {
                return null;
            }
            $p              = new \WP_Post();
            $p->ID          = $id;
            $p->post_type   = $m['type'];
            $p->post_parent = $m['parent'];
            return $p;
        }
    }
}

namespace BCC\Trust\Tests\Integration {

    use BCC\Trust\Onchain\Repositories\ChainRepository;
    use BCC\Trust\Onchain\Repositories\ValidatorRepository;
    use BCC\Trust\Onchain\Services\ValidatorLogoService;
    use PHPUnit\Framework\Attributes\Group;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
    use PHPUnit\Framework\TestCase;

    /**
     * F-42 — a changed validator logo no longer leaves the old image behind.
     *
     * A logo change sideloaded a NEW attachment and never removed the one it
     * replaced, so every Keybase avatar change orphaned another image in the
     * media library; a failed row update orphaned the new upload instead.
     *
     * Real validators table; WordPress media is an in-memory fake keyed by
     * attachment id, so "deleted" is observed rather than inferred.
     */
    #[Group('integration')]
    #[Group('mariadb')]
    #[RunTestsInSeparateProcesses]
    #[PreserveGlobalState(false)]
    final class ValidatorLogoReplacementIntegrationTest extends TestCase
    {
        private int $validatorId = 0;

        protected function setUp(): void
        {
            parent::setUp();
            $GLOBALS['__logo_media']   = [];
            $GLOBALS['__logo_deleted'] = [];

            $wpdb    = $GLOBALS['wpdb'];
            $chainId = ChainRepository::resolveIdAnyState('cosmos');
            self::assertIsInt($chainId);
            $wpdb->query("DELETE FROM `" . ValidatorRepository::table() . "` WHERE operator_address = 'f42logoval'");
            $wpdb->query($wpdb->prepare(
                'INSERT INTO `' . ValidatorRepository::table() . '` (operator_address, chain_id, expires_at) VALUES (%s, %d, UTC_TIMESTAMP())',
                'f42logoval',
                $chainId
            ));
            self::assertSame('', $wpdb->last_error, 'fixture: validator');
            $this->validatorId = (int) $wpdb->insert_id;
        }

        protected function tearDown(): void
        {
            $GLOBALS['wpdb']->query("DELETE FROM `" . ValidatorRepository::table() . "` WHERE operator_address = 'f42logoval'");
            parent::tearDown();
        }

        private function media(int $id, string $url, string $file, int $parent = 0, string $type = 'attachment'): void
        {
            $GLOBALS['__logo_media'][$id] = ['url' => $url, 'file' => $file, 'parent' => $parent, 'type' => $type];
        }

        private function commit(int $newId, string $newUrl, ?string $previousUrl): void
        {
            $m = new \ReflectionMethod(ValidatorLogoService::class, 'commitLogo');
            $m->invoke(null, $this->validatorId, ['id' => $newId, 'url' => $newUrl], 'https://keybase.example/new.jpg', $previousUrl);
        }

        private function storedLogo(): ?string
        {
            $wpdb = $GLOBALS['wpdb'];
            $v = $wpdb->get_var($wpdb->prepare('SELECT logo_url FROM `' . ValidatorRepository::table() . '` WHERE id = %d', $this->validatorId));
            return $v === null ? null : (string) $v;
        }

        public function testAChangedLogoDeletesTheOneItReplaced(): void
        {
            $id = $this->validatorId;
            $this->media(11, 'https://site.test/u/old.png', "/u/validator-{$id}-logo.png");
            $this->media(12, 'https://site.test/u/new.png', "/u/validator-{$id}-logo-1.png");

            $this->commit(12, 'https://site.test/u/new.png', 'https://site.test/u/old.png');

            self::assertSame('https://site.test/u/new.png', $this->storedLogo());
            self::assertSame([11], $GLOBALS['__logo_deleted'], 'the replaced auto-logo is removed');
            self::assertArrayHasKey(12, $GLOBALS['__logo_media'], 'the new logo stays');
        }

        public function testAFirstLogoDeletesNothing(): void
        {
            $this->media(12, 'https://site.test/u/new.png', "/u/validator-{$this->validatorId}-logo.png");

            $this->commit(12, 'https://site.test/u/new.png', null);

            self::assertSame([], $GLOBALS['__logo_deleted']);
        }

        /** Only media this service created for THIS validator is ever deleted. */
        public function testForeignMediaIsNeverDeleted(): void
        {
            $id = $this->validatorId;
            $other = $id + 1;
            $cases = [
                [21, 'https://site.test/u/claimer.png', '/u/my-own-upload.png', 0],          // a claimer's own image
                [22, 'https://site.test/u/otherval.png', "/u/validator-{$other}-logo.png", 0], // another validator's logo
                [23, 'https://site.test/u/child.png', "/u/validator-{$id}-logo.png", 77],      // attached to a post
            ];
            foreach ($cases as [$mid, $url, $file, $parent]) {
                $GLOBALS['__logo_media'] = [];
                $GLOBALS['__logo_deleted'] = [];
                $this->media($mid, $url, $file, $parent);
                $this->media(99, 'https://site.test/u/new.png', "/u/validator-{$id}-logo-9.png");

                $this->commit(99, 'https://site.test/u/new.png', $url);

                self::assertSame([], $GLOBALS['__logo_deleted'], "{$file} (parent {$parent}) must not be deleted");
            }
        }

        public function testAFailedRowUpdateDeletesTheNewUploadAndKeepsTheOld(): void
        {
            $wpdb = $GLOBALS['wpdb'];
            $id   = $this->validatorId;
            $this->media(11, 'https://site.test/u/old.png', "/u/validator-{$id}-logo.png");
            $this->media(12, 'https://site.test/u/new.png', "/u/validator-{$id}-logo-1.png");

            $wpdb->failQueriesMatching = '/^\s*UPDATE `?\S*onchain_validators/i';
            try {
                $this->commit(12, 'https://site.test/u/new.png', 'https://site.test/u/old.png');
                self::assertGreaterThan(0, $wpdb->injectedFailures, 'the fault must actually have fired');
            } finally {
                $wpdb->clearFaultInjection();
            }

            self::assertSame([12], $GLOBALS['__logo_deleted'], 'the unreferenced new upload is removed');
            self::assertArrayHasKey(11, $GLOBALS['__logo_media'], 'the logo the row still points at is kept');
        }
    }
}
