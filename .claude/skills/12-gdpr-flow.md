# Skill 12 — Implement GDPR Delete Flow (Per-Asset Pattern)

**Owner agent:** `@security-compliance`.

**When to invoke:** Phase 6, step 5.

---

## Inputs

An `approved_contextlist` from Moodle's privacy framework.

## Outputs

- `local/fastpix/classes/privacy/provider.php` implementing:
  - `\core_privacy\local\metadata\provider`
  - `\core_privacy\local\request\plugin\provider`
  - `\core_privacy\local\request\core_userlist_provider`

## Steps

### 1. `get_metadata`

```php
public static function get_metadata(\core_privacy\local\metadata\collection $collection):
    \core_privacy\local\metadata\collection {

    $collection->add_database_table('local_fastpix_asset', [
        'owner_userid' => 'privacy:metadata:asset:owner_userid',
        'title'        => 'privacy:metadata:asset:title',
    ], 'privacy:metadata:asset');

    $collection->add_database_table('local_fastpix_upload_session', [
        'userid' => 'privacy:metadata:upload_session:userid',
    ], 'privacy:metadata:upload_session');

    $collection->add_external_location_link('fastpix', [
        'media_id' => 'privacy:metadata:fastpix:media_id',
        'metadata' => 'privacy:metadata:fastpix:metadata',
    ], 'privacy:metadata:fastpix');

    return $collection;
}
```

### 2. `delete_data_for_user` — the per-asset pattern

```php
public static function delete_data_for_user(\core_privacy\local\request\approved_contextlist $contextlist): void {
    global $DB;
    $userid = $contextlist->get_user()->id;

    // 1. Soft-delete asset rows + queue for FastPix deletion
    $assets = $DB->get_records('local_fastpix_asset', ['owner_userid' => $userid]);
    foreach ($assets as $asset) {
        $DB->update_record('local_fastpix_asset', (object)[
            'id'                     => $asset->id,
            'deleted_at'             => time(),
            'gdpr_delete_pending_at' => time(),
            'timemodified'           => time(),
        ]);

        // 2. Try FastPix delete; on failure, retry task picks up
        try {
            \local_fastpix\api\gateway::instance()->delete_media($asset->fastpix_id);
            // Success: clear pending flag
            $DB->set_field('local_fastpix_asset', 'gdpr_delete_pending_at', null,
                ['id' => $asset->id]);
        } catch (\local_fastpix\exception\gateway_unavailable $e) {
            // Stays pending; retry_gdpr_delete picks up within 15 min
        }
    }

    // 3. Hard-delete upload sessions
    $DB->delete_records('local_fastpix_upload_session', ['userid' => $userid]);
}
```

### 3. `export_user_data`, `get_contexts_for_userid`, `get_users_in_context`, `delete_data_for_users`

Standard implementations querying the two owner-keyed tables.

## Constraints

- **Per-asset DELETE pattern**, NOT a bulk "delete all my data" call.
- **Local soft-delete completes even if FastPix fails** — compliance: local data is gone immediately.
- **Retry task `retry_gdpr_delete`** handles eventual-consistency tail; max 24h SLA.
- **Alert event after 6 consecutive failures per asset** (handled by retry task, not this provider).

## Verification

- Privacy export round-trips for synthetic user with 3 assets and 2 upload sessions.
- Privacy delete: per-asset DELETE; on FastPix failure, `gdpr_delete_pending_at` set; retry task completes within 24h.
- Alert event fires after 6 consecutive failures.
