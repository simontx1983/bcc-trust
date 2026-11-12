<?php
/**
 * ValidatorLogoService — auto-import a validator's logo into WP media.
 *
 * Runs inside the hourly enrichment (EnrichmentScheduler::enrichRow) after
 * the signal upsert. For a validator with a usable identity hint it:
 *
 *   1. Resolves a remote image URL via the chain's LogoResolver
 *      (Phase 1: KeybaseLogoResolver for Cosmos `description.identity`).
 *   2. Downloads it through the SSRF-hardened transport (ApiRetry →
 *      SafeHttpClient: no redirects, private-IP/metadata blocks, DNS
 *      pinning, size cap) and re-validates the bytes are a real image.
 *   3. Sideloads it into the WP media library and stores the LOCAL url on
 *      the validator row (CardViewService reads it as the lowest-precedence
 *      crest source, so a claimer/manual upload always wins).
 *
 * Design rules:
 *   - Best-effort + non-fatal: a logo miss is cosmetic and must never fail
 *     enrichment. Every failure path swallows + logs + stamps the throttle.
 *   - Throttled: re-resolution at most every RECHECK_INTERVAL so we don't
 *     hammer Keybase. An identity change is picked up within that window.
 *   - Low-trust: validator-info on some chains is unauthenticated-writable;
 *     the auto logo is cosmetic and the claimer override is the trust anchor.
 *
 * @package BCC\Trust\Onchain\Services
 */

namespace BCC\Trust\Onchain\Services;

use BCC\Core\Log\Logger;
use BCC\Trust\Onchain\Contracts\LogoResolver;
use BCC\Trust\Onchain\Repositories\ValidatorRepository;
use BCC\Trust\Onchain\Support\ApiRetry;

if (!defined('ABSPATH')) {
    exit;
}

final class ValidatorLogoService
{
    /** Re-resolve a logo at most this often once it has been checked. */
    private const RECHECK_INTERVAL = 7 * DAY_IN_SECONDS;

    /**
     * Hard cap on the downloaded image (bytes). Keybase processed uploads
     * are ~360×360 and well under 100 KiB; 1 MiB is generous headroom while
     * keeping a hostile endpoint from OOMing a worker. Hitting the cap is
     * treated as "abnormal → reject" rather than storing a truncated image.
     */
    private const MAX_IMAGE_BYTES = 1_048_576; // 1 MiB

    /** @var list<string> Image MIME allowlist (no SVG — XSS surface). */
    private const ALLOWED_MIMES = ['image/png', 'image/jpeg', 'image/webp'];

    /**
     * Resolve + sideload the logo for one validator, subject to the
     * throttle. Never throws.
     *
     * @param int $validatorId wp_bcc_onchain_validators.id
     */
    public static function maybeRefresh(int $validatorId): void
    {
        try {
            self::run($validatorId);
        } catch (\Throwable $e) {
            // Defensive backstop — run() already swallows its own failures,
            // but enrichment must never die on a cosmetic logo error.
            Logger::warning('[ValidatorLogoService] unexpected failure', [
                'validator_id' => $validatorId,
                'error'        => $e->getMessage(),
            ]);
        }
    }

    private static function run(int $validatorId): void
    {
        $state = ValidatorRepository::getLogoState($validatorId);
        if ($state === null) {
            return;
        }

        // ── Throttle ─────────────────────────────────────────────────────
        if (!self::isDue($state->logo_checked_at ?? null)) {
            return;
        }

        $identity = is_string($state->identity ?? null) ? trim((string) $state->identity) : '';
        if ($identity === '') {
            // Nothing to resolve from — stamp so we don't re-check hot.
            ValidatorRepository::markLogoChecked($validatorId);
            return;
        }

        $resolver = self::resolverFor();
        $remoteUrl = $resolver->resolveImageUrl($identity);
        if ($remoteUrl === null) {
            ValidatorRepository::markLogoChecked($validatorId);
            return;
        }

        // Source unchanged AND we already have a WORKING local copy → just
        // re-stamp. See needsResideload(): a stored logo on another host or
        // with its file gone is re-downloaded even when the source is the same.
        $storedUrl = is_string($state->logo_url ?? null) ? (string) $state->logo_url : null;
        $sourceRef = is_string($state->logo_source_ref ?? null) ? (string) $state->logo_source_ref : null;
        if (!self::needsResideload($storedUrl, $sourceRef, $remoteUrl)) {
            ValidatorRepository::markLogoChecked($validatorId);
            return;
        }

        $new = self::sideload($remoteUrl, $validatorId);
        if ($new === null) {
            Logger::warning('[ValidatorLogoService] sideload produced no local URL', [
                'validator_id' => $validatorId,
            ]);
            ValidatorRepository::markLogoChecked($validatorId);
            return;
        }

        $previous = is_string($state->logo_url ?? null) ? (string) $state->logo_url : null;
        self::commitLogo($validatorId, $new, $remoteUrl, $previous);
    }

    /**
     * Point the validator at the newly sideloaded logo, then drop the media
     * it replaced (F-42).
     *
     * A logo change used to sideload a NEW attachment and leave the old one
     * in the media library forever: every Keybase avatar change on every
     * validator accumulated another orphaned image. And a failed row update
     * left the new upload orphaned instead. Now:
     *   - row update fails → delete the NEW attachment (nothing points at it);
     *   - row update succeeds → delete the PREVIOUS auto-logo attachment,
     *     only if it is provably one this service created for this
     *     validator (see deleteReplacedLogo()).
     *
     * @param array{id: int, url: string} $new
     */
    private static function commitLogo(int $validatorId, array $new, string $remoteUrl, ?string $previousUrl): void
    {
        if (!ValidatorRepository::setLogo($validatorId, $new['url'], $remoteUrl)) {
            wp_delete_attachment($new['id'], true);
            Logger::warning('[ValidatorLogoService] logo row update failed; new upload removed', [
                'validator_id' => $validatorId,
            ]);
            return;
        }

        if ($previousUrl !== null && $previousUrl !== '' && $previousUrl !== $new['url']) {
            self::deleteReplacedLogo($validatorId, $previousUrl);
        }
    }

    /**
     * Delete the attachment behind a replaced auto-logo URL — but ONLY when
     * it is one this service sideloaded for THIS validator: its file is
     * `validator-{id}-logo.<ext>` (WP may append `-N` on a name clash) and
     * it has no parent post. A URL that resolves to anything else — a
     * claimer's own upload, a page thumbnail, a foreign or external URL —
     * is left alone.
     */
    private static function deleteReplacedLogo(int $validatorId, string $previousUrl): void
    {
        $attachmentId = (int) attachment_url_to_postid($previousUrl);
        if ($attachmentId <= 0) {
            return;
        }

        $file = (string) get_attached_file($attachmentId);
        $post = get_post($attachmentId);
        $ours = preg_match('/^validator-' . $validatorId . '-logo(-\d+)?\.(png|jpe?g|webp)$/i', basename($file)) === 1
            && $post instanceof \WP_Post
            && $post->post_type === 'attachment'
            && (int) $post->post_parent === 0;

        if (!$ours) {
            return;
        }

        wp_delete_attachment($attachmentId, true);
    }

    /**
     * Whether a validator's logo must be (re-)downloaded.
     *
     * Skipped ONLY when the Keybase source is unchanged AND the stored URL is
     * on this site's upload baseurl AND its file exists. The old check skipped
     * whenever any logo_url existed, so a URL carried in from another host
     * (the 2026-11-12 staging-restore incident: broken logos) or a file
     * that had gone missing never healed itself.
     *
     * @param array{baseurl?: string, basedir?: string}|null $uploadDir test seam; defaults to wp_upload_dir()
     */
    public static function needsResideload(?string $storedUrl, ?string $sourceRef, string $remoteUrl, ?array $uploadDir = null): bool
    {
        if ($storedUrl === null || $storedUrl === '' || $remoteUrl !== $sourceRef) {
            return true;
        }
        return !\BCC\Trust\Onchain\Support\LocalUploadUrl::isCurrentAndPresent($storedUrl, $uploadDir);
    }

    /**
     * Select the logo resolver. Phase 1 has a single Keybase resolver
     * gated by the 16-hex identity format, so Cosmos identities resolve
     * and everything else returns null. Phase 2 (Solana validator-info)
     * introduces chain-keyed selection here.
     */
    private static function resolverFor(): LogoResolver
    {
        return new KeybaseLogoResolver();
    }

    /**
     * Download + validate + sideload a remote image into WP media. Returns
     * the new attachment's id and local URL, or null on any failure. SSRF defence is
     * inherited from ApiRetry → SafeHttpClient (no redirects, private-IP
     * + metadata blocks, DNS pinning, size cap); on top we re-validate the
     * bytes are a real, allowlisted, fully-decodable image.
     *
     * @return array{id: int, url: string}|null
     */
    private static function sideload(string $remoteUrl, int $validatorId): ?array
    {
        $resp = ApiRetry::get(
            $remoteUrl,
            ['timeout' => 8, 'limit_response_size' => self::MAX_IMAGE_BYTES],
            ['label' => 'Validator logo download', 'max_retries' => 1]
        );

        if (is_wp_error($resp)) {
            return null;
        }
        if ((int) wp_remote_retrieve_response_code($resp) !== 200) {
            return null;
        }

        $body = wp_remote_retrieve_body($resp);
        if (!is_string($body) || $body === '') {
            return null;
        }
        // At/over the cap means the transfer was clipped → possibly a
        // truncated image. Reject rather than store a corrupt file.
        if (strlen($body) >= self::MAX_IMAGE_BYTES) {
            return null;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = wp_tempnam('bcc-validator-logo');
        if (!is_string($tmp) || $tmp === '') {
            return null;
        }

        $written = file_put_contents($tmp, $body);
        if ($written === false) {
            @unlink($tmp);
            return null;
        }

        // Primary validation: the real MIME from image content (rejects
        // non-images, polyglots, and truncations that aren't decodable).
        $dims = @getimagesize($tmp);
        if ($dims === false || $dims[0] <= 0 || $dims[1] <= 0) {
            @unlink($tmp);
            return null;
        }
        $mime = $dims['mime'];
        if (!in_array($mime, self::ALLOWED_MIMES, true)) {
            @unlink($tmp);
            return null;
        }

        // The extension is derived from the content MIME, never the URL.
        $ext = self::extensionForMime($mime);

        // Defense-in-depth: WP's own finfo+extension check must agree. It
        // needs a filename WITH an extension — an extension-less name makes
        // wp_check_filetype_and_ext return type=false and rejects every file.
        $check  = wp_check_filetype_and_ext($tmp, "logo.{$ext}");
        $wpMime = isset($check['type']) && is_string($check['type']) ? $check['type'] : '';
        if ($wpMime !== $mime) {
            @unlink($tmp);
            return null;
        }

        $fileArray = [
            'name'     => "validator-{$validatorId}-logo.{$ext}",
            'tmp_name' => $tmp,
        ];

        // post_parent 0 — the attachment isn't a child of the validator page;
        // its URL is stored on the validator row, not pinned as a thumbnail
        // (a claimer's set_post_thumbnail would otherwise outrank the auto
        // logo at view time, which is exactly the precedence we want).
        $attachmentId = media_handle_sideload($fileArray, 0, null, [
            'test_form' => false,
        ]);

        if (is_wp_error($attachmentId)) {
            @unlink($tmp);
            return null;
        }

        $url = wp_get_attachment_url((int) $attachmentId);
        if (!is_string($url) || $url === '') {
            wp_delete_attachment((int) $attachmentId, true); // unusable without a URL
            return null;
        }
        return ['id' => (int) $attachmentId, 'url' => $url];
    }

    private static function extensionForMime(string $mime): string
    {
        return match ($mime) {
            'image/png'  => 'png',
            'image/webp' => 'webp',
            default      => 'jpg',
        };
    }

    /**
     * Due when never checked, or last check older than RECHECK_INTERVAL.
     * Stored timestamps are UTC `Y-m-d H:i:s`.
     */
    private static function isDue(?string $logoCheckedAt): bool
    {
        if ($logoCheckedAt === null || $logoCheckedAt === '') {
            return true;
        }
        $checked = strtotime($logoCheckedAt . ' UTC');
        if ($checked === false) {
            return true;
        }
        return (time() - $checked) >= self::RECHECK_INTERVAL;
    }
}
