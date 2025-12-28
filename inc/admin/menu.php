<?php
/**
 * PostPress AI — Admin Menu Bootstrap
 *
 * ========= CHANGE LOG =========
 * 2025-12-28: HARDEN: Limit Settings API bootstrap registration to only when needed (options.php saves or our Settings page). // CHANGED:
 * 2025-12-28: FIX: Ensure license key sanitization matches Settings screen behavior even when settings.php is not loaded.      // CHANGED:
 * 2025-12-28: CLEAN: Remove unused array-option bootstrap registration ('ppa_settings') to reduce surface area.               // CHANGED:
 *
 * 2025-12-28: ADD: Custom SVG dashicon for PostPress AI menu; position set to 3 (high priority).  // CHANGED:
 * 2025-12-28: FIX: Remove duplicate "PostPress Composer" submenu entry.
 *             WP already auto-creates the first submenu for the parent slug via add_menu_page().              // CHANGED:
 *             We keep the rename of that auto submenu label, but do not add a second submenu item.           // CHANGED:
 *
 * 2025-12-28: FIX: Register the 'ppa_settings' settings group early on admin_init so options.php accepts option_page=ppa_settings
 *             even when settings.php is only included by the menu callback (prevents "allowed options list" error).            // CHANGED:
 *             (No Django/endpoints/CORS/auth changes. No layout/CSS changes. menu.php only.)                                  // CHANGED:
 *
 * 2025-12-27: FIX: Add Settings submenu (admin-only) under PostPress AI and route to settings.php include. // CHANGED:
 * 2025-12-27: FIX: Gate Testbed submenu behind PPA_ENABLE_TESTBED === true (hidden by default).            // CHANGED:
 * 2025-12-27: FIX: Normalize Testbed slug to "postpress-ai-testbed" to match screen detection/enqueue.     // CHANGED:
 * 2025-12-27: KEEP: Single menu registrar lives here; settings.php no longer registers its own submenu.     // CHANGED:
 *
 * 2025-11-11: Use PPA_PLUGIN_DIR for template resolution (more reliable than nested plugin_dir_path math).   // CHANGED:
 *             Keep capability 'edit_posts' and consistent permission messages.                               // CHANGED:
 *             Clarify/annotate legacy Tools→Testbed removal.                                                 // CHANGED:
 * 2025-11-10: Fallback Testbed markup now uses IDs expected by ppa-testbed.js                // (prev)
 *             (ppa-testbed-input|preview|store|output|status). Prefer ppa-testbed.php        // (prev)
 *             over testbed.php when including templates. No inline assets added.             // (prev)
 * 2025-11-09: Add self-contained markup fallback for Testbed when no template file is found;
 *             align H1 to "Testbed"; keep no-inline assets; centralized enqueue owns CSS/JS.
 * 2025-11-08: Add submenus under the top-level:
 *             - Rename default submenu to "PostPress Composer" (same slug as parent).
 *             - Add "Testbed" submenu (slug: ppa-testbed) under PostPress AI.
 *             - Remove legacy Tools→Testbed to avoid duplicates.
 * 2025-11-04: New file. Restores the top-level "PostPress AI" admin menu and composer renderer.
 *             - Registers menu with capability 'edit_posts' (Admin/Editor/Author).
 *             - Defines ppa_render_composer() (no inline JS/CSS; includes composer.php).
 *             - Defensive guards + breadcrumbs via error_log('PPA: ...').
 *
 * Notes:
 * - Keep this file presentation-free. No echo except inside the explicit render callbacks.
 * - Admin assets are handled in inc/admin/enqueue.php (scoped by screen checks).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sanitize helper for simple PPA settings (string or array).
 * This is intentionally conservative: only supports scalar strings + nested arrays of strings.      // CHANGED:
 */
if ( ! function_exists( 'ppa_sanitize_setting_value' ) ) {                                         // CHANGED:
	function ppa_sanitize_setting_value( $value ) {                                                 // CHANGED:
		$value = wp_unslash( $value );                                                               // CHANGED:

		if ( is_array( $value ) ) {                                                                  // CHANGED:
			$out = array();                                                                          // CHANGED:
			foreach ( $value as $k => $v ) {                                                         // CHANGED:
				// Preserve keys, sanitize values recursively.                                        // CHANGED:
				$out[ $k ] = ppa_sanitize_setting_value( $v );                                       // CHANGED:
			}                                                                                        // CHANGED:
			return $out;                                                                             // CHANGED:
		}                                                                                            // CHANGED:

		return sanitize_text_field( (string) $value );                                               // CHANGED:
	}                                                                                                // CHANGED:
}                                                                                                    // CHANGED:

/**
 * License key sanitizer used during options.php saves when settings.php isn't loaded yet.
 * Must match the intent of PPA_Admin_Settings::sanitize_license_key().                             // CHANGED:
 */
if ( ! function_exists( 'ppa_sanitize_license_key_value' ) ) {                                     // CHANGED:
	function ppa_sanitize_license_key_value( $value ) {                                             // CHANGED:
		$value = is_string( $value ) ? trim( $value ) : '';                                          // CHANGED:
		if ( '' === $value ) {                                                                      // CHANGED:
			return '';                                                                              // CHANGED:
		}                                                                                           // CHANGED:
		$value = preg_replace( '/\s+/', '', $value );                                                // CHANGED:
		if ( strlen( $value ) > 200 ) {                                                             // CHANGED:
			$value = substr( $value, 0, 200 );                                                      // CHANGED:
		}                                                                                           // CHANGED:
		return $value;                                                                              // CHANGED:
	}                                                                                               // CHANGED:
}                                                                                                    // CHANGED:

/**
 * Settings API bootstrap (critical for options.php saves).
 *
 * WHY:
 * - The Settings page form posts to options.php with option_page=ppa_settings.
 * - If register_setting('ppa_settings', ...) has NOT run by admin_init, WP rejects the save with:
 *   "Error: The ppa_settings options page is not in the allowed options list."
 * - settings.php is currently included by the Settings menu callback (late), so it may miss admin_init on save.  // CHANGED:
 *
 * FIX:
 * - Register the relevant setting(s) here on admin_init (early), without touching settings.php.
 * - This is WP-only, does not impact Django/endpoints/CORS/auth/etc.                                                  // CHANGED:
 */
if ( ! function_exists( 'ppa_register_settings_api_bootstrap' ) ) {                                 // CHANGED:
	function ppa_register_settings_api_bootstrap() {                                                 // CHANGED:
		if ( ! is_admin() ) {                                                                       // CHANGED:
			return;                                                                                 // CHANGED:
		}                                                                                            // CHANGED:

		// Only admins can hit options.php successfully anyway, but keep this tight.                 // CHANGED:
		if ( ! current_user_can( 'manage_options' ) ) {                                             // CHANGED:
			return;                                                                                 // CHANGED:
		}                                                                                            // CHANGED:

		// CHANGED: Only run when needed:
		// - options.php (saving settings)
		// - our Settings screen (page=postpress-ai-settings)                                        // CHANGED:
		$pagenow = isset( $GLOBALS['pagenow'] ) ? (string) $GLOBALS['pagenow'] : '';                // CHANGED:
		$phpself = isset( $_SERVER['PHP_SELF'] ) ? basename( (string) $_SERVER['PHP_SELF'] ) : '';  // CHANGED:
		$page    = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';       // CHANGED:

		$is_options_php = ( 'options.php' === $pagenow ) || ( 'options.php' === $phpself );         // CHANGED:
		$is_ppa_settings = ( 'postpress-ai-settings' === $page );                                    // CHANGED:

		if ( ! $is_options_php && ! $is_ppa_settings ) {                                             // CHANGED:
			return;                                                                                 // CHANGED:
		}                                                                                            // CHANGED:

		// Avoid overriding an existing registration if settings.php (or another file) registers first.  // CHANGED:
		global $wp_registered_settings;                                                             // CHANGED:
		if ( ! is_array( $wp_registered_settings ) ) {                                              // CHANGED:
			$wp_registered_settings = array();                                                      // CHANGED:
		}                                                                                            // CHANGED:

		// License key option must be registered by admin_init for options.php whitelist.
		// IMPORTANT: Use the same sanitize intent as settings.php.                                   // CHANGED:
		if ( ! isset( $wp_registered_settings['ppa_license_key'] ) ) {                              // CHANGED:
			register_setting(                                                                       // CHANGED:
				'ppa_settings',                                                                     // CHANGED: option_group (must match settings_fields('ppa_settings'))
				'ppa_license_key',                                                                  // CHANGED: option_name
				array(                                                                              // CHANGED:
					'type'              => 'string',                                                // CHANGED:
					'sanitize_callback' => 'ppa_sanitize_license_key_value',                         // CHANGED:
					'default'           => '',                                                      // CHANGED:
				)                                                                                   // CHANGED:
			);                                                                                       // CHANGED:
		}                                                                                            // CHANGED:
	}                                                                                                // CHANGED:
	add_action( 'admin_init', 'ppa_register_settings_api_bootstrap', 0 );                            // CHANGED: priority 0 = early
}                                                                                                    // CHANGED:

/**
 * Register the top-level "PostPress AI" menu and route to the Composer renderer.
 * Also adds:
 *  - Submenu "PostPress Composer" (renames the default submenu label).
 *  - Submenu "Settings" (admin-only).
 *  - Submenu "Testbed" (hidden unless PPA_ENABLE_TESTBED === true).
 */
if ( ! function_exists( 'ppa_register_admin_menu' ) ) {
	function ppa_register_admin_menu() {
		$capability_composer = 'edit_posts';
		$capability_admin    = 'manage_options'; // Settings + Testbed are admin-only.                // CHANGED:
		$menu_slug           = 'postpress-ai';

		// Custom SVG icon for PostPress AI                                                         // CHANGED:
		$icon_svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none">
  <path d="M3 2h8.5c3.59 0 6.5 2.91 6.5 6.5S15.09 15 11.5 15H8v3H3V2z" fill="currentColor" opacity="0.9"/>
  <g stroke="#ffffff" stroke-width="1.8" stroke-linecap="round" fill="none">
    <circle cx="12" cy="7" r="1.8"/>
    <circle cx="7" cy="11" r="1.5"/>
    <path d="M12 8.8 L12 10.5 L10 12.5 L7 12.5"/>
    <path d="M7 11 L7 9"/>
  </g>
</svg>'; // CHANGED:
		$menu_icon = 'data:image/svg+xml;base64,' . base64_encode( $icon_svg );                      // CHANGED:

		// Top-level menu (Composer)
		add_menu_page(
			__( 'PostPress AI', 'postpress-ai' ),
			__( 'PostPress AI', 'postpress-ai' ),
			$capability_composer,
			$menu_slug,
			'ppa_render_composer',
			$menu_icon,                                                                              // CHANGED:
			3                                                                                        // CHANGED: position 3 = high priority (right after Dashboard)
		);

		// Rename the auto-generated first submenu to "PostPress Composer"
		// NOTE: WP auto-creates this first submenu for the parent slug; do NOT add a duplicate.      // CHANGED:
		global $submenu;
		if ( isset( $submenu[ $menu_slug ][0] ) ) {
			$submenu[ $menu_slug ][0][0] = __( 'PostPress Composer', 'postpress-ai' );
		}

		// Settings submenu (admin-only)                                                        // CHANGED:
		add_submenu_page(                                                                         // CHANGED:
			$menu_slug,                                                                           // CHANGED:
			__( 'PostPress AI Settings', 'postpress-ai' ),                                        // CHANGED:
			__( 'Settings', 'postpress-ai' ),                                                     // CHANGED:
			$capability_admin,                                                                    // CHANGED:
			'postpress-ai-settings',                                                              // CHANGED:
			'ppa_render_settings'                                                                 // CHANGED:
		);                                                                                        // CHANGED:

		// Testbed submenu (admin-only AND gated)                                                 // CHANGED:
		$testbed_enabled = ( defined( 'PPA_ENABLE_TESTBED' ) && true === PPA_ENABLE_TESTBED );    // CHANGED:
		if ( $testbed_enabled ) {                                                                // CHANGED:
			add_submenu_page(                                                                     // CHANGED:
				$menu_slug,                                                                       // CHANGED:
				__( 'PPA Testbed', 'postpress-ai' ),                                               // CHANGED:
				__( 'Testbed', 'postpress-ai' ),                                                   // CHANGED:
				$capability_admin,                                                                // CHANGED:
				'postpress-ai-testbed',                                                           // CHANGED: normalized slug
				'ppa_render_testbed'                                                              // CHANGED:
			);                                                                                    // CHANGED:
		}                                                                                        // CHANGED:

		// Remove any legacy Tools→Testbed to avoid duplicates (harmless if not present).         // CHANGED:
		remove_submenu_page( 'tools.php', 'ppa-testbed' );                                       // CHANGED:
		remove_submenu_page( 'tools.php', 'postpress-ai-testbed' );                              // CHANGED:

		error_log( 'PPA: admin_menu registered (slug=' . $menu_slug . ')' );
	}
	add_action( 'admin_menu', 'ppa_register_admin_menu', 9 );
}

/**
 * Composer renderer (main UI).
 * Includes inc/admin/composer.php if present; otherwise prints a small, safe message.
 */
if ( ! function_exists( 'ppa_render_composer' ) ) {
	function ppa_render_composer() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'postpress-ai' ) );
		}

		// Prefer constant defined by root loader; avoids nested plugin_dir_path drift.            // CHANGED:
		$composer = trailingslashit( PPA_PLUGIN_DIR ) . 'inc/admin/composer.php';                 // CHANGED:

		if ( file_exists( $composer ) ) {
			error_log( 'PPA: including composer.php' );
			require $composer;
			return;
		}

		// Fallback UI (minimal, no inline assets beyond this safe notice).
		error_log( 'PPA: composer.php missing at ' . $composer );
		echo '<div class="wrap"><h1>PostPress Composer</h1><p>'
			. esc_html__( 'Composer UI not found. Ensure inc/admin/composer.php exists.', 'postpress-ai' )
			. '</p></div>';
	}
}

/**
 * Settings renderer (submenu).
 * Includes inc/admin/settings.php; the settings file owns the UI rendering.
 */
if ( ! function_exists( 'ppa_render_settings' ) ) {                                             // CHANGED:
	function ppa_render_settings() {                                                            // CHANGED:
		if ( ! current_user_can( 'manage_options' ) ) {                                          // CHANGED:
			wp_die( esc_html__( 'You do not have permission to access this page.', 'postpress-ai' ) ); // CHANGED:
		}                                                                                        // CHANGED:

		$settings = trailingslashit( PPA_PLUGIN_DIR ) . 'inc/admin/settings.php';                 // CHANGED:

		if ( file_exists( $settings ) ) {                                                        // CHANGED:
			error_log( 'PPA: including settings.php' );                                           // CHANGED:
			require $settings;                                                                    // CHANGED:
			return;                                                                               // CHANGED:
		}                                                                                        // CHANGED:

		error_log( 'PPA: settings.php missing at ' . $settings );                                 // CHANGED:
		echo '<div class="wrap"><h1>PostPress AI Settings</h1><p>'                                 // CHANGED:
			. esc_html__( 'Settings UI not found. Ensure inc/admin/settings.php exists.', 'postpress-ai' ) // CHANGED:
			. '</p></div>';                                                                       // CHANGED:
	}                                                                                            // CHANGED:
}                                                                                                // CHANGED:

/**
 * Testbed renderer (submenu).
 * Looks for one of the known filenames, falls back to minimal stub if absent.
 */
if ( ! function_exists( 'ppa_render_testbed' ) ) {
	function ppa_render_testbed() {
		if ( ! current_user_can( 'manage_options' ) ) {                                          // CHANGED:
			wp_die( esc_html__( 'You do not have permission to access this page.', 'postpress-ai' ) );
		}

		$base = trailingslashit( PPA_PLUGIN_DIR ) . 'inc/admin/';                                 // CHANGED:

		// Prefer the new template name first, then legacy.
		$candidates = array(
			$base . 'ppa-testbed.php',
			$base . 'testbed.php',
		);

		foreach ( $candidates as $file ) {
			if ( file_exists( $file ) ) {
				error_log( 'PPA: including ' . basename( $file ) );
				require $file;
				return;
			}
		}

		// Fallback UI — no inline JS/CSS; centralized enqueue provides styles/scripts.
		error_log( 'PPA: testbed UI not found in inc/admin/ — using fallback markup' );
		?>
		<div class="wrap ppa-testbed-wrap">
			<h1><?php echo esc_html__( 'Testbed', 'postpress-ai' ); ?></h1>
			<p class="ppa-hint">
				<?php echo esc_html__( 'This is the PostPress AI Testbed. Use it to send preview/draft requests to the backend.', 'postpress-ai' ); ?>
			</p>

			<!-- Status area consumed by JS -->
			<div id="ppa-testbed-status" class="ppa-notice" role="status" aria-live="polite"></div>

			<div class="ppa-form-group">
				<label for="ppa-testbed-input"><?php echo esc_html__( 'Payload (JSON or brief text)', 'postpress-ai' ); ?></label>
				<textarea id="ppa-testbed-input" rows="8" placeholder="<?php echo esc_attr__( 'Enter JSON for advanced control or plain text for a quick brief…', 'postpress-ai' ); ?>"></textarea>
			</div>

			<div class="ppa-actions" role="group" aria-label="<?php echo esc_attr__( 'Testbed actions', 'postpress-ai' ); ?>">
				<button id="ppa-testbed-preview" class="ppa-btn" type="button"><?php echo esc_html__( 'Preview', 'postpress-ai' ); ?></button>
				<button id="ppa-testbed-store" class="ppa-btn ppa-btn-secondary" type="button"><?php echo esc_html__( 'Save to Draft', 'postpress-ai' ); ?></button>
			</div>

			<h2 class="screen-reader-text"><?php echo esc_html__( 'Response Output', 'postpress-ai' ); ?></h2>
			<pre id="ppa-testbed-output" aria-live="polite" aria-label="<?php echo esc_attr__( 'Preview or store response output', 'postpress-ai' ); ?>"></pre>
		</div>
		<?php
	}
}
