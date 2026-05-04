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

// Admin settings page.
$string['settings_credentials']       = 'API Credentials';
$string['settings_features']          = 'Feature Flags';
$string['setting_apikey']             = 'API Key';
$string['setting_apikey_desc']        = 'Your FastPix API key. Generate one in the FastPix dashboard under Settings → API Keys.';
$string['setting_apisecret']          = 'API Secret';
$string['setting_apisecret_desc']     = 'Your FastPix API secret. Pair with the API Key above. Stored encrypted at rest.';
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
