<?php
/**
 * Plugin Name: GV AI translate
 * Plugin URI: https://www.gabrieleviola.it
 * Description: Adds an AI-powered language selector and frontend text translation with configurable providers and local cache.
 * Version: 1.0.10
 * Author: Gabriele Viola
 * Author URI: https://www.gabrieleviola.it
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: gv-ai-translate
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

final class GV_AI_Translate {
    const OPT = 'traduttore_options';
    const PARAM = 'traduttore_lang';
    const COOKIE = 'traduttore_lang';
    const MAX_TEXT_LENGTH = 600;
    const VERSION = '1.0.10';

    private static $instance = null;
    private $options = array();
    private $current_lang = 'it';
    private $default_lang = 'it';

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->options = $this->get_options();
        $this->default_lang = !empty($this->options['default_lang']) ? $this->options['default_lang'] : 'it';
        $this->current_lang = $this->detect_lang();

        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_init', array($this, 'maybe_clear_cache'));
        add_action('admin_init', array($this, 'migrate_legacy_options'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        add_shortcode('gv_translate', array($this, 'shortcode'));
        // New shortcode name for clarity; keep old one as alias for backward compatibility
        add_shortcode('traduttore_translate', array($this, 'shortcode'));
        add_action('wp_footer', array($this, 'floating_selector'));
        add_action('wp_ajax_gvait_translate_texts', array($this, 'ajax_translate_texts'));
        add_action('wp_ajax_nopriv_gvait_translate_texts', array($this, 'ajax_translate_texts'));
        // New AJAX action name; keep old ones for backward compatibility
        add_action('wp_ajax_traduttore_translate_texts', array($this, 'ajax_translate_texts'));
        add_action('wp_ajax_nopriv_traduttore_translate_texts', array($this, 'ajax_translate_texts'));
        add_action('init', array($this, 'maybe_redirect_clean_url'));
        add_action('init', array($this, 'maybe_disable_page_cache'), 1);
    }

    public function get_options() {
        $defaults = array(
            'groq_api_key' => '',
            'groq_model' => 'llama-3.1-8b-instant',
            'openai_api_key' => '',
            'openai_model' => 'gpt-4o-mini',
            'anthropic_api_key' => '',
            'anthropic_model' => 'claude-3-5-haiku-latest',
            'google_api_key' => '',
            'google_model' => 'gemini-1.5-flash',
            'provider_order' => 'groq,openai,anthropic,google',
            'default_lang' => 'it',
            'languages' => 'it,en',
            'selector_style' => 'dropdown',
            'floating' => '1',
            'floating_position' => 'top-right',
            'auto_translate' => '0',
            'cache_days' => 'forever',
            'max_text_nodes' => '120',
            'min_chars' => '2',
            'debug' => '0',
        );

        // Try new option key first, fall back to legacy for upgrades
        $saved = get_option(self::OPT, array());
        if (empty($saved)) {
            $saved = get_option('gvait_options', array());
        }
        if (!is_array($saved)) {
            $saved = array();
        }

        if (empty($saved['groq_api_key']) && !empty($saved['api_key'])) {
            $saved['groq_api_key'] = $saved['api_key'];
        }
        if (empty($saved['groq_model']) && !empty($saved['model'])) {
            $saved['groq_model'] = $saved['model'];
        }

        return wp_parse_args($saved, $defaults);
    }

    public function register_settings() {
        register_setting('gvait_settings', self::OPT, array($this, 'sanitize_options'));
    }

    public function sanitize_options($input) {
        if (!is_array($input)) {
            $input = array();
        }

        $out = $this->get_options();

        $out['groq_api_key'] = sanitize_text_field(isset($input['groq_api_key']) ? $input['groq_api_key'] : '');
        $out['groq_model'] = sanitize_text_field(isset($input['groq_model']) ? $input['groq_model'] : 'llama-3.1-8b-instant');

        $out['openai_api_key'] = sanitize_text_field(isset($input['openai_api_key']) ? $input['openai_api_key'] : '');
        $out['openai_model'] = sanitize_text_field(isset($input['openai_model']) ? $input['openai_model'] : 'gpt-4o-mini');

        $out['anthropic_api_key'] = sanitize_text_field(isset($input['anthropic_api_key']) ? $input['anthropic_api_key'] : '');
        $out['anthropic_model'] = sanitize_text_field(isset($input['anthropic_model']) ? $input['anthropic_model'] : 'claude-3-5-haiku-latest');

        $out['google_api_key'] = sanitize_text_field(isset($input['google_api_key']) ? $input['google_api_key'] : '');
        $out['google_model'] = sanitize_text_field(isset($input['google_model']) ? $input['google_model'] : 'gemini-1.5-flash');

        $allowed = array('groq', 'openai', 'anthropic', 'google', 'google_free');
        $order_raw = strtolower(sanitize_text_field(isset($input['provider_order']) ? $input['provider_order'] : 'groq,openai,anthropic,google'));
        $raw_parts = explode(',', $order_raw);
        $parts = array();
        foreach ($raw_parts as $p) {
            $p = trim($p);
            if (in_array($p, $allowed, true) && !in_array($p, $parts, true)) {
                $parts[] = $p;
            }
        }
        $out['provider_order'] = !empty($parts) ? implode(',', $parts) : 'groq,openai,anthropic,google';

        $out['default_lang'] = isset($input['default_lang']) ? strtolower(sanitize_key($input['default_lang'])) : 'it';

        $langs = isset($input['languages']) ? strtolower(sanitize_text_field($input['languages'])) : 'it,en';
        $langs = preg_replace('/[^a-z,\-]/', '', $langs);
        $out['languages'] = $langs ? $langs : 'it,en';

        $style = isset($input['selector_style']) ? $input['selector_style'] : 'dropdown';
        $out['selector_style'] = in_array($style, array('dropdown', 'buttons'), true) ? $style : 'dropdown';

        $out['floating'] = !empty($input['floating']) ? '1' : '0';

        $pos = isset($input['floating_position']) ? $input['floating_position'] : 'top-right';
        $out['floating_position'] = in_array($pos, array('top-right', 'top-left', 'bottom-right', 'bottom-left'), true) ? $pos : 'top-right';

        $out['auto_translate'] = !empty($input['auto_translate']) ? '1' : '0';

        $cache_input = isset($input['cache_days']) ? $input['cache_days'] : 'forever';
        $out['cache_days'] = ($cache_input === 'forever') ? 'forever' : strval(max(1, intval($cache_input)));

        $out['max_text_nodes'] = strval(max(20, min(500, intval(isset($input['max_text_nodes']) ? $input['max_text_nodes'] : 120))));
        $out['min_chars'] = strval(max(1, min(30, intval(isset($input['min_chars']) ? $input['min_chars'] : 2))));
        $out['debug'] = !empty($input['debug']) ? '1' : '0';

        return $out;
    }

    public function admin_menu() {
        add_options_page(
            __('GV AI Translate', 'gv-ai-translate'),
            __('GV AI Translate', 'gv-ai-translate'),
            'manage_options',
            'gv-ai-translate',
            array($this, 'settings_page')
        );
    }

    public function maybe_clear_cache() {
        if (!current_user_can('manage_options')) {
            return;
        }
        // support both legacy and new clear-cache query param
        if (!isset($_GET['gvait_clear_cache']) && !isset($_GET['traduttore_clear_cache'])) {
            return;
        }

        if (isset($_GET['traduttore_clear_cache'])) {
            check_admin_referer('traduttore_clear_cache');
        } else {
            check_admin_referer('gvait_clear_cache');
        }

        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_gvait_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_gvait_%'");
        // also clean transients created with new prefix
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_traduttore_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_traduttore_%'");

        wp_safe_redirect(admin_url('options-general.php?page=gv-ai-translate&cache_cleared=1'));
        exit;
    }

    public function settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $o = $this->get_options();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('GV AI Translate', 'gv-ai-translate'); ?></h1>

            <?php if (isset($_GET['cache_cleared'])) : ?>
                <div class="notice notice-success"><p><?php echo esc_html__('Translation cache cleared successfully.', 'gv-ai-translate'); ?></p></div>
            <?php endif; ?>

            <p>
                <a href="<?php echo esc_url(wp_nonce_url(admin_url('options-general.php?page=gv-ai-translate&traduttore_clear_cache=1'), 'traduttore_clear_cache')); ?>" class="button button-secondary">
                    <?php echo esc_html__('Clear translation cache', 'gv-ai-translate'); ?>
                </a>
            </p>

            <form method="post" action="options.php">
                <?php settings_fields('gvait_settings'); ?>

                <h2><?php echo esc_html__('AI providers and fallback', 'gv-ai-translate'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php echo esc_html__('Provider order', 'gv-ai-translate'); ?></th>
                        <td>
                            <input type="text" name="<?php echo esc_attr(self::OPT); ?>[provider_order]" value="<?php echo esc_attr($o['provider_order']); ?>" class="regular-text">
                            <p class="description"><?php echo wp_kses_post(__('Example: <code>groq,openai,anthropic,google</code>. Add <code>google_free</code> manually only if you explicitly want to use the unofficial Google Translate fallback without an API key.', 'gv-ai-translate')); ?></p>
                        </td>
                    </tr>
                    <tr><th scope="row"><?php echo esc_html__('Groq API key', 'gv-ai-translate'); ?></th><td><input type="password" name="<?php echo esc_attr(self::OPT); ?>[groq_api_key]" value="<?php echo esc_attr($o['groq_api_key']); ?>" class="regular-text" autocomplete="off"></td></tr>
                    <tr><th scope="row"><?php echo esc_html__('Groq model', 'gv-ai-translate'); ?></th><td><input type="text" name="<?php echo esc_attr(self::OPT); ?>[groq_model]" value="<?php echo esc_attr($o['groq_model']); ?>" class="regular-text"></td></tr>
                    <tr><th scope="row"><?php echo esc_html__('OpenAI API key', 'gv-ai-translate'); ?></th><td><input type="password" name="<?php echo esc_attr(self::OPT); ?>[openai_api_key]" value="<?php echo esc_attr($o['openai_api_key']); ?>" class="regular-text" autocomplete="off"></td></tr>
                    <tr><th scope="row"><?php echo esc_html__('OpenAI model', 'gv-ai-translate'); ?></th><td><input type="text" name="<?php echo esc_attr(self::OPT); ?>[openai_model]" value="<?php echo esc_attr($o['openai_model']); ?>" class="regular-text"></td></tr>
                    <tr><th scope="row"><?php echo esc_html__('Anthropic API key', 'gv-ai-translate'); ?></th><td><input type="password" name="<?php echo esc_attr(self::OPT); ?>[anthropic_api_key]" value="<?php echo esc_attr($o['anthropic_api_key']); ?>" class="regular-text" autocomplete="off"></td></tr>
                    <tr><th scope="row"><?php echo esc_html__('Anthropic model', 'gv-ai-translate'); ?></th><td><input type="text" name="<?php echo esc_attr(self::OPT); ?>[anthropic_model]" value="<?php echo esc_attr($o['anthropic_model']); ?>" class="regular-text"></td></tr>
                    <tr><th scope="row"><?php echo esc_html__('Google Gemini API key', 'gv-ai-translate'); ?></th><td><input type="password" name="<?php echo esc_attr(self::OPT); ?>[google_api_key]" value="<?php echo esc_attr($o['google_api_key']); ?>" class="regular-text" autocomplete="off"></td></tr>
                    <tr><th scope="row"><?php echo esc_html__('Gemini model', 'gv-ai-translate'); ?></th><td><input type="text" name="<?php echo esc_attr(self::OPT); ?>[google_model]" value="<?php echo esc_attr($o['google_model']); ?>" class="regular-text"></td></tr>
                </table>

                <h2><?php echo esc_html__('Languages and behavior', 'gv-ai-translate'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr><th scope="row"><?php echo esc_html__('Default language', 'gv-ai-translate'); ?></th><td><input type="text" name="<?php echo esc_attr(self::OPT); ?>[default_lang]" value="<?php echo esc_attr($o['default_lang']); ?>" class="small-text"></td></tr>
                    <tr><th scope="row"><?php echo esc_html__('Available languages', 'gv-ai-translate'); ?></th><td><input type="text" name="<?php echo esc_attr(self::OPT); ?>[languages]" value="<?php echo esc_attr($o['languages']); ?>" class="regular-text"><p class="description"><?php echo esc_html__('Comma-separated, for example: it,en.', 'gv-ai-translate'); ?></p></td></tr>
                    <tr><th scope="row"><?php echo esc_html__('Selector style', 'gv-ai-translate'); ?></th><td><select name="<?php echo esc_attr(self::OPT); ?>[selector_style]"><option value="dropdown" <?php selected($o['selector_style'], 'dropdown'); ?>><?php echo esc_html__('Dropdown', 'gv-ai-translate'); ?></option><option value="buttons" <?php selected($o['selector_style'], 'buttons'); ?>><?php echo esc_html__('Buttons', 'gv-ai-translate'); ?></option></select></td></tr>
                    <tr><th scope="row"><?php echo esc_html__('Floating selector', 'gv-ai-translate'); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPT); ?>[floating]" value="1" <?php checked($o['floating'], '1'); ?>> <?php echo esc_html__('Show automatically', 'gv-ai-translate'); ?></label></td></tr>
                    <tr><th scope="row"><?php echo esc_html__('Floating position', 'gv-ai-translate'); ?></th><td><select name="<?php echo esc_attr(self::OPT); ?>[floating_position]"><?php foreach (array('top-right','top-left','bottom-right','bottom-left') as $p) : ?><option value="<?php echo esc_attr($p); ?>" <?php selected($o['floating_position'], $p); ?>><?php echo esc_html($p); ?></option><?php endforeach; ?></select></td></tr>
                    <tr><th scope="row"><?php echo esc_html__('Frontend auto-translation', 'gv-ai-translate'); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPT); ?>[auto_translate]" value="1" <?php checked($o['auto_translate'], '1'); ?>> <?php echo esc_html__('Enable frontend auto-translation. When enabled, visible page text may be sent to the configured translation providers.', 'gv-ai-translate'); ?></label></td></tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Cache', 'gv-ai-translate'); ?></th>
                        <td>
                            <select name="<?php echo esc_attr(self::OPT); ?>[cache_days]">
                                <option value="1" <?php selected($o['cache_days'], '1'); ?>><?php echo esc_html__('1 day', 'gv-ai-translate'); ?></option>
                                <option value="7" <?php selected($o['cache_days'], '7'); ?>><?php echo esc_html__('7 days', 'gv-ai-translate'); ?></option>
                                <option value="30" <?php selected($o['cache_days'], '30'); ?>><?php echo esc_html__('30 days', 'gv-ai-translate'); ?></option>
                                <option value="90" <?php selected($o['cache_days'], '90'); ?>><?php echo esc_html__('90 days', 'gv-ai-translate'); ?></option>
                                <option value="forever" <?php selected($o['cache_days'], 'forever'); ?>><?php echo esc_html__('Never expire, persistent cache', 'gv-ai-translate'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr><th scope="row"><?php echo esc_html__('Maximum texts per page', 'gv-ai-translate'); ?></th><td><input type="number" name="<?php echo esc_attr(self::OPT); ?>[max_text_nodes]" value="<?php echo esc_attr($o['max_text_nodes']); ?>" min="20" max="500" class="small-text"></td></tr>
                    <tr><th scope="row"><?php echo esc_html__('Debug', 'gv-ai-translate'); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPT); ?>[debug]" value="1" <?php checked($o['debug'], '1'); ?>> <?php echo esc_html__('Add diagnostic comments', 'gv-ai-translate'); ?></label></td></tr>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function migrate_legacy_options() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $new = get_option(self::OPT, false);
        if ($new === false || $new === array()) {
            $legacy = get_option('gvait_options', false);
            if (is_array($legacy) && !empty($legacy)) {
                // Copy legacy options into new key without deleting legacy key
                update_option(self::OPT, $legacy);
                // refresh $this->options in memory
                $this->options = wp_parse_args($legacy, $this->get_options());
            }
        }
    }

    public function enqueue_assets() {
        wp_enqueue_style('gvait-frontend', plugins_url('assets/css/frontend.css', __FILE__), array(), self::VERSION);
        wp_enqueue_script('gvait-frontend', plugins_url('assets/js/frontend.js', __FILE__), array(), self::VERSION, true);
        wp_add_inline_script('gvait-frontend', 'window.GVAIT_FRONTEND = ' . wp_json_encode(array(
            'param' => self::PARAM,
            'cookie' => self::COOKIE,
            'paramLegacy' => 'gv_lang',
            'cookieLegacy' => 'gvait_lang',
            'currentLang' => $this->current_lang,
            'defaultLang' => $this->default_lang,
            'languages' => $this->languages(),
        )) . ';', 'before');

        if (!is_admin() && $this->current_lang !== $this->default_lang && $this->options['auto_translate'] === '1') {
            wp_enqueue_script('gvait-ajax-translate', plugins_url('assets/js/translate.js', __FILE__), array(), self::VERSION, true);
            wp_add_inline_script('gvait-ajax-translate', 'window.GVAIT_TRANSLATE = ' . wp_json_encode(array(
                'enabled' => true,
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('traduttore_translate_texts'),
                'nonceLegacy' => wp_create_nonce('gvait_translate_texts'),
                'ajaxAction' => 'traduttore_translate_texts',
                'ajaxActionLegacy' => 'gvait_translate_texts',
                'lang' => $this->current_lang,
                'defaultLang' => $this->default_lang,
                'maxNodes' => intval($this->options['max_text_nodes']),
                'minChars' => intval($this->options['min_chars']),
                'maxTextLength' => self::MAX_TEXT_LENGTH,
            )) . ';', 'before');
        }
    }

    private function languages() {
        $langs = array_filter(array_map('trim', explode(',', $this->options['languages'])));
        if (!in_array($this->default_lang, $langs, true)) {
            array_unshift($langs, $this->default_lang);
        }
        return array_values(array_unique($langs));
    }

    private function detect_lang() {
        $langs = $this->languages();

        // Fonte unica ufficiale: ?gv_lang=xx. Accettiamo solo questo parametro.
        // I vecchi parametri lang/gt_lang/googtrans vengono rimossi in maybe_redirect_clean_url().
        // Accept both new and legacy query parameter for upgrades.
        if (isset($_GET[self::PARAM]) || isset($_GET['gv_lang'])) {
            $rawLang = isset($_GET[self::PARAM]) ? wp_unslash($_GET[self::PARAM]) : wp_unslash($_GET['gv_lang']);
            $lang = strtolower(sanitize_key($rawLang));
            if (in_array($lang, $langs, true)) {
                $this->set_language_cookie($lang);
                return $lang;
            }
        }

        // Support legacy cookie name as well for existing installs
        $cookie_lang = '';
        if (!empty($_COOKIE[self::COOKIE])) {
            $cookie_lang = wp_unslash($_COOKIE[self::COOKIE]);
        } elseif (!empty($_COOKIE['gvait_lang'])) {
            $cookie_lang = wp_unslash($_COOKIE['gvait_lang']);
        }

        if ($cookie_lang !== '') {
            $lang = strtolower(sanitize_key($cookie_lang));
            if (in_array($lang, $langs, true)) {
                if ($lang === $this->default_lang) {
                    $this->set_language_cookie($lang);
                }
                return $lang;
            }

            $this->set_language_cookie($this->default_lang);
        }

        return $this->default_lang;
    }

    private function set_language_cookie($lang) {
        if (headers_sent()) {
            return;
        }

        $path = defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';
        $domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';

        if ($lang === $this->default_lang) {
            setcookie(self::COOKIE, '', time() - YEAR_IN_SECONDS, $path, $domain, is_ssl(), true);
            unset($_COOKIE[self::COOKIE]);
            return;
        }

        setcookie(self::COOKIE, $lang, time() + YEAR_IN_SECONDS, $path, $domain, is_ssl(), true);
        $_COOKIE[self::COOKIE] = $lang;
    }

    public function maybe_disable_page_cache() {
        if (is_admin() || wp_doing_ajax()) {
            return;
        }

        // Disable page caching when language param/cookie is present (support legacy names too)
        $has_param = isset($_GET[self::PARAM]) || isset($_GET['gv_lang']);
        $has_cookie = !empty($_COOKIE[self::COOKIE]) || !empty($_COOKIE['gvait_lang']);

        if ($has_param || $has_cookie) {
            if (!defined('DONOTCACHEPAGE')) {
                define('DONOTCACHEPAGE', true);
            }
            if (!defined('DONOTCACHEOBJECT')) {
                define('DONOTCACHEOBJECT', true);
            }
            if (!defined('DONOTCACHEDB')) {
                define('DONOTCACHEDB', true);
            }
        }
    }

    public function maybe_redirect_clean_url() {
        if (is_admin() || wp_doing_ajax()) {
            return;
        }
        // Only attempt cleanup if a language param (new or legacy) is present
        if (!isset($_GET[self::PARAM]) && !isset($_GET['gv_lang'])) {
            return;
        }

        $dirty = false;
        foreach (array('lang','gt_lang','googtrans') as $p) {
            if (isset($_GET[$p])) {
                $dirty = true;
            }
        }
        if (!$dirty) {
            return;
        }

        $url = remove_query_arg(array('lang','gt_lang','googtrans'));
        wp_safe_redirect($url, 301);
        exit;
    }

    public function shortcode() {
        $langs = $this->languages();
        $style_class = sanitize_html_class($this->options['selector_style']);
        $classes = 'gvait-selector traduttore-selector gvait-' . $style_class . ' traduttore-' . $style_class;

        ob_start();
        echo '<div class="' . esc_attr($classes) . '" translate="no" data-gvait-no-translate="1" data-traduttore-no-translate="1">';

        if ($this->options['selector_style'] === 'buttons') {
            foreach ($langs as $lang) {
                $active = ($lang === $this->current_lang) ? ' active' : '';
                echo '<a class="gvait-lang traduttore-lang' . esc_attr($active) . '" href="' . esc_url($this->clean_lang_url($lang)) . '">';
                echo $this->flag_markup($lang);
                echo '<span class="gvait-lang-code traduttore-lang-code">' . esc_html(strtoupper($lang)) . '</span>';
                echo '</a>';
            }
        } else {
            echo '<details class="gvait-dropdown">';
            echo '<summary class="gvait-trigger">';
            echo $this->flag_markup($this->current_lang);
            echo '<span class="gvait-current">' . esc_html($this->lang_label($this->current_lang)) . '</span>';
            echo '<span class="gvait-chevron" aria-hidden="true"></span>';
            echo '</summary>';
            echo '<div class="gvait-menu" role="list">';
            foreach ($langs as $lang) {
                $active = ($lang === $this->current_lang) ? ' active' : '';
                echo '<a class="gvait-option traduttore-option' . esc_attr($active) . '" href="' . esc_url($this->clean_lang_url($lang)) . '">';
                echo $this->flag_markup($lang);
                echo '<span class="gvait-option-label traduttore-option-label">' . esc_html($this->lang_label($lang)) . '</span>';
                echo '<span class="gvait-option-code traduttore-option-code">' . esc_html(strtoupper($lang)) . '</span>';
                echo '</a>';
            }
            echo '</div>';
            echo '</details>';
        }

        echo '</div>';
        return ob_get_clean();
    }

    public function floating_selector() {
        if ($this->options['floating'] !== '1') {
            return;
        }
        echo '<div class="gvait-floating gvait-' . esc_attr($this->options['floating_position']) . '" translate="no" data-gvait-no-translate="1">';
        echo $this->shortcode();
        echo '</div>';
    }

    private function clean_lang_url($lang) {
        $url = remove_query_arg(array('lang','gt_lang','googtrans'));
        return add_query_arg(self::PARAM, $lang, $url);
    }

    private function flag_markup($lang) {

        $map = array(
            'it' => 'it',
            'en' => 'gb',
        );

        $flag = isset($map[$lang]) ? $map[$lang] : 'it';

        $src = plugins_url(
            'assets/flags/' . $flag . '.svg',
            __FILE__
        );

        return '<img class="gvait-flag" src="' . esc_url($src) . '" alt="' . esc_attr($lang) . '">';
    }

    private function lang_label($lang) {
        $map = array(
            'it' => 'Italiano',
            'en' => 'English',
            'fr' => 'Francais',
            'es' => 'Espanol',
            'de' => 'Deutsch',
            'pt' => 'Portugues',
        );
        return isset($map[$lang]) ? $map[$lang] : strtoupper($lang);
    }

    public function ajax_translate_texts() {
        // Accept both new and legacy nonces for backward compatibility
        $ok = check_ajax_referer('traduttore_translate_texts', 'nonce', false) || check_ajax_referer('gvait_translate_texts', 'nonce', false);
        if (!$ok) {
            wp_send_json_error(array('message' => __('Invalid nonce.', 'gv-ai-translate')), 403);
        }

        $lang = isset($_POST['lang']) ? strtolower(sanitize_key(wp_unslash($_POST['lang']))) : '';
        if (!$lang || $lang === $this->default_lang || !in_array($lang, $this->languages(), true)) {
            wp_send_json_error(array('message' => __('Invalid language.', 'gv-ai-translate')), 400);
        }

        $raw = isset($_POST['texts']) ? wp_unslash($_POST['texts']) : '[]';
        $texts = json_decode($raw, true);
        if (!is_array($texts)) {
            wp_send_json_error(array('message' => __('Invalid payload.', 'gv-ai-translate')), 400);
        }

        $max = intval($this->options['max_text_nodes']);
        $clean = array();
        foreach ($texts as $t) {
            $t = trim(preg_replace('/\s+/u', ' ', sanitize_text_field((string) $t)));
            if ($t !== '' && $this->text_length($t) <= self::MAX_TEXT_LENGTH) {
                $clean[] = $t;
            }
            if (count($clean) >= $max) {
                break;
            }
        }

        if (empty($clean)) {
            wp_send_json_success(array('translations' => array()));
        }

        $hash = md5($lang . '|' . wp_json_encode($clean));
        $cache_key_new = 'traduttore_ajax_' . $hash;
        $cache_key_old = 'gvait_ajax_' . $hash;
        $cached = get_transient($cache_key_new);
        if ($cached === false) {
            $cached = get_transient($cache_key_old);
        }
        if (is_array($cached)) {
            wp_send_json_success(array('translations' => $cached, 'cached' => true));
        }

        $translations = $this->translate_array_with_fallback($clean, $lang);
        if (!is_array($translations)) {
            wp_send_json_error(array('message' => __('Translation failed.', 'gv-ai-translate')), 500);
        }

        $ttl = ($this->options['cache_days'] === 'forever') ? 10 * YEAR_IN_SECONDS : max(1, intval($this->options['cache_days'])) * DAY_IN_SECONDS;
        set_transient($cache_key_new, $translations, $ttl);
        // also set legacy key for upgrades
        set_transient($cache_key_old, $translations, $ttl);

        wp_send_json_success(array('translations' => $translations, 'cached' => false));
    }

    private function text_length($text) {
        $text = (string) $text;
        if (function_exists('mb_strlen')) {
            return mb_strlen($text, 'UTF-8');
        }

        return strlen($text);
    }

    private function translate_array_with_fallback($texts, $target_lang) {
        $providers = array_filter(array_map('trim', explode(',', $this->options['provider_order'])));

        foreach ($providers as $provider) {
            $arr = false;
            if ($provider === 'groq') {
                $arr = $this->call_openai_compatible_provider('groq', $texts, $target_lang);
            } elseif ($provider === 'openai') {
                $arr = $this->call_openai_compatible_provider('openai', $texts, $target_lang);
            } elseif ($provider === 'anthropic') {
                $arr = $this->call_anthropic_provider($texts, $target_lang);
            } elseif ($provider === 'google') {
                $arr = $this->call_google_provider($texts, $target_lang);
            } elseif ($provider === 'google_free') {
                $arr = $this->call_google_free_provider($texts, $target_lang);
            }

            if (is_array($arr) && count($arr) === count($texts)) {
                return $arr;
            }
        }

        return false;
    }

    private function translation_messages($texts, $target_lang) {
        $target_name = $this->lang_label($target_lang);
        $source_name = $this->lang_label($this->default_lang);
        $system = 'You are a professional website translator. Translate only human-visible text. Preserve meaning, tone, punctuation, numbers, brand names, URLs, emails, emojis and short labels. Return ONLY valid JSON array with exactly the same number of strings. No markdown.';
        $user = "Translate from {$source_name} to {$target_name}. Return a JSON array. Input JSON array:\n" . wp_json_encode(array_values($texts), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return array($system, $user);
    }

    private function parse_json_array_response($content) {
        $content = trim((string) $content);
        if ($content === '') {
            return false;
        }

        $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
        $content = preg_replace('/\s*```$/', '', $content);

        $arr = json_decode($content, true);
        if (!is_array($arr) && preg_match('/\[[\s\S]*\]/', $content, $m)) {
            $arr = json_decode($m[0], true);
        }

        if (!is_array($arr)) {
            return false;
        }

        return array_values(array_map('strval', $arr));
    }

    private function call_openai_compatible_provider($provider, $texts, $target_lang) {
        list($system, $user) = $this->translation_messages($texts, $target_lang);

        if ($provider === 'groq') {
            $key = trim($this->options['groq_api_key']);
            $model = $this->options['groq_model'];
            $url = 'https://api.groq.com/openai/v1/chat/completions';
        } else {
            $key = trim($this->options['openai_api_key']);
            $model = $this->options['openai_model'];
            $url = 'https://api.openai.com/v1/chat/completions';
        }

        if ($key === '') {
            return false;
        }

        $res = wp_remote_post($url, array(
            'timeout' => 45,
            'headers' => array(
                'Authorization' => 'Bearer ' . $key,
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode(array(
                'model' => $model,
                'temperature' => 0.1,
                'messages' => array(
                    array('role' => 'system', 'content' => $system),
                    array('role' => 'user', 'content' => $user),
                ),
            )),
        ));

        if (is_wp_error($res)) {
            return false;
        }

        $code = wp_remote_retrieve_response_code($res);
        if ($code < 200 || $code >= 300) {
            return false;
        }

        $json = json_decode(wp_remote_retrieve_body($res), true);
        $content = isset($json['choices'][0]['message']['content']) ? $json['choices'][0]['message']['content'] : '';
        return $this->parse_json_array_response($content);
    }

    private function call_anthropic_provider($texts, $target_lang) {
        $key = trim($this->options['anthropic_api_key']);
        if ($key === '') {
            return false;
        }

        list($system, $user) = $this->translation_messages($texts, $target_lang);

        $res = wp_remote_post('https://api.anthropic.com/v1/messages', array(
            'timeout' => 45,
            'headers' => array(
                'x-api-key' => $key,
                'anthropic-version' => '2023-06-01',
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode(array(
                'model' => $this->options['anthropic_model'],
                'system' => $system,
                'max_tokens' => 4096,
                'temperature' => 0.1,
                'messages' => array(
                    array('role' => 'user', 'content' => $user),
                ),
            )),
        ));

        if (is_wp_error($res)) {
            return false;
        }

        $code = wp_remote_retrieve_response_code($res);
        if ($code < 200 || $code >= 300) {
            return false;
        }

        $json = json_decode(wp_remote_retrieve_body($res), true);
        $content = isset($json['content'][0]['text']) ? $json['content'][0]['text'] : '';
        return $this->parse_json_array_response($content);
    }

    private function call_google_provider($texts, $target_lang) {
        $key = trim($this->options['google_api_key']);
        if ($key === '') {
            return false;
        }

        list($system, $user) = $this->translation_messages($texts, $target_lang);

        $model = $this->options['google_model'];
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($key);

        $res = wp_remote_post($url, array(
            'timeout' => 45,
            'headers' => array('Content-Type' => 'application/json'),
            'body' => wp_json_encode(array(
                'contents' => array(array(
                    'parts' => array(array(
                        'text' => $system . "\n\n" . $user,
                    )),
                )),
                'generationConfig' => array('temperature' => 0.1),
            )),
        ));

        if (is_wp_error($res)) {
            return false;
        }

        $code = wp_remote_retrieve_response_code($res);
        if ($code < 200 || $code >= 300) {
            return false;
        }

        $json = json_decode(wp_remote_retrieve_body($res), true);
        $content = isset($json['candidates'][0]['content']['parts'][0]['text']) ? $json['candidates'][0]['content']['parts'][0]['text'] : '';
        return $this->parse_json_array_response($content);
    }

    private function call_google_free_provider($texts, $target_lang) {
        $out = array();
        $source = $this->default_lang ? $this->default_lang : 'auto';

        foreach ($texts as $text) {
            $text = (string) $text;
            if (trim($text) === '') {
                $out[] = $text;
                continue;
            }

            $url = add_query_arg(array(
                'client' => 'gtx',
                'sl' => $source,
                'tl' => $target_lang,
                'dt' => 't',
                'q' => $text,
            ), 'https://translate.googleapis.com/translate_a/single');

            $res = wp_remote_get($url, array(
                'timeout' => 20,
                'headers' => array(
                    'User-Agent' => 'Mozilla/5.0 WordPress GV-AI-Translate/' . self::VERSION,
                ),
            ));

            if (is_wp_error($res)) {
                return false;
            }

            $code = wp_remote_retrieve_response_code($res);
            if ($code < 200 || $code >= 300) {
                return false;
            }

            $json = json_decode(wp_remote_retrieve_body($res), true);
            if (!is_array($json) || !isset($json[0]) || !is_array($json[0])) {
                return false;
            }

            $translated = '';
            foreach ($json[0] as $chunk) {
                if (isset($chunk[0])) {
                    $translated .= (string) $chunk[0];
                }
            }

            $out[] = $translated !== '' ? $translated : $text;
        }

        return $out;
    }

}

GV_AI_Translate::instance();
