# Passkeys and WebAuthn step-up in tests

Don't stub WebAuthn verification. A software authenticator creates credentials and signs assertions for real, so tests exercise the server's verification: signature, relying-party id hash, origin, challenge binding and signature counter. It has two halves with the same credential: a PHP class for feature tests and for registering a passkey before a browser test, and a JavaScript twin that answers `navigator.credentials` inside Chromium.

Files to add to the project:

- `tests/Support/SoftwareAuthenticator.php` (below)
- `tests/Support/openssl.cnf`: an empty file with a comment. PHP builds that ship without a default `openssl.cnf` (Laravel Herd on Windows, for one) cannot generate key pairs unless `openssl_pkey_new()` is given a config file.
- `tests/Browser/Support/software-authenticator.js` (below)

## PHP authenticator

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * A software WebAuthn authenticator: ES256 key pair, "none" attestation, user
 * presence and verification flags, a signature counter. Its state can be handed
 * to the browser twin (tests/Browser/Support/software-authenticator.js) to answer
 * navigator.credentials.get() inside Chromium with the same credential.
 */
final class SoftwareAuthenticator
{
    private OpenSSLAsymmetricKey $key;

    private string $credentialId;

    private ?string $userHandle = null;

    private int $signCount = 0;

    public function __construct()
    {
        $key = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'config' => __DIR__.'/openssl.cnf',
        ]);

        if ($key === false) {
            throw new RuntimeException('Could not generate a P-256 key pair: '.openssl_error_string());
        }

        $this->key = $key;
        $this->credentialId = random_bytes(32);
    }

    /**
     * Answer navigator.credentials.create() for the browser-side creation options.
     *
     * @param  array<string, mixed>  $options
     * @return array{id: string, rawId: string, type: string, response: array{clientDataJSON: string, attestationObject: string}}
     */
    public function register(array $options, string $origin): array
    {
        $this->userHandle = self::decode($options['user']['id']);

        $clientData = $this->clientData('webauthn.create', $options['challenge'], $origin);

        $authenticatorData = hash('sha256', $options['rp']['id'], true)
            .chr(0x45) // user present | user verified | attested credential data
            .pack('N', $this->signCount)
            .str_repeat("\0", 16) // AAGUID: none
            .pack('n', strlen($this->credentialId))
            .$this->credentialId
            .$this->coseKey();

        $attestationObject = "\xa3" // map(3)
            .self::text('fmt').self::text('none')
            .self::text('attStmt')."\xa0" // map(0)
            .self::text('authData').self::bytes($authenticatorData);

        return [
            'id' => self::encode($this->credentialId),
            'rawId' => self::encode($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => self::encode($clientData),
                'attestationObject' => self::encode($attestationObject),
            ],
        ];
    }

    /**
     * Answer navigator.credentials.get() for browser-side request options.
     *
     * @param  array<string, mixed>  $options
     * @return array{id: string, rawId: string, type: string, response: array{authenticatorData: string, clientDataJSON: string, signature: string, userHandle: string|null}}
     */
    public function assert(array $options, string $origin): array
    {
        $clientData = $this->clientData('webauthn.get', $options['challenge'], $origin);

        $this->signCount++;

        $authenticatorData = hash('sha256', $options['rpId'], true)
            .chr(0x05) // user present | user verified
            .pack('N', $this->signCount);

        if (! openssl_sign($authenticatorData.hash('sha256', $clientData, true), $signature, $this->key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Signing the assertion failed: '.openssl_error_string());
        }

        return [
            'id' => self::encode($this->credentialId),
            'rawId' => self::encode($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'authenticatorData' => self::encode($authenticatorData),
                'clientDataJSON' => self::encode($clientData),
                'signature' => self::encode($signature), // DER, as WebAuthn requires
                'userHandle' => $this->userHandle === null ? null : self::encode($this->userHandle),
            ],
        ];
    }

    /**
     * The state the browser twin needs to keep signing with this credential.
     *
     * @return array{credentialId: string, userHandle: string|null, signCount: int, jwk: array<string, mixed>}
     */
    public function exportForBrowser(): array
    {
        $ec = openssl_pkey_get_details($this->key)['ec'];

        return [
            'credentialId' => self::encode($this->credentialId),
            'userHandle' => $this->userHandle === null ? null : self::encode($this->userHandle),
            'signCount' => $this->signCount,
            'jwk' => [
                'kty' => 'EC',
                'crv' => 'P-256',
                'x' => self::encode(self::coordinate($ec['x'])),
                'y' => self::encode(self::coordinate($ec['y'])),
                'd' => self::encode(self::coordinate($ec['d'])),
                'ext' => true,
            ],
        ];
    }

    public function credentialId(): string
    {
        return self::encode($this->credentialId);
    }

    private function clientData(string $type, string $challenge, string $origin): string
    {
        return json_encode([
            'type' => $type,
            'challenge' => $challenge,
            'origin' => $origin,
            'crossOrigin' => false,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** COSE_Key of the public key: {kty: EC2, alg: ES256, crv: P-256, x, y}. */
    private function coseKey(): string
    {
        $ec = openssl_pkey_get_details($this->key)['ec'];

        return "\xa5" // map(5)
            ."\x01\x02" // kty: EC2
            ."\x03\x26" // alg: -7 (ES256)
            ."\x20\x01" // crv (-1): P-256
            ."\x21".self::bytes(self::coordinate($ec['x'])) // x (-2)
            ."\x22".self::bytes(self::coordinate($ec['y'])); // y (-3)
    }

    /** OpenSSL drops leading zero bytes; COSE wants fixed 32-byte coordinates. */
    private static function coordinate(string $value): string
    {
        return str_pad($value, 32, "\0", STR_PAD_LEFT);
    }

    private static function text(string $value): string
    {
        return chr(0x60 | strlen($value)).$value; // major type 3, length < 24
    }

    private static function bytes(string $value): string
    {
        $length = strlen($value);

        return match (true) {
            $length < 24 => chr(0x40 | $length),
            $length < 256 => "\x58".chr($length),
            default => "\x59".pack('n', $length),
        }.$value;
    }

    private static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function decode(string $encoded): string
    {
        return base64_decode(strtr($encoded, '-_', '+/'), true) ?: throw new RuntimeException('Invalid base64url value.');
    }
}
```

## Browser twin

```js
// Replaces navigator.credentials.create()/get() with a software ES256
// authenticator ("none" attestation, user present + verified), so the app's real
// passkey registration and step-up ceremonies run end to end in Chromium. The
// credential lives in localStorage, so it survives page loads on the tenant
// origin; PHP can seed it via window.__softwareAuthenticator.save(state).
// Injected with $page->script().
(() => {
    const STORAGE_KEY = '__softwareAuthenticator';
    const encoder = new TextEncoder();

    const toBytes = (value) => (value instanceof Uint8Array ? value : new Uint8Array(value));
    const b64uToBytes = (s) => Uint8Array.from(atob(s.replace(/-/g, '+').replace(/_/g, '/')), (c) => c.charCodeAt(0));
    const bytesToB64u = (b) => btoa(String.fromCharCode(...toBytes(b))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');

    const concat = (...parts) => {
        const arrays = parts.map((part) => (Array.isArray(part) ? Uint8Array.from(part) : toBytes(part)));
        const out = new Uint8Array(arrays.reduce((length, array) => length + array.length, 0));
        let offset = 0;
        arrays.forEach((array) => { out.set(array, offset); offset += array.length; });
        return out;
    };

    const sha256 = async (bytes) => new Uint8Array(await crypto.subtle.digest('SHA-256', bytes));
    const uint32 = (n) => [(n >>> 24) & 0xff, (n >>> 16) & 0xff, (n >>> 8) & 0xff, n & 0xff];
    const cborText = (s) => concat([0x60 | s.length], encoder.encode(s));
    const cborBytes = (b) => (b.length < 24
        ? concat([0x40 | b.length], b)
        : (b.length < 256 ? concat([0x58, b.length], b) : concat([0x59, b.length >> 8, b.length & 0xff], b)));

    // WebCrypto signs ECDSA as raw r||s; WebAuthn wants an ASN.1 DER sequence.
    const derSignature = (raw) => {
        const integer = (bytes) => {
            let i = 0;
            while (i < bytes.length - 1 && bytes[i] === 0) i++;
            let value = bytes.slice(i);
            if (value[0] & 0x80) value = concat([0x00], value);
            return concat([0x02, value.length], value);
        };
        const body = concat(integer(raw.slice(0, 32)), integer(raw.slice(32)));
        return concat([0x30, body.length], body);
    };

    const load = () => JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null');
    const save = (state) => localStorage.setItem(STORAGE_KEY, JSON.stringify(state));

    const create = async ({ publicKey }) => {
        const pair = await crypto.subtle.generateKey({ name: 'ECDSA', namedCurve: 'P-256' }, true, ['sign', 'verify']);
        const jwk = await crypto.subtle.exportKey('jwk', pair.privateKey);
        const credentialId = crypto.getRandomValues(new Uint8Array(32));

        const coseKey = concat(
            [0xa5, 0x01, 0x02, 0x03, 0x26, 0x20, 0x01, 0x21, 0x58, 0x20], b64uToBytes(jwk.x),
            [0x22, 0x58, 0x20], b64uToBytes(jwk.y),
        );
        const authenticatorData = concat(
            await sha256(encoder.encode(publicKey.rp.id)),
            [0x45], uint32(0), new Uint8Array(16), [0x00, credentialId.length], credentialId, coseKey,
        );
        const clientDataJSON = encoder.encode(JSON.stringify({
            type: 'webauthn.create', challenge: bytesToB64u(publicKey.challenge), origin: location.origin, crossOrigin: false,
        }));
        const attestationObject = concat(
            [0xa3], cborText('fmt'), cborText('none'), cborText('attStmt'), [0xa0], cborText('authData'), cborBytes(authenticatorData),
        );

        save({ credentialId: bytesToB64u(credentialId), userHandle: bytesToB64u(publicKey.user.id), signCount: 0, jwk });

        return {
            id: bytesToB64u(credentialId),
            rawId: credentialId.buffer,
            type: 'public-key',
            response: { clientDataJSON: clientDataJSON.buffer, attestationObject: attestationObject.buffer },
        };
    };

    const get = async ({ publicKey }) => {
        const state = load();
        if (! state) {
            throw new DOMException('No software credential registered.', 'NotAllowedError');
        }

        const key = await crypto.subtle.importKey('jwk', state.jwk, { name: 'ECDSA', namedCurve: 'P-256' }, false, ['sign']);
        state.signCount += 1;
        save(state);

        const authenticatorData = concat(await sha256(encoder.encode(publicKey.rpId)), [0x05], uint32(state.signCount));
        const clientDataJSON = encoder.encode(JSON.stringify({
            type: 'webauthn.get', challenge: bytesToB64u(publicKey.challenge), origin: location.origin, crossOrigin: false,
        }));
        const signed = concat(authenticatorData, await sha256(clientDataJSON));
        const raw = new Uint8Array(await crypto.subtle.sign({ name: 'ECDSA', hash: 'SHA-256' }, key, signed));

        return {
            id: state.credentialId,
            rawId: b64uToBytes(state.credentialId).buffer,
            type: 'public-key',
            response: {
                authenticatorData: authenticatorData.buffer,
                clientDataJSON: clientDataJSON.buffer,
                signature: derSignature(raw).buffer,
                userHandle: state.userHandle ? b64uToBytes(state.userHandle).buffer : null,
            },
        };
    };

    navigator.credentials.create = create;
    navigator.credentials.get = get;
    window.__softwareAuthenticator = { load, save, reset: () => localStorage.removeItem(STORAGE_KEY) };
})();
```

## Helpers (laravel/passkeys)

```php
use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Support\WebAuthn;
use Tests\Support\SoftwareAuthenticator;
use Webauthn\PublicKeyCredential;

/**
 * Registers a real passkey for $user through laravel/passkeys' own ceremony
 * (registration options, attestation, StorePasskey) with the tenant host as
 * relying party. Returns the authenticator holding the private key.
 */
function registerPasskey(Tenant $tenant, User $user): SoftwareAuthenticator
{
    $authenticator = new SoftwareAuthenticator;
    $origin = rtrim(tenantUrl($tenant), '/');

    $tenant->run(function () use ($authenticator, $origin, $user): void {
        config([
            'passkeys.relying_party_id' => parse_url($origin, PHP_URL_HOST),
            'passkeys.allowed_origins' => [$origin],
        ]);

        $options = app(GenerateRegistrationOptions::class)($user);
        $credential = $authenticator->register(WebAuthn::toBrowserArray($options), $origin);

        app(StorePasskey::class)(
            $user,
            'Test key',
            WebAuthn::fromJson(json_encode($credential, JSON_THROW_ON_ERROR), PublicKeyCredential::class),
            $options,
        );
    });

    return $authenticator;
}

/**
 * Replaces navigator.credentials on the current page with the software
 * authenticator (repeat after every full page load), optionally seeding the
 * credential of a passkey registered from PHP with registerPasskey().
 */
function useSoftwareAuthenticator(mixed $page, ?SoftwareAuthenticator $authenticator = null): mixed
{
    $page->script(file_get_contents(base_path('tests/Browser/Support/software-authenticator.js')));

    if ($authenticator !== null) {
        $page->script('window.__softwareAuthenticator.save('.json_encode($authenticator->exportForBrowser(), JSON_THROW_ON_ERROR).')');
    }

    return $page;
}
```

In a test:

```php
$authenticator = registerPasskey($tenant, $manager);
$this->actingAs($manager);

$page = visit(tenantUrl($tenant, "/time-entries/{$entry->id}/edit"));

useSoftwareAuthenticator($page, $authenticator)
    ->fill(field('ended_at'), '2026-03-02T18:00')
    ->click('Confirm with passkey')
    ->assertSee('Confirmed')
    ->press('Save')
    ->assertSee('Saved')
    ->assertNoJavaScriptErrors();
```

The relying-party id must equal the host the browser is on (`<subdomain>.localhost` in tests). Also test the refusals: no confirmation, a replayed assertion, an assertion for another record, and a cloned authenticator (counter not increasing).
