# Skill 13 — Implement Health Endpoint

**Owner agent:** `@security-compliance` + `@gateway-integration`.

**When to invoke:** Phase 7, step 1.

---

## Inputs

None at runtime; reads MUC and DB state.

## Outputs

- `local/fastpix/health.php` — root endpoint.
- `local/fastpix/classes/service/health_service.php` — aggregator.

## Steps

### 1. `health_service.php`

```php
namespace local_fastpix\service;

class health_service {

    public function check_all(): array {
        return [
            'gateway' => $this->check_gateway(),
            'db'      => $this->check_db(),
            'muc'     => $this->check_muc(),
            'webhook_backlog' => $this->check_webhook_backlog(),
            'gdpr_backlog'    => $this->check_gdpr_backlog(),
        ];
    }

    private function check_gateway(): array {
        $start = microtime(true);
        $ok = \local_fastpix\api\gateway::instance()->health_probe();
        return [
            'status'     => $ok ? 'ok' : 'down',
            'latency_ms' => (int)((microtime(true) - $start) * 1000),
        ];
    }

    private function check_db(): array {
        global $DB;
        try {
            $DB->get_record('local_fastpix_asset', [], 'id', IGNORE_MULTIPLE);
            return ['status' => 'ok'];
        } catch (\Throwable $e) {
            return ['status' => 'down'];
        }
    }

    private function check_muc(): array {
        try {
            $cache = \cache::make('local_fastpix', 'rate_limit');
            $cache->set('health_canary', time());
            $cache->get('health_canary');
            return ['status' => 'ok'];
        } catch (\Throwable $e) {
            return ['status' => 'degraded'];  // fail-open per ADR-006
        }
    }

    private function check_webhook_backlog(): array {
        global $DB;
        $stale = $DB->count_records_select('local_fastpix_webhook_event',
            'status = :s AND received_at < :cutoff',
            ['s' => 'received', 'cutoff' => time() - 300]);
        return ['stale_count' => $stale, 'status' => $stale > 100 ? 'warning' : 'ok'];
    }

    private function check_gdpr_backlog(): array {
        global $DB;
        $pending = $DB->count_records_select('local_fastpix_asset',
            'gdpr_delete_pending_at IS NOT NULL');
        return ['pending_count' => $pending];
    }
}
```

### 2. `health.php`

```php
<?php
require_once(__DIR__ . '/../../config.php');

$service = new \local_fastpix\service\health_service();
$checks = $service->check_all();

$overall = 'ok';
if ($checks['gateway']['status'] === 'down' || $checks['db']['status'] === 'down') {
    $overall = 'down';
} elseif ($checks['muc']['status'] === 'degraded'
    || $checks['webhook_backlog']['status'] === 'warning') {
    $overall = 'degraded';
}

$http_status = $overall === 'down' ? 503 : 200;

header('Content-Type: application/json');
http_response_code($http_status);

echo json_encode([
    'status'    => $overall,
    'checks'    => $checks,
    'version'   => get_config('local_fastpix', 'version'),
    'timestamp' => time(),
]);
exit;
```

## Constraints

- **Health endpoint MUST NOT block.** Each check has a 1s timeout (`gateway::health_probe` already does).
- **Health does NOT report secret values, key IDs, or PII.**
- **`gateway::health_probe()` returns false on failure**, never throws.
- **HTTP 503 only when DB or gateway down.** MUC down → 200 with `degraded`.

## Verification

- Endpoint returns valid JSON with all 5 checks.
- HTTP 503 when FastPix probe fails.
- JSON contains no credentials, JWTs, or signatures.
- Endpoint returns within 5 seconds even if every check times out.
