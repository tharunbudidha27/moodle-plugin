<?php
defined('MOODLE_INTERNAL') || die();

/**
 * One-time install bootstrap for local_fastpix.
 *
 * Generates the webhook signing secret, the user-hash salt, and seeds
 * default feature-flag configuration. Runs ONCE on first install only —
 * subsequent schema changes go through db/upgrade.php.
 *
 * The local RS256 signing key is NOT minted here. credential_service::
 * ensure_signing_key() bootstraps it lazily on first use, after the admin
 * has saved API credentials.
 */
function xmldb_local_fastpix_install() {
    // Webhook signing secret — 64 hex chars from a CSPRNG. The admin pastes
    // this value into the FastPix dashboard's webhook configuration.
    set_config('webhook_secret_current',     bin2hex(random_bytes(32)), 'local_fastpix');
    set_config('webhook_secret_previous',    '',                        'local_fastpix');
    set_config('webhook_secret_rotated_at',  0,                         'local_fastpix');

    // User-hash salt for HMAC(userid) before sending to FastPix metadata.
    set_config('user_hash_salt', random_string(32), 'local_fastpix');

    // Signing-key creation timestamp — 0 means "not tracked yet".
    // signing_key_rotator seeds it on first run.
    set_config('signing_key_created_at', 0, 'local_fastpix');

    // Feature flags — DRM off by default; require explicit opt-in.
    set_config('feature_drm_enabled',   0,  'local_fastpix');
    set_config('drm_configuration_id',  '', 'local_fastpix');

    // Tell the admin where to point FastPix's webhook configuration.
    $webhook_url = (new moodle_url('/local/fastpix/webhook.php'))->out(false);
    mtrace("local_fastpix installed. Configure FastPix webhooks to POST to:\n  {$webhook_url}");

    return true;
}
