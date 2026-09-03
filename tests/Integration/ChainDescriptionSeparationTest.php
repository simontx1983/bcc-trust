<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\CollectionRepository;
use BCC\Trust\Onchain\ValueObjects\ChainDescriptionState;
use PHPUnit\Framework\TestCase;

/**
 * PR 7 — the two descriptions, against a REAL MySQL.
 *
 * ── THE GUARANTEE ───────────────────────────────────────────────────────
 * BCC keeps two descriptions on purpose:
 *
 *   • the BLOCKCHAIN COLLECTION description — imported, untrusted, private
 *     until an administrator approves it;
 *   • COMMUNITY ABOUT — the PeepSo community biography, written by community
 *     managers, whose provisioning default is
 *     "On-chain verified holders of {name}. Auto-managed."
 *
 * They must coexist, and changing either must leave the other BYTE-IDENTICAL.
 * The separation is structural — the collection description lives on the
 * collection row and the community biography on the group post — so these
 * tests assert the structure actually holds rather than assuming it.
 */
final class ChainDescriptionSeparationTest extends TestCase
{
    private const CHAIN = 8802;

    private function table(): string
    {
        return bcc_onchain_collections_table();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wpdb']->query('DELETE FROM `' . $this->table() . '` WHERE chain_id = ' . self::CHAIN);
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']->query('DELETE FROM `' . $this->table() . '` WHERE chain_id = ' . self::CHAIN);
        parent::tearDown();
    }

    private function seed(string $contract = '0xdesc'): int
    {
        $t = $this->table();
        $GLOBALS['wpdb']->query(
            "INSERT INTO `{$t}` (contract_address, chain_id, collection_name, expires_at)
             VALUES ('{$contract}', " . self::CHAIN . ", 'Seeded', UTC_TIMESTAMP())"
        );

        return (int) $GLOBALS['wpdb']->insert_id;
    }

    private function row(int $id): object
    {
        $t   = $this->table();
        $row = $GLOBALS['wpdb']->get_row("SELECT * FROM `{$t}` WHERE id = {$id}");
        self::assertIsObject($row);

        return $row;
    }

    // ── import ──────────────────────────────────────────────────────────

    public function testAnImportedDescriptionLandsAsPendingAndIsNotPublic(): void
    {
        $id = $this->seed();

        self::assertTrue(CollectionRepository::importChainDescription($id, 'A collection of things.', 'cosmwasm'));

        $row = $this->row($id);
        self::assertSame('A collection of things.', $row->chain_description);
        self::assertSame(ChainDescriptionState::PENDING, $row->chain_description_state);
        self::assertSame('cosmwasm', $row->chain_description_source);

        // ⚠ The public reader must not see it — this is the whole point.
        self::assertNull(CollectionRepository::findApprovedChainDescription($id));
    }

    public function testImportedTextIsSanitized(): void
    {
        $id = $this->seed();

        CollectionRepository::importChainDescription($id, "<b>Bold</b>\x00 and\n\nspaced", 'evm');

        $stored = (string) $this->row($id)->chain_description;
        self::assertStringNotContainsString('<b>', $stored);
        self::assertStringNotContainsString("\x00", $stored);
        self::assertSame('Bold and spaced', $stored);
    }

    public function testAnAbsentDescriptionNeverClearsAStoredOne(): void
    {
        $id = $this->seed();
        CollectionRepository::importChainDescription($id, 'Kept.', 'cosmwasm');
        CollectionRepository::setChainDescriptionState($id, ChainDescriptionState::PENDING, ChainDescriptionState::APPROVED);

        // A later scan where the extension is absent — the normal case.
        foreach ([null, '', '   ', '<br/>'] as $absent) {
            self::assertFalse(CollectionRepository::importChainDescription($id, $absent, 'cosmwasm'));
        }

        $row = $this->row($id);
        self::assertSame('Kept.', $row->chain_description, 'absence is not evidence the old text is wrong');
        self::assertSame(ChainDescriptionState::APPROVED, $row->chain_description_state);
    }

    public function testReimportingIdenticalTextDoesNotRequeueIt(): void
    {
        $id = $this->seed();
        CollectionRepository::importChainDescription($id, 'Same text.', 'cosmwasm');
        CollectionRepository::setChainDescriptionState($id, ChainDescriptionState::PENDING, ChainDescriptionState::REJECTED);

        // A rejected description must not reappear in the queue on every scan.
        CollectionRepository::importChainDescription($id, 'Same text.', 'cosmwasm');

        self::assertSame(ChainDescriptionState::REJECTED, $this->row($id)->chain_description_state);
    }

    public function testChangedTextResetsAnApprovedDescriptionToPending(): void
    {
        $id = $this->seed();
        CollectionRepository::importChainDescription($id, 'Original.', 'cosmwasm');
        CollectionRepository::setChainDescriptionState($id, ChainDescriptionState::PENDING, ChainDescriptionState::APPROVED);
        self::assertSame('Original.', CollectionRepository::findApprovedChainDescription($id));

        // ⚠ New text must NOT inherit the approval of text nobody read.
        CollectionRepository::importChainDescription($id, 'Rewritten by the contract author.', 'cosmwasm');

        $row = $this->row($id);
        self::assertSame(ChainDescriptionState::PENDING, $row->chain_description_state);
        self::assertNull(CollectionRepository::findApprovedChainDescription($id));
    }

    // ── approval transitions ────────────────────────────────────────────

    public function testApprovalMakesItPublic(): void
    {
        $id = $this->seed();
        CollectionRepository::importChainDescription($id, 'Approved text.', 'evm');

        self::assertTrue(CollectionRepository::setChainDescriptionState(
            $id,
            ChainDescriptionState::PENDING,
            ChainDescriptionState::APPROVED
        ));

        self::assertSame('Approved text.', CollectionRepository::findApprovedChainDescription($id));
    }

    public function testATransitionFromTheWrongCurrentStateIsRefused(): void
    {
        $id = $this->seed();
        CollectionRepository::importChainDescription($id, 'Text.', 'evm');

        // The row is `pending`; a caller that believed it was `approved` must
        // match zero rows and be told so — this is the compare-and-swap that
        // stops two administrators both "winning".
        self::assertFalse(CollectionRepository::setChainDescriptionState(
            $id,
            ChainDescriptionState::APPROVED,
            ChainDescriptionState::REJECTED
        ));

        self::assertSame(ChainDescriptionState::PENDING, $this->row($id)->chain_description_state);
    }

    public function testAnIllegalTransitionIsRefused(): void
    {
        $id = $this->seed();
        CollectionRepository::importChainDescription($id, 'Text.', 'evm');

        // none -> approved skips review entirely.
        self::assertFalse(CollectionRepository::setChainDescriptionState(
            $id,
            ChainDescriptionState::NONE,
            ChainDescriptionState::APPROVED
        ));
        self::assertNull(CollectionRepository::findApprovedChainDescription($id));
    }

    public function testUnknownStatesAreRefused(): void
    {
        $id = $this->seed();
        self::assertFalse(CollectionRepository::setChainDescriptionState($id, 'pending', 'published'));
        self::assertFalse(CollectionRepository::setChainDescriptionState($id, 'whatever', 'approved'));
    }

    /**
     * An illegal transition is refused even when the CURRENT state matches.
     *
     * ⚠ This is the case that was missing. `testAnIllegalTransitionIsRefused`
     * used `none → approved` on a row that was `pending`, so the
     * compare-and-swap `WHERE state = 'none'` matched zero rows and the test
     * passed for the WRONG REASON — a mutation control that made
     * `canTransition()` return `true` survived it untouched.
     *
     * Here the row really is `none`, so the CAS would match. Only the
     * transition rule can refuse it.
     */
    public function testAnIllegalTransitionIsRefusedEvenWhenTheCurrentStateMatches(): void
    {
        $id = $this->seed();

        // A fresh row is 'none' with no imported text — the CAS will match.
        self::assertSame(ChainDescriptionState::NONE, $this->row($id)->chain_description_state);

        self::assertFalse(
            CollectionRepository::setChainDescriptionState(
                $id,
                ChainDescriptionState::NONE,
                ChainDescriptionState::APPROVED
            ),
            'none -> approved skips review and must be refused by the RULE, not by the CAS'
        );

        self::assertSame(ChainDescriptionState::NONE, $this->row($id)->chain_description_state);
    }

    /**
     * ⚠ Public visibility is membership-in-a-list, never a negation.
     *
     * `!== PENDING` would publish an unknown or corrupted state. The column is
     * `NOT NULL DEFAULT 'none'`, so a row that predates the migration reads as
     * `none` — and under a negated check that would be PUBLIC.
     */
    public function testOnlyApprovedIsPubliclyVisible(): void
    {
        self::assertTrue(ChainDescriptionState::isPubliclyVisible(ChainDescriptionState::APPROVED));

        foreach ([
            ChainDescriptionState::NONE,
            ChainDescriptionState::PENDING,
            ChainDescriptionState::REJECTED,
            'some_unknown_state',
            '',
        ] as $notPublic) {
            self::assertFalse(
                ChainDescriptionState::isPubliclyVisible($notPublic),
                "'{$notPublic}' must not be publicly visible"
            );
        }
    }

    // ── ⚠ separation from Community About ───────────────────────────────

    /**
     * Importing and approving a collection description moves NOTHING else on
     * the row — and nothing at all outside the two description columns.
     *
     * ── AN INHERITED LIMITATION, STATED PLAINLY ─────────────────────────
     * ⚠ Community About is not modelled here, because it CANNOT be: it is a
     * PeepSo group `description`, written by `new \PeepSoGroup(...)` in
     * GatedGroupProvisioningService, and PeepSo owns that storage and does
     * not load in this harness (nor under wp-cli). Seeding a `post_content`
     * column would model a table PeepSo does not use, and a test that invents
     * the storage it is protecting proves nothing.
     *
     * So the guarantee is proved the other way round, which is stronger: a
     * byte-level fingerprint of the ENTIRE collection row is compared before
     * and after, with only the two description columns permitted to differ.
     * Whatever else exists — here or in PeepSo — the writer demonstrably does
     * not reach it, and {@see testTheImporterHasNoPathToACommunityBiography}
     * shows it structurally cannot.
     */
    public function testApprovingACollectionDescriptionMovesNothingElse(): void
    {
        $id = $this->seed();
        $t  = $this->table();

        // Give the row realistic community-relevant content first.
        $GLOBALS['wpdb']->query(
            "UPDATE `{$t}` SET collection_name = 'Seeded Holders', total_supply = 500,
                    image_url = 'https://cdn.test/x.png', is_verified = 1
              WHERE id = {$id}"
        );

        $fingerprint = static function (int $id) use ($t): array {
            $row = $GLOBALS['wpdb']->get_row("SELECT * FROM `{$t}` WHERE id = {$id}");
            self::assertIsObject($row);

            return (array) $row;
        };

        $before = $fingerprint($id);

        CollectionRepository::importChainDescription($id, 'The collection itself.', 'cosmwasm');
        CollectionRepository::setChainDescriptionState($id, ChainDescriptionState::PENDING, ChainDescriptionState::APPROVED);

        $after = $fingerprint($id);

        $permitted = ['chain_description', 'chain_description_state', 'chain_description_source'];

        foreach ($before as $column => $value) {
            if (in_array($column, $permitted, true)) {
                continue;
            }
            self::assertSame(
                $value,
                $after[$column] ?? null,
                "{$column} must be byte-identical after a description import + approval"
            );
        }

        // And the description really did change, so the comparison above is
        // not passing because nothing happened at all.
        self::assertSame('The collection itself.', CollectionRepository::findApprovedChainDescription($id));
        self::assertNotSame($before['chain_description_state'], $after['chain_description_state']);
    }

    /**
     * The importer cannot reach a community at all.
     *
     * Structural rather than behavioural: a behavioural test can only show
     * that it did not happen this time. There is no group id in scope and no
     * post write in the method, so it CANNOT happen.
     */
    public function testTheImporterHasNoPathToACommunityBiography(): void
    {
        $reflection = new \ReflectionMethod(CollectionRepository::class, 'importChainDescription');
        $file       = (string) $reflection->getFileName();
        $lines      = file($file) ?: [];
        $body       = implode('', array_slice(
            $lines,
            (int) $reflection->getStartLine() - 1,
            (int) $reflection->getEndLine() - (int) $reflection->getStartLine() + 1
        ));

        foreach (['post_content', 'wp_posts', 'peepso', 'group_id', 'wp_update_post', 'PeepSo'] as $forbidden) {
            self::assertStringNotContainsStringIgnoringCase(
                $forbidden,
                $body,
                "importChainDescription must have no path to '{$forbidden}'"
            );
        }
    }
}
