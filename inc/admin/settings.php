<?php
/**
 * PostPress AI — Admin Settings
 * Path: inc/admin/settings.php
 *
 * Provides:
 * - Settings UI renderer for PostPress AI.
 * - License key storage (admin-only) + license actions that call Django /license/* (server-side only).
 * - Test Connection action that calls Django /version/ and /health/ (server-side only).
 * - Display-only caching of last licensing response for admin visibility (no enforcement).
 *
 * Notes:
 * - Server URL is infrastructure; kept internally (constant/option) but NOT shown to end users.   // CHANGED:
 * - Connection Key is legacy; if present we use it, otherwise we use License Key as the auth key. // CHANGED:
 *
 * ========= CHANGE LOG =========
 * 2026-01-01: CLEAN: Reduce Settings debug.log noise — log failures only (no start/ok chatter).                 // CHANGED:
 * 2026-01-01: FIX: Make init() + register_settings() idempotent (prevents double hook/registration).          // CHANGED:
 * 2026-01-01: HARDEN: Sanitize license key by stripping control chars + whitespace (paste-safe).             // CHANGED:
 * 2026-01-01: FIX: Render Settings page ONLY on admin.php?page=postpress-ai-settings (never admin-post).    // CHANGED:
 *
 * 2025-12-28: CLEAN: Remove duplicate submit_button() guard that was repeated inside render_page().           // CHANGED:
 * 2025-12-28: UX: Disable "Check License" + "Test Connection" until a License Key is saved.                 // CHANGED:
 * 2025-12-28: UX: Disable "Deactivate This Site" when status is known "Not active" (still allowed if Unknown). // CHANGED:
 * 2025-12-28: LOG: Add tight PPA: logs for license actions + connectivity for faster debug.log triage.       // CHANGED:
 *
 * 2025-12-28: FIX: Prevent duplicate plan-limit notice by suppressing querystring license notice
 *              when site_limit_reached is true (persistent notice handles it).                              // CHANGED:
 * 2025-12-28: UX: If last license response shows plan_limit + site_limit_reached, show friendly message
 *              and disable "Activate This Site" (UX only; no enforcement; endpoints unchanged).             // CHANGED:
 * 2025-12-28: FIX: Prevent fatal "Call to undefined function submit_button()" by defensively loading
 *              wp-admin/includes/template.php inside render_page() and providing a last-resort shim.         // CHANGED:
 *
 * 2025-12-27: FIX: Remove submenu registration from this file; menu.php is the single menu registrar.        // CHANGED:
 *             Settings screen is routed here via ppa_render_settings() include from menu.php.               // CHANGED:
 *
 * 2025-11-19: Initial settings screen & connectivity test (Django URL + shared key).                        // CHANGED:
 * 2025-12-25: Add license UI + admin-post handlers to call Django /license/* endpoints (server-side).       // CHANGED:
 * 2025-12-25: HARDEN: Settings screen + actions admin-only (manage_options).                                // CHANGED:
 * 2025-12-25: UX: Simplify copy for creators; remove technical pipeline language.                           // CHANGED:
 * 2025-12-25: BRAND: Add stable wrapper classes (ppa-admin ppa-settings) for CSS parity with Composer.      // CHANGED:
 * 2025-12-25: CLEAN: Remove inline layout styles; use class hooks for styling later.                        // CHANGED:
 * 2025-12-25: UX: Render fields manually (no duplicate section headings); “grandma-friendly” labels.        // CHANGED:
 * 2025-12-25: FIX: Render notices inside Setup card so they never float outside the frame/grid.             // CHANGED:
 * 2025-12-25: UX: Hide Server URL + Connection Key from UI; License Key is the only user-facing input.      // CHANGED:
 * 2025-12-25: AUTH: If Connection Key is empty, use License Key for X-PPA-Key (legacy-safe).               // CHANGED:
 * 2025-12-25: UX GUARDRAILS: Disable Activate until key saved; show Active/Not active badge (pure PHP).     // CHANGED:
 * 2025-12-26: UX HARDEN: Persist "active on this site" locally after successful Activate; clear on Deactivate. // CHANGED:
 *            (UI convenience only; Django remains authoritative.)                                           // CHANGED:
 */

defined( 'ABSPATH' ) || exit;

/* PPA_SUBMIT_BUTTON_GUARD_START */
 // CHANGED: Prevent fatal error if wp-admin template helpers aren't loaded yet (submit_button()).
 // CHANGED: This can happen if Settings renders early in admin bootstrap or on admin-post requests.

if ( is_admin() && ! function_exists( 'submit_button' ) ) { // CHANGED:
	$ppa_tpl = ABSPATH . 'wp-admin/includes/template.php'; // CHANGED:
	if ( file_exists( $ppa_tpl ) ) { // CHANGED:
		require_once $ppa_tpl; // CHANGED:
	} // CHANGED:
} // CHANGED:

// CHANGED: Last-resort shim — only if submit_button STILL doesn't exist after template include.
if ( ! function_exists( 'submit_button' ) ) { // CHANGED:
	function submit_button( $text = null, $type = 'primary', $name = 'submit', $wrap = true, $other_attributes = null ) { // CHANGED:
		$text    = $text ?: __( 'Save Changes' ); // CHANGED:
		$classes = 'button button-' . $type; // CHANGED:
		$attrs   = ''; // CHANGED:

		if ( is_array( $other_attributes ) ) { // CHANGED:
			foreach ( $other_attributes as $k => $v ) { // CHANGED:
				$attrs .= ' ' . esc_attr( $k ) . '="' . esc_attr( $v ) . '"'; // CHANGED:
			} // CHANGED:
		} elseif ( is_string( $other_attributes ) && trim( $other_attributes ) !== '' ) { // CHANGED:
			$attrs .= ' ' . trim( $other_attributes ); // CHANGED:
		} // CHANGED:

		$btn = '<input type="submit" name="' . esc_attr( $name ) . '" id="' . esc_attr( $name ) . '" class="' . esc_attr( $classes ) . '" value="' . esc_attr( $text ) . '"' . $attrs . ' />'; // CHANGED:
		echo $wrap ? '<p class="submit">' . $btn . '</p>' : $btn; // CHANGED:
	} // CHANGED:
} // CHANGED:
/* PPA_SUBMIT_BUTTON_GUARD_END */

if ( ! class_exists( 'PPA_Admin_Settings' ) ) {

	/**
	 * Admin Settings for PostPress AI.
	 *
	 * Note:
	 * - This file is included by inc/admin/menu.php when visiting the Settings screen.
	 * - Menu registration is owned by menu.php (single registrar).                                      // CHANGED:
	 */
	class PPA_Admin_Settings {

		// ===== License option + transient (display-only) =====
		const OPT_LICENSE_KEY      = 'ppa_license_key';                                        // CHANGED:
		const OPT_ACTIVE_SITE      = 'ppa_license_active_site';                                // CHANGED:
		const TRANSIENT_LAST_LIC   = 'ppa_license_last_result';
		const LAST_LIC_TTL_SECONDS = 10 * MINUTE_IN_SECONDS;

		// CHANGED: Idempotency guards (this file may be included more than once depending on admin bootstrap).
		private static $booted              = false; // CHANGED:
		private static $settings_registered = false; // CHANGED:

		/**
		 * Centralized capability:
		 * Settings + licensing are admin-only.
		 */
		private static function cap() {
			return 'manage_options';
		}

		/**
		 * Tight logger for debug.log triage.
		 * Always prefixed with "PPA:" so you can grep cleanly.                                     // CHANGED:
		 *
		 * IMPORTANT (2026-01-01):
		 * - Call this ONLY on failures. No "start/ok/http=200" chatter.                            // CHANGED:
		 *
		 * @param string $msg
		 */
		private static function log( $msg ) {                                                   // CHANGED:
			$msg = is_string( $msg ) ? trim( $msg ) : '';                                       // CHANGED:
			if ( '' === $msg ) {                                                                // CHANGED:
				return;                                                                        // CHANGED:
			}                                                                                   // CHANGED:
			error_log( 'PPA: ' . $msg );                                                        // CHANGED:
		}                                                                                       // CHANGED:

		/**
		 * Bootstrap hooks.
		 */
		public static function init() {
			if ( self::$booted ) { // CHANGED:
				return; // CHANGED:
			} // CHANGED:
			self::$booted = true; // CHANGED:

			// IMPORTANT: menu.php owns the submenu entry now.                                         // CHANGED:
			// We only register settings + handlers here.                                               // CHANGED:

			// Settings API registration.
			add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );

			// If this file loads after admin_init (e.g. via admin_menu), admin_init already ran.
			// Register settings immediately so options.php will accept option_page=ppa_settings.
			if ( did_action( 'admin_init' ) ) {
				self::register_settings();
			}

			// Test Connection handler (admin-post).
			add_action( 'admin_post_ppa_test_connectivity', array( __CLASS__, 'handle_test_connectivity' ) );

			// Licensing handlers (admin-post, server-side only).
			add_action( 'admin_post_ppa_license_verify', array( __CLASS__, 'handle_license_verify' ) );
			add_action( 'admin_post_ppa_license_activate', array( __CLASS__, 'handle_license_activate' ) );
			add_action( 'admin_post_ppa_license_deactivate', array( __CLASS__, 'handle_license_deactivate' ) );
		}

		/**
		 * Register options and fields for the settings screen.
		 *
		 * Note:
		 * - We still register legacy options for backwards compatibility (sanitization + storage),
		 *   but we DO NOT render them in the UI anymore.                                          // CHANGED:
		 */
		public static function register_settings() {
			if ( self::$settings_registered ) { // CHANGED:
				return; // CHANGED:
			} // CHANGED:
			self::$settings_registered = true; // CHANGED:

			// (Legacy) Django URL is still supported (constant/option), but not rendered.          // CHANGED:
			register_setting(
				'ppa_settings',
				'ppa_django_url',
				array(
					'type'              => 'string',
					'sanitize_callback' => array( __CLASS__, 'sanitize_django_url' ),
					'default'           => 'https://apps.techwithwayne.com/postpress-ai/',
				)
			);

			// (Legacy) Shared key is still supported, but not rendered.                            // CHANGED:
			register_setting(
				'ppa_settings',
				'ppa_shared_key',
				array(
					'type'              => 'string',
					'sanitize_callback' => array( __CLASS__, 'sanitize_shared_key' ),
					'default'           => '',
				)
			);

			// License key storage (raw; masked display only).
			register_setting(
				'ppa_settings',
				self::OPT_LICENSE_KEY,
				array(
					'type'              => 'string',
					'sanitize_callback' => array( __CLASS__, 'sanitize_license_key' ),
					'default'           => '',
				)
			);

			// (Legacy) Sections/fields kept registered for compatibility, but not rendered.
			add_settings_section(
				'ppa_settings_connection',
				__( 'Connection', 'postpress-ai' ),
				array( __CLASS__, 'section_connection_intro' ),
				'postpress-ai-settings'
			);

			add_settings_field(
				'ppa_django_url',
				__( 'Server URL', 'postpress-ai' ),
				array( __CLASS__, 'field_django_url' ),
				'postpress-ai-settings',
				'ppa_settings_connection'
			);

			add_settings_field(
				'ppa_shared_key',
				__( 'Connection Key', 'postpress-ai' ),
				array( __CLASS__, 'field_shared_key' ),
				'postpress-ai-settings',
				'ppa_settings_connection'
			);

			add_settings_section(
				'ppa_settings_license',
				__( 'License', 'postpress-ai' ),
				array( __CLASS__, 'section_license_intro' ),
				'postpress-ai-settings'
			);

			add_settings_field(
				self::OPT_LICENSE_KEY,
				__( 'License Key', 'postpress-ai' ),
				array( __CLASS__, 'field_license_key' ),
				'postpress-ai-settings',
				'ppa_settings_license'
			);
		}

		public static function sanitize_django_url( $value ) {
			$value = is_string( $value ) ? trim( $value ) : '';
			if ( '' === $value ) {
				return '';
			}
			$value = esc_url_raw( $value );
			$value = untrailingslashit( $value );
			return $value;
		}

		public static function sanitize_shared_key( $value ) {
			if ( ! is_string( $value ) ) {
				return '';
			}
			$value = trim( $value );
			return $value;
		}

		public static function sanitize_license_key( $value ) {
			$value = is_string( $value ) ? trim( $value ) : '';
			if ( '' === $value ) {
				return '';
			}

			// CHANGED: Strip control characters (invisible paste junk) + whitespace. Keep format permissive.
			$tmp = preg_replace( '/[\x00-\x1F\x7F]/', '', $value ); // CHANGED:
			$value = is_string( $tmp ) ? $tmp : $value; // CHANGED:

			$tmp = preg_replace( '/\s+/', '', $value ); // CHANGED:
			$value = is_string( $tmp ) ? $tmp : $value; // CHANGED:

			if ( strlen( $value ) > 200 ) {
				$value = substr( $value, 0, 200 );
			}
			return $value;
		}

		/**
		 * Render a Composer-parity notice (scoped to Settings CSS).
		 * IMPORTANT: We render notices INSIDE the Setup card so they never float outside the frame/grid.
		 *
		 * @param string $status ok|error
		 * @param string $message
		 */
		private static function render_notice( $status, $message ) {
			$status  = ( 'ok' === $status ) ? 'ok' : 'error';
			$message = is_string( $message ) ? trim( $message ) : '';
			if ( '' === $message ) {
				return;
			}

			$cls = ( 'ok' === $status ) ? 'ppa-notice ppa-notice--success' : 'ppa-notice ppa-notice--error';
			?>
			<div class="<?php echo esc_attr( $cls ); ?>">
				<p><?php echo esc_html( $message ); ?></p>
			</div>
			<?php
		}

		/**
		 * Detect plan-limit site cap response.
		 *
		 * Requirements (Option A):
		 * - error.type = plan_limit
		 * - error.code = site_limit_reached
		 *
		 * NOTE: This is UX-only (disable Activate + show clearer message). No enforcement.
		 *
		 * @param mixed $result
		 * @return bool
		 */
		private static function is_plan_limit_site_limit_reached( $result ) {                   // CHANGED:
			if ( ! is_array( $result ) ) {                                                     // CHANGED:
				return false;                                                                  // CHANGED:
			}                                                                                  // CHANGED:

			$err = array();                                                                    // CHANGED:

			if ( isset( $result['error'] ) && is_array( $result['error'] ) ) {                  // CHANGED:
				$err = $result['error'];                                                       // CHANGED:
			} elseif ( isset( $result['data']['error'] ) && is_array( $result['data']['error'] ) ) { // CHANGED:
				$err = $result['data']['error'];                                               // CHANGED:
			}                                                                                  // CHANGED:

			$type = isset( $err['type'] ) ? strtolower( trim( (string) $err['type'] ) ) : '';  // CHANGED:
			$code = isset( $err['code'] ) ? strtolower( trim( (string) $err['code'] ) ) : '';  // CHANGED:

			return ( 'plan_limit' === $type && 'site_limit_reached' === $code );                // CHANGED:
		}                                                                                      // CHANGED:

		/**
		 * Local “active on this site” helper.
		 *
		 * IMPORTANT:
		 * - This is NOT enforcement. Django remains authoritative.
		 * - This is only used to reduce confusion in the UI (disable Activate + show badge)
		 *   after a successful Activate action.
		 */
		private static function is_active_on_this_site_option() {                               // CHANGED:
			$stored = get_option( self::OPT_ACTIVE_SITE, '' );                                   // CHANGED:
			$stored = is_string( $stored ) ? untrailingslashit( $stored ) : '';                  // CHANGED:
			$home   = untrailingslashit( home_url( '/' ) );                                      // CHANGED:
			return ( '' !== $stored && $stored === $home );                                      // CHANGED:
		}

		/**
		 * Determine “active on this site” from the cached last result (display-only).
		 *
		 * @param mixed $last
		 * @return string one of: active|inactive|unknown
		 */
		private static function derive_activation_state( $last ) {                               // CHANGED:
			if ( self::is_active_on_this_site_option() ) {                                       // CHANGED:
				return 'active';                                                                 // CHANGED:
			}                                                                                     // CHANGED:

			if ( ! is_array( $last ) ) {                                                         // CHANGED:
				return 'unknown';                                                                // CHANGED:
			}                                                                                     // CHANGED:

			$data = isset( $last['data'] ) && is_array( $last['data'] ) ? $last['data'] : array(); // CHANGED:

			$candidates = array(                                                                  // CHANGED:
				'active',                                                                         // CHANGED:
				'is_active',                                                                      // CHANGED:
				'site_active',                                                                    // CHANGED:
				'activated',                                                                      // CHANGED:
				'status',                                                                         // CHANGED:
				'activation_status',                                                              // CHANGED:
			);

			foreach ( $candidates as $k ) {                                                       // CHANGED:
				if ( array_key_exists( $k, $data ) ) {                                            // CHANGED:
					$v = $data[ $k ];                                                             // CHANGED:
					if ( is_bool( $v ) ) {                                                        // CHANGED:
						return $v ? 'active' : 'inactive';                                        // CHANGED:
					}                                                                             // CHANGED:
					if ( is_string( $v ) ) {                                                      // CHANGED:
						$vv = strtolower( trim( $v ) );                                           // CHANGED:
						if ( in_array( $vv, array( 'active', 'activated', 'on', 'enabled' ), true ) ) { // CHANGED:
							return 'active';                                                     // CHANGED:
						}                                                                         // CHANGED:
						if ( in_array( $vv, array( 'inactive', 'deactivated', 'off', 'disabled' ), true ) ) { // CHANGED:
							return 'inactive';                                                   // CHANGED:
						}                                                                         // CHANGED:
					}                                                                             // CHANGED:
				}                                                                                 // CHANGED:
			}

			if ( isset( $data['active_sites'] ) && is_array( $data['active_sites'] ) ) {          // CHANGED:
				$home = untrailingslashit( home_url( '/' ) );                                     // CHANGED:
				foreach ( $data['active_sites'] as $site ) {                                      // CHANGED:
					if ( is_string( $site ) && untrailingslashit( $site ) === $home ) {           // CHANGED:
						return 'active';                                                         // CHANGED:
					}                                                                             // CHANGED:
				}                                                                                 // CHANGED:
				return 'inactive';                                                                // CHANGED:
			}

			return 'unknown';                                                                      // CHANGED:
		}

		// Legacy helpers (kept registered, not shown in UI now).
		public static function section_connection_intro() {
			?>
			<p class="ppa-help">
				<?php esc_html_e( 'These settings connect this site to PostPress AI.', 'postpress-ai' ); ?>
			</p>
			<?php
		}

		public static function field_django_url() {
			$value = get_option( 'ppa_django_url', 'https://apps.techwithwayne.com/postpress-ai/' );
			?>
			<input type="url"
			       name="ppa_django_url"
			       id="ppa_django_url"
			       class="regular-text code"
			       value="<?php echo esc_attr( $value ); ?>"
			       placeholder="https://apps.techwithwayne.com/postpress-ai" />
			<p class="description">
				<?php esc_html_e( 'Where PostPress AI lives (the web address you were given).', 'postpress-ai' ); ?>
			</p>
			<?php
		}

		public static function field_shared_key() {
			$value = get_option( 'ppa_shared_key', '' );
			?>
			<input type="password"
			       name="ppa_shared_key"
			       id="ppa_shared_key"
			       class="regular-text"
			       value="<?php echo esc_attr( $value ); ?>"
			       autocomplete="off" />
			<p class="description">
				<?php esc_html_e( 'Secret key that links this site to your PostPress AI account.', 'postpress-ai' ); ?>
			</p>
			<?php
		}

		public static function section_license_intro() {
			?>
			<p class="ppa-help">
				<?php esc_html_e( 'Add your license key to turn PostPress AI on for this site.', 'postpress-ai' ); ?>
			</p>
			<?php
		}

		public static function field_license_key() {
			$raw    = (string) get_option( self::OPT_LICENSE_KEY, '' );
			$masked = self::mask_secret( $raw );
			?>
			<input type="text"
			       name="<?php echo esc_attr( self::OPT_LICENSE_KEY ); ?>"
			       id="<?php echo esc_attr( self::OPT_LICENSE_KEY ); ?>"
			       class="regular-text"
			       value="<?php echo esc_attr( $raw ); ?>"
			       autocomplete="off"
			       placeholder="ppa_live_***************" />
			<p class="description">
				<?php esc_html_e( 'Saved key:', 'postpress-ai' ); ?>
				<code><?php echo esc_html( $masked ); ?></code>
			</p>
			<?php
		}

		private static function get_django_base_url() {
			if ( defined( 'PPA_DJANGO_URL' ) && PPA_DJANGO_URL ) {
				$base = (string) PPA_DJANGO_URL;
			} else {
				$base = (string) get_option( 'ppa_django_url', 'https://apps.techwithwayne.com/postpress-ai/' );
				if ( '' === trim( $base ) ) {
					$base = 'https://apps.techwithwayne.com/postpress-ai/';
				}
			}
			$base = esc_url_raw( $base );
			$base = untrailingslashit( $base );
			return $base;
		}

		private static function resolve_shared_key() {
			if ( defined( 'PPA_SHARED_KEY' ) && PPA_SHARED_KEY ) {
				return trim( (string) PPA_SHARED_KEY );
			}

			$opt = get_option( 'ppa_shared_key', '' );
			if ( is_string( $opt ) ) {
				$opt = trim( $opt );
				if ( '' !== $opt ) {
					return $opt;
				}
			}

			$filtered = apply_filters( 'ppa_shared_key', '' );
			if ( is_string( $filtered ) ) {
				$filtered = trim( $filtered );
				if ( '' !== $filtered ) {
					return $filtered;
				}
			}

			$lic = self::get_license_key();
			if ( '' !== $lic ) {
				return $lic;
			}

			return '';
		}

		private static function get_license_key() {
			$key = (string) get_option( self::OPT_LICENSE_KEY, '' );
			$key = self::sanitize_license_key( $key );
			return $key;
		}

		public static function handle_test_connectivity() {
			if ( ! current_user_can( self::cap() ) ) {
				wp_die( esc_html__( 'You are not allowed to perform this action.', 'postpress-ai' ) );
			}

			check_admin_referer( 'ppa-test-connectivity' );

			$base = self::get_django_base_url();
			$key  = self::resolve_shared_key();

			if ( '' === $base ) {
				self::log( 'test_connectivity fail: missing base url' );                         // CHANGED:
				self::redirect_with_test_result( 'error', __( 'Missing server configuration. Please contact support.', 'postpress-ai' ) );
			}

			if ( '' === $key ) {
				self::log( 'test_connectivity fail: missing key' );                              // CHANGED:
				self::redirect_with_test_result( 'error', __( 'Please add your License Key first, then click Save.', 'postpress-ai' ) );
			}

			$headers = array(
				'Accept'           => 'application/json; charset=utf-8',
				'User-Agent'       => 'PostPressAI-WordPress/' . ( defined( 'PPA_VERSION' ) ? PPA_VERSION : 'dev' ),
				'X-PPA-Key'        => $key,
				'X-PPA-View'       => 'settings',
				'X-Requested-With' => 'XMLHttpRequest',
			);

			$endpoints = array(
				'version' => trailingslashit( $base ) . 'version/',
				'health'  => trailingslashit( $base ) . 'health/',
			);

			$ok_count = 0;
			$messages = array();

			foreach ( $endpoints as $label => $url ) {
				$response = wp_remote_get(
					$url,
					array(
						'headers' => $headers,
						'timeout' => 15,
					)
				);

				if ( is_wp_error( $response ) ) {
					self::log( 'test_connectivity fail: ' . $label . ' wp_error: ' . $response->get_error_message() ); // CHANGED:
					$messages[] = sprintf(
						__( '%1$s failed: %2$s', 'postpress-ai' ),
						ucfirst( $label ),
						$response->get_error_message()
					);
					continue;
				}

				$code = (int) wp_remote_retrieve_response_code( $response );
				if ( $code >= 200 && $code < 300 ) {
					$ok_count++;
				} else {
					self::log( 'test_connectivity fail: ' . $label . ' http=' . $code );        // CHANGED:
					$messages[] = sprintf(
						__( '%1$s returned HTTP %2$d.', 'postpress-ai' ),
						ucfirst( $label ),
						$code
					);
				}
			}

			if ( 2 === $ok_count ) {
				// CHANGED: no success logs — keep debug.log quiet unless something fails.
				self::redirect_with_test_result(
					'ok',
					__( 'Connected! This site can reach PostPress AI.', 'postpress-ai' )
				);
			}

			$msg = __( 'Not connected yet. Please double-check your License Key.', 'postpress-ai' );
			if ( ! empty( $messages ) ) {
				$msg .= ' ' . implode( ' ', $messages );
			}

			self::log( 'test_connectivity failed' );                                             // CHANGED:
			self::redirect_with_test_result( 'error', $msg );
		}

		public static function handle_license_verify() {
			self::handle_license_action_common( 'verify' );
		}

		public static function handle_license_activate() {
			self::handle_license_action_common( 'activate' );
		}

		public static function handle_license_deactivate() {
			self::handle_license_action_common( 'deactivate' );
		}

		private static function handle_license_action_common( $action ) {
			if ( ! current_user_can( self::cap() ) ) {
				wp_die( esc_html__( 'You are not allowed to perform this action.', 'postpress-ai' ) );
			}

			$action = is_string( $action ) ? $action : '';
			if ( ! in_array( $action, array( 'verify', 'activate', 'deactivate' ), true ) ) {
				wp_die( esc_html__( 'Invalid action.', 'postpress-ai' ) );
			}

			check_admin_referer( 'ppa-license-' . $action );

			$base = self::get_django_base_url();
			$key  = self::resolve_shared_key();
			$lic  = self::get_license_key();

			if ( '' === $base ) {
				self::log( 'license_action fail: missing base url' );                            // CHANGED:
				self::redirect_with_license_result( 'error', __( 'Missing server configuration. Please contact support.', 'postpress-ai' ), array() );
			}

			if ( '' === $lic ) {
				self::log( 'license_action fail: missing license key' );                         // CHANGED:
				self::redirect_with_license_result( 'error', __( 'Please paste your License Key first, then click Save.', 'postpress-ai' ), array() );
			}

			if ( '' === $key ) {
				self::log( 'license_action fail: missing auth key' );                            // CHANGED:
				self::redirect_with_license_result( 'error', __( 'Please paste your License Key first, then click Save.', 'postpress-ai' ), array() );
			}

			$endpoint = trailingslashit( $base ) . 'license/' . $action . '/';

			$headers = array(
				'Accept'           => 'application/json; charset=utf-8',
				'Content-Type'     => 'application/json; charset=utf-8',
				'User-Agent'       => 'PostPressAI-WordPress/' . ( defined( 'PPA_VERSION' ) ? PPA_VERSION : 'dev' ),
				'X-PPA-Key'        => $key,
				'X-PPA-View'       => 'settings_license',
				'X-Requested-With' => 'XMLHttpRequest',
			);

			$payload = array(
				'license_key' => $lic,
				'site_url'    => home_url( '/' ),
			);

			$response = wp_remote_post(
				$endpoint,
				array(
					'headers' => $headers,
					'timeout' => 20,
					'body'    => wp_json_encode( $payload ),
				)
			);

			$result = self::normalize_django_response( $response );
			self::cache_last_license_result( $result );

			// CHANGED: failure-only logging (no success chatter; no secret dumps).
			$http = ( is_array( $result ) && isset( $result['_http_status'] ) ) ? (int) $result['_http_status'] : 0; // CHANGED:
			$ok   = ( is_array( $result ) && isset( $result['ok'] ) && true === $result['ok'] ); // CHANGED:
			if ( ! $ok ) { // CHANGED:
				$err_code = ''; // CHANGED:
				if ( is_array( $result ) && isset( $result['error']['code'] ) ) { // CHANGED:
					$err_code = (string) $result['error']['code']; // CHANGED:
				} elseif ( is_array( $result ) && isset( $result['error']['type'] ) ) { // CHANGED:
					$err_code = (string) $result['error']['type']; // CHANGED:
				} // CHANGED:
				$err_code = trim( $err_code ); // CHANGED:
				self::log( 'license_action failed: action=' . $action . ' http=' . $http . ( '' !== $err_code ? ' code=' . $err_code : '' ) ); // CHANGED:
			} // CHANGED:

			if ( $ok ) {
				if ( 'activate' === $action ) {
					update_option( self::OPT_ACTIVE_SITE, home_url( '/' ), false );
				} elseif ( 'deactivate' === $action ) {
					delete_option( self::OPT_ACTIVE_SITE );
				}
			}

			$notice = self::notice_from_license_result( ucfirst( $action ), $result );
			$status = ( isset( $result['ok'] ) && true === $result['ok'] ) ? 'ok' : 'error';

			self::redirect_with_license_result( $status, $notice, $result );
		}

		private static function normalize_django_response( $response ) {
			if ( is_wp_error( $response ) ) {
				return array(
					'ok'    => false,
					'error' => array(
						'type' => 'wp_http_error',
						'code' => 'request_failed',
						'hint' => $response->get_error_message(),
					),
					'ver'   => 'wp.ppa.v1',
				);
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$body = (string) wp_remote_retrieve_body( $response );

			$json = json_decode( $body, true );
			if ( ! is_array( $json ) ) {
				return array(
					'ok'    => false,
					'error' => array(
						'type'        => 'wp_parse_error',
						'code'        => 'invalid_json',
						'hint'        => 'PostPress AI did not return readable data.',
						'http_status' => $code,
						'body_prefix' => substr( $body, 0, 300 ),
					),
					'ver'   => 'wp.ppa.v1',
				);
			}

			$json['_http_status'] = $code;
			return $json;
		}

		private static function cache_last_license_result( $result ) {
			set_transient( self::TRANSIENT_LAST_LIC, $result, self::LAST_LIC_TTL_SECONDS );
		}

		private static function notice_from_license_result( $label, $result ) {
			if ( is_array( $result ) && isset( $result['ok'] ) && true === $result['ok'] ) {
				if ( 'Verify' === $label ) {
					return __( 'License looks good.', 'postpress-ai' );
				}
				if ( 'Activate' === $label ) {
					return __( 'This site is now activated.', 'postpress-ai' );
				}
				if ( 'Deactivate' === $label ) {
					return __( 'This site is now deactivated.', 'postpress-ai' );
				}
				return __( 'Done.', 'postpress-ai' );
			}

			// CHANGED: Friendlier message for site limit reached (no endpoints changed; UX only).
			if ( self::is_plan_limit_site_limit_reached( $result ) ) {                            // CHANGED:
				return __( 'Plan limit reached: your account has hit its site limit. Upgrade your plan or deactivate another site, then try again.', 'postpress-ai' ); // CHANGED:
			}                                                                                      // CHANGED:

			$code = '';
			if ( is_array( $result ) && isset( $result['error']['code'] ) ) {
				$code = (string) $result['error']['code'];
			} elseif ( is_array( $result ) && isset( $result['error']['type'] ) ) {
				$code = (string) $result['error']['type'];
			}

			if ( '' !== $code ) {
				return sprintf( __( 'Something went wrong (%s).', 'postpress-ai' ), $code );
			}

			return __( 'Something went wrong.', 'postpress-ai' );
		}

		private static function mask_secret( $value ) {
			$value = is_string( $value ) ? trim( $value ) : '';
			if ( '' === $value ) {
				return '(none)';
			}
			$len = strlen( $value );
			if ( $len <= 8 ) {
				return str_repeat( '*', $len );
			}
			return substr( $value, 0, 4 ) . str_repeat( '*', max( 0, $len - 8 ) ) . substr( $value, -4 );
		}

		private static function redirect_with_test_result( $status, $message ) {
			$status  = ( 'ok' === $status ) ? 'ok' : 'error';
			$message = is_string( $message ) ? $message : '';

			$url = add_query_arg(
				array(
					'page'         => 'postpress-ai-settings',
					'ppa_test'     => $status,
					'ppa_test_msg' => rawurlencode( $message ),
				),
				admin_url( 'admin.php' )
			);

			wp_safe_redirect( $url );
			exit;
		}

		private static function redirect_with_license_result( $status, $message, $result ) {
			$status  = ( 'ok' === $status ) ? 'ok' : 'error';
			$message = is_string( $message ) ? $message : '';

			$url = add_query_arg(
				array(
					'page'        => 'postpress-ai-settings',
					'ppa_lic'     => $status,
					'ppa_lic_msg' => rawurlencode( $message ),
				),
				admin_url( 'admin.php' )
			);

			wp_safe_redirect( $url );
			exit;
		}

		public static function render_page() {
			if ( ! current_user_can( self::cap() ) ) {
				wp_die( esc_html__( 'You are not allowed to access this page.', 'postpress-ai' ) );
			}

			$test_status = isset( $_GET['ppa_test'] ) ? sanitize_key( wp_unslash( $_GET['ppa_test'] ) ) : '';
			$test_msg    = isset( $_GET['ppa_test_msg'] ) ? wp_unslash( $_GET['ppa_test_msg'] ) : '';
			if ( is_string( $test_msg ) && '' !== $test_msg ) {
				$test_msg = rawurldecode( $test_msg );
			}

			$lic_status = isset( $_GET['ppa_lic'] ) ? sanitize_key( wp_unslash( $_GET['ppa_lic'] ) ) : '';
			$lic_msg    = isset( $_GET['ppa_lic_msg'] ) ? wp_unslash( $_GET['ppa_lic_msg'] ) : '';
			if ( is_string( $lic_msg ) && '' !== $lic_msg ) {
				$lic_msg = rawurldecode( $lic_msg );
			}

			$last = get_transient( self::TRANSIENT_LAST_LIC );

			$val_license = (string) get_option( self::OPT_LICENSE_KEY, '' );
			$val_license = self::sanitize_license_key( $val_license );

			$has_key            = ( '' !== $val_license );
			$activation_state   = self::derive_activation_state( $last );
			$is_active_here     = ( 'active' === $activation_state );
			$is_inactive_here   = ( 'inactive' === $activation_state );
			$site_limit_reached = self::is_plan_limit_site_limit_reached( $last );               // CHANGED:
			?>
			<div class="wrap ppa-admin ppa-settings">
				<h1><?php esc_html_e( 'PostPress AI Settings', 'postpress-ai' ); ?></h1>

				<div class="ppa-card">
					<?php
					if ( '' !== $test_status && '' !== $test_msg ) {
						self::render_notice( $test_status, $test_msg );
					}
					if ( ! $site_limit_reached && '' !== $lic_status && '' !== $lic_msg ) { // CHANGED:
						self::render_notice( $lic_status, $lic_msg );
					}

					// CHANGED: Persistent, clearer UX for plan limit site cap (based on last cached license response).
					if ( $site_limit_reached ) {                                                    // CHANGED:
						self::render_notice( 'error', __( 'Plan limit reached: your account has hit its site limit. You can’t activate this site until you upgrade your plan or deactivate another site.', 'postpress-ai' ) ); // CHANGED:
					}                                                                                  // CHANGED:
					?>

					<h2 class="title"><?php esc_html_e( 'Setup', 'postpress-ai' ); ?></h2>
					<p class="ppa-help"><?php esc_html_e( 'Paste your license key below, then click Save.', 'postpress-ai' ); ?></p>

					<form method="post" action="options.php">
						<?php settings_fields( 'ppa_settings' ); ?>

						<table class="form-table" role="presentation">
							<tbody>
								<tr>
									<th scope="row">
										<label for="<?php echo esc_attr( self::OPT_LICENSE_KEY ); ?>"><?php esc_html_e( 'License Key', 'postpress-ai' ); ?></label>
									</th>
									<td>
										<input type="text"
										       name="<?php echo esc_attr( self::OPT_LICENSE_KEY ); ?>"
										       id="<?php echo esc_attr( self::OPT_LICENSE_KEY ); ?>"
										       class="regular-text"
										       value="<?php echo esc_attr( $val_license ); ?>"
										       autocomplete="off"
										       placeholder="ppa_live_***************" />
										<p class="description">
											<?php esc_html_e( 'Saved key:', 'postpress-ai' ); ?>
											<code><?php echo esc_html( self::mask_secret( $val_license ) ); ?></code>
										</p>
									</td>
								</tr>
							</tbody>
						</table>

						<?php submit_button( __( 'Save', 'postpress-ai' ) ); ?>
					</form>
				</div>

				<div class="ppa-card">
					<h2 class="title"><?php esc_html_e( 'License Actions', 'postpress-ai' ); ?></h2>
					<p class="ppa-help">
						<?php esc_html_e( 'Use these buttons to check or activate this site.', 'postpress-ai' ); ?>
					</p>

					<?php
					if ( $is_active_here ) :
						?>
						<p class="ppa-help"><strong><?php esc_html_e( 'Status:', 'postpress-ai' ); ?></strong> <span class="ppa-badge ppa-badge--active"><?php esc_html_e( 'Active on this site', 'postpress-ai' ); ?></span></p>
						<?php
					elseif ( $is_inactive_here ) :
						?>
						<p class="ppa-help"><strong><?php esc_html_e( 'Status:', 'postpress-ai' ); ?></strong> <span class="ppa-badge ppa-badge--inactive"><?php esc_html_e( 'Not active', 'postpress-ai' ); ?></span></p>
						<?php
					else :
						?>
						<p class="ppa-help"><strong><?php esc_html_e( 'Status:', 'postpress-ai' ); ?></strong> <span class="ppa-badge ppa-badge--unknown"><?php esc_html_e( 'Unknown (run Check License)', 'postpress-ai' ); ?></span></p>
						<?php
					endif;
					?>

					<div class="ppa-actions-row">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ppa-action-form">
							<?php wp_nonce_field( 'ppa-license-verify' ); ?>
							<input type="hidden" name="action" value="ppa_license_verify" />
							<?php
							$disable_verify = ( ! $has_key );                                          // CHANGED:
							$attrs_verify   = $disable_verify ? array( 'disabled' => 'disabled' ) : array(); // CHANGED:
							submit_button( __( 'Check License', 'postpress-ai' ), 'secondary', 'ppa_license_verify_btn', false, $attrs_verify ); // CHANGED:
							?>
							<?php if ( $disable_verify ) : ?>
								<p class="description ppa-inline-help"><?php esc_html_e( 'Save your license key first.', 'postpress-ai' ); ?></p>
							<?php endif; ?>
						</form>

						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ppa-action-form">
							<?php wp_nonce_field( 'ppa-license-activate' ); ?>
							<input type="hidden" name="action" value="ppa_license_activate" />
							<?php
							$disable_activate = ( ! $has_key ) || $is_active_here || $site_limit_reached; // CHANGED:
							$attrs            = $disable_activate ? array( 'disabled' => 'disabled' ) : array();
							submit_button( __( 'Activate This Site', 'postpress-ai' ), 'primary', 'ppa_license_activate_btn', false, $attrs );
							?>
							<?php if ( ! $has_key ) : ?>
								<p class="description ppa-inline-help"><?php esc_html_e( 'Save your license key first. Then you can activate.', 'postpress-ai' ); ?></p>
							<?php elseif ( $is_active_here ) : ?>
								<p class="description ppa-inline-help"><?php esc_html_e( 'This site is already active.', 'postpress-ai' ); ?></p>
							<?php elseif ( $site_limit_reached ) : ?>
								<p class="description ppa-inline-help"><?php esc_html_e( 'Plan limit reached. Upgrade your plan or deactivate another site, then try again.', 'postpress-ai' ); ?></p>
							<?php endif; ?>
						</form>

						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ppa-action-form">
							<?php wp_nonce_field( 'ppa-license-deactivate' ); ?>
							<input type="hidden" name="action" value="ppa_license_deactivate" />
							<?php
							// CHANGED: Deactivate is disabled ONLY when we *know* it's not active.
							// If status is Unknown, keep enabled so user can still attempt a clean deactivation.
							$disable_deactivate = ( ! $has_key ) || $is_inactive_here;                    // CHANGED:
							$attrs_deactivate   = $disable_deactivate ? array( 'disabled' => 'disabled' ) : array(); // CHANGED:
							submit_button( __( 'Deactivate This Site', 'postpress-ai' ), 'delete', 'ppa_license_deactivate_btn', false, $attrs_deactivate ); // CHANGED:
							?>
							<?php if ( ! $has_key ) : ?>
								<p class="description ppa-inline-help"><?php esc_html_e( 'Save your license key first.', 'postpress-ai' ); ?></p>
							<?php elseif ( $is_inactive_here ) : ?>
								<p class="description ppa-inline-help"><?php esc_html_e( 'This site is not active right now.', 'postpress-ai' ); ?></p>
							<?php endif; ?>
						</form>
					</div>

					<h3><?php esc_html_e( 'Last response (optional)', 'postpress-ai' ); ?></h3>
					<p class="ppa-help"><?php esc_html_e( 'Only for troubleshooting if something fails.', 'postpress-ai' ); ?></p>
					<textarea readonly class="ppa-debug-box"><?php
						echo esc_textarea( $last ? wp_json_encode( $last, JSON_PRETTY_PRINT ) : 'No recent result yet.' );
					?></textarea>
				</div>

				<div class="ppa-card">
					<h2 class="title"><?php esc_html_e( 'Test Connection', 'postpress-ai' ); ?></h2>
					<p class="ppa-help">
						<?php esc_html_e( 'Click to make sure this site can reach PostPress AI.', 'postpress-ai' ); ?>
					</p>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'ppa-test-connectivity' ); ?>
						<input type="hidden" name="action" value="ppa_test_connectivity" />
						<?php
						$disable_test = ( ! $has_key );                                                // CHANGED:
						$attrs_test   = $disable_test ? array( 'disabled' => 'disabled' ) : array();  // CHANGED:
						submit_button( __( 'Test Connection', 'postpress-ai' ), 'secondary', 'ppa_test_connectivity_btn', false, $attrs_test ); // CHANGED:
						?>
						<?php if ( $disable_test ) : ?>
							<p class="description ppa-inline-help"><?php esc_html_e( 'Save your license key first.', 'postpress-ai' ); ?></p>
						<?php endif; ?>
					</form>
				</div>
			</div>
			<?php
		}
	}

	// Boot hooks (safe/idempotent).
	PPA_Admin_Settings::init();

	// CHANGED: Render ONLY on the real settings screen (admin.php). Never render on admin-post (prevents output/redirect issues).
	if ( is_admin() ) { // CHANGED:
		global $pagenow; // CHANGED:
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // CHANGED:
		if ( 'admin.php' === $pagenow && 'postpress-ai-settings' === $page ) { // CHANGED:
			PPA_Admin_Settings::render_page(); // CHANGED:
		} // CHANGED:
	} // CHANGED:

}
