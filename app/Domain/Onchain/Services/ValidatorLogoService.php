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

        // Source unchanged AND we already have a local copy → just re-stamp.
        $haveLocal = is_string($state->logo_url ?? null) && (string) $state->logo_url !== '';
        if ($haveLocal && $remoteUrl === ($state->logo_source_ref ?? null)) {
            ValidatorRepository::markLogoChecked($validatorId);
            return;
        }

        $localUrl = self::sideload($remoteUrl, $validatorId);
        if ($localUrl === null) {
            Logger::warning('[ValidatorLogoService] sideload produced no local URL', [
                'validator_id' => $validatorId,
            ]);
            ValidatorRepository::markLogoChecked($validatorId);
            return;
        }

        ValidatorRepository::setLogo($validatorId, $localUrl, $remoteUrl);
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
     * the local attachment URL or null on any failure. SSRF defence is
     * inherited from ApiRetry → SafeHttpClient (no redirects, private-IP
     * + metadata blocks, DNS pinning, size cap); on top we re-validate the
     * bytes are a real, allowlisted, fully-decodable image.
     */
    private static function sideload(string $remoteUrl, int $validatorId): ?string
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
        return is_string($url) && $url !== '' ? $url : null;
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
