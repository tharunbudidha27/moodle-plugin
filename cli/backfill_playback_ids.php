<?php
/**
 * Backfill missing playback_id on assets ingested before the 2026-05-08
 * projector fix (which loosened the policy filter to accept "public").
 * Re-queues the original webhook event through the proper projector path
 * so per-asset lock + dual-key cache invalidation are honored (W4/W5).
 *
 * Usage:
 *   php local/fastpix/cli/backfill_playback_ids.php           # dry-run
 *   php local/fastpix/cli/backfill_playback_ids.php --apply   # mutate
 */

define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$opts] = cli_get_params([
    'apply'                 => false,
    'include-test-fixtures' => false,
    'help'                  => false,
], ['h' => 'help']);

if ($opts['help']) {
    cli_writeln("Backfill playback_id on legacy assets.");
    cli_writeln("  --apply                  perform the mutation (otherwise dry-run)");
    cli_writeln("  --include-test-fixtures  also process synthetic test-asset-* rows");
    exit(0);
}

global $DB;

$where  = "playback_id IS NULL AND deleted_at IS NULL";
$params = [];
if (!$opts['include-test-fixtures']) {
    $where .= " AND fastpix_id NOT LIKE :p";
    $params['p'] = 'test-asset-%';
}
$assets = $DB->get_records_select('local_fastpix_asset', $where, $params);
cli_writeln("candidates: " . count($assets) . ($opts['apply'] ? ' (apply)' : ' (dry-run)'));

$repaired = 0;
$skipped  = 0;
foreach ($assets as $asset) {
    $needle = '%' . $DB->sql_like_escape($asset->fastpix_id) . '%playbackIds%';
    $event_row = $DB->get_record_sql(
        "SELECT id, event_type, provider_event_id
           FROM {local_fastpix_webhook_event}
          WHERE payload LIKE :needle
          ORDER BY received_at ASC
          LIMIT 1",
        ['needle' => $needle]
    );
    if (!$event_row || empty($event_row->provider_event_id)) {
        cli_writeln("  skip {$asset->fastpix_id} (no playbackIds-bearing ledger row)");
        $skipped++;
        continue;
    }

    cli_writeln("  repair {$asset->fastpix_id} via ledger_id={$event_row->id} ({$event_row->event_type})");
    if (!$opts['apply']) {
        continue;
    }

    $asset->last_event_id = null;
    $asset->last_event_at = null;
    $asset->timemodified  = time();
    $DB->update_record('local_fastpix_asset', $asset);

    $DB->set_field('local_fastpix_webhook_event', 'status', 'pending', ['id' => $event_row->id]);

    $task = new \local_fastpix\task\process_webhook();
    $task->set_custom_data((object)['provider_event_id' => $event_row->provider_event_id]);
    \core\task\manager::queue_adhoc_task($task);
    $repaired++;
}

cli_writeln("");
cli_writeln("repaired={$repaired} skipped={$skipped}");
if ($opts['apply'] && $repaired > 0) {
    cli_writeln("next: php admin/cli/cron.php");
}
