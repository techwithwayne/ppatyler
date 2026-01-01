<?php
/**
 * Plugin Name: PostPress AI
 * Description: Secure server-to-server AI content preview & store via Django (PostPress AI). Adds a Composer screen and server-side AJAX proxy to your Django backend.
 * Author: Tech With Wayne
 * Version: 2.1.0
 * Requires at least: 6.1
 * Requires PHP: 7.4
 * Text Domain: postpress-ai
 *
 * @package PostPressAI
 */

/**
 * CHANGE LOG
 * 2026-01-01 — CLEAN: Remove noisy admin-post "fallback armed" debug.log line; keep only error logging on failure. # CHANGED:
 * 2026-01-01 — HARDEN: Gate ppa-testbed cache-busting so it only applies when Testbed is enabled AND the request is the Testbed page. # CHANGED:
 *
 * 2025-12-28 — HARDEN: Arm admin-post fallback only when the incoming request action matches our settings actions.
 *              This reduces debug.log noise and avoids attaching extra hooks on unrelated admin-post requests. # CHANGED:
 * 2025-12-28 — HARDEN: Detect admin-post via $pagenow OR PHP_SELF basename for stacks where $pagenow isn't set
 *              yet at plugins_loaded.                                                                          # CHANGED:
 *
 * 2025-12-28 — FIX: Admin-post fallback handlers. When clicking "Activate This Site", WP hits admin-post.php.
 *              settings.php is normally only included by the Settings page renderer (menu.php), so on admin-post
 *              requests the handler may not exist → blank admin-post screen. We now register tiny fallback hooks
 *              ONLY on admin-post.php requests, requiring settings.php and dispatching the correct handler. # CHANGED:
 *
 * 2025-11-11 — Fix syntax error in includes block (require_once shortcodes); keep enqueue + ver overrides.     # CHANGED:
 * 2025-11-11 — Add defensive remove_action() before our enqueue hook to avoid duplicate earlier hooks.        # CHANGED:
 * 2025-11-11 — Script ver override by SRC: force filemtime ?ver for ANY handle whose src points to          # CHANGED:
 *               postpress-ai/assets/js/admin.js (priority 999). Keeps jsTagVer === window.PPA.jsVer.        # CHANGED:
 * 2025-11-10 — Run admin enqueue at priority 99 so our filemtime ver wins; add admin-side                    # CHANGED:
 *              script/style ver filters for ppa-admin, ppa-admin-css, ppa-testbed (force ?ver).             # CHANGED:
 * 2025-11-10 — Simplify admin enqueue: delegate screen checks to ppa_admin_enqueue() and                     # CHANGED:
 *              remove duplicate gating here; hook once after includes load.                                  # CHANGED:
 * 2025-11-09 — Recognize new Testbed screen id 'postpress-ai_page_ppa-testbed'; sanitize $_GET['page'];     // CHANGED:
 *              keep legacy 'tools_page_ppa-testbed' and query fallback.                                     // CHANGED:
 * 2025-11-08 — Add PPA_PLUGIN_FILE; add PPA_VERSION alias to PPA_PLUGIN_VER for consistency;                // CHANGED:
 *              keep centralized enqueue wiring; minor tidy of cache-bust fallbacks to use PPA_VERSION.      // CHANGED:
 * 2025-11-08 — Prefer controller class for AJAX; fallback to inc/ajax/* only if controller not found; always load marker.php.
 * 2025-11-04 — Centralize requires; init logging & shortcode; remove inline JS/CSS.
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

/** ---------------------------------------------------------------------------------
 * Constants
 * -------------------------------------------------------------------------------- */
if ( ! defined( 'PPA_PLUGIN_FILE' ) ) {                      // CHANGED:
        define( 'PPA_PLUGIN_FILE', __FILE__ );                   // CHANGED:
}
if ( ! defined( 'PPA_PLUGIN_DIR' ) ) {
        define( 'PPA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'PPA_PLUGIN_URL' ) ) {
        define( 'PPA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'PPA_PLUGIN_VER' ) ) {
        define( 'PPA_PLUGIN_VER', '2.1.0' );
}
if ( ! defined( 'PPA_VERSION' ) ) {                          // CHANGED:
        define( 'PPA_VERSION', PPA_PLUGIN_VER );                 // CHANGED:
}

/** ---------------------------------------------------------------------------------
 * Includes (single source of truth)
 * -------------------------------------------------------------------------------- */
add_action( 'plugins_loaded', function () {
        // Admin UI (menu + composer renderer)
        $admin_menu = PPA_PLUGIN_DIR . 'inc/admin/menu.php';
        if ( file_exists( $admin_menu ) ) { require_once $admin_menu; }

        // Admin enqueue helpers
        $admin_enqueue = PPA_PLUGIN_DIR . 'inc/admin/enqueue.php';
        if ( file_exists( $admin_enqueue ) ) { require_once $admin_enqueue; }

        // Frontend shortcode
        $shortcodes = PPA_PLUGIN_DIR . 'inc/shortcodes/class-ppa-shortcodes.php';
        if ( file_exists( $shortcodes ) ) { require_once $shortcodes; \PPA\Shortcodes\PPAShortcodes::init(); }   // CHANGED:

        // Logging module
        $logging = PPA_PLUGIN_DIR . 'inc/logging/class-ppa-logging.php';
        if ( file_exists( $logging ) ) { require_once $logging; \PPA\Logging\PPALogging::init(); }
}, 9 );

/** ---------------------------------------------------------------------------------
 * Admin-post fallback handlers (Settings actions)
 *
 * Why:
 * - The Settings UI file (inc/admin/settings.php) is normally included only when visiting the Settings screen
 *   via the menu renderer (inc/admin/menu.php).                                         # CHANGED:
 * - But license buttons post to wp-admin/admin-post.php. If settings.php isn't included on that request,
 *   the admin_post_* handlers may not be registered → blank admin-post screen.          # CHANGED:
 *
 * Fix:
 * - ONLY on admin-post.php requests, register tiny fallback handlers for our settings actions.
 * - Each handler require_once()'s settings.php and calls the correct static method.     # CHANGED:
 * - This avoids loading settings.php on every admin request, and avoids duplicate hooks on normal pages. # CHANGED:
 * -------------------------------------------------------------------------------- */
add_action( 'plugins_loaded', function () {                                                           // CHANGED:
        if ( ! is_admin() ) {                                                                             // CHANGED:
                return;                                                                                       // CHANGED:
        }                                                                                                  // CHANGED:

        // CHANGED: Robust detection: some stacks don't have $GLOBALS['pagenow'] ready this early.
        $pagenow  = isset( $GLOBALS['pagenow'] ) ? (string) $GLOBALS['pagenow'] : '';                      // CHANGED:
        $php_self = isset( $_SERVER['PHP_SELF'] ) ? basename( (string) $_SERVER['PHP_SELF'] ) : '';        // CHANGED:
        if ( 'admin-post.php' !== $pagenow && 'admin-post.php' !== $php_self ) {                           // CHANGED:
                return;                                                                                       // CHANGED:
        }                                                                                                  // CHANGED:

        // CHANGED: Only arm fallback when THIS request is actually one of our actions.
        $req_action = '';                                                                                  // CHANGED:
        if ( isset( $_REQUEST['action'] ) ) {                                                              // CHANGED:
            $req_action = sanitize_key( wp_unslash( $_REQUEST['action'] ) );                                // CHANGED:
        }                                                                                                  // CHANGED:

        $action_map = array(                                                                               // CHANGED:
                'ppa_test_connectivity' => 'handle_test_connectivity',                                      // CHANGED:
                'ppa_license_verify'    => 'handle_license_verify',                                         // CHANGED:
                'ppa_license_activate'  => 'handle_license_activate',                                       // CHANGED:
                'ppa_license_deactivate'=> 'handle_license_deactivate',                                     // CHANGED:
        );                                                                                                  // CHANGED:

        if ( '' === $req_action || ! isset( $action_map[ $req_action ] ) ) {                               // CHANGED:
                return;                                                                                       // CHANGED:
        }                                                                                                  // CHANGED:

        $settings_file = PPA_PLUGIN_DIR . 'inc/admin/settings.php';                                        // CHANGED:

        $dispatch = function ( $method, $action ) use ( $settings_file ) {                                 // CHANGED:
                // Require settings.php only when a settings action actually fires (this request).            // CHANGED:
                if ( file_exists( $settings_file ) ) {                                                      // CHANGED:
                        require_once $settings_file;                                                        // CHANGED:
                }                                                                                            // CHANGED:

                if ( class_exists( 'PPA_Admin_Settings' ) && is_callable( array( 'PPA_Admin_Settings', $method ) ) ) { // CHANGED:
                        // Call the real handler (it does capability + nonce checks and redirects).              // CHANGED:
                        call_user_func( array( 'PPA_Admin_Settings', $method ) );                               // CHANGED:
                        exit; // Safety: the handler normally redirects+exits; this guarantees no fall-through.  // CHANGED:
                }                                                                                            // CHANGED:

                // CHANGED: Only log on actual failure (this is important signal; not "noise").
                error_log( 'PPA: admin-post fallback could not dispatch ' . $action . ' → ' . $method );    // CHANGED:
                wp_die(
                        esc_html__( 'PostPress AI settings handler missing. Please reinstall or contact support.', 'postpress-ai' ),
                        'PostPress AI',
                        array( 'response' => 500 )
                ); // CHANGED:
        };                                                                                                 // CHANGED:

        $method = $action_map[ $req_action ];                                                              // CHANGED:

        // CHANGED: Register ONLY the one hook needed for this request.
        add_action( 'admin_post_' . $req_action, function () use ( $dispatch, $method, $req_action ) {     // CHANGED:
                $dispatch( $method, $req_action );                                                         // CHANGED:
        }, 0 );                                                                                            // CHANGED:

        // CHANGED: Intentionally no "armed" error_log here — keep admin-post clean unless something fails.
}, 7 );                                                                                                   // CHANGED:

/** ---------------------------------------------------------------------------------
 * AJAX handlers — load early so admin-ajax.php can find them
 * Prefer controller class; fallback to legacy inc/ajax/* files if missing.
 * -------------------------------------------------------------------------------- */
add_action( 'plugins_loaded', function () {
        $controller = PPA_PLUGIN_DIR . 'inc/class-ppa-controller.php';
        $ajax_dir   = PPA_PLUGIN_DIR . 'inc/ajax/';

        if ( file_exists( $controller ) ) {
                // Preferred: class registers wp_ajax_* hooks internally.
                require_once $controller;
        } else {
                foreach ( array( 'preview.php', 'store.php' ) as $file ) {
                        $path = $ajax_dir . $file;
                        if ( file_exists( $path ) ) { require_once $path; }
                }
        }

        // marker.php is always loaded (no controller equivalent).
        $marker = PPA_PLUGIN_DIR . 'inc/ajax/marker.php';
        if ( file_exists( $marker ) ) { require_once $marker; }
}, 8 );

/** ---------------------------------------------------------------------------------
 * Admin enqueue — delegate to inc/admin/enqueue.php (single source of truth)
 * -------------------------------------------------------------------------------- */
add_action( 'plugins_loaded', function () {                                                      // CHANGED:
        if ( function_exists( 'ppa_admin_enqueue' ) ) {                                              // CHANGED:
                // Neutralize any earlier hooks that might enqueue duplicates at other priorities.       // CHANGED:
                remove_action( 'admin_enqueue_scripts', 'ppa_admin_enqueue', 10 );                       // CHANGED:
                remove_action( 'admin_enqueue_scripts', 'ppa_admin_enqueue', 99 );                       // CHANGED:
                add_action( 'admin_enqueue_scripts', 'ppa_admin_enqueue', 99 );                          // CHANGED:
        }
}, 10 );                                                                                         // CHANGED:

/** ---------------------------------------------------------------------------------
 * Admin asset cache-busting (ver=filemtime) — enforce for admin handles/SRCs
 * -------------------------------------------------------------------------------- */
add_action( 'admin_init', function () {                                                          // CHANGED:
        // CHANGED: Local helper — identifies the Testbed request by page slug.
        // We only touch ppa-testbed versions when Testbed is enabled AND we are on the Testbed page.
        $ppa_is_testbed_request = function () {                                                   // CHANGED:
                $page = '';                                                                       // CHANGED:
                if ( isset( $_GET['page'] ) ) {                                                   // CHANGED:
                        $page = sanitize_key( wp_unslash( $_GET['page'] ) );                      // CHANGED:
                }                                                                                 // CHANGED:
                return in_array( $page, array( 'postpress-ai-testbed', 'ppa-testbed' ), true );   // CHANGED:
        };                                                                                        // CHANGED:

        // Styles (admin) — by handle
        add_filter( 'style_loader_src', function ( $src, $handle ) {
                if ( 'ppa-admin-css' !== $handle ) { return $src; }
                $file = PPA_PLUGIN_DIR . 'assets/css/admin.css';
                $ver  = ( file_exists( $file ) ? (string) filemtime( $file ) : PPA_VERSION );
                $src  = remove_query_arg( 'ver', $src );
                return add_query_arg( 'ver', $ver, $src );
        }, 10, 2 );

        // Scripts (admin) — by handle (ppa-admin, ppa-testbed)
        add_filter( 'script_loader_src', function ( $src, $handle ) use ( $ppa_is_testbed_request ) {      // CHANGED:
                if ( 'ppa-admin' !== $handle && 'ppa-testbed' !== $handle ) { return $src; }

                // CHANGED: Never touch ppa-testbed unless enabled + actually on the Testbed page.
                if ( 'ppa-testbed' === $handle ) {                                                        // CHANGED:
                        if ( ! defined( 'PPA_ENABLE_TESTBED' ) || true !== PPA_ENABLE_TESTBED ) {          // CHANGED:
                                return $src;                                                               // CHANGED:
                        }                                                                                   // CHANGED:
                        if ( ! $ppa_is_testbed_request() ) {                                                // CHANGED:
                                return $src;                                                               // CHANGED:
                        }                                                                                   // CHANGED:
                }                                                                                           // CHANGED:

                $file = ( 'ppa-admin' === $handle )
                        ? ( PPA_PLUGIN_DIR . 'assets/js/admin.js' )
                        : ( PPA_PLUGIN_DIR . 'inc/admin/ppa-testbed.js' );
                $ver  = ( file_exists( $file ) ? (string) filemtime( $file ) : PPA_VERSION );
                $src  = remove_query_arg( 'ver', $src );
                return add_query_arg( 'ver', $ver, $src );
        }, 10, 2 );

        // Scripts (admin) — by SRC (catch-all): if any handle loads our admin.js path, force filemtime ver.   # CHANGED:
        add_filter( 'script_loader_src', function ( $src, $handle ) {                                          // CHANGED:
                // Quick path check; works for absolute URLs too.                                              // CHANGED:
                if ( strpos( (string) $src, 'postpress-ai/assets/js/admin.js' ) === false ) {                  // CHANGED:
                        return $src;                                                                          // CHANGED:
                }                                                                                              // CHANGED:
                $file = PPA_PLUGIN_DIR . 'assets/js/admin.js';                                                 // CHANGED:
                $ver  = ( file_exists( $file ) ? (string) filemtime( $file ) : PPA_VERSION );                  // CHANGED:
                $src  = remove_query_arg( 'ver', $src );                                                       // CHANGED:
                return add_query_arg( 'ver', $ver, $src );                                                     // CHANGED:
        }, 999, 2 );                                                                                           // CHANGED:
}, 9 );                                                                                                       // CHANGED:

/** ---------------------------------------------------------------------------------
 * Public asset cache-busting (ver=filemtime) — handles registered by shortcode
 * -------------------------------------------------------------------------------- */
add_action( 'init', function () {
        // Styles
        add_filter( 'style_loader_src', function ( $src, $handle ) {
                if ( 'ppa-frontend' !== $handle ) { return $src; }
                $file = PPA_PLUGIN_DIR . 'assets/css/ppa-frontend.css';
                $ver  = ( file_exists( $file ) ? (string) filemtime( $file ) : PPA_VERSION );
                $src  = remove_query_arg( 'ver', $src );
                return add_query_arg( 'ver', $ver, $src );
        }, 10, 2 );

        // Scripts
        add_filter( 'script_loader_src', function ( $src, $handle ) {
                if ( 'ppa-frontend' !== $handle ) { return $src; }
                $file = PPA_PLUGIN_DIR . 'assets/js/ppa-frontend.js';
                $ver  = ( file_exists( $file ) ? (string) filemtime( $file ) : PPA_VERSION );
                $src  = remove_query_arg( 'ver', $src );
                return add_query_arg( 'ver', $ver, $src );
        }, 10, 2 );
}, 12 );

/** ---------------------------------------------------------------------------------
 * Top-level admin menu & Composer render (kept in inc/admin/menu.php)
 * --------------------------------------------------------------------------------
 * The actual UI lives in inc/admin/composer.php and is loaded by the menu renderer.
 * This file should not echo HTML; keeping bootstrap clean prevents accidental fatals.
 * -------------------------------------------------------------------------------- */
