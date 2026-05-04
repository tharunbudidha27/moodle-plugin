# Skill 07 — Implement Webhook Projector with Per-Asset Locking + Total Ordering

**Owner agent:** `@webhook-processing`.

**When to invoke:** Phase 5, step 4. The single most concurrency-sensitive piece.

---

## Inputs

A normalized event object (from the verifier).

## Outputs

- `local/fastpix/classes/webhook/projector.php`
- `local/fastpix/classes/webhook/event_dispatcher.php`

## Steps

### 1. Lock and dispatch flow

```php
namespace local_fastpix\webhook;

use core\lock\lock_config;

class projector {

    private const LOCK_TIMEOUT_SECONDS = 5;
    private const LOCK_RESOURCE_PREFIX = 'asset_';

    public function project(\stdClass $event): void {
        $asset_key = $this->extract_asset_key($event);
        if ($asset_key === null) {
            $this->project_unlocked($event);
            return;
        }

        $factory = lock_config::get_lock_factory('local_fastpix');
        $lock = $factory->get_lock(
            self::LOCK_RESOURCE_PREFIX . $asset_key,
            self::LOCK_TIMEOUT_SECONDS
        );

        if (!$lock) {
            throw new \local_fastpix\exception\lock_acquisition_failed("asset_lock:{$asset_key}");
        }

        try {
            $this->project_inside_lock($event, $asset_key);
        } finally {
            $lock->release();
        }
    }

    private function extract_asset_key(\stdClass $event): ?string {
        return $event->object->id ?? null;  // event.object.id, NOT event.data.id
    }
}
```

### 2. Inside-lock body

```php
private function project_inside_lock(\stdClass $event, string $asset_key): void {
    global $DB;

    $asset = $DB->get_record('local_fastpix_asset', ['fastpix_id' => $asset_key]);

    if (!$asset) {
        if ($event->type === 'video.media.created') {
            $this->insert_new_asset($event);
            $this->mark_ledger_projected($event->id);
            return;
        }
        // forward compatibility: unknown asset, mark projected, log warn
        \local_fastpix\helper\logger::warn('webhook.asset_missing', [
            'event_id' => $event->id,
            'asset_id' => $asset_key,
            'event_type' => $event->type,
        ]);
        $this->mark_ledger_projected($event->id);
        return;
    }

    // TOTAL ORDERING with lex tiebreak
    $is_out_of_order = $asset->last_event_at !== null && (
        $event->created_at < (int)$asset->last_event_at
        || (
            $event->created_at === (int)$asset->last_event_at
            && $event->id <= $asset->last_event_id
        )
    );

    if ($is_out_of_order) {
        \local_fastpix\helper\logger::warn('webhook.out_of_order', [
            'event_id'         => $event->id,
            'event_created_at' => $event->created_at,
            'last_event_id'    => $asset->last_event_id,
            'last_event_at'    => $asset->last_event_at,
        ]);
        $this->mark_ledger_projected($event->id);
        return;
    }

    // Dispatch by event_type
    (new event_dispatcher())->apply($event, $asset);

    // Update ordering tracking
    $DB->update_record('local_fastpix_asset', (object)[
        'id'            => $asset->id,
        'last_event_at' => $event->created_at,
        'last_event_id' => $event->id,
        'timemodified'  => time(),
    ]);

    $this->mark_ledger_projected($event->id);

    // Invalidate BOTH cache keys, atomic with the write
    $cache = \cache::make('local_fastpix', 'asset');
    $cache->delete($asset_key);
    if (!empty($asset->playback_id)) {
        $cache->delete('pb:' . $asset->playback_id);
    }
}
```

### 3. Event dispatcher

`event_dispatcher::apply($event, $asset)` switches on `$event->type`:

| Event type | Action |
|---|---|
| `video.media.created` | INSERT (handled before dispatcher) |
| `video.media.ready` | UPDATE status='ready', duration, has_captions, playback_id; emit `asset_ready` |
| `video.media.updated` | UPDATE changed fields |
| `video.media.deleted` | UPDATE deleted_at |
| `video.media.failed` | UPDATE status='failed'; emit `asset_failed` |
| `video.track.created` | INSERT into `local_fastpix_track` |
| `video.track.ready` | UPDATE track status='ready' |
| `video.track.deleted` | DELETE track row |
| (unknown) | log + mark projected (forward compat) |

## Constraints

- **Lock acquired BEFORE the SELECT.** Covers the read-then-write critical section.
- **Total-ordering tiebreak is mandatory.** Equal timestamps without it cause non-deterministic state.
- **Asset key is `event.object.id`.** NEVER `event.data.id`.
- **Cache invalidation INSIDE the lock.** Otherwise concurrent reader repopulates stale data.
- **`finally` releases the lock**, even when projection throws.
- **No gateway calls inside the projector.** Rule W7.

## Verification

All 12 projector tests + 2 lock-contention integration tests in §14.3-4 pass. Notably:
- Concurrent projection of same asset (event_at=110 vs 105) → final state from event_at=110.
- Equal-timestamp lex tiebreak: lex-larger `provider_event_id` wins.
- Same `provider_event_id` as `last_event_id` dropped.
- Lock release on exception (finally tested).
- Lock acquisition timeout throws `lock_acquisition_failed`; ledger NOT marked projected; adhoc retries.
