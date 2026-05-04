# Skill 08 — Implement Asset Service with DB + MUC + Lazy Fetch

**Owner agent:** `@asset-service`.

**When to invoke:** Phase 3, step 1.

---

## Inputs

A `fastpix_id` (Media ID), or a `playback_id`, or an internal row `id`.

## Outputs

- `local/fastpix/classes/service/asset_service.php` with the public contract from §13 of the system overview.

## Public API (STABLE — used by mod_fastpix and filter_fastpix)

```php
public static function get_by_fastpix_id(string $fastpix_id, bool $include_deleted = false): ?\stdClass
public static function get_by_playback_id(string $playback_id, bool $include_deleted = false): ?\stdClass
public static function get_by_fastpix_id_or_fetch(string $fastpix_id): \stdClass  // throws asset_not_found / gateway_unavailable
public static function get_by_id(int $id, bool $include_deleted = false): ?\stdClass
public static function list_for_owner(int $userid, ?string $status = 'ready', int $limit = 50): array
public static function list_for_owner_paginated(int $userid, ?string $status, int $offset, int $limit, string $search = ''): array
public static function soft_delete(int $id): void
```

## Steps

### 1. Dual-key cache strategy

Cache area `local_fastpix/asset`. Two keys per row:
- `<fastpix_id>` (Media ID lookup)
- `pb:<playback_id>` (filter / shortcode lookup)

Both populated on read; both invalidated on write.

### 2. `get_by_fastpix_id`

1. Cache lookup by `fastpix_id`.
2. On miss, `$DB->get_record('local_fastpix_asset', ['fastpix_id' => ...])`.
3. Cache the row (whether or not it's soft-deleted; the filter logic happens on read).
4. If soft-deleted and `!$include_deleted`, return null.
5. Else return the row.

### 3. `get_by_playback_id`

Same shape, cache key `pb:<playback_id>`.

### 4. `get_by_fastpix_id_or_fetch` — the lazy-fetch read path

```php
public static function get_by_fastpix_id_or_fetch(string $fastpix_id): \stdClass {
    $asset = self::get_by_fastpix_id($fastpix_id);
    if ($asset !== null) {
        return $asset;
    }

    // Cold start: fetch from FastPix
    try {
        $remote = \local_fastpix\api\gateway::instance()->get_media($fastpix_id);
    } catch (\local_fastpix\exception\gateway_not_found $e) {
        throw new \local_fastpix\exception\asset_not_found($fastpix_id);
    }

    global $DB;

    // Extract first private/drm playback_id
    $playback_id = null;
    $access_policy = $remote->data->accessPolicy ?? 'private';
    if (!empty($remote->data->playbackIds)) {
        foreach ($remote->data->playbackIds as $pb) {
            if (in_array($pb->accessPolicy ?? '', ['private', 'drm'], true)) {
                $playback_id = $pb->id;
                $access_policy = $pb->accessPolicy;
                break;
            }
        }
    }

    $now = time();
    $row = (object)[
        'fastpix_id'       => $remote->data->id,
        'playback_id'      => $playback_id,
        'owner_userid'     => 0,                                  // sentinel
        'title'            => $remote->data->title ?? "Imported {$remote->data->id}",
        'duration'         => $remote->data->duration ?? null,
        'status'           => $remote->data->status ?? 'ready',
        'access_policy'    => $access_policy,
        'drm_required'     => $access_policy === 'drm' ? 1 : 0,
        'no_skip_required' => 0,
        'has_captions'     => self::has_caption_track($remote->data),
        'last_event_id'    => null,
        'last_event_at'    => null,
        'deleted_at'       => null,
        'timecreated'      => $now,
        'timemodified'     => $now,
    ];

    try {
        $row->id = $DB->insert_record('local_fastpix_asset', $row);
    } catch (\dml_write_exception $e) {
        // Race: parallel insert won. Re-read.
        $existing = self::get_by_fastpix_id($fastpix_id);
        if ($existing) {
            return $existing;
        }
        throw $e;
    }

    \cache::make('local_fastpix', 'asset')->set($fastpix_id, $row);
    if ($playback_id) {
        \cache::make('local_fastpix', 'asset')->set('pb:' . $playback_id, $row);
    }

    return $row;
}
```

### 5. `soft_delete`

```php
public static function soft_delete(int $id): void {
    global $DB;
    $row = $DB->get_record('local_fastpix_asset', ['id' => $id], 'id, fastpix_id, playback_id');
    if (!$row) return;

    $DB->update_record('local_fastpix_asset', (object)[
        'id'           => $id,
        'deleted_at'   => time(),
        'timemodified' => time(),
    ]);

    $cache = \cache::make('local_fastpix', 'asset');
    $cache->delete($row->fastpix_id);
    if (!empty($row->playback_id)) {
        $cache->delete('pb:' . $row->playback_id);
    }
}
```

## Constraints

- **Lazy fetch FORBIDDEN on write paths.** Projector, privacy provider, scheduled tasks must use `get_by_fastpix_id` (no `_or_fetch`).
- **`owner_userid=0` is the cold-start sentinel.** Capability checks based on owner MUST treat it as "no owner."
- **Both cache keys invalidated on every write.**
- **Public contract methods MUST keep their signatures stable.** Breaking changes require major version bump.

## Verification

- Cache hit / miss / soft-deleted-filter all pass.
- Cold-start lazy fetch: exactly one `gateway::get_media`; INSERT with `owner_userid=0`.
- Race: two concurrent first-views → one INSERT, second re-reads.
- FastPix 404 → `asset_not_found` (not `gateway_not_found`).
- `soft_delete` invalidates BOTH keys.
