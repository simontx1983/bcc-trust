<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Support\ProviderConfigStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The boolean-only configuration report, and the guarantee that it has nothing
 * left to leak.
 *
 * ── ⚠⚠⚠ WHAT IS ACTUALLY BEING PINNED ───────────────────────────────────
 * Not "does it compute a hostname" — that is {@see EndpointDescriptorTest}'s
 * job. What is pinned here is the SHAPE: that a credentialed URL handed to
 * {@see ProviderConfigStatus::describing()} cannot be recovered from the
 * resulting object through ANY accessor, through `toArray()`, through
 * `json_encode()`, through `summary()`, or through `print_r`/`var_export` of the
 * object itself.
 *
 * The last two matter more than they look. A status object is exactly the sort of
 * thing that ends up in a debug dump or an exception context, and a private
 * property holding the original URL would be invisible to every other assertion
 * here while being fully visible in `print_r()` output pasted into a ticket.
 *
 * ── ⚠⚠ REFLECTION IS USED ON PURPOSE ────────────────────────────────────
 * Testing only the public API would pass on an implementation that stashed the
 * URL in a property "for later". The structural assertions below read the
 * object's real state, because the guarantee is about what it STORES, not only
 * about what it currently chooses to return.
 */
#[CoversClass(ProviderConfigStatus::class)]
final class ProviderConfigStatusTest extends TestCase
{
    /** Credentialed endpoints in every shape this codebase actually produces. */
    private const CREDENTIALED = [
        'alchemy path key'    => ['alchemy', 'https://eth-mainnet.g.alchemy.com/v2/SECRETKEY', 'eth-mainnet.g.alchemy.com'],
        'alchemy nft rewrite' => ['alchemy', 'https://eth-mainnet.g.alchemy.com/nft/v3/SECRETKEY', 'eth-mainnet.g.alchemy.com'],
        'helius query key'    => ['helius', 'https://mainnet.helius-rpc.com/?api-key=SECRETKEY', 'mainnet.helius-rpc.com'],
        'userinfo'            => ['provider', 'https://user:SECRETKEY@rpc.example/v1/', 'rpc.example'],
        'fragment'            => ['provider', 'https://rpc.example/v1/SECRETKEY#SECRETKEY', 'rpc.example'],
        'multi query'         => ['provider', 'https://rpc.example/rpc?key=SECRETKEY&token=SECRETKEY', 'rpc.example'],
    ];

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function credentialedEndpoints(): array
    {
        return self::CREDENTIALED;
    }

    // ── 1. ⚠⚠⚠ The credential cannot be recovered, by any route ──────────

    #[DataProvider('credentialedEndpoints')]
    public function testNoRouteOutOfTheObjectYieldsTheCredential(string $provider, string $url, string $host): void
    {
        $status = ProviderConfigStatus::describing($provider, $url);

        $surfaces = [
            'provider()'   => $status->provider(),
            'hostname()'   => $status->hostname(),
            'summary()'    => $status->summary(),
            'toArray()'    => (string) json_encode($status->toArray()),
            'json_encode'  => (string) json_encode($status),
            'print_r'      => print_r($status, true),
            'var_export'   => var_export($status, true),
            'serialize'    => serialize($status),
        ];

        foreach ($surfaces as $where => $text) {
            self::assertStringNotContainsString('SECRETKEY', $text, "the credential is recoverable via {$where}");
            // ⚠ The whole URL, not just the secret: a stored complete endpoint is
            // a leak even for a provider whose key happens to live elsewhere.
            self::assertStringNotContainsString($url, $text, "the complete endpoint is recoverable via {$where}");
        }

        self::assertSame($host, $status->hostname());
        self::assertTrue($status->isConfigured());
    }

    /**
     * ⚠⚠ The object STORES nothing but the four reported fields.
     *
     * Read through reflection, because a property is a leak whether or not an
     * accessor currently exposes it.
     */
    #[DataProvider('credentialedEndpoints')]
    public function testTheObjectStoresNothingBeyondTheFourReportedFields(string $provider, string $url): void
    {
        $status = ProviderConfigStatus::describing($provider, $url);
        $ref    = new \ReflectionObject($status);

        $names = [];
        foreach ($ref->getProperties() as $prop) {
            $prop->setAccessible(true);
            $names[] = $prop->getName();
            $value   = $prop->getValue($status);

            self::assertTrue(
                is_string($value) || is_bool($value),
                "property \${$prop->getName()} is neither a string nor a bool; only scalars belong here"
            );
            if (is_string($value)) {
                self::assertStringNotContainsString('SECRETKEY', $value, "property \${$prop->getName()} holds the credential");
                self::assertStringNotContainsString('/', $value, "property \${$prop->getName()} holds a path-bearing value");
            }
        }

        sort($names);
        self::assertSame(
            ['configured', 'hostname', 'provider', 'resolverAvailable'],
            $names,
            'a fifth field appeared — if it carries endpoint data this guarantee is void'
        );
    }

    /**
     * ⚠ No accessor may return anything URL-shaped, for ANY input.
     *
     * Walks every public method with no required parameters, so a newly added
     * getter is covered without this test being updated.
     */
    #[DataProvider('credentialedEndpoints')]
    public function testNoZeroArgPublicMethodReturnsSomethingUrlShaped(string $provider, string $url): void
    {
        $status = ProviderConfigStatus::describing($provider, $url);
        $ref    = new \ReflectionObject($status);

        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->getNumberOfRequiredParameters() > 0) {
                continue;
            }

            $flat = print_r($method->invoke($status), true);
            self::assertDoesNotMatchRegularExpression(
                '~[a-z][a-z0-9+.-]*://~i',
                $flat,
                "{$method->getName()}() returned something scheme-shaped"
            );
            self::assertStringNotContainsString('SECRETKEY', $flat, "{$method->getName()}() returned the credential");
        }
    }

    // ── 2. The report still answers the operator's question ──────────────

    public function testTheSerialisationIsExactlyTheFourDocumentedKeys(): void
    {
        $status = ProviderConfigStatus::describing('helius', 'https://mainnet.helius-rpc.com/?api-key=K');

        self::assertSame(
            ['provider', 'configured', 'hostname', 'resolver_available'],
            array_keys($status->toArray())
        );
        self::assertSame($status->toArray(), $status->jsonSerialize());
    }

    public function testUnconfiguredIsDistinctFromResolverMissing(): void
    {
        $noCredential = ProviderConfigStatus::unconfigured('alchemy');
        $noResolver   = ProviderConfigStatus::unconfigured('alchemy', false);

        self::assertFalse($noCredential->isConfigured());
        self::assertTrue($noCredential->isResolverAvailable());
        self::assertSame('alchemy: unconfigured', $noCredential->summary());

        self::assertFalse($noResolver->isConfigured());
        self::assertFalse($noResolver->isResolverAvailable());
        self::assertSame('alchemy: no resolver', $noResolver->summary());

        // ⚠ The point of keeping them apart: "no credential" is fixed by the
        // operator, "no resolver" is fixed by a developer. One report that said
        // only `false` would send the operator to the wrong place.
        self::assertNotSame($noCredential->summary(), $noResolver->summary());
    }

    /** @return array<string, array{0: string|null}> */
    public static function unusableEndpoints(): array
    {
        return [
            'null'            => [null],
            'empty'           => [''],
            'whitespace'      => ['   '],
            'not a url'       => ['not a url at all'],
            'scheme only'     => ['https://'],
            'no scheme'       => ['eth-mainnet.g.alchemy.com/v2/K'],
            'disallowed'      => ['javascript:alert(1)'],
            'control char'    => ["https://rpc.example/v1/K\nHeader: x"],
        ];
    }

    /**
     * An unusable endpoint reports unconfigured with an EMPTY hostname.
     *
     * ⚠ Empty, not a placeholder. `hostname()` is consumed as a host — put
     * `(unrecognised endpoint)` in it and a caller building a link or a
     * comparison gets something that looks like data and is not.
     */
    #[DataProvider('unusableEndpoints')]
    public function testAnUnusableEndpointIsUnconfiguredWithNoHostname(?string $url): void
    {
        $status = ProviderConfigStatus::describing('provider', $url);

        self::assertFalse($status->isConfigured());
        self::assertSame('', $status->hostname());
        self::assertSame('provider: unconfigured', $status->summary());

        // ⚠ And it never echoes the input it could not parse.
        if (is_string($url) && trim($url) !== '') {
            self::assertStringNotContainsString(trim($url), (string) json_encode($status->toArray()));
        }
    }

    // ── 3. ⚠⚠ The provider NAME cannot become a smuggling channel ────────

    /**
     * A caller that passes a URL as the provider name gets a name, not a URL.
     *
     * This is the one field a caller controls freely, so it is the one place a
     * complete endpoint could re-enter a report that promises none.
     */
    public function testAUrlPassedAsTheProviderNameCannotSurvive(): void
    {
        $status = ProviderConfigStatus::describing('https://host/v2/SECRETKEY', null);

        $flat = (string) json_encode($status->toArray());
        self::assertStringNotContainsString('SECRETKEY', $flat);
        self::assertStringNotContainsString('://', $flat);
        self::assertStringNotContainsString('/', $flat);
    }

    public function testMarkupInTheProviderNameCannotSurvive(): void
    {
        $status = ProviderConfigStatus::describing('<script>alert(1)</script>', null);

        self::assertSame('scriptalert1script', $status->provider());
    }

    public function testAnEmptyProviderNameBecomesUnknownRatherThanBlank(): void
    {
        self::assertSame('unknown', ProviderConfigStatus::describing('', null)->provider());
        self::assertSame('unknown', ProviderConfigStatus::describing('!!!', null)->provider());
    }

    public function testAProviderNameIsLengthBounded(): void
    {
        $status = ProviderConfigStatus::describing(str_repeat('a', 500), null);

        self::assertSame(40, strlen($status->provider()));
    }

    // ── 4. ⚠ The reduction cannot be bypassed ────────────────────────────

    /**
     * There is no public constructor, so no caller can assemble a status with a
     * hostname it made up — or with a complete URL in the hostname field.
     */
    public function testTheConstructorIsNotPublic(): void
    {
        $ctor = (new \ReflectionClass(ProviderConfigStatus::class))->getConstructor();

        self::assertNotNull($ctor);
        self::assertTrue($ctor->isPrivate(), 'a public constructor lets a caller skip the reduction entirely');
    }

    /** No static factory may exist that takes a pre-made hostname. */
    public function testTheOnlyWaysInAreTheTwoReducingFactories(): void
    {
        // ⚠ `getMethods(IS_PUBLIC | IS_STATIC)` is an OR filter, not an AND —
        // it returns every public method AND every static one, private statics
        // included. The predicate has to be applied by hand.
        $ref     = new \ReflectionClass(ProviderConfigStatus::class);
        $factory = [];
        foreach ($ref->getMethods() as $m) {
            if ($m->isPublic() && $m->isStatic()) {
                $factory[] = $m->getName();
            }
        }
        sort($factory);

        self::assertSame(['describing', 'unconfigured'], $factory);
    }
}
