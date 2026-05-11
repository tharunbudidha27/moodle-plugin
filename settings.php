<?php
// This file is part of local_fastpix.
//
// Admin settings page for the FastPix integration plugin.
//
// Evaluated by Moodle's admin tree on every admin request. Only the OUTER
// settings-page registration runs unconditionally; all widget construction
// is gated by `$ADMIN->fulltree` (the admin is actually rendering this
// page, not just walking the tree for navigation) AND
// `has_capability('local/fastpix:configurecredentials')` so a delegated
// "credentials manager" role does not need site-config to manage FastPix.
//
// Idempotent + read-only here. No DB writes, no gateway calls — the
// settings tree is walked many times per request and a slow path here
// would block every admin page render (audit drill 2026-05-11).

defined('MOODLE_INTERNAL') || die();

if (!$hassiteconfig) {
    return;
}

$settings = new admin_settingpage(
    'local_fastpix',
    new lang_string('pluginname', 'local_fastpix'),
);
$ADMIN->add('server', $settings);

if (!$ADMIN->fulltree) {
    return;
}

if (!has_capability('local/fastpix:configurecredentials', context_system::instance())) {
    return;
}

// ---------------------------------------------------------------------------
// Helper — emit an admin_setting_description that renders a button + a
// status span + a muted descriptor. Centralizes the markup so the two
// admin buttons (Test connection, Send test event) stay byte-identical.
// ---------------------------------------------------------------------------

/**
 * Build the HTML for an inline admin button + status pair.
 *
 * @param string $buttonid      DOM id of the button.
 * @param string $statusid      DOM id of the status span.
 * @param string $labelkey      Lang string key for the button label.
 * @param string $descriptionkey Lang string key for the muted descriptor.
 * @return string
 */
$local_fastpix_button_html = static function (
    string $buttonid,
    string $statusid,
    string $labelkey,
    string $descriptionkey,
): string {
    $button = \html_writer::tag('button', get_string($labelkey, 'local_fastpix'), [
        'id'    => $buttonid,
        'type'  => 'button',
        'class' => 'btn btn-secondary',
    ]);
    // ml-2 = Bootstrap 4 (Moodle 4.5 boost default); ms-2 = Bootstrap 5
    // (future themes). Include both class names; unknown classes are
    // ignored.
    $status = \html_writer::tag('span', '', [
        'id'    => $statusid,
        'class' => 'ml-2 ms-2 local-fastpix-status',
    ]);
    $description = \html_writer::tag('div',
        get_string($descriptionkey, 'local_fastpix'),
        ['class' => 'form-text text-muted'],
    );
    return $button . ' ' . $status . $description;
};

// ---- 1. API credentials ---------------------------------------------------

$settings->add(new admin_setting_heading(
    'local_fastpix/heading_credentials',
    new lang_string('settings_credentials', 'local_fastpix'),
    '',
));

$settings->add(new admin_setting_configtext(
    'local_fastpix/apikey',
    new lang_string('setting_apikey', 'local_fastpix'),
    new lang_string('setting_apikey_desc', 'local_fastpix'),
    '',
    PARAM_ALPHANUMEXT,
));

$settings->add(new admin_setting_configpasswordunmask(
    'local_fastpix/apisecret',
    new lang_string('setting_apisecret', 'local_fastpix'),
    new lang_string('setting_apisecret_desc', 'local_fastpix'),
    '',
));

$btn_test_connection_id = 'local_fastpix_test_connection_btn';
$btn_test_connection_status_id = 'local_fastpix_test_connection_status';
$settings->add(new admin_setting_description(
    'local_fastpix/test_connection_button',
    new lang_string('button_test_connection', 'local_fastpix'),
    $local_fastpix_button_html(
        $btn_test_connection_id,
        $btn_test_connection_status_id,
        'button_test_connection',
        'button_test_connection_desc',
    ),
));
// Button JS binding intentionally NOT loaded here. The grunt-built AMD
// bundle is hand-crafted (no node toolchain in the dev container) and
// historically cascades into Moodle's first.js bootstrap, breaking the
// admin password-unmask widget on this same page. Restore the
// js_call_amd line below once amd/build/test_connection.min.js is
// produced by `npx grunt amd --root=local/fastpix` on a host with node.
//
// Until then the button is visual-only; ops can drive the same probe
// via:
//   docker exec moodle-docker-webserver-1 php -r '
//     define("CLI_SCRIPT", true); require "config.php";
//     echo \\local_fastpix\\api\\gateway::instance()->health_probe() ? "OK\n" : "FAIL\n";
//   '

// ---- 2. Upload defaults ---------------------------------------------------

$settings->add(new admin_setting_heading(
    'local_fastpix/heading_upload_defaults',
    new lang_string('setting_section_upload_defaults', 'local_fastpix'),
    '',
));

$settings->add(new admin_setting_configselect(
    'local_fastpix/default_access_policy',
    new lang_string('setting_default_access_policy', 'local_fastpix'),
    new lang_string('setting_default_access_policy_desc', 'local_fastpix'),
    'private',
    [
        'public'  => new lang_string('access_policy_public',  'local_fastpix'),
        'private' => new lang_string('access_policy_private', 'local_fastpix'),
        'drm'     => new lang_string('access_policy_drm',     'local_fastpix'),
    ],
));

$settings->add(new admin_setting_configselect(
    'local_fastpix/max_resolution',
    new lang_string('setting_max_resolution', 'local_fastpix'),
    new lang_string('setting_max_resolution_desc', 'local_fastpix'),
    '1080p',
    [
        '480p'  => '480p',
        '720p'  => '720p',
        '1080p' => '1080p',
        '1440p' => '1440p',
        '2160p' => '2160p',
    ],
));

// ---- 3. Feature flags -----------------------------------------------------

$settings->add(new admin_setting_heading(
    'local_fastpix/heading_features',
    new lang_string('settings_features', 'local_fastpix'),
    '',
));

$settings->add(new admin_setting_configcheckbox(
    'local_fastpix/feature_drm_enabled',
    new lang_string('setting_drm_enabled', 'local_fastpix'),
    new lang_string('setting_drm_enabled_desc', 'local_fastpix'),
    0,
));

$settings->add(new admin_setting_configtext(
    'local_fastpix/drm_configuration_id',
    new lang_string('setting_drm_config_id', 'local_fastpix'),
    new lang_string('setting_drm_config_id_desc', 'local_fastpix'),
    '',
    PARAM_ALPHANUMEXT,
));

// Hide the DRM config id when the DRM feature flag is OFF (rule W12 double
// gate is enforced at runtime; this is just UI clarity).
$settings->hide_if(
    'local_fastpix/drm_configuration_id',
    'local_fastpix/feature_drm_enabled',
    'notchecked',
);

// ---- 4. Webhooks ----------------------------------------------------------

$settings->add(new admin_setting_heading(
    'local_fastpix/heading_webhooks',
    new lang_string('settings_webhooks', 'local_fastpix'),
    new lang_string('settings_webhooks_desc', 'local_fastpix'),
));

// Conditional "not configured" notice — only when the secret is empty so
// the warning disappears on first paste.
if (trim((string)get_config('local_fastpix', 'webhook_secret_current')) === '') {
    $settings->add(new admin_setting_description(
        'local_fastpix/webhook_secret_not_configured_notice',
        '',
        \html_writer::div(
            get_string('webhook_secret_not_configured_notice', 'local_fastpix'),
            'alert alert-warning',
        ),
    ));
}

$webhook_url = (new moodle_url('/local/fastpix/webhook.php'))->out(false);
$settings->add(new admin_setting_description(
    'local_fastpix/webhook_url',
    new lang_string('setting_webhook_url', 'local_fastpix'),
    \html_writer::tag('code', s($webhook_url)),
));

$settings->add(new \local_fastpix\admin\setting_webhook_secret(
    'local_fastpix/webhook_secret_current',
    new lang_string('setting_webhook_secret', 'local_fastpix'),
    new lang_string('setting_webhook_secret_desc', 'local_fastpix'),
    '',
));

// Last-rotation timestamp display (read-only operator hint). Only shown
// when a rotation has actually occurred. Format via userdate so it
// respects the operator's timezone / locale.
$rotated_at = (int)get_config('local_fastpix', 'webhook_secret_rotated_at');
if ($rotated_at > 0) {
    $settings->add(new admin_setting_description(
        'local_fastpix/webhook_secret_rotated_at_display',
        new lang_string('setting_webhook_secret_rotated_at', 'local_fastpix'),
        \html_writer::tag('code', s(userdate($rotated_at))),
    ));
}

$btn_send_event_id = 'local_fastpix_send_test_event_btn';
$btn_send_event_status_id = 'local_fastpix_send_test_event_status';
$settings->add(new admin_setting_description(
    'local_fastpix/send_test_event_button',
    new lang_string('button_send_test_event', 'local_fastpix'),
    $local_fastpix_button_html(
        $btn_send_event_id,
        $btn_send_event_status_id,
        'button_send_test_event',
        'button_send_test_event_desc',
    ),
));
// Send-test-event JS binding likewise pending a real grunt build.
// CLI equivalent:
//   docker exec moodle-docker-webserver-1 php local/fastpix/cli/webhook_loopback_test.php --count=1
