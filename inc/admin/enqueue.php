<?php
/*
 * PostPress AI — Admin Enqueue
 *
 * ========= CHANGE LOG =========
 * 2026-01-03 • FIX: Guard ppa-admin-editor.js enqueue when file missing; adjust deps so no 404 + no breakage. // CHANGED:
 *
 * 2026-01-01 • HARDEN: Sanitize page param with wp_unslash() before sanitize_key() (consistent + safer).        // CHANGED:
 * 2026-01-01 • HARDEN: purge_by_rel() style branch now respects $needle (prevents over-purging our CSS).        // CHANGED:
 *
 * 2026-01-01 • HARDEN: Gate ALL Testbed enqueues behind PPA_ENABLE_TESTBED === true.                           // CHANGED:
 *              Direct URL hits to Testbed while disabled enqueue nothing (keeps logs clean).                   // CHANGED:
 * 2026-01-01 • FIX: Enqueue Testbed-only CSS when present (admin-testbed.css). Keeps per-screen CSS modular.   # CHANGED:
 * 2026-01-01 • KEEP: Composer loads only admin-composer.css when present (no admin.css on Composer).          # CHANGED:
 * 2026-01-01 • KEEP: Settings remains CSS-only and enforces “no admin.css / no JS”.                           # CHANGED:
 *
 * 2025-12-28 • HARDEN: Settings screen JS isolation is now “airtight” by arming a late admin_print_scripts purge.      # CHANGED:
 *              This guarantees Settings is truly CSS-only (no PostPress AI JS can sneak in).                           # CHANGED:
 * 2025-12-28 • HARDEN: Settings screen CSS isolation is now “airtight” by arming a late admin_print_styles purge.
 *              This guarantees Settings prints ONLY admin-settings.css even if another callback enqueues admin.css later. # CHANGED:
 * 2025-12-28 • CLEAN: Do not enqueue window.PPA / ppa-admin-config on Settings screen (CSS-only page).              # CHANGED:
 *
 * 2025-12-27 • FIX: Detect Settings screen reliably and enqueue admin-settings.css there.                  # CHANGED:
 * 2025-12-27 • FIX: Enforce UNBREAKABLE RULE — Settings loads ONLY admin-settings.css (NOT admin.css).    # CHANGED:
 * 2025-12-27 • FIX: Expand page detection to include Settings + normalized Testbed slug.                  # CHANGED:
 *
 * 2025-12-10.2 • Enqueue ppa-admin-editor.js (editor helpers) between core and admin.js; no behavior change.      # CHANGED:
 * 2025-12-20.2 • Register modular admin modules (ppa-admin-*.js) and enqueue only when non-empty; keep admin.js as boot. # CHANGED:
 * 2025-12-09.1 • Enqueue ppa-admin-core.js before admin.js so shared helpers live in PPAAdmin.core; no behavior change. # CHANGED:
 * 2025-11-22 • Enqueue admin-preview-spinner.js on Composer screen after admin.js for preview/generate spinner.  # CHANGED:
 * 2025-11-13 • Force https scheme for admin/testbed asset URLs to avoid 301s/mixed content.                      # CHANGED:
 * 2025-11-11 • Map $hook → screen id fallback to improve reliability under WP-CLI and edge admin contexts.      # CHANGED:
 * 2025-11-10 • CLI-safe: avoid get_current_screen() under WP-CLI to prevent fatals during wp eval.             # CHANGED:
 * 2025-11-10 • Aggressive purge: scan registry and dequeue/deregister ANY handle whose src matches our assets. # CHANGED:
 * 2025-11-10 • FORCE fresh versions: purge then re-register so filemtime ?ver wins.                             # CHANGED:
 * 2025-11-10 • ACCEPT $hook param for admin_enqueue_scripts compatibility.                                      # (prev)
 * 2025-11-10 • ADD wpNonce to window.PPA/ppaAdmin; cache-bust ppa-testbed.js by filemtime.                      # (prev)
 * 2025-11-08 • Cache-busted admin.css/js via filemtime; expose cssVer/jsVer on window.PPA; depend admin.js on config. # (prev)
 */

defined('ABSPATH') || exit;

if (!function_exists('ppa_admin_enqueue')) {
    function ppa_admin_enqueue($hook = '') {                                                                          // (kept)
        // ---------------------------------------------------------------------
        // Resolve plugin paths/URLs once
        // ---------------------------------------------------------------------
        $plugin_root_dir  = dirname(dirname(__DIR__));  // .../wp-content/plugins/postpress-ai
        $plugin_main_file = $plugin_root_dir . '/postpress-ai.php';

        // Asset rel paths
        $admin_core_js_rel           = 'assets/js/ppa-admin-core.js';                                                 // CHANGED:
        $admin_editor_js_rel         = 'assets/js/ppa-admin-editor.js';                                               // CHANGED:
        $admin_api_js_rel            = 'assets/js/ppa-admin-api.js';                                                  // CHANGED:
        $admin_payloads_js_rel       = 'assets/js/ppa-admin-payloads.js';                                             // CHANGED:
        $admin_notices_js_rel        = 'assets/js/ppa-admin-notices.js';                                              // CHANGED:
        $admin_generate_view_js_rel  = 'assets/js/ppa-admin-generate-view.js';                                        // CHANGED:
        $admin_comp_preview_js_rel   = 'assets/js/ppa-admin-composer-preview.js';                                     // CHANGED:
        $admin_comp_generate_js_rel  = 'assets/js/ppa-admin-composer-generate.js';                                    // CHANGED:
        $admin_comp_store_js_rel     = 'assets/js/ppa-admin-composer-store.js';                                       // CHANGED:
        $admin_js_rel                = 'assets/js/admin.js';
        $admin_css_rel               = 'assets/css/admin.css';
        $admin_composer_css_rel      = 'assets/css/admin-composer.css';                                               // CHANGED:
        $admin_testbed_css_rel       = 'assets/css/admin-testbed.css';                                                // CHANGED:
        $admin_settings_css_rel      = 'assets/css/admin-settings.css';                                               // CHANGED:
        $testbed_js_rel              = 'inc/admin/ppa-testbed.js';
        $admin_spinner_rel           = 'assets/js/admin-preview-spinner.js';                                          // CHANGED:

        // Asset files (for filemtime)
        $admin_core_js_file          = $plugin_root_dir . '/' . $admin_core_js_rel;                                   // CHANGED:
        $admin_editor_js_file        = $plugin_root_dir . '/' . $admin_editor_js_rel;                                 // CHANGED:
        $admin_api_js_file           = $plugin_root_dir . '/' . $admin_api_js_rel;                                    // CHANGED:
        $admin_payloads_js_file      = $plugin_root_dir . '/' . $admin_payloads_js_rel;                               // CHANGED:
        $admin_notices_js_file       = $plugin_root_dir . '/' . $admin_notices_js_rel;                                // CHANGED:
        $admin_generate_view_js_file = $plugin_root_dir . '/' . $admin_generate_view_js_rel;                          // CHANGED:
        $admin_comp_preview_js_file  = $plugin_root_dir . '/' . $admin_comp_preview_js_rel;                           // CHANGED:
        $admin_comp_generate_js_file = $plugin_root_dir . '/' . $admin_comp_generate_js_rel;                          // CHANGED:
        $admin_comp_store_js_file    = $plugin_root_dir . '/' . $admin_comp_store_js_rel;                             // CHANGED:
        $admin_js_file               = $plugin_root_dir . '/' . $admin_js_rel;
        $admin_css_file              = $plugin_root_dir . '/' . $admin_css_rel;
        $admin_composer_css_file     = $plugin_root_dir . '/' . $admin_composer_css_rel;                              // CHANGED:
        $admin_testbed_css_file      = $plugin_root_dir . '/' . $admin_testbed_css_rel;                               // CHANGED:
        $admin_settings_css_file     = $plugin_root_dir . '/' . $admin_settings_css_rel;                              // CHANGED:
        $testbed_js_file             = $plugin_root_dir . '/' . $testbed_js_rel;
        $admin_spinner_file          = $plugin_root_dir . '/' . $admin_spinner_rel;                                   // CHANGED:

        // Asset URLs (prefer PPA_PLUGIN_URL if defined)
        if (defined('PPA_PLUGIN_URL')) {
            $base_url                   = rtrim(PPA_PLUGIN_URL, '/');
            $admin_core_js_url          = $base_url . '/' . $admin_core_js_rel;                                       // CHANGED:
            $admin_editor_js_url        = $base_url . '/' . $admin_editor_js_rel;                                     // CHANGED:
            $admin_api_js_url           = $base_url . '/' . $admin_api_js_rel;                                        // CHANGED:
            $admin_payloads_js_url      = $base_url . '/' . $admin_payloads_js_rel;                                   // CHANGED:
            $admin_notices_js_url       = $base_url . '/' . $admin_notices_js_rel;                                    // CHANGED:
            $admin_generate_view_js_url = $base_url . '/' . $admin_generate_view_js_rel;                              // CHANGED:
            $admin_comp_preview_js_url  = $base_url . '/' . $admin_comp_preview_js_rel;                               // CHANGED:
            $admin_comp_generate_js_url = $base_url . '/' . $admin_comp_generate_js_rel;                              // CHANGED:
            $admin_comp_store_js_url    = $base_url . '/' . $admin_comp_store_js_rel;                                 // CHANGED:
            $admin_js_url               = $base_url . '/' . $admin_js_rel;
            $admin_css_url              = $base_url . '/' . $admin_css_rel;
            $admin_composer_css_url     = $base_url . '/' . $admin_composer_css_rel;                                  // CHANGED:
            $admin_testbed_css_url      = $base_url . '/' . $admin_testbed_css_rel;                                   // CHANGED:
            $admin_settings_css_url     = $base_url . '/' . $admin_settings_css_rel;                                  // CHANGED:
            $testbed_js_url             = $base_url . '/' . $testbed_js_rel;
            $admin_spinner_url          = $base_url . '/' . $admin_spinner_rel;                                       // CHANGED:
        } else {
            $admin_core_js_url          = plugins_url($admin_core_js_rel,          $plugin_main_file);                // CHANGED:
            $admin_editor_js_url        = plugins_url($admin_editor_js_rel,        $plugin_main_file);                // CHANGED:
            $admin_api_js_url           = plugins_url($admin_api_js_rel,           $plugin_main_file);                // CHANGED:
            $admin_payloads_js_url      = plugins_url($admin_payloads_js_rel,      $plugin_main_file);                // CHANGED:
            $admin_notices_js_url       = plugins_url($admin_notices_js_rel,       $plugin_main_file);                // CHANGED:
            $admin_generate_view_js_url = plugins_url($admin_generate_view_js_rel, $plugin_main_file);                // CHANGED:
            $admin_comp_preview_js_url  = plugins_url($admin_comp_preview_js_rel,  $plugin_main_file);                // CHANGED:
            $admin_comp_generate_js_url = plugins_url($admin_comp_generate_js_rel, $plugin_main_file);                // CHANGED:
            $admin_comp_store_js_url    = plugins_url($admin_comp_store_js_rel,    $plugin_main_file);                // CHANGED:
            $admin_js_url               = plugins_url($admin_js_rel,               $plugin_main_file);
            $admin_css_url              = plugins_url($admin_css_rel,              $plugin_main_file);
            $admin_composer_css_url     = plugins_url($admin_composer_css_rel,     $plugin_main_file);                // CHANGED:
            $admin_testbed_css_url      = plugins_url($admin_testbed_css_rel,      $plugin_main_file);                // CHANGED:
            $admin_settings_css_url     = plugins_url($admin_settings_css_rel,     $plugin_main_file);                // CHANGED:
            $testbed_js_url             = plugins_url($testbed_js_rel,             $plugin_main_file);
            $admin_spinner_url          = plugins_url($admin_spinner_rel,          $plugin_main_file);                // CHANGED:
        }

        // Force HTTPS scheme for all admin/testbed asset URLs                                                     // CHANGED:
        if (function_exists('set_url_scheme')) {                                                                    // CHANGED:
            $admin_core_js_url          = set_url_scheme($admin_core_js_url, 'https');                              // CHANGED:
            $admin_editor_js_url        = set_url_scheme($admin_editor_js_url, 'https');                            // CHANGED:
            $admin_api_js_url           = set_url_scheme($admin_api_js_url, 'https');                               // CHANGED:
            $admin_payloads_js_url      = set_url_scheme($admin_payloads_js_url, 'https');                          // CHANGED:
            $admin_notices_js_url       = set_url_scheme($admin_notices_js_url, 'https');                           // CHANGED:
            $admin_generate_view_js_url = set_url_scheme($admin_generate_view_js_url, 'https');                     // CHANGED:
            $admin_comp_preview_js_url  = set_url_scheme($admin_comp_preview_js_url, 'https');                      // CHANGED:
            $admin_comp_generate_js_url = set_url_scheme($admin_comp_generate_js_url, 'https');                     // CHANGED:
            $admin_comp_store_js_url    = set_url_scheme($admin_comp_store_js_url, 'https');                        // CHANGED:
            $admin_js_url               = set_url_scheme($admin_js_url, 'https');                                   // CHANGED:
            $admin_css_url              = set_url_scheme($admin_css_url, 'https');                                  // CHANGED:
            $admin_composer_css_url     = set_url_scheme($admin_composer_css_url, 'https');                         // CHANGED:
            $admin_testbed_css_url      = set_url_scheme($admin_testbed_css_url, 'https');                          // CHANGED:
            $admin_settings_css_url     = set_url_scheme($admin_settings_css_url, 'https');                         // CHANGED:
            $testbed_js_url             = set_url_scheme($testbed_js_url, 'https');                                 // CHANGED:
            $admin_spinner_url          = set_url_scheme($admin_spinner_url, 'https');                              // CHANGED:
        }                                                                                                           // CHANGED:

        // Versions (cache-bust by file mtime, safe fallback to time())
        $admin_core_js_ver          = file_exists($admin_core_js_file)          ? (string) filemtime($admin_core_js_file)          : (string) time(); // CHANGED:
        $admin_editor_js_ver        = file_exists($admin_editor_js_file)        ? (string) filemtime($admin_editor_js_file)        : (string) time(); // CHANGED:
        $admin_api_js_ver           = file_exists($admin_api_js_file)           ? (string) filemtime($admin_api_js_file)           : (string) time(); // CHANGED:
        $admin_payloads_js_ver      = file_exists($admin_payloads_js_file)      ? (string) filemtime($admin_payloads_js_file)      : (string) time(); // CHANGED:
        $admin_notices_js_ver       = file_exists($admin_notices_js_file)       ? (string) filemtime($admin_notices_js_file)       : (string) time(); // CHANGED:
        $admin_generate_view_js_ver = file_exists($admin_generate_view_js_file) ? (string) filemtime($admin_generate_view_js_file) : (string) time(); // CHANGED:
        $admin_comp_preview_js_ver  = file_exists($admin_comp_preview_js_file)  ? (string) filemtime($admin_comp_preview_js_file)  : (string) time(); // CHANGED:
        $admin_comp_generate_js_ver = file_exists($admin_comp_generate_js_file) ? (string) filemtime($admin_comp_generate_js_file) : (string) time(); // CHANGED:
        $admin_comp_store_js_ver    = file_exists($admin_comp_store_js_file)    ? (string) filemtime($admin_comp_store_js_file)    : (string) time(); // CHANGED:
        $admin_js_ver               = file_exists($admin_js_file)               ? (string) filemtime($admin_js_file)               : (string) time();
        $admin_css_ver              = file_exists($admin_css_file)              ? (string) filemtime($admin_css_file)              : (string) time();
        $admin_composer_css_ver     = file_exists($admin_composer_css_file)     ? (string) filemtime($admin_composer_css_file)     : (string) time(); // CHANGED:
        $admin_testbed_css_ver      = file_exists($admin_testbed_css_file)      ? (string) filemtime($admin_testbed_css_file)      : (string) time(); // CHANGED:
        $admin_settings_css_ver     = file_exists($admin_settings_css_file)     ? (string) filemtime($admin_settings_css_file)     : (string) time(); // CHANGED:
        $testbed_js_ver             = file_exists($testbed_js_file)             ? (string) filemtime($testbed_js_file)             : (string) time();
        $admin_spinner_ver          = file_exists($admin_spinner_file)          ? (string) filemtime($admin_spinner_file)          : (string) time(); // CHANGED:

        // ---------------------------------------------------------------------
        // Determine whether this is our admin page
        // ---------------------------------------------------------------------
        $is_cli    = defined('WP_CLI') && WP_CLI;                                                                    // (kept)
        $screen    = null;
        $screen_id = '';
        if (!$is_cli && function_exists('get_current_screen')) {                                                     // (kept)
            try {
                $screen = get_current_screen();
            } catch (Throwable $e) {
                $screen = null;
            }                                                                                                       // (kept)
            $screen_id = $screen ? $screen->id : '';
        }
        $page_param = isset($_GET['page']) ? sanitize_key(wp_unslash((string) $_GET['page'])) : '';                  // CHANGED:

        // Screen IDs
        $slug_main_ui  = 'toplevel_page_postpress-ai';                      // Composer (top-level)
        $slug_settings = 'postpress-ai_page_postpress-ai-settings';         // Settings submenu              # CHANGED:
        $slug_testbed  = 'postpress-ai_page_postpress-ai-testbed';          // Testbed submenu (normalized) # CHANGED:

        // Fallback logic: $hook param is reliable, $screen_id can be empty under edge contexts
        $is_main_ui  = ($hook === $slug_main_ui)  || ($screen_id === $slug_main_ui)  || ($page_param === 'postpress-ai');
        $is_settings = ($hook === $slug_settings) || ($screen_id === $slug_settings) || ($page_param === 'postpress-ai-settings');     // CHANGED:
        $is_testbed  = ($hook === $slug_testbed)  || ($screen_id === $slug_testbed)  || ($page_param === 'postpress-ai-testbed');      // CHANGED:

        // Gate everything to just our pages (prevents WP-admin collateral)
        if (!($is_main_ui || $is_settings || $is_testbed)) {                                                         // CHANGED:
            return;                                                                                                  // CHANGED:
        }                                                                                                            // CHANGED:

        // DEV-only: Testbed is disabled unless explicitly enabled.                                                  // CHANGED:
        $testbed_enabled = (defined('PPA_ENABLE_TESTBED') && true === PPA_ENABLE_TESTBED);                           // CHANGED:

        // If someone hits the Testbed URL while disabled, enqueue NOTHING (stay quiet).                             // CHANGED:
        if ($is_testbed && !$testbed_enabled) {                                                                      // CHANGED:
            return;                                                                                                  // CHANGED:
        }                                                                                                            // CHANGED:

        // ---------------------------------------------------------------------
        // SETTINGS PAGE: enforce CSS-only + airtight “no admin.css” rule
        // ---------------------------------------------------------------------
        if ($is_settings) {                                                                                          // CHANGED:
            $rel_path = 'postpress-ai/';                                                                              // CHANGED:

            // Purge any PostPress AI styles EXCEPT admin-settings.css (run now + run late).                          // CHANGED:
            $purge_ppai_styles_except_settings = function() use ($rel_path, $admin_settings_css_rel) {                // CHANGED:
                $st = function_exists('wp_styles') ? wp_styles() : null;                                             // CHANGED:
                if (!$st || empty($st->registered)) {                                                                 // CHANGED:
                    return;                                                                                           // CHANGED:
                }                                                                                                     // CHANGED:
                foreach ($st->registered as $h => $dep) {                                                             // CHANGED:
                    $src = isset($dep->src) ? (string) $dep->src : '';                                                // CHANGED:
                    if (!$src) {                                                                                      // CHANGED:
                        continue;                                                                                     // CHANGED:
                    }                                                                                                 // CHANGED:
                    if (strpos($src, $rel_path) === false) {                                                          // CHANGED:
                        continue;                                                                                     // CHANGED:
                    }                                                                                                 // CHANGED:
                    // Keep ONLY admin-settings.css on Settings page.                                                 // CHANGED:
                    if (strpos($src, $admin_settings_css_rel) !== false) {                                            // CHANGED:
                        continue;                                                                                     // CHANGED:
                    }                                                                                                 // CHANGED:
                    if (wp_style_is($h, 'enqueued')) {                                                                // CHANGED:
                        wp_dequeue_style($h);                                                                         // CHANGED:
                    }                                                                                                 // CHANGED:
                    if (wp_style_is($h, 'registered')) {                                                              // CHANGED:
                        wp_deregister_style($h);                                                                      // CHANGED:
                    }                                                                                                 // CHANGED:
                }                                                                                                     // CHANGED:
            };                                                                                                        // CHANGED:

            // Purge ANY PostPress AI scripts on Settings page (CSS-only page).                                       // CHANGED:
            $purge_ppai_scripts = function() use ($rel_path) {                                                        // CHANGED:
                $sc = function_exists('wp_scripts') ? wp_scripts() : null;                                            // CHANGED:
                if (!$sc || empty($sc->registered)) {                                                                 // CHANGED:
                    return;                                                                                           // CHANGED:
                }                                                                                                     // CHANGED:
                foreach ($sc->registered as $h => $dep) {                                                             // CHANGED:
                    $src = isset($dep->src) ? (string) $dep->src : '';                                                // CHANGED:
                    if (!$src) {                                                                                      // CHANGED:
                        continue;                                                                                     // CHANGED:
                    }                                                                                                 // CHANGED:
                    if (strpos($src, $rel_path) === false) {                                                          // CHANGED:
                        continue;                                                                                     // CHANGED:
                    }                                                                                                 // CHANGED:
                    if (wp_script_is($h, 'enqueued')) {                                                               // CHANGED:
                        wp_dequeue_script($h);                                                                        // CHANGED:
                    }                                                                                                 // CHANGED:
                    if (wp_script_is($h, 'registered')) {                                                             // CHANGED:
                        wp_deregister_script($h);                                                                     // CHANGED:
                    }                                                                                                 // CHANGED:
                }                                                                                                     // CHANGED:
            };                                                                                                        // CHANGED:

            // Run immediately (in case something enqueued earlier).                                                  // CHANGED:
            $purge_ppai_styles_except_settings();                                                                     // CHANGED:
            $purge_ppai_scripts();                                                                                    // CHANGED:

            // Arm a late purge so if another callback enqueues admin.css/admin.js AFTER us, we still win.            // CHANGED:
            static $ppa_settings_purge_armed = false;                                                                 // CHANGED:
            if (!$ppa_settings_purge_armed) {                                                                         // CHANGED:
                $ppa_settings_purge_armed = true;                                                                     // CHANGED:
                add_action('admin_print_styles',  $purge_ppai_styles_except_settings, 999);                           // CHANGED:
                add_action('admin_print_scripts', $purge_ppai_scripts, 999);                                          // CHANGED:
            }                                                                                                         // CHANGED:

            // Enqueue ONLY settings CSS.                                                                             // CHANGED:
            wp_register_style('ppa-admin-settings-css', $admin_settings_css_url, array(), $admin_settings_css_ver, 'all'); // CHANGED:
            wp_enqueue_style('ppa-admin-settings-css');                                                               // CHANGED:
            return;                                                                                                   // CHANGED:
        }                                                                                                             // CHANGED:

        // ---------------------------------------------------------------------
        // NON-SETTINGS PAGES (Composer/Testbed): build shared config + purge + enqueue
        // ---------------------------------------------------------------------

        // Build shared config (window.PPA) — exposed before admin.js
        $effective_css_ver = $admin_css_ver;                                                                          // CHANGED:
        if ($is_main_ui && file_exists($admin_composer_css_file)) {                                                   // CHANGED:
            $effective_css_ver = $admin_composer_css_ver;                                                             // CHANGED:
        } elseif ($is_testbed && $testbed_enabled && file_exists($admin_testbed_css_file)) {                          // CHANGED:
            $effective_css_ver = $admin_testbed_css_ver;                                                              // CHANGED:
        }                                                                                                             // CHANGED:

        $cfg = array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'restUrl' => esc_url_raw(rest_url()),
            'page'    => $page_param,
            'cssVer'  => $effective_css_ver,                                                                          // CHANGED:
            'jsVer'   => $admin_js_ver,
            'wpNonce' => wp_create_nonce('wp_rest'),
        );

        wp_register_script('ppa-admin-config', false, array(), null, true);
        wp_enqueue_script('ppa-admin-config');
        wp_add_inline_script('ppa-admin-config', 'window.PPA = ' . wp_json_encode($cfg) . ';', 'before');

        // Aggressive purge: dequeue/deregister any previously registered/enqueued
        // handles whose src matches our plugin assets. Ensures our filemtime ver wins.
        $rel_path = 'postpress-ai/';

        $purge_by_rel = function($needle, $type) use ($rel_path) {                                                    // (kept)
            if ($type === 'script') {
                $sc = wp_scripts();
                if ($sc && !empty($sc->registered)) {
                    foreach ($sc->registered as $h => $dep) {
                        $src = isset($dep->src) ? (string) $dep->src : '';
                        if ($src && strpos($src, $rel_path) !== false && strpos($src, $needle) !== false) {
                            if (wp_script_is($h, 'enqueued')) {
                                wp_dequeue_script($h);
                            }
                            if (wp_script_is($h, 'registered')) {
                                wp_deregister_script($h);
                            }
                        }
                    }
                }
            } else {
                $st = wp_styles();
                if ($st && !empty($st->registered)) {
                    foreach ($st->registered as $h => $dep) {
                        $src = isset($dep->src) ? (string) $dep->src : '';
                        if ($src && strpos($src, $rel_path) !== false && strpos($src, $needle) !== false) {          // CHANGED:
                            if (wp_style_is($h, 'enqueued')) {
                                wp_dequeue_style($h);
                            }
                            if (wp_style_is($h, 'registered')) {
                                wp_deregister_style($h);
                            }
                        }
                    }
                }
            }
        };

        // Purge our known handles by rel needle
        $purge_by_rel($admin_core_js_rel,          'script');                                                        // CHANGED:
        $purge_by_rel($admin_editor_js_rel,        'script');                                                        // CHANGED:
        $purge_by_rel($admin_api_js_rel,           'script');                                                        // CHANGED:
        $purge_by_rel($admin_payloads_js_rel,      'script');                                                        // CHANGED:
        $purge_by_rel($admin_notices_js_rel,       'script');                                                        // CHANGED:
        $purge_by_rel($admin_generate_view_js_rel, 'script');                                                        // CHANGED:
        $purge_by_rel($admin_comp_preview_js_rel,  'script');                                                        // CHANGED:
        $purge_by_rel($admin_comp_generate_js_rel, 'script');                                                        // CHANGED:
        $purge_by_rel($admin_comp_store_js_rel,    'script');                                                        // CHANGED:
        $purge_by_rel($admin_js_rel,               'script');                                                        // (kept)
        $purge_by_rel($admin_css_rel,              'style');                                                         // (kept)
        $purge_by_rel($admin_composer_css_rel,     'style');                                                         // CHANGED:
        $purge_by_rel($admin_testbed_css_rel,      'style');                                                         // CHANGED:
        $purge_by_rel($admin_settings_css_rel,     'style');                                                         // CHANGED:
        $purge_by_rel($testbed_js_rel,             'script');                                                        // (kept)
        $purge_by_rel($admin_spinner_rel,          'script');                                                        // CHANGED:

        // Enqueue assets
        if ($is_main_ui || $is_testbed) {

            // CSS — per-screen first, fallback to legacy admin.css
            if ($is_main_ui && file_exists($admin_composer_css_file)) {                                              // CHANGED:
                wp_register_style('ppa-admin-composer-css', $admin_composer_css_url, array(), $admin_composer_css_ver, 'all'); // CHANGED:
                wp_enqueue_style('ppa-admin-composer-css');                                                          // CHANGED:
            } elseif ($is_testbed && $testbed_enabled && file_exists($admin_testbed_css_file)) {                      // CHANGED:
                wp_register_style('ppa-admin-testbed-css', $admin_testbed_css_url, array(), $admin_testbed_css_ver, 'all');   // CHANGED:
                wp_enqueue_style('ppa-admin-testbed-css');                                                           // CHANGED:
            } else {
                wp_register_style('ppa-admin-css', $admin_css_url, array(), $admin_css_ver, 'all');
                wp_enqueue_style('ppa-admin-css');
            }

            // JS core helpers — must load after config so window.PPA is present
            wp_register_script(
                'ppa-admin-core',
                $admin_core_js_url,
                array('jquery', 'ppa-admin-config'),
                $admin_core_js_ver,
                true
            );
            wp_enqueue_script('ppa-admin-core');

            // Modular scripts (foundation) — register always; enqueue only when non-empty.
            $active_modules = array();
            $maybe_enqueue = function($handle, $url, $deps, $ver, $file) use (&$active_modules) {
                wp_register_script($handle, $url, $deps, $ver, true);
                if ($file && file_exists($file) && filesize($file) > 0) {
                    $active_modules[] = $handle;
                    wp_enqueue_script($handle);
                }
            };
            $maybe_enqueue('ppa-admin-api',      $admin_api_js_url,      array('jquery','ppa-admin-config','ppa-admin-core'), $admin_api_js_ver,      $admin_api_js_file);
            $maybe_enqueue('ppa-admin-payloads', $admin_payloads_js_url, array('jquery','ppa-admin-config','ppa-admin-core'), $admin_payloads_js_ver, $admin_payloads_js_file);
            $maybe_enqueue('ppa-admin-notices',  $admin_notices_js_url,  array('jquery','ppa-admin-config','ppa-admin-core'), $admin_notices_js_ver,  $admin_notices_js_file);

            // JS editor helpers — OPTIONAL. If file is missing, do NOT enqueue (prevents 404).                        // CHANGED:
            $editor_ok  = (file_exists($admin_editor_js_file) && (filesize($admin_editor_js_file) > 0));              // CHANGED:
            $editor_dep = $editor_ok ? 'ppa-admin-editor' : 'ppa-admin-core';                                         // CHANGED:

            if ($editor_ok) {                                                                                         // CHANGED:
                wp_register_script(                                                                                   // CHANGED:
                    'ppa-admin-editor',                                                                               // CHANGED:
                    $admin_editor_js_url,                                                                             // CHANGED:
                    array('jquery', 'ppa-admin-config', 'ppa-admin-core'),                                            // CHANGED:
                    $admin_editor_js_ver,                                                                             // CHANGED:
                    true                                                                                              // CHANGED:
                );                                                                                                     // CHANGED:
                wp_enqueue_script('ppa-admin-editor');                                                                // CHANGED:
            }                                                                                                          // CHANGED:

            // Modular scripts (composer) — depend on editor ONLY when it exists.                                      // CHANGED:
            $composer_deps = array('jquery','ppa-admin-config','ppa-admin-core');                                      // CHANGED:
            if ($editor_ok) {                                                                                         // CHANGED:
                $composer_deps[] = 'ppa-admin-editor';                                                                // CHANGED:
            }                                                                                                         // CHANGED:

            $maybe_enqueue('ppa-admin-generate-view',     $admin_generate_view_js_url, $composer_deps, $admin_generate_view_js_ver, $admin_generate_view_js_file); // CHANGED:
            $maybe_enqueue('ppa-admin-composer-preview',  $admin_comp_preview_js_url,  $composer_deps, $admin_comp_preview_js_ver,  $admin_comp_preview_js_file);  // CHANGED:
            $maybe_enqueue('ppa-admin-composer-generate', $admin_comp_generate_js_url, $composer_deps, $admin_comp_generate_js_ver, $admin_comp_generate_js_file); // CHANGED:
            $maybe_enqueue('ppa-admin-composer-store',    $admin_comp_store_js_url,    $composer_deps, $admin_comp_store_js_ver,    $admin_comp_store_js_file);    // CHANGED:

            // JS — depend on config + core (+ editor only if present) + active modules                               // CHANGED:
            $admin_deps = array('jquery', 'ppa-admin-config', 'ppa-admin-core');                                      // CHANGED:
            if ($editor_ok) {                                                                                         // CHANGED:
                $admin_deps[] = 'ppa-admin-editor';                                                                   // CHANGED:
            }                                                                                                         // CHANGED:
            $admin_deps = array_merge($admin_deps, $active_modules);                                                  // CHANGED:

            wp_register_script(
                'ppa-admin',
                $admin_js_url,
                $admin_deps,                                                                                          // CHANGED:
                $admin_js_ver,
                true
            );
            wp_localize_script('ppa-admin', 'ppaAdmin', array(
                'ajaxurl' => $cfg['ajaxUrl'],
                'nonce'   => wp_create_nonce('ppa-admin'),
                'cssVer'  => $cfg['cssVer'],
                'jsVer'   => $cfg['jsVer'],
                'wpNonce' => $cfg['wpNonce'],
            ));
            wp_enqueue_script('ppa-admin');

            // Composer-only preview spinner (after admin.js)
            if ($is_main_ui && file_exists($admin_spinner_file)) {
                wp_register_script(
                    'ppa-admin-preview-spinner',
                    $admin_spinner_url,
                    array('ppa-admin'),
                    $admin_spinner_ver,
                    true
                );
                wp_enqueue_script('ppa-admin-preview-spinner');
            }

            // Testbed-only helper JS (DEV-only)
            if ($is_testbed && $testbed_enabled) {                                                                    // CHANGED:
                wp_register_script('ppa-testbed', $testbed_js_url, array('ppa-admin-config'), $testbed_js_ver, true);
                wp_enqueue_script('ppa-testbed');
            }
        }
    }
}

// Hook (guard against accidental double-hooking in edge includes)
if (function_exists('add_action') && function_exists('has_action')) {
    if (false === has_action('admin_enqueue_scripts', 'ppa_admin_enqueue')) { // CHANGED:
        add_action('admin_enqueue_scripts', 'ppa_admin_enqueue', 10, 1);
    }
} else {
    add_action('admin_enqueue_scripts', 'ppa_admin_enqueue', 10, 1);
}
