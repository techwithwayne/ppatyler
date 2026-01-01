<?php
/**
 * PostPress AI — Admin Asset Loader (deprecated shim)
 * Path: inc/class-ppa-admin.php
 *
 * ========= CHANGE LOG =========
 * 2026-01-01: CLEAN: Make deprecated enqueue() a silent no-op (no debug.log noise on admin screens). # CHANGED:
 *
 * 2025-11-11: Harden deprecation — remove_action across common priorities and callback forms;        # CHANGED:
 *             add late guard on admin_init to ensure no legacy enqueue survives.                     # CHANGED:
 * 2025-11-10: Deprecate legacy asset enqueues; delegate to centralized loader in                     # (prev)
 *             inc/admin/enqueue.php. Stop adding admin_enqueue_scripts hook.                         # (prev)
 *             Provide no-op enqueue() and defensive remove_action in init().                         # (prev)
 * 2025-11-08: Add cache-busted CSS/JS (filemtime), limit to plugin screen, expose PPA cfg.           # (prev)
 */

namespace PPA\Admin; // CHANGED:

if (!defined('ABSPATH')) { // CHANGED:
    exit;                  // CHANGED:
}                          // CHANGED:

class Admin { // CHANGED:
    /**
     * Bootstrap (deprecated for assets).
     * No longer attaches admin_enqueue_scripts; centralized loader owns assets.               // CHANGED:
     */
    public static function init() { // CHANGED:
        // Actively remove any previously-attached legacy enqueues, regardless of priority    // CHANGED:
        foreach ([10, 20, 99, 100] as $prio) {                                               // CHANGED:
            remove_action('admin_enqueue_scripts', [__CLASS__, 'enqueue'], $prio);            // CHANGED:
            // Some older builds stored the callable as a static-string reference             // CHANGED:
            remove_action('admin_enqueue_scripts', __NAMESPACE__ . '\\Admin::enqueue', $prio);// CHANGED:
            remove_action('admin_enqueue_scripts', 'PPA\\Admin\\Admin::enqueue', $prio);      // CHANGED:
        }

        // Late guard: if any code re-adds this after plugins_loaded, yank it at runtime.      // CHANGED:
        add_action('admin_init', function () {                                                // CHANGED:
            foreach ([10, 20, 99, 100] as $prio) {                                           // CHANGED:
                remove_action('admin_enqueue_scripts', [__CLASS__, 'enqueue'], $prio);        // CHANGED:
                remove_action('admin_enqueue_scripts', __NAMESPACE__ . '\\Admin::enqueue', $prio); // CHANGED:
                remove_action('admin_enqueue_scripts', 'PPA\\Admin\\Admin::enqueue', $prio);  // CHANGED:
            }
        }, 1);                                                                                // CHANGED:
        // Intentionally DO NOT add any enqueue hooks here.                                    // CHANGED:
    }                                                                                          // CHANGED:

    /**
     * Back-compat only: previous screen check helper.
     * Retained to avoid fatals if referenced externally.                                      // CHANGED:
     */
    protected static function is_plugin_screen(): bool { // CHANGED:
        $page = isset($_GET['page']) ? sanitize_key((string) $_GET['page']) : '';             // CHANGED:
        if ($page === 'postpress-ai') {                                                       // CHANGED:
            return true;                                                                      // CHANGED:
        }
        if (function_exists('get_current_screen')) {                                          // CHANGED:
            $screen = get_current_screen();                                                   // CHANGED:
            if ($screen && (strpos((string) ($screen->id ?? ''), 'postpress-ai') !== false)) {// CHANGED:
                return true;                                                                  // CHANGED:
            }
        }
        return false;                                                                         // CHANGED:
    }                                                                                         // CHANGED:

    /**
     * Deprecated no-op. Assets are now enqueued exclusively by inc/admin/enqueue.php.         // CHANGED:
     */
    public static function enqueue($hook_suffix = null) { // CHANGED:
        // CHANGED: Silent no-op. No logs here — we only log real failures in active code paths.
        return;                                                                               // CHANGED:
    }                                                                                         // CHANGED:
}                                                                                             // CHANGED:

// Bootstrap (kept for compatibility)                                                          // CHANGED:
Admin::init();                                                                                // CHANGED:
