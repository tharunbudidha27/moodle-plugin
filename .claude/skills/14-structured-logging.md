# Skill 14 — Implement Structured Logging Helper

**Owner agent:** `@backend-architect` (designs) + `@security-compliance` (audits).

**When to invoke:** Phase 7, step 2.

---

## Inputs

Logging event name + context array.

## Outputs

- `local/fastpix/classes/helper/logger.php`.

## Steps

```php
namespace local_fastpix\helper;

class logger {

    public static function info(string $event, array $context = []): void {
        self::write('info', $event, $context);
    }

    public static function warn(string $event, array $context = []): void {
        self::write('warn', $event, $context);
    }

    public static function error(string $event, array $context = []): void {
        self::write('error', $event, $context);
    }

    public static function user_hash(int $userid): string {
        $salt = get_config('local_fastpix', 'user_hash_salt');
        return hash_hmac('sha256', (string)$userid, $salt);
    }

    private static function write(string $level, string $event, array $context): void {
        $base = [
            'ts'       => date('c'),
            'level'    => $level,
            'event'    => $event,
            'plugin'   => 'local_fastpix',
            'trace_id' => self::trace_id(),
        ];
        $line = self::redact(array_merge($base, $context));

        // Use Moodle's logstore if available; else fall back to error_log.
        if (function_exists('mtrace_log_' . $level)) {
            ('mtrace_log_' . $level)(json_encode($line, JSON_UNESCAPED_SLASHES));
        } else {
            error_log(json_encode($line, JSON_UNESCAPED_SLASHES));
        }
    }

    /**
     * Strip dangerous values BEFORE writing.
     */
    private static function redact(array $line): array {
        foreach ($line as $key => $value) {
            if (!is_string($value)) continue;

            // JWT pattern
            if (preg_match('/^eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $value)) {
                $line[$key] = '[REDACTED:JWT]';
                continue;
            }
            // Long base64 (likely a signature or key)
            if (preg_match('/^[A-Za-z0-9+\/=]{40,}$/', $value)) {
                $line[$key] = '[REDACTED:B64]';
                continue;
            }
            // Forbidden config keys
            if (in_array($key, ['apikey', 'apisecret', 'signing_private_key',
                                'webhook_secret_current', 'webhook_secret_previous'], true)) {
                $line[$key] = '[REDACTED:CONFIG]';
            }
        }
        return $line;
    }

    private static function trace_id(): string {
        // UUID v7-like: 48-bit timestamp + 80-bit random
        return sprintf('%08x-%04x-7%03x-%04x-%012x',
            time() & 0xffffffff,
            random_int(0, 0xffff),
            random_int(0, 0xfff),
            random_int(0, 0xffff),
            random_int(0, 0xffffffffffff));
    }
}
```

## Constraints

- **NEVER log raw `userid`, email, IP, JWT, signature, `apikey/apisecret`.**
- **Redaction is mandatory** — even for "I'll be careful" cases. The redact filter is the safety net.
- **PHPUnit canary** asserts log buffer never contains forbidden patterns.
- **Trace ID propagates** from webhook → adhoc task → projector via `set_custom_data`.

## Verification

Canary test runs every gateway / verifier / projector / signer happy path and greps the captured buffer for:
- `/eyJ[A-Za-z0-9_-]{10,}/` (JWT)
- The `apikey` / `apisecret` config values
- A test signature header
- A test email / IP

Asserts zero matches.
