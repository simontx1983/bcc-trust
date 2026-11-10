<?php
/**
 * Read-only probe for the VAPID / JWT / PKI / brick-math dependency chain.
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────
 * #191 refreshed the committed production vendor/ tree, which bumped
 * brick/math 0.17.1 -> 1.0.0 (a MAJOR boundary), web-token/jwt-library
 * 4.1.6 -> 4.2.7 and spomky-labs/pki-framework 1.4.2 -> 1.6.3.
 *
 * ⚠ NOTHING in the test suite covers any of them. There is no reference to
 * Minishlink, WebPush or VAPID anywhere under tests/, so a green suite says
 * nothing at all about these three packages.
 *
 * ⚠⚠ AND NO AMOUNT OF CLICKING REACHES THEM EITHER.
 * `PushDispatcher::vapidAuth()` requires all three VAPID constants, and
 * `BCC_PUSH_VAPID_SUBJECT` is undefined on both staging and production — so
 * `new WebPush(...)` is never reached and the whole chain stays unloaded.
 * This probe is therefore the ONLY way to exercise it, which is why it is a
 * committed script rather than a throwaway.
 *
 * ── HOW TO USE IT ───────────────────────────────────────────────────────
 * Take a BEFORE reading, deploy, take an AFTER reading, require them equal:
 *
 *   php scripts/tests/vapid-chain-probe.php <path-to-bcc-trust>
 *
 * Expected on staging/production (PHP 8.5.12, gmp loaded):
 *
 *   VAPID::createVapidKeys    ok (public 87 chars, private 43 chars ...)
 *   public key length sane    yes
 *   VAPID JWT signed          yes (header present, value not printed)
 *
 * ⚠⚠⚠ IT FAILS WITHOUT GMP, AND THAT IS NOT A REGRESSION. On a machine with
 * no gmp extension `createVapidKeys` throws `RuntimeException: Unable to
 * create the key`. Measured: the PRE-#191 tree throws IDENTICALLY on the same
 * machine, so a throw here means "wrong environment", not "broken bump". If
 * you see it, check the `gmp loaded` line before concluding anything — run
 * the same probe against a checkout of the previous commit as a control.
 *
 * ── SECRETS ─────────────────────────────────────────────────────────────
 * ⛔ This generates a throwaway keypair in memory and prints ONLY lengths and
 * booleans. No key material, and nothing derived from a key, is ever printed
 * or written. The keypair is discarded when the process exits; it is not the
 * site's VAPID key and must never be installed as one.
 */

declare(strict_types=1);

$root     = $argv[1] ?? getcwd();
$autoload = rtrim($root, "/\\") . '/vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "no autoloader at {$autoload}\n");
    exit(2);
}

require $autoload;

$fail = 0;

printf("php                       %s (id %d)\n", PHP_VERSION, PHP_VERSION_ID);
printf("gmp loaded                %s\n", extension_loaded('gmp') ? 'yes' : 'no  <-- READ THE HEADER');
printf("bcmath loaded             %s\n", extension_loaded('bcmath') ? 'yes' : 'no');

// The polyfills are autoload_files entries, so they have already executed by
// the time we get here. #191 adds symfony/polyfill-php80, whose bootstrap runs
// on every request on PHP 8.5 — if it ever declared these unconditionally it
// would collide with the real engine classes and fatal before this line.
printf("polyfill mb_strlen        %s\n", function_exists('mb_strlen') ? 'available' : 'MISSING');
printf("Attribute class           %s\n", class_exists('Attribute') ? 'present' : 'MISSING');
printf("ValueError class          %s\n", class_exists('ValueError') ? 'present' : 'MISSING');

foreach ([
    'Minishlink\\WebPush\\VAPID',
    'Minishlink\\WebPush\\WebPush',
    'Jose\\Component\\Core\\JWK',
    'Brick\\Math\\BigInteger',
    'SpomkyLabs\\Pki\\CryptoTypes\\AlgorithmIdentifier\\AlgorithmIdentifier',
] as $class) {
    $ok = class_exists($class);
    $fail += $ok ? 0 : 1;
    printf("%-42s %s\n", $class, $ok ? 'resolves' : 'NOT FOUND');
}

// brick/math crossed a major boundary, so exercise the arithmetic API rather
// than merely loading the class.
try {
    $n = \Brick\Math\BigInteger::of('123456789012345678901234567890')
        ->multipliedBy(7)
        ->mod(\Brick\Math\BigInteger::of(1000000007));
    printf("brick/math arithmetic     ok (result has %d digits)\n", strlen((string) $n));
} catch (\Throwable $e) {
    $fail++;
    printf("brick/math arithmetic     THREW %s: %s\n", get_class($e), $e->getMessage());
}

// The real thing: key generation walks jwt-library -> pki-framework -> brick/math.
$keys = null;
try {
    $keys    = \Minishlink\WebPush\VAPID::createVapidKeys();
    $pubLen  = strlen((string) ($keys['publicKey'] ?? ''));
    $privLen = strlen((string) ($keys['privateKey'] ?? ''));
    printf(
        "VAPID::createVapidKeys    ok (public %d chars, private %d chars — VALUES NOT PRINTED)\n",
        $pubLen,
        $privLen
    );
    // An uncompressed P-256 point is 65 bytes -> 87 base64url chars.
    $sane = $pubLen >= 80 && $pubLen <= 92;
    $fail += $sane ? 0 : 1;
    printf("public key length sane    %s\n", $sane ? 'yes' : 'NO (' . $pubLen . ')');
} catch (\Throwable $e) {
    $fail++;
    printf("VAPID::createVapidKeys    THREW %s: %s\n", get_class($e), $e->getMessage());
}

// And the signing path a real send would take.
if (is_array($keys)) {
    try {
        $auth = \Minishlink\WebPush\VAPID::validate([
            'subject'    => 'mailto:probe@example.invalid',
            'publicKey'  => $keys['publicKey'],
            'privateKey' => $keys['privateKey'],
        ]);
        $headers = \Minishlink\WebPush\VAPID::getVapidHeaders(
            'https://fcm.googleapis.com',
            $auth['subject'],
            $auth['publicKey'],
            $auth['privateKey'],
            'aes128gcm'
        );
        $signed = isset($headers['Authorization']) && $headers['Authorization'] !== '';
        $fail  += $signed ? 0 : 1;
        printf("VAPID JWT signed          %s\n", $signed ? 'yes (header present, value not printed)' : 'NO');
    } catch (\Throwable $e) {
        $fail++;
        printf("VAPID JWT signed          THREW %s: %s\n", get_class($e), $e->getMessage());
    }
} else {
    printf("VAPID JWT signed          skipped (no keypair)\n");
}

printf("\n%s\n", $fail === 0 ? 'PASS: the chain is intact.' : "FAIL: {$fail} check(s) failed.");
exit($fail === 0 ? 0 : 1);
