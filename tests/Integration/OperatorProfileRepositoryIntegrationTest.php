<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Onchain\Repositories\ClaimRepository;
use BCC\Trust\Onchain\Repositories\OperatorProfileRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Real-SQL coverage for the two operator-profile tables.
 *
 * The unit suite for this feature is source-reading only: it proves the
 * handlers call the authority and that the shapers emit the right keys, but
 * every statement here is an `INSERT … ON DUPLICATE KEY UPDATE` whose
 * behaviour lives in the engine, not in the PHP. Three of those behaviours
 * cannot be observed any other way:
 *
 *   1. A partial second write must not blank the columns it does not name.
 *      `updateProfile()` always inserts EVERY column (missing ones as '')
 *      but builds its UPDATE list from only the supplied ones — so the
 *      insert half and the update half disagree on purpose, and only a real
 *      duplicate-key collision shows which half ran.
 *   2. `bio` is `TEXT NOT NULL` with no default, because MySQL forbids a
 *      default on TEXT. Any write that fails to name it is rejected under
 *      STRICT_TRANS_TABLES.
 *   3. The owner-change branch is
 *      `IF(user_id = VALUES(user_id), col, NULL)`, which depends on
 *      `VALUES()` being evaluated against the incoming row while `user_id`
 *      still holds the PREVIOUS owner. That ordering is an engine
 *      guarantee; a mocked $wpdb would return whatever it was queued.
 *
 * ── ⚠⚠⚠ THE THINGS THAT MUST NOT HAPPEN ─────────────────────────────────
 *  1. ONE OPERATOR READS ANOTHER'S PRIVATE OVERRIDE. The overrides table is
 *     keyed on `page_id` ALONE, so a single row per page is all that exists
 *     and `user_id` is just a column on it. Nothing in the schema stops a
 *     read from returning the row to the wrong user — the `(page_id = %d AND
 *     user_id = %d)` pair predicate and the post-fetch re-check are the only
 *     things that do.
 *  2. A NEW OWNER INHERITS THE OLD OWNER'S DRAFT TEXT. Because the row
 *     survives an ownership change (same `page_id`), the previous operator's
 *     unpublished bio would otherwise appear under the new operator's
 *     branding on a public card.
 *  3. A SUPPRESSED FIELD SILENTLY REVERTS. `null` and `''` are different
 *     instructions — inherit versus suppress — and a column that collapsed
 *     one into the other would republish text the operator deliberately
 *     cleared for that page.
 *
 * ⚠ Anti-vacuity is explicit here because the harness makes a missing table
 * SILENT in two separate ways: the bootstrap's `dbDelta` stub only writes
 * schema errors to STDERR and returns normally, and every repository method
 * guards on `TableRegistry::exists()` and returns empty/false when the table
 * is absent. A suite that skipped the existence assertion would report green
 * against no tables at all.
 *
 * ── MEASURED MUTATION RESULTS (MySQL 8.0.35, STRICT_TRANS_TABLES) ───────
 * Each mutation is a mistake a reviewer could plausibly wave through:
 *
 *   M1  drop `user_id = %d` from findOverridesForUser's WHERE
 *         → KILLED (testOverrideReadsAreKeyedOnTheUserAndPagePair)
 *   M2  `IF(user_id = VALUES(user_id), col, NULL)` → `col`
 *         → KILLED, 1 failure
 *           (testTheNewOwnerOfAPageDoesNotInheritThePreviousOperatorsOverrides)
 *   M3  add every field to updateProfile's UPDATE list, not just supplied
 *         → KILLED, 1 failure
 *           (testASecondPartialUpdateDoesNotClearTheFieldsItDoesNotName)
 *   M4  drop findPublicForResolvedPages' post-fetch pair re-check ALONE
 *         → ⚠ SURVIVED, and that is correct: the SQL
 *           `(page_id = %d AND user_id = %d)` predicate already excludes the
 *           row, so the two are genuine defence in depth and NEITHER can be
 *           killed on its own. Recorded rather than deleted — a control that
 *           cannot die is information, not a test to throw away.
 *   M5  remove BOTH pairing layers (SQL predicate AND re-check)
 *         → KILLED, 4 failures, including
 *           testThePublicResolverOmitsAPageWhoseOverrideBelongsToAnotherUser
 *
 * So M4+M5 together are the honest statement: the pairing is enforced twice,
 * and this suite fails the moment it stops being enforced at all.
 */
#[Group('integration')]
#[Group('mariadb')]
#[CoversClass(OperatorProfileRepository::class)]
final class OperatorProfileRepositoryIntegrationTest extends TestCase
{
    private const OPERATOR_A = 9101;
    private const OPERATOR_B = 9102;
    private const PAGE_ONE   = 9201;
    private const PAGE_TWO   = 9202;

    private OperatorProfileRepository $repo;

    protected function setUp(): void
    {
        global $wpdb;

        foreach ([TableRegistry::operatorProfiles(), TableRegistry::operatorProfileOverrides()] as $table) {
            // ⚠ Asserted, not fired and forgotten: $wpdb->query() returns
            // false silently, and a failed TRUNCATE would leak the previous
            // case's rows into this one.
            self::assertNotFalse(
                $wpdb->query('TRUNCATE TABLE `' . $table . '`'),
                "fixture TRUNCATE of {$table} must succeed"
            );
        }

        $this->repo = new OperatorProfileRepository();
    }

    // ── 0. anti-vacuity ───────────────────────────────────────────────

    /**
     * Both tables must really be there, and the two INDEPENDENT name
     * derivations must agree.
     *
     * The installer names its tables through `BCC\Core\DB\DB::table()`
     * (`$wpdb->prefix . 'bcc_' . $name`) while the repository reads them
     * through `TableRegistry` (`$wpdb->prefix . 'bcc_operator_profiles'`).
     * Nothing links the two spellings. If they ever drift, the installer
     * creates one table and every read guards itself out on another — which
     * presents as "the operator has no profile", not as an error.
     */
    public function testBothTablesExistAndTheTwoNameDerivationsAgree(): void
    {
        global $wpdb;

        $profiles  = TableRegistry::operatorProfiles();
        $overrides = TableRegistry::operatorProfileOverrides();

        self::assertSame(
            \BCC\Core\DB\DB::table('operator_profiles'),
            $profiles,
            'the installer and the repository must name the same profiles table'
        );
        self::assertSame(
            \BCC\Core\DB\DB::table('operator_profile_overrides'),
            $overrides,
            'the installer and the repository must name the same overrides table'
        );

        foreach ([$profiles, $overrides] as $table) {
            self::assertSame(
                $table,
                $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)),
                "{$table} must have been created by the real installer"
            );
            self::assertTrue(TableRegistry::exists($table), "TableRegistry must see {$table}");
        }
    }

    /**
     * The nullability split IS the inheritance contract, so it is asserted
     * against INFORMATION_SCHEMA rather than inferred from behaviour.
     *
     * Shared `bio` is NOT NULL (an operator either has text or has ''), and
     * override `bio` is NULLABLE (null is the instruction "inherit"). Read
     * from the catalog rather than by probing a write, because a probe's
     * result depends on `sql_mode`, which differs between the two CI engines.
     */
    public function testTheNullabilitySplitThatCarriesTheInheritanceContract(): void
    {
        global $wpdb;

        $shared = $this->columnIsNullable(TableRegistry::operatorProfiles(), 'bio');
        self::assertSame('NO', $shared, 'shared bio must be NOT NULL');

        $override = $this->columnIsNullable(TableRegistry::operatorProfileOverrides(), 'bio');
        self::assertSame('YES', $override, 'override bio must be NULLABLE — null means inherit');

        // And the shared column genuinely has no default, which is why every
        // insert path has to name it.
        //
        // ⚠ Engine difference, tolerated deliberately: MariaDB reports "no
        // default" as the four-character STRING 'NULL' for some columns while
        // MySQL reports a real SQL NULL. Both mean the same thing, so both are
        // accepted — and an actual default (e.g. DEFAULT '') still fails this,
        // because it would come back as ''.
        $default = $wpdb->get_var($wpdb->prepare(
            'SELECT COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            TableRegistry::operatorProfiles(),
            'bio'
        ));
        self::assertTrue(
            $default === null || $default === 'NULL',
            'TEXT cannot carry a default; the writer must supply bio (got: ' . var_export($default, true) . ')'
        );
    }

    // ── 1. shared profile: save and read ──────────────────────────────

    public function testASharedProfileRoundTripsThroughRealSql(): void
    {
        self::assertTrue($this->repo->updateProfile(self::OPERATOR_A, [
            'brand_name'   => 'Anvil Validation',
            'bio'          => 'Running validators since the first halving.',
            'website_url'  => 'https://anvil.example',
            'x_url'        => 'https://x.com/anvil',
            'discord_url'  => 'https://discord.gg/anvil',
            'telegram_url' => 'https://t.me/anvil',
        ]));

        $profile = $this->repo->findProfileForUser(self::OPERATOR_A);

        self::assertNotNull($profile, 'the profile must be readable after the write');
        self::assertSame(self::OPERATOR_A, $profile['user_id']);
        self::assertSame('Anvil Validation', $profile['brand_name']);
        self::assertSame('Running validators since the first halving.', $profile['bio']);
        self::assertSame('https://anvil.example', $profile['website_url']);
        self::assertSame('https://x.com/anvil', $profile['x_url']);
        self::assertSame('https://discord.gg/anvil', $profile['discord_url']);
        self::assertSame('https://t.me/anvil', $profile['telegram_url']);
        self::assertNull($profile['logo_attachment_id'], 'no logo was uploaded');
        self::assertNotSame('', $profile['updated_at'], 'updated_at must be stamped');
    }

    /**
     * ⚠ The defaultless NOT NULL column, proved by the value rather than by
     * an error code. A write naming only `brand_name` must still land a row
     * whose `bio` is '' — never NULL, and never a rejected insert.
     *
     * Asserting the stored value instead of "the insert throws" keeps this
     * engine-independent: CI's MariaDB runs with `sql_mode` set to
     * `NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION`, which does NOT include
     * STRICT_TRANS_TABLES, while MySQL 8 is strict by default. A test that
     * demanded a refusal would pass on one engine and fail on the other for
     * a reason that has nothing to do with this code.
     */
    public function testAPartialFirstWriteStillNamesEveryColumnSoBioIsNeverNull(): void
    {
        self::assertTrue($this->repo->updateProfile(self::OPERATOR_A, [
            'brand_name' => 'Only A Name',
        ]));

        $raw = $this->rawProfile(self::OPERATOR_A);

        self::assertNotNull($raw, 'the row must exist');
        self::assertSame('Only A Name', $raw->brand_name);
        self::assertNotNull($raw->bio, 'bio must never be stored as NULL');
        self::assertSame('', $raw->bio, 'an unsupplied bio is the empty string');
        self::assertSame('', $raw->website_url);
    }

    /**
     * ⚠⚠ THE INSERT HALF AND THE UPDATE HALF DISAGREE ON PURPOSE, and only a
     * real duplicate-key collision reveals which one ran. The INSERT lists
     * every column (unsupplied ones as ''), while the UPDATE list is built
     * only from the supplied keys. So a second write naming one field must
     * leave the rest standing — if the insert half were ever reached again,
     * the operator's bio and links would be silently blanked.
     */
    public function testASecondPartialUpdateDoesNotClearTheFieldsItDoesNotName(): void
    {
        self::assertTrue($this->repo->updateProfile(self::OPERATOR_A, [
            'brand_name'  => 'First Name',
            'bio'         => 'Keep this bio.',
            'website_url' => 'https://keep.example',
        ]));

        self::assertTrue($this->repo->updateProfile(self::OPERATOR_A, [
            'brand_name' => 'Second Name',
        ]));

        $profile = $this->repo->findProfileForUser(self::OPERATOR_A);

        self::assertNotNull($profile);
        self::assertSame('Second Name', $profile['brand_name'], 'the named field changes');
        self::assertSame('Keep this bio.', $profile['bio'], 'an unnamed field must survive');
        self::assertSame('https://keep.example', $profile['website_url'], 'an unnamed field must survive');
        self::assertSame(1, $this->countProfiles(), 'the second write must UPDATE, not insert a second row');
    }

    public function testSettingAndClearingALogoLeavesTheTextFieldsAlone(): void
    {
        self::assertTrue($this->repo->updateProfile(self::OPERATOR_A, [
            'brand_name' => 'Logo Owner',
            'bio'        => 'Bio survives a logo change.',
        ]));

        self::assertTrue($this->repo->setLogoAttachment(self::OPERATOR_A, 4242));
        $withLogo = $this->repo->findProfileForUser(self::OPERATOR_A);
        self::assertNotNull($withLogo);
        self::assertSame(4242, $withLogo['logo_attachment_id']);
        self::assertSame('Logo Owner', $withLogo['brand_name'], 'text must not be disturbed');
        self::assertSame('Bio survives a logo change.', $withLogo['bio']);

        self::assertTrue($this->repo->setLogoAttachment(self::OPERATOR_A, null));
        $cleared = $this->repo->findProfileForUser(self::OPERATOR_A);
        self::assertNotNull($cleared);
        self::assertNull($cleared['logo_attachment_id'], 'removing the logo nulls the pointer');
        self::assertSame('Logo Owner', $cleared['brand_name'], 'text must still not be disturbed');
    }

    // ── 2. overrides and inheritance ──────────────────────────────────

    /**
     * The whole precedence rule in one case, because the three outcomes are
     * only meaningful against each other: an override value WINS, `null`
     * INHERITS the shared value, and `''` SUPPRESSES the field for that page
     * rather than falling back to the shared text.
     */
    public function testAnOverrideWinsWhileNullInheritsAndAnEmptyStringSuppresses(): void
    {
        $this->seedSharedProfile();

        self::assertTrue($this->repo->updatePageOverrides(self::OPERATOR_A, self::PAGE_ONE, [
            'brand_name'  => 'Page One Brand',
            'bio'         => null,
            'website_url' => '',
        ]));

        $effective = $this->effectiveFor(self::PAGE_ONE, self::OPERATOR_A);

        self::assertSame('Page One Brand', $effective['brand_name'], 'the override wins');
        self::assertSame('Shared bio.', $effective['bio'], 'null inherits the shared value');
        self::assertSame('', $effective['website_url'], 'an empty string suppresses, it does not inherit');
        self::assertSame('https://x.com/shared', $effective['x_url'], 'an untouched field inherits');
    }

    public function testAnOverrideIsScopedToItsOwnPage(): void
    {
        $this->seedSharedProfile();

        self::assertTrue($this->repo->updatePageOverrides(self::OPERATOR_A, self::PAGE_ONE, [
            'brand_name' => 'Page One Only',
        ]));

        $resolved = $this->repo->findPublicForResolvedPages([
            self::PAGE_ONE => ['state' => ClaimRepository::OPERATOR_RESOLVED, 'user_id' => self::OPERATOR_A],
            self::PAGE_TWO => ['state' => ClaimRepository::OPERATOR_RESOLVED, 'user_id' => self::OPERATOR_A],
        ]);

        self::assertSame('Page One Only', $resolved[self::PAGE_ONE]['brand_name']);
        self::assertSame('Shared Brand', $resolved[self::PAGE_TWO]['brand_name'], 'page two must be untouched');
    }

    // ── 3. clearing an override ───────────────────────────────────────

    public function testWritingNullClearsAnOverrideBackToInheritance(): void
    {
        $this->seedSharedProfile();

        self::assertTrue($this->repo->updatePageOverrides(self::OPERATOR_A, self::PAGE_ONE, [
            'brand_name' => 'Temporary Brand',
        ]));
        self::assertSame(
            'Temporary Brand',
            $this->effectiveFor(self::PAGE_ONE, self::OPERATOR_A)['brand_name']
        );

        self::assertTrue($this->repo->updatePageOverrides(self::OPERATOR_A, self::PAGE_ONE, [
            'brand_name' => null,
        ]));

        self::assertSame(
            'Shared Brand',
            $this->effectiveFor(self::PAGE_ONE, self::OPERATOR_A)['brand_name'],
            'a null write restores inheritance'
        );
        $stored = $this->repo->findOverridesForUser(self::OPERATOR_A, [self::PAGE_ONE]);
        self::assertNull($stored[self::PAGE_ONE]['brand_name'], 'the column itself must be NULL, not ""');
    }

    /**
     * `deletePageOverrides()` takes a page id ALONE — authorization happens
     * at the endpoint, so the repository's own contract is simply "remove
     * this page's row and nothing else". Both halves of that matter: the
     * shared profile must survive (otherwise a per-page reset would wipe the
     * operator's branding everywhere) and a sibling page's override must
     * survive (otherwise resetting one chain resets them all).
     */
    public function testDeletingOnePagesOverrideLeavesTheSharedProfileAndSiblingPages(): void
    {
        $this->seedSharedProfile();

        self::assertTrue($this->repo->updatePageOverrides(self::OPERATOR_A, self::PAGE_ONE, [
            'brand_name' => 'One Brand',
        ]));
        self::assertTrue($this->repo->updatePageOverrides(self::OPERATOR_A, self::PAGE_TWO, [
            'brand_name' => 'Two Brand',
        ]));
        self::assertSame(2, $this->countOverrides(), 'both override rows must exist first');

        self::assertTrue($this->repo->deletePageOverrides(self::PAGE_ONE));

        self::assertSame(1, $this->countOverrides(), 'exactly one row is removed');

        $remaining = $this->repo->findOverridesForUser(self::OPERATOR_A, [self::PAGE_ONE, self::PAGE_TWO]);
        self::assertArrayNotHasKey(self::PAGE_ONE, $remaining, 'page one has no override row');
        self::assertSame('Two Brand', $remaining[self::PAGE_TWO]['brand_name'], 'page two survives');

        $profile = $this->repo->findProfileForUser(self::OPERATOR_A);
        self::assertNotNull($profile, 'the shared profile must survive a per-page reset');
        self::assertSame('Shared Brand', $profile['brand_name']);

        self::assertSame(
            'Shared Brand',
            $this->effectiveFor(self::PAGE_ONE, self::OPERATOR_A)['brand_name'],
            'page one falls back to the shared value'
        );
    }

    // ── 4. one operator must not reach another's data ─────────────────

    public function testOneOperatorsSharedProfileIsNeverReturnedForAnother(): void
    {
        $this->seedSharedProfile();

        self::assertNull(
            $this->repo->findProfileForUser(self::OPERATOR_B),
            "operator B has no profile and must not be handed operator A's"
        );
        self::assertSame(1, $this->countProfiles(), 'only one profile row exists');
    }

    /**
     * ⚠ The overrides table is keyed on `page_id` ALONE, so there is exactly
     * one row per page and `user_id` is an ordinary column on it. The read's
     * `user_id = %d` predicate is the ONLY thing standing between operator B
     * and operator A's unpublished page text.
     */
    public function testOverrideReadsAreKeyedOnTheUserAndPagePair(): void
    {
        $this->seedSharedProfile();

        self::assertTrue($this->repo->updatePageOverrides(self::OPERATOR_A, self::PAGE_ONE, [
            'brand_name' => 'A private draft',
            'bio'        => 'A private bio.',
        ]));

        $asB = $this->repo->findOverridesForUser(self::OPERATOR_B, [self::PAGE_ONE]);

        self::assertSame([], $asB, "operator B must read nothing for a page row owned by operator A");

        // Positive control: the same call as the real owner DOES return it,
        // so the empty result above is the predicate working rather than an
        // empty table or a mistyped page id.
        $asA = $this->repo->findOverridesForUser(self::OPERATOR_A, [self::PAGE_ONE]);
        self::assertSame('A private draft', $asA[self::PAGE_ONE]['brand_name']);
    }

    /**
     * ⚠⚠⚠ THE SHARPEST CASE. A validator page's verified operator can
     * change — the claim moves, the page id does not. Because the override
     * row is keyed on `page_id`, the PREVIOUS operator's row is still
     * sitting there when the new operator makes their first write.
     *
     * `updatePageOverrides()` handles it with
     * `col = IF(user_id = VALUES(user_id), col, NULL)` for every field the
     * caller did NOT supply, and updates `user_id` LAST so that comparison
     * still sees the old owner. The effect must be: the new owner's supplied
     * field lands, and every field they did not supply is NULLED rather than
     * inherited from a stranger.
     *
     * Get this wrong and the previous operator's unpublished bio appears on
     * a public card under the new operator's name.
     */
    public function testTheNewOwnerOfAPageDoesNotInheritThePreviousOperatorsOverrides(): void
    {
        self::assertTrue($this->repo->updatePageOverrides(self::OPERATOR_A, self::PAGE_ONE, [
            'brand_name'  => 'A brand',
            'bio'         => 'A private bio that must not survive.',
            'website_url' => 'https://a-private.example',
        ]));

        // The page changes hands; B's first write names only brand_name.
        self::assertTrue($this->repo->updatePageOverrides(self::OPERATOR_B, self::PAGE_ONE, [
            'brand_name' => 'B brand',
        ]));

        $raw = $this->rawOverride(self::PAGE_ONE);
        self::assertNotNull($raw);
        self::assertSame(self::OPERATOR_B, (int) $raw->user_id, 'the row now belongs to B');
        self::assertSame('B brand', $raw->brand_name, "B's supplied value lands");
        self::assertNull($raw->bio, "A's bio must be NULLED, not inherited");
        self::assertNull($raw->website_url, "A's website must be NULLED, not inherited");
        self::assertSame(1, $this->countOverrides(), 'still one row for this page');

        // And A can no longer read the row they used to own.
        self::assertSame([], $this->repo->findOverridesForUser(self::OPERATOR_A, [self::PAGE_ONE]));
    }

    /**
     * The public read path, from the other direction: when the stored
     * override row belongs to someone else and the resolved operator has no
     * profile of their own, the page must be OMITTED entirely rather than
     * rendered with a stranger's branding.
     */
    public function testThePublicResolverOmitsAPageWhoseOverrideBelongsToAnotherUser(): void
    {
        self::assertTrue($this->repo->updatePageOverrides(self::OPERATOR_A, self::PAGE_ONE, [
            'brand_name' => 'A brand nobody else may see',
        ]));

        $resolved = $this->repo->findPublicForResolvedPages([
            self::PAGE_ONE => ['state' => ClaimRepository::OPERATOR_RESOLVED, 'user_id' => self::OPERATOR_B],
        ]);

        self::assertSame([], $resolved, 'B owns no profile and must not inherit A\'s override row');

        // Positive control: resolved to A, the same page DOES render.
        $asA = $this->repo->findPublicForResolvedPages([
            self::PAGE_ONE => ['state' => ClaimRepository::OPERATOR_RESOLVED, 'user_id' => self::OPERATOR_A],
        ]);
        self::assertSame('A brand nobody else may see', $asA[self::PAGE_ONE]['brand_name']);
    }

    /**
     * An unresolved page is never served, whichever way it failed to
     * resolve. Both states are checked because they reach the guard through
     * different values — AMBIGUOUS carries a null user id, UNCLAIMED fails
     * on the state string alone.
     */
    public function testAnAmbiguousOrUnclaimedPageIsNeverResolved(): void
    {
        $this->seedSharedProfile();

        self::assertSame([], $this->repo->findPublicForResolvedPages([
            self::PAGE_ONE => ['state' => ClaimRepository::OPERATOR_AMBIGUOUS, 'user_id' => null],
        ]), 'an ambiguous page must never resolve');

        self::assertSame([], $this->repo->findPublicForResolvedPages([
            self::PAGE_ONE => ['state' => ClaimRepository::OPERATOR_UNCLAIMED, 'user_id' => null],
        ]), 'an unclaimed page must never resolve');

        // Positive control with the same seeded profile, so the two empties
        // above are the state gate rather than a missing profile.
        self::assertArrayHasKey(self::PAGE_ONE, $this->repo->findPublicForResolvedPages([
            self::PAGE_ONE => ['state' => ClaimRepository::OPERATOR_RESOLVED, 'user_id' => self::OPERATOR_A],
        ]));
    }

    // ── helpers ───────────────────────────────────────────────────────

    private function seedSharedProfile(): void
    {
        self::assertTrue($this->repo->updateProfile(self::OPERATOR_A, [
            'brand_name'   => 'Shared Brand',
            'bio'          => 'Shared bio.',
            'website_url'  => 'https://shared.example',
            'x_url'        => 'https://x.com/shared',
            'discord_url'  => 'https://discord.gg/shared',
            'telegram_url' => 'https://t.me/shared',
        ]), 'fixture shared profile must be written');
    }

    /**
     * @return array<string, mixed>
     */
    private function effectiveFor(int $pageId, int $userId): array
    {
        $resolved = $this->repo->findPublicForResolvedPages([
            $pageId => ['state' => ClaimRepository::OPERATOR_RESOLVED, 'user_id' => $userId],
        ]);

        self::assertArrayHasKey($pageId, $resolved, "page {$pageId} must resolve for user {$userId}");

        return $resolved[$pageId];
    }

    private function columnIsNullable(string $table, string $column): ?string
    {
        global $wpdb;

        $value = $wpdb->get_var($wpdb->prepare(
            'SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            $table,
            $column
        ));

        self::assertNotNull($value, "{$table}.{$column} must exist in the catalog");

        return is_string($value) ? $value : null;
    }

    private function rawProfile(int $userId): ?object
    {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . TableRegistry::operatorProfiles() . ' WHERE user_id = %d',
            $userId
        ));
    }

    private function rawOverride(int $pageId): ?object
    {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . TableRegistry::operatorProfileOverrides() . ' WHERE page_id = %d',
            $pageId
        ));
    }

    private function countProfiles(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . TableRegistry::operatorProfiles());
    }

    private function countOverrides(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . TableRegistry::operatorProfileOverrides());
    }
}
