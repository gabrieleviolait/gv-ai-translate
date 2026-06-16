<?php
/**
 * Uninstall cleanup for GV AI Translate.
 *
 * @package GV_AI_Translate
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('traduttore_options');
delete_option('gvait_options');

global $wpdb;

$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_traduttore_%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_traduttore_%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_gvait_%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_gvait_%'");
