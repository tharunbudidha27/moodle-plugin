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

// Capability labels.
$string['fastpix:configurecredentials'] = 'Configure FastPix API credentials';
$string['fastpix:uploadmedia'] = 'Upload media to FastPix';

// Scheduled task names.
$string['task_orphan_sweeper'] = 'Sweep expired upload sessions';
$string['task_webhook_event_pruner'] = 'Prune old processed webhook events';
$string['task_asset_cleanup'] = 'Hard-delete soft-deleted assets past GDPR retention';
$string['task_signing_key_rotator'] = 'Rotate JWT signing key every 90 days';
$string['task_retry_gdpr_delete'] = 'Retry pending GDPR remote deletions';

// Adhoc task name.
$string['task_process_webhook'] = 'Process a single FastPix webhook event';

// Web service descriptions.
$string['ws_create_upload_session'] = 'Create a FastPix file upload session';
$string['ws_create_url_pull_session'] = 'Create a FastPix URL pull session';
$string['ws_get_upload_status'] = 'Get the status of a FastPix upload session';

// Admin settings page.
$string['settings_credentials']       = 'API Credentials';
$string['settings_features']          = 'Feature Flags';
$string['setting_apikey']             = 'API Key';
$string['setting_apikey_desc']        = 'Your FastPix API key. Generate one in the FastPix dashboard under Settings → API Keys.';
$string['setting_apisecret']          = 'API Secret';
$string['setting_apisecret_desc']     = 'Your FastPix API secret. Pair with the API Key above. The UI masks this value, but it is stored as plaintext in the Moodle config table (mdl_config_plugins). Protect database backups and read access accordingly.';
$string['setting_drm_enabled']        = 'Enable DRM';
$string['setting_drm_enabled_desc']   = 'When enabled, content can be uploaded with DRM-protected playback. Requires a DRM Configuration ID.';
$string['setting_drm_config_id']      = 'DRM Configuration ID';
$string['setting_drm_config_id_desc'] = 'The DRM configuration ID from FastPix. Required when DRM is enabled.';
$string['setting_webhook_url']        = 'Webhook URL';

// Privacy API metadata.
$string['privacy:metadata:asset']                       = 'FastPix assets uploaded by the user';
$string['privacy:metadata:asset:owner_userid']          = 'The Moodle user who uploaded the asset';
$string['privacy:metadata:asset:fastpix_id']            = 'The FastPix-side identifier of the asset';
$string['privacy:metadata:asset:title']                 = 'The title of the asset';
$string['privacy:metadata:asset:duration']              = 'The duration in seconds';
$string['privacy:metadata:asset:timecreated']           = 'When the asset was uploaded';
$string['privacy:metadata:upload_session']              = 'In-progress FastPix upload sessions';
$string['privacy:metadata:upload_session:userid']       = 'The Moodle user who started the upload';
$string['privacy:metadata:upload_session:upload_id']    = 'The FastPix-side upload session identifier';
$string['privacy:metadata:upload_session:source_url']   = 'The source URL for URL-pull uploads (if applicable)';
$string['privacy:metadata:upload_session:state']        = 'The current state of the upload session';
$string['privacy:metadata:upload_session:timecreated']  = 'When the upload session was started';
$string['privacy:metadata:fastpix']                     = 'FastPix.io — external video hosting service';
$string['privacy:metadata:fastpix:owner_userhash']      = 'An HMAC-derived hash of the user ID (no plaintext PII sent)';
$string['privacy:metadata:fastpix:site_url']            = 'The Moodle site URL (used for cross-asset audit)';
// v1.0 cleanup — new strings.
$string['task_purge_soft_deleted_assets'] = 'Hard-purge soft-deleted assets after 7 days';
$string['event_webhook_secret_rotated']   = 'Webhook signing secret rotated';

$string['asset_not_ready'] = 'Asset exists but is not yet ready for playback: {$a}';

$string['settings_credentials']             = 'API credentials';
$string['settings_features']                = 'Feature flags';
$string['settings_webhooks']                = 'Webhooks';
$string['settings_webhooks_desc']           = 'FastPix posts events to the webhook URL below. Paste the signing secret from the FastPix dashboard into the field at the bottom of this section; rotation is automatic with a 30-minute rollover window.';
$string['setting_section_upload_defaults']  = 'Upload defaults';
$string['setting_default_access_policy']    = 'Default access policy';
$string['setting_default_access_policy_desc'] = 'Access policy applied to new uploads when the caller does not specify one explicitly. public plays without a token; private requires a JWT; drm requires JWT + DRM Configuration ID.';
$string['setting_max_resolution']           = 'Default maximum resolution';
$string['setting_max_resolution_desc']      = 'Cap applied to new uploads at FastPix transcode time.';
$string['setting_webhook_secret']           = 'Webhook signing secret';
$string['setting_webhook_secret_desc']      = 'Paste the signing secret FastPix generated for this webhook URL. The verifier honors the previous value for 30 minutes after each save (rollover window).';
$string['setting_webhook_secret_too_short'] = 'Webhook secret must be at least 32 characters; FastPix generates 64-character secrets by default.';
$string['webhook_secret_not_configured_notice'] = 'Webhook signing secret is not configured. FastPix events will be rejected until you paste the secret from the FastPix dashboard below.';

$string['button_test_connection']      = 'Test connection';
$string['button_test_connection_desc'] = 'Sends a no-write GET to FastPix using the configured credentials and reports latency. Useful immediately after pasting new credentials.';
$string['button_send_test_event']      = 'Send test event';
$string['button_send_test_event_desc'] = 'Fires a synthetic signed webhook event into the local processor to verify the ingestion pipeline end-to-end without involving FastPix.';

$string['test_connection_running'] = 'Probing…';
$string['test_connection_success'] = 'Connected (latency {$a} ms)';
$string['test_connection_failed']  = 'Failed: {$a}';

$string['send_test_event_running'] = 'Sending…';
$string['send_test_event_success'] = 'Test event delivered (ledger id {$a})';
$string['send_test_event_failed']  = 'Failed: {$a}';

// settings.php — access policy select option labels.
$string['access_policy_public']  = 'public — plays without a token';
$string['access_policy_private'] = 'private — requires a JWT';
$string['access_policy_drm']     = 'drm — requires a JWT plus a configured DRM Configuration ID';

// settings.php — rotation status display.
$string['setting_webhook_secret_rotated_at'] = 'Last secret rotation';
