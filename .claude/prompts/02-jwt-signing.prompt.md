# Prompt — Generate `jwt_signing_service.php`

This prompt produces the local RS256 JWT signer. Reminder: per ADR-002 corrected, FastPix has **no** `createToken` endpoint; the plugin signs JWTs locally with the `firebase/php-jwt` library and the RSA private key generated during credential bootstrap.

Variables to fill in: none.

---

```
You are @jwt-signing for the local_fastpix Moodle plugin.

CONTEXT YOU HAVE READ:
- 01-local-fastpix.md §3.5 (stream URLs), §12 (JWT signing service), ADR-002 corrected.
- The vendored firebase/php-jwt at classes/vendor/php-jwt/.

TASK: Generate `local/fastpix/classes/service/jwt_signing_service.php`.

REQUIREMENTS:
1. Namespace: `local_fastpix\service`. Class: `jwt_signing_service`. Plain class (not singleton).
2. Public method: `sign_for_playback(string $playback_id, ?int $ttl = null): string`.
3. Read `signing_key_id` and `signing_private_key` via `get_config('local_fastpix', ...)`.
4. Throw `\local_fastpix\exception\signing_key_missing` if either is empty.
5. `base64_decode($private_key, true)` strict mode; throw `signing_key_missing('invalid_base64')` on failure.
6. Build the payload EXACTLY:
     kid = <signing_key_id>
     aud = "media:" . $playback_id     (literal "media:" prefix; not "<playback_id>" alone)
     iss = "fastpix.io"
     sub = ""
     iat = time()
     exp = time() + ($ttl ?? 300)
7. Call `\Firebase\JWT\JWT::encode($payload, $pem, 'RS256', $kid)`.
8. Public method: `token_ttl_seconds(): int` returns 300.

DO NOT:
- Make any HTTP call. This is pure CPU.
- Cache the JWT (each call returns a fresh one).
- Use HS256 or any symmetric algorithm.
- Branch on "is this for DRM or signed URL?" — same JWT serves both.
- Log the JWT or the private key.

OUTPUT: PHP file only. Match the namespace structure and the file header convention.
```
