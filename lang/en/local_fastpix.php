<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'FastPix';

// Exception strings (constructor $context interpolated as {$a}).
$string['gateway_unavailable'] = 'FastPix gateway unavailable: {$a}';
$string['gateway_invalid_response'] = 'FastPix gateway returned an invalid response: {$a}';
$string['gateway_not_found'] = 'FastPix media not found: {$a}';
$string['signing_key_missing'] = 'JWT signing key is missing or invalid: {$a}';
$string['hmac_invalid'] = 'Webhook signature verification failed: {$a}';
$string['lock_acquisition_failed'] = 'Could not acquire per-asset lock: {$a}';
$string['asset_not_found'] = 'Asset not found: {$a}';
$string['drm_not_configured'] = 'DRM is not configured: {$a}';
$string['ssrf_blocked'] = 'URL rejected by SSRF guard: {$a}';
$string['rate_limit_exceeded'] = 'Rate limit exceeded: {$a}';
$string['credentials_missing'] = 'FastPix credentials are not configured: {$a}';

// Scheduled task names.
$string['task_orphan_sweeper'] = 'Sweep expired upload sessions';
$string['task_webhook_event_pruner'] = 'Prune old processed webhook events';
$string['task_asset_cleanup'] = 'Hard-delete soft-deleted assets past GDPR retention';
$string['task_signing_key_rotator'] = 'Rotate JWT signing key every 90 days';
