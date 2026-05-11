# local_fastpix — Dev Troubleshooting

Self-recovery guide for the most common dev-environment issue: video
uploads succeed at FastPix, but the Moodle DB doesn't reflect them.

> In production, Moodle's system cron handles webhook projection
> automatically. The recipes below are for local dev.

## Quick diagnosis (90 seconds)

### 1. Are webhooks reaching Moodle?
Open ngrok inspector at <http://127.0.0.1:4040>.
- Requests visible → tunnel up, continue.
- No requests → restart ngrok, re-paste URL into FastPix dashboard.

### 2. Are they landing in the ledger?
```bash
docker exec moodle-docker-webserver-1 php -r '
define("CLI_SCRIPT", true); require "/var/www/html/config.php";
foreach ($DB->get_records("local_fastpix_webhook_event", null,
    "received_at DESC", "received_at, status, event_type", 0, 5) as $r) {
  echo date("H:i:s", $r->received_at) . " " . $r->status . " " . $r->event_type . "\n";
}
'
```

| Status | Meaning | Fix |
|---|---|---|
| all `processed` | Projector ran; problem is elsewhere | check assets table |
| all `pending` | Cron isn't running | step 3 |
| some `failed` | Projector threw; check `last_error` column | code fix |
| no rows | Signature failing or endpoint rejecting | re-paste `webhook_secret_current` |

### 3. Drain cron
```bash
docker exec moodle-docker-webserver-1 php /var/www/html/admin/cli/cron.php
```
Or as a persistent loop:
```bash
while true; do
  docker exec moodle-docker-webserver-1 php /var/www/html/admin/cli/cron.php
  sleep 30
done
```

### 4. Verify assets
```bash
docker exec moodle-docker-webserver-1 php -r '
define("CLI_SCRIPT", true); require "/var/www/html/config.php";
foreach ($DB->get_records("local_fastpix_asset", null,
    "timemodified DESC", "fastpix_id, status, playback_id, access_policy", 0, 5) as $r) {
  echo str_pad($r->fastpix_id, 40) . " status=" . $r->status .
       " playback=" . ($r->playback_id ?? "NULL") . " policy=" . $r->access_policy . "\n";
}
'
```

Healthy state: `status=ready`, `playback_id` populated, `policy` is
`public`/`private`/`drm`.

## Pre-existing assets with `playback_id=NULL`

If an asset reached `status=ready` before the 2026-05-08 projector fix
landed, its `playback_id` may be NULL. Repair via the proper projector
path:

```bash
# Dry-run first
docker exec moodle-docker-webserver-1 php /var/www/html/local/fastpix/cli/backfill_playback_ids.php

# Apply
docker exec moodle-docker-webserver-1 php /var/www/html/local/fastpix/cli/backfill_playback_ids.php --apply

# Drain cron to flush the requeued events
docker exec moodle-docker-webserver-1 php /var/www/html/admin/cli/cron.php
```

## Webhook signature failures (401)

| Cause | Fix |
|---|---|
| Pasted secret has trailing whitespace | Re-copy from FastPix dashboard |
| Secret rotated on FastPix side, not in Moodle | Re-paste current secret into admin |
| Secret < 32 chars | `webhook.secret_too_short` log line; admin field rejects |

## Don't do this

If `last_error` on ledger rows points to a code-level failure (new
FastPix event field, payload shape change), do **not** patch the DB
directly — that bypasses the per-asset lock (W4) and dual-key cache
invalidation (W5). Correct path:

1. Capture the failing payload from `local_fastpix_webhook_event.payload`.
2. Add a regression test in `tests/projector_test.php`.
3. Update `classes/webhook/projector.php`.
4. Run tests until green.
5. Reset `last_event_id`/`last_event_at` and re-queue affected events
   via `cli/backfill_playback_ids.php`.
