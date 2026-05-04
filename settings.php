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

    // ---- Webhook URL (informational, read-only) -------------------------

    $webhook_url = (new moodle_url('/local/fastpix/webhook.php'))->out(false);
    $settings->add(new admin_setting_description(
        'local_fastpix/webhook_url',
        new lang_string('setting_webhook_url', 'local_fastpix'),
        $webhook_url,
    ));
}
