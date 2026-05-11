<?php
defined('MOODLE_INTERNAL') || die();

if (!$hassiteconfig) {
    return;
}

$settings = new admin_settingpage(
    'local_fastpix',
    new lang_string('pluginname', 'local_fastpix'),
);
$ADMIN->add('server', $settings);

if ($ADMIN->fulltree
    && has_capability('local/fastpix:configurecredentials', context_system::instance())) {

    // ---- API credentials ------------------------------------------------

    $settings->add(new admin_setting_heading(
        'local_fastpix/credentials',
        new lang_string('settings_credentials', 'local_fastpix'),
        '',
    ));

    $settings->add(new admin_setting_configtext(
        'local_fastpix/apikey',
        new lang_string('setting_apikey', 'local_fastpix'),
        new lang_string('setting_apikey_desc', 'local_fastpix'),
        '',
        PARAM_TEXT,
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_fastpix/apisecret',
        new lang_string('setting_apisecret', 'local_fastpix'),
        new lang_string('setting_apisecret_desc', 'local_fastpix'),
        '',
    ));

    // Test-connection button: runs gateway::health_probe() via AJAX
    // (local_fastpix_test_connection) and renders the result inline.
    $btn_id    = 'fp-test-connection-btn';
    $status_id = 'fp-test-connection-status';
    $btn_html  = \html_writer::tag('button',
        get_string('button_test_connection', 'local_fastpix'),
        ['id' => $btn_id, 'type' => 'button', 'class' => 'btn btn-secondary']
    );
    $status_html = \html_writer::tag('span', '', [
        'id'    => $status_id,
        'class' => 'ml-2 fp-test-connection-status',
        'style' => 'margin-left: 0.75em;',
    ]);
    $settings->add(new admin_setting_description(
        'local_fastpix/test_connection_button',
        new lang_string('button_test_connection', 'local_fastpix'),
        $btn_html . ' ' . $status_html . ' '
            . \html_writer::tag('div',
                get_string('button_test_connection_desc', 'local_fastpix'),
                ['class' => 'form-text text-muted']
            ),
    ));
    if (isset($PAGE) && $PAGE instanceof \moodle_page) {
        $PAGE->requires->js_call_amd(
            'local_fastpix/test_connection',
            'init',
            [$btn_id, $status_id],
        );
    }

    // ---- Upload defaults ------------------------------------------------

    $settings->add(new admin_setting_heading(
        'local_fastpix/upload_defaults',
        new lang_string('setting_section_upload_defaults', 'local_fastpix'),
        '',
    ));

    $settings->add(new admin_setting_configselect(
        'local_fastpix/default_access_policy',
        new lang_string('setting_default_access_policy', 'local_fastpix'),
        new lang_string('setting_default_access_policy_desc', 'local_fastpix'),
        'private',
        ['public' => 'public', 'private' => 'private', 'drm' => 'drm'],
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

    // ---- Feature flags --------------------------------------------------

    $settings->add(new admin_setting_heading(
        'local_fastpix/features',
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
        PARAM_TEXT,
    ));

    // ---- Webhooks -------------------------------------------------------

    $settings->add(new admin_setting_heading(
        'local_fastpix/heading_webhooks',
        new lang_string('settings_webhooks', 'local_fastpix'),
        new lang_string('settings_webhooks_desc', 'local_fastpix'),
    ));

    if (trim((string)get_config('local_fastpix', 'webhook_secret_current')) === '') {
        $settings->add(new admin_setting_description(
            'local_fastpix/webhook_secret_not_configured_notice',
            '',
            \html_writer::div(
                get_string('webhook_secret_not_configured_notice', 'local_fastpix'),
                'alert alert-warning'
            ),
        ));
    }

    $webhook_url = (new moodle_url('/local/fastpix/webhook.php'))->out(false);
    $settings->add(new admin_setting_description(
        'local_fastpix/webhook_url',
        new lang_string('setting_webhook_url', 'local_fastpix'),
        $webhook_url,
    ));

    $settings->add(new \local_fastpix\admin\setting_webhook_secret(
        'local_fastpix/webhook_secret_current',
        new lang_string('setting_webhook_secret', 'local_fastpix'),
        new lang_string('setting_webhook_secret_desc', 'local_fastpix'),
        '',
    ));

    // Send-test-event button.
    $test_event_btn_id    = 'fp-send-test-event-btn';
    $test_event_status_id = 'fp-send-test-event-status';
    $test_event_btn_html  = \html_writer::tag('button',
        get_string('button_send_test_event', 'local_fastpix'),
        ['id' => $test_event_btn_id, 'type' => 'button', 'class' => 'btn btn-secondary']
    );
    $test_event_status_html = \html_writer::tag('span', '', [
        'id'    => $test_event_status_id,
        'class' => 'fp-send-test-event-status',
        'style' => 'margin-left: 0.75em;',
    ]);
    $settings->add(new admin_setting_description(
        'local_fastpix/send_test_event_button',
        new lang_string('button_send_test_event', 'local_fastpix'),
        $test_event_btn_html . ' ' . $test_event_status_html . ' '
            . \html_writer::tag('div',
                get_string('button_send_test_event_desc', 'local_fastpix'),
                ['class' => 'form-text text-muted']
            ),
    ));
    if (isset($PAGE) && $PAGE instanceof \moodle_page) {
        $PAGE->requires->js_call_amd(
            'local_fastpix/send_test_event',
            'init',
            [$test_event_btn_id, $test_event_status_id],
        );
    }
}
