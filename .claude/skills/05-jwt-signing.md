# Skill 05 — Implement JWT Signing Service (RS256)

**Owner agent:** `@jwt-signing`.

**When to invoke:** Phase 2, step 3.

---

## Inputs

- `signing_key_id` (the `kid`) and `signing_private_key` (Base64-encoded PEM) read via `get_config('local_fastpix', ...)`.

## Outputs

- `local/fastpix/classes/service/jwt_signing_service.php`.

## Steps

```php
namespace local_fastpix\service;

use Firebase\JWT\JWT;

class jwt_signing_service {

    private const TOKEN_TTL_SECONDS = 300;
    private const ISS = 'fastpix.io';

    public function sign_for_playback(string $playback_id, ?int $ttl = null): string {
        $kid = (string)get_config('local_fastpix', 'signing_key_id');
        $private_key_b64 = (string)get_config('local_fastpix', 'signing_private_key');

        if ($kid === '' || $private_key_b64 === '') {
            throw new \local_fastpix\exception\signing_key_missing();
        }

        $pem = base64_decode($private_key_b64, true);
        if ($pem === false) {
            throw new \local_fastpix\exception\signing_key_missing('invalid_base64');
        }

        $now = time();
        $payload = [
            'kid' => $kid,
            'aud' => 'media:' . $playback_id,   // literal "media:" prefix is required
            'iss' => self::ISS,
            'sub' => '',
            'iat' => $now,
            'exp' => $now + ($ttl ?? self::TOKEN_TTL_SECONDS),
        ];

        return JWT::encode($payload, $pem, 'RS256', $kid);
    }

    public function token_ttl_seconds(): int {
        return self::TOKEN_TTL_SECONDS;
    }
}
```

## Constraints

- **Pure CPU. No HTTP, no DB writes, no caching.**
- **`RS256` only.** FastPix issues RSA-2048 keys.
- **Same JWT serves both `token` and `drm-token`** per FastPix docs — do NOT branch by use case.
- **`aud` is exactly `media:<playback_id>`.** The literal `media:` prefix is required.
- **Each playback start gets a fresh sign.** No cache.

## Verification

All 9 mandatory tests in §12 of `01-local-fastpix.md`. Notably:
- Roundtrip: sign with private key, decode with public-key fixture, payload claims match.
- Missing config throws `signing_key_missing` with the right reason.
- Custom TTL honored: `exp - iat == $ttl`.
- `aud` claim format exactly `media:<playback_id>`.
- `kid` in JWT header matches `kid` in payload.
