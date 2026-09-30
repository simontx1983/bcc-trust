<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * THE ONLY SHAPE IN WHICH PROVIDER CONFIGURATION MAY BE REPORTED.
 *
 * ── ⚠⚠⚠ WHY A TYPE, AND NOT A CONVENTION ────────────────────────────────
 * Every provider-configuration diagnostic in this codebase was hand-assembled
 * at its call site: the Settings page printed one shape, the indexer status view
 * another, an operator script a third. Each independently decided how much of an
 * endpoint was safe to show, and on 2026-09-30 one of them was wrong — a
 * diagnostic printed a path-embedded Alchemy key and a live credential had to be
 * rotated.
 *
 * "Print only the hostname" is not enforceable as a convention, because the
 * value that reaches a diagnostic is a complete credentialed URL and the
 * reduction is the author's to remember. So the reduction happens ONCE, here,
 * and what a diagnostic receives is a value that no longer contains the URL.
 *
 * ── WHAT THIS CARRIES, EXHAUSTIVELY ─────────────────────────────────────
 *   provider            a NAME, from a closed set — never a URL
 *   configured          bool
 *   hostname            host only, '' when there is nothing describable
 *   resolver_available  bool — is the code path that resolves this provider
 *                       present and able to produce an endpoint at all
 *
 * `configured` and `resolver_available` are genuinely different questions and
 * collapsing them is how "it says configured but makes no calls" happens: a
 * credential can be present while the driver that would use it is absent, and a
 * resolver can exist with nothing to resolve.
 *
 * ── ⚠⚠ THE GUARANTEE, STATED PRECISELY ──────────────────────────────────
 * This object cannot be ASKED for a URL: it stores none, no accessor returns
 * one, and {@see toArray()} is the complete serialisation — four keys, three
 * scalars and a host.
 *
 * It is NOT a claim that no URL was ever seen. {@see describing()} must look at
 * one to reduce it, exactly as a redactor must. The difference that matters is
 * that the reduction happens once, in a place that is tested, instead of at
 * every call site; and that what crosses the boundary to a renderer, a log line
 * or a REST response is this object, which has nothing left to leak.
 *
 * ⚠ Construction is private precisely so that the reduction cannot be skipped.
 * {@see describing()} and {@see unconfigured()} are the only ways in.
 *
 * @see EndpointDescriptor the deny-by-default reducer this delegates to
 * @see \BCC\Trust\Onchain\Tests\Unit\ProviderConfigStatusTest
 */
final class ProviderConfigStatus implements \JsonSerializable
{
    private function __construct(
        private readonly string $provider,
        private readonly bool $configured,
        private readonly string $hostname,
        private readonly bool $resolverAvailable,
    ) {
    }

    /**
     * Reduce a resolved endpoint to a reportable status.
     *
     * ⚠ `$resolvedUrl` is CONSUMED AND DISCARDED. It is read to derive a
     * hostname and a boolean and is not retained. Callers pass the real
     * credentialed URL here and must not carry it any further themselves.
     *
     * `$resolverAvailable` defaults to true because the common case is a caller
     * that HAS a resolver and called it. Pass false where the resolver itself is
     * missing — an unregistered driver, a disabled chain type — so that
     * "no credential" and "no code path" stay distinguishable in the report.
     */
    public static function describing(
        string $provider,
        ?string $resolvedUrl,
        bool $resolverAvailable = true
    ): self {
        return new self(
            self::safeProviderName($provider),
            EndpointDescriptor::isConfigured($resolvedUrl),
            EndpointDescriptor::host($resolvedUrl),
            $resolverAvailable
        );
    }

    /**
     * A definitively unconfigured provider, with no endpoint to reduce.
     *
     * ⚠ Prefer this over `describing($name, null)` when the absence is KNOWN
     * rather than derived. It takes no URL parameter at all, so a caller that
     * has no endpoint cannot accidentally pass something else.
     */
    public static function unconfigured(string $provider, bool $resolverAvailable = true): self
    {
        return new self(self::safeProviderName($provider), false, '', $resolverAvailable);
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    /** Host only. '' when nothing describable — never a placeholder that looks like a host. */
    public function hostname(): string
    {
        return $this->hostname;
    }

    public function isResolverAvailable(): bool
    {
        return $this->resolverAvailable;
    }

    /**
     * The complete serialisation. Four keys, and there will never be a fifth
     * carrying an endpoint.
     *
     * @return array{provider: string, configured: bool, hostname: string, resolver_available: bool}
     */
    public function toArray(): array
    {
        return [
            'provider'           => $this->provider,
            'configured'         => $this->configured,
            'hostname'           => $this->hostname,
            'resolver_available' => $this->resolverAvailable,
        ];
    }

    /** @return array{provider: string, configured: bool, hostname: string, resolver_available: bool} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * A one-line operator-readable summary, safe for a log or a status page.
     *
     * Deliberately provided so that a caller wanting one line does not
     * hand-build it from an endpoint. Uses the hostname, never a URL.
     */
    public function summary(): string
    {
        if (!$this->resolverAvailable) {
            return $this->provider . ': no resolver';
        }
        if (!$this->configured) {
            return $this->provider . ': unconfigured';
        }

        return $this->provider . ': configured' . ($this->hostname !== '' ? ' (' . $this->hostname . ')' : '');
    }

    /**
     * A provider NAME, reduced to a shape that cannot be a URL or markup.
     *
     * ⚠ Names are short identifiers — `alchemy`, `helius`, `magiceden`. This is
     * not paranoia about the current callers, which all pass literals: it is
     * what stops a future caller passing `$chain->rpc_url` as the "name" and
     * having it printed verbatim by a method whose whole purpose is that no URL
     * can reach a renderer. A URL cannot survive this filter — `:` and `/` are
     * not in the allowed set.
     */
    private static function safeProviderName(string $provider): string
    {
        $clean = strtolower(trim($provider));
        $clean = (string) preg_replace('~[^a-z0-9._-]~', '', $clean);

        return $clean === '' ? 'unknown' : substr($clean, 0, 40);
    }
}
