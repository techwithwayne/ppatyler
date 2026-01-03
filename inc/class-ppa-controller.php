<?php
/**
 * PPA Controller — Server-side AJAX proxy for PostPress AI
 *
 * LOCATION
 * --------
 * /wp-content/plugins/postpress-ai/inc/class-ppa-controller.php
 *
 * CHANGE LOG
 * 2026-01-03 • REFACTOR: Modularize endpoint flow (preflight + remote call + parse helpers). No contract changes. // CHANGED:
 *
 * 2026-01-03 • FIX: Gate truth normalization (accept array OR string transient shapes).                        // CHANGED:
 * 2026-01-03 • FIX: Throttle gate-block logging (failure-only, no spam).                                       // CHANGED:
 * 2026-01-03 • ADD: Sticky proxy capability flag ppa_proxy_license_header_ok (1/0).                            // CHANGED:
 * 2026-01-03 • FIX: Gate trusts persisted options (ppa_license_state + ppa_license_active_site) to avoid false unknown. // CHANGED:
 * 2026-01-03 • FIX: If shared key missing and license-key proxy auth is rejected (401/403), fail cleanly and stop guessing. // CHANGED:
 * 2026-01-03 • CLEAN: Log only failures (no non-json success logging; remove store success-only logs).         // CHANGED:
 *
 * 2026-01-03 • FIX: Enforce license gating using server-truth cache + rate-limited verify fallback.            // CHANGED:
 * 2026-01-03 • FIX: Block preview/store/generate unless license is ACTIVE for this site (no guesswork).       // CHANGED:
 * 2026-01-03 • HARDEN: Proxy auth defaults to shared-key (known-good). License-key-as-header requires explicit enable. // CHANGED:
 * 2026-01-03 • CLEAN: No secrets logged; blocked responses are clean + actionable.                            // CHANGED:
 *
 * 2026-01-03 • FIX: Proxy auth FALLS BACK to License Key when Shared Key is missing (customer domains).       // CHANGED:
 * 2026-01-03 • FIX: Normalize site_url comparisons (ignore scheme + optional www) to prevent false mismatch.  // CHANGED:
 * 2026-01-03 • FIX: Interpret activation.activated safely when backend returns string/int values.             // CHANGED:
 * 2026-01-03 • CLEAN: Remove success-only preview/debug_headers logging (failures only).                      // CHANGED:
 *
 * 2026-01-03 • FIX: Ignore cached gate "unknown" so opt:fresh can immediately unblock after verify/activate.   // CHANGED:
 *
 * 2026-01-02 • CLEAN: Remove success-only debug.log spam for generate/store django_url + store payload_source. // CHANGED:
 *
 * 2025-12-30 • FIX: Normalize Django base URL (auto-prepend https:// when scheme missing + hard fallback)
 *              to prevent wp_remote_post “A valid URL was not provided.”                                   // CHANGED:
 * 2025-12-30 • FIX: Replace str_starts_with() usage for PHP 7.4 compatibility (use strpos===0).            // CHANGED:
 * 2025-12-30 • DIAG: Add minimal debug.log lines for resolved Django URL per endpoint (no secrets).        // CHANGED:
 * 2025-12-30 • FIX: Store payload 400/invalid_json: robust read_json_body() fallback to $_POST fields,
 *              guaranteeing we forward a JSON object to Django even when browser sends multipart/form-data. // CHANGED:
 * 2025-12-30 • DIAG: Log store payload source (php_input vs post_fields) when not JSON.                   // CHANGED:
 *
 * 2025-11-19 • Expand shared key resolution (constant/option/filter) for wp.org readiness.                // CHANGED:
 * 2025-11-16 • Add generate proxy (ppa_generate) to Django /generate/ for AssistantRunner-backed content.  // CHANGED:
 * 2025-11-16 • Add mode hint support to store proxy (draft/publish/update + update support).              // CHANGED:
 * 2025-11-15 • Add debug headers AJAX proxy (ppa_debug_headers) to call Django /debug/headers/ and surface info
 *              to the Testbed UI; reuse shared key + outgoing header filters with GET semantics.           // CHANGED:
 * 2025-11-13 • Tighten Django parity: pass X-PPA-View and nonce headers through, force X-Requested-With,
 *              normalize WP-side error payloads to {ok,error,code,meta}, and set endpoint earlier for logs. // CHANGED:
 * 2025-11-11 • Preview: guarantee result.html on the WP proxy by deriving from content/text/brief if missing.
 *              - New helpers: looks_like_html(), text_to_html(), derive_preview_html().                    // CHANGED:
 *              - No secrets logged; response shape preserved.                                              // CHANGED:
 * 2025-11-10 • Add shared-key guard (server_misconfig 500), Accept header, and minimal
 *              endpoint logging without secrets or payloads. Keep response shape stable.                   // CHANGED:
 * 2025-11-09 • Security & robustness: POST-only, nonce check from headers, constants override,
 *              URL/headers sanitization, filters for URL/headers/args, safer JSON handling.                // CHANGED:
 * 2025-11-08 • Post-process /store/: create local WP post (draft/publish) and inject id/permalink/edit_link.
 *              Only create locally when Django indicates success (HTTP 2xx and ok).                        // CHANGED:
 *              Set post_author to current user; avoid reinjecting if already present.                      // CHANGED:
 *              Defensive JSON handling across payload/result.                                              // CHANGED:
 * 2025-10-12 • Initial proxy endpoints to Django (preview/store).
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'PPA_Controller' ) ) {

	class PPA_Controller {

		/**
		 * Current endpoint label used by filters/logging (preview|store|debug_headers|generate).
		 *
		 * @var string
		 */
		private static $endpoint = 'preview'; // CHANGED:

		/**
		 * Track which auth mode was chosen for the current request (shared|license). // CHANGED:
		 *
		 * @var string
		 */
		private static $proxy_auth_mode = 'shared'; // CHANGED:

		/**
		 * Register AJAX hooks (admin-only).
		 */
		public static function init() {
			add_action( 'wp_ajax_ppa_preview',        array( __CLASS__, 'ajax_preview' ) );
			add_action( 'wp_ajax_ppa_store',          array( __CLASS__, 'ajax_store' ) );
			add_action( 'wp_ajax_ppa_debug_headers',  array( __CLASS__, 'ajax_debug_headers' ) ); // CHANGED:
			add_action( 'wp_ajax_ppa_generate',       array( __CLASS__, 'ajax_generate' ) );      // CHANGED:
		}

		/* ─────────────────────────────────────────────────────────────────────
		 * Internals (errors + logging)
		 * ──────────────────────────────────────────────────────────────────── */

		/**
		 * Build a Django-like error payload for WP-side failures.
		 *
		 * NOTE: This lives inside the "data" wrapper when using wp_send_json_error.
		 *
		 * @param string $error_code
		 * @param int    $http_status
		 * @param array  $meta_extra
		 * @return array
		 */
		private static function error_payload( $error_code, $http_status, $meta_extra = array() ) { // CHANGED:
			$http_status = (int) $http_status;
			$meta_base   = array(
				'source'   => 'wp_proxy',
				'endpoint' => self::$endpoint,
			);
			if ( ! is_array( $meta_extra ) ) {
				$meta_extra = array();
			}
			return array(
				'ok'    => false,
				'error' => (string) $error_code,
				'code'  => $http_status,
				'meta'  => array_merge( $meta_base, $meta_extra ),
			);
		}

		/**
		 * Failure-only throttled logger (prevents repeated spam lines).           // CHANGED:
		 *
		 * @param string $key
		 * @param int    $ttl_seconds
		 * @param string $msg
		 * @return void
		 */
		private static function log_throttled( $key, $ttl_seconds, $msg ) { // CHANGED:
			$tkey = 'ppa_log_throttle_' . sanitize_key( (string) $key ); // CHANGED:
			if ( get_transient( $tkey ) ) { // CHANGED:
				return; // CHANGED:
			} // CHANGED:
			set_transient( $tkey, 1, max( 1, (int) $ttl_seconds ) ); // CHANGED:
			error_log( (string) $msg ); // CHANGED:
		} // CHANGED:

		/**
		 * Helper: only log non-json when request failed (non-2xx).                 // CHANGED:
		 *
		 * @param string $label
		 * @param int    $code
		 * @return void
		 */
		private static function maybe_log_non_json_failure( $label, $code ) { // CHANGED:
			$code  = (int) $code; // CHANGED:
			$label = sanitize_key( (string) $label ); // CHANGED:
			if ( $code < 200 || $code >= 300 ) { // CHANGED:
				error_log( 'PPA: ' . $label . ' http ' . $code . ' (non-json)' ); // CHANGED:
			} // CHANGED:
		} // CHANGED:

		/**
		 * Helper: only log http line when request failed (non-2xx).                // CHANGED:
		 *
		 * @param string $label
		 * @param int    $code
		 * @return void
		 */
		private static function maybe_log_http_failure( $label, $code ) { // CHANGED:
			$code  = (int) $code; // CHANGED:
			$label = sanitize_key( (string) $label ); // CHANGED:
			if ( $code < 200 || $code >= 300 ) { // CHANGED:
				error_log( 'PPA: ' . $label . ' http ' . $code ); // CHANGED:
			} // CHANGED:
		} // CHANGED:

		/* ─────────────────────────────────────────────────────────────────────
		 * Internals (request preflight)
		 * ──────────────────────────────────────────────────────────────────── */

		/**
		 * Enforce POST method; send 405 if not.
		 */
		private static function must_post() { // CHANGED:
			$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : '';
			if ( 'POST' !== $method ) {
				wp_send_json_error(
					self::error_payload(
						'method_not_allowed',
						405,
						array( 'reason' => 'non_post' )
					),
					405
				);
			}
		}

		/**
		 * Verify nonce from headers (X-PPA-Nonce or X-WP-Nonce); 403 if invalid/missing.
		 */
		private static function verify_nonce_or_forbid() { // CHANGED:
			$headers = function_exists( 'getallheaders' ) ? (array) getallheaders() : array();
			$nonce   = '';
			if ( isset( $_SERVER['HTTP_X_PPA_NONCE'] ) ) {
				$nonce = (string) $_SERVER['HTTP_X_PPA_NONCE'];
			} elseif ( isset( $_SERVER['HTTP_X_WP_NONCE'] ) ) {
				$nonce = (string) $_SERVER['HTTP_X_WP_NONCE'];
			} elseif ( isset( $headers['X-PPA-Nonce'] ) ) {
				$nonce = (string) $headers['X-PPA-Nonce'];
			} elseif ( isset( $headers['X-WP-Nonce'] ) ) {
				$nonce = (string) $headers['X-WP-Nonce'];
			}
			if ( ! $nonce || ! wp_verify_nonce( $nonce, 'ppa-admin' ) ) {
				wp_send_json_error(
					self::error_payload(
						'forbidden',
						403,
						array( 'reason' => 'nonce_invalid_or_missing' )
					),
					403
				);
			}
		}

		/**
		 * Preflight for any endpoint: capability, POST-only, nonce, optional license gate. // CHANGED:
		 *
		 * @param string $endpoint
		 * @param bool   $enforce_gate
		 * @return void
		 */
		private static function preflight_or_die( $endpoint, $enforce_gate ) { // CHANGED:
			self::$endpoint = sanitize_key( (string) $endpoint ); // CHANGED:
			if ( self::$endpoint === '' ) { self::$endpoint = 'preview'; } // CHANGED:

			if ( ! current_user_can( 'edit_posts' ) ) {
				wp_send_json_error( self::error_payload( 'forbidden', 403, array( 'reason' => 'capability_missing' ) ), 403 );
			}

			self::must_post();
			self::verify_nonce_or_forbid();

			if ( $enforce_gate ) { // CHANGED:
				self::enforce_license_gate_or_block(); // CHANGED:
			} // CHANGED:
		} // CHANGED:

		/* ─────────────────────────────────────────────────────────────────────
		 * Internals (IO + remote calls)
		 * ──────────────────────────────────────────────────────────────────── */

		/**
		 * Read incoming request body as JSON (robust).
		 *
		 * @return array{raw:string,json:array,source:string,content_type:string}
		 */
		private static function read_json_body() { // CHANGED:
			$content_type = isset( $_SERVER['CONTENT_TYPE'] ) ? (string) $_SERVER['CONTENT_TYPE'] : ''; // CHANGED:
			$source       = 'php_input'; // CHANGED:

			$raw_in = file_get_contents( 'php://input' );
			$raw    = is_string( $raw_in ) ? (string) $raw_in : '';
			$raw    = (string) $raw;

			// 1) Try raw input as JSON first.
			$assoc = json_decode( $raw, true );
			if ( JSON_ERROR_NONE === json_last_error() && is_array( $assoc ) ) {
				return array(
					'raw'          => ( $raw !== '' ? $raw : '{}' ),
					'json'          => $assoc,
					'source'       => $source,
					'content_type' => $content_type,
				);
			}

			// 2) Fallback: some clients send a single JSON blob field (payload/data/json/body).
			$candidates = array( 'payload', 'data', 'json', 'body' ); // CHANGED:
			foreach ( $candidates as $k ) { // CHANGED:
				if ( isset( $_POST[ $k ] ) && is_string( $_POST[ $k ] ) ) { // CHANGED:
					$maybe = wp_unslash( (string) $_POST[ $k ] ); // CHANGED:
					$maybe = (string) $maybe; // CHANGED:
					if ( '' !== trim( $maybe ) ) { // CHANGED:
						$assoc2 = json_decode( $maybe, true ); // CHANGED:
						if ( JSON_ERROR_NONE === json_last_error() && is_array( $assoc2 ) ) { // CHANGED:
							return array( // CHANGED:
								'raw'          => $maybe, // CHANGED:
								'json'          => $assoc2, // CHANGED:
								'source'       => 'post_' . $k, // CHANGED:
								'content_type' => $content_type, // CHANGED:
							); // CHANGED:
						} // CHANGED:
					} // CHANGED:
				} // CHANGED:
			}

			// 3) Fallback: build an object from $_POST fields (common with FormData/multipart).
			if ( ! empty( $_POST ) && is_array( $_POST ) ) { // CHANGED:
				$assoc3 = wp_unslash( $_POST ); // CHANGED:
				if ( ! is_array( $assoc3 ) ) { // CHANGED:
					$assoc3 = array(); // CHANGED:
				} // CHANGED:

				// Strip WP/AJAX noise keys so Django only sees the real payload. // CHANGED:
				foreach ( array( 'action', '_ajax_nonce', '_wpnonce', '_wp_http_referer', 'security', 'nonce' ) as $noise ) { // CHANGED:
					if ( isset( $assoc3[ $noise ] ) ) { // CHANGED:
						unset( $assoc3[ $noise ] ); // CHANGED:
					} // CHANGED:
				} // CHANGED:

				$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $assoc3 ) : json_encode( $assoc3 ); // CHANGED:
				$encoded = is_string( $encoded ) ? $encoded : ''; // CHANGED:
				if ( '' === trim( $encoded ) ) { // CHANGED:
					$encoded = '{}'; // CHANGED:
				} // CHANGED:

				return array( // CHANGED:
					'raw'          => $encoded, // CHANGED:
					'json'          => $assoc3, // CHANGED:
					'source'       => 'post_fields', // CHANGED:
					'content_type' => $content_type, // CHANGED:
				); // CHANGED:
			}

			// 4) Total fallback: empty object (never forward empty string).
			return array(
				'raw'          => '{}', // CHANGED:
				'json'          => array(),
				'source'       => 'empty', // CHANGED:
				'content_type' => $content_type,
			);
		}

		/**
		 * Helper: die with request_failed payload when wp_remote_* returns WP_Error. // CHANGED:
		 *
		 * @param string   $label
		 * @param \WP_Error $err
		 * @return void
		 */
		private static function wp_error_or_die( $label, $err ) { // CHANGED:
			$label = sanitize_key( (string) $label ); // CHANGED:
			error_log( 'PPA: ' . $label . ' request_failed' ); // CHANGED: failure-only
			wp_send_json_error( // CHANGED:
				self::error_payload( // CHANGED:
					'request_failed', // CHANGED:
					500, // CHANGED:
					array( 'detail' => is_object( $err ) ? $err->get_error_message() : '' ) // CHANGED:
				), // CHANGED:
				500 // CHANGED:
			); // CHANGED:
		} // CHANGED:

		/**
		 * Helper: perform a remote call and parse JSON (but do NOT send).          // CHANGED:
		 *
		 * @param string $method 'POST'|'GET'
		 * @param string $url
		 * @param array  $args
		 * @return array{is_error:bool,code:int,body:string,is_json:bool,json:array}
		 */
		private static function remote_call_parsed( $method, $url, $args ) { // CHANGED:
			$method = strtoupper( (string) $method ); // CHANGED:
			$url    = (string) $url; // CHANGED:
			$args   = is_array( $args ) ? $args : array(); // CHANGED:

			$resp = ( $method === 'GET' ) ? wp_remote_get( $url, $args ) : wp_remote_post( $url, $args ); // CHANGED:
			if ( is_wp_error( $resp ) ) { // CHANGED:
				return array( // CHANGED:
					'is_error' => true, // CHANGED:
					'code'     => 0, // CHANGED:
					'body'     => '', // CHANGED:
					'is_json'  => false, // CHANGED:
					'json'     => array(), // CHANGED:
					'error'    => $resp, // CHANGED:
				); // CHANGED:
			} // CHANGED:

			$code = (int) wp_remote_retrieve_response_code( $resp ); // CHANGED:
			$body = (string) wp_remote_retrieve_body( $resp ); // CHANGED:

			$json = json_decode( $body, true ); // CHANGED:
			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $json ) ) { // CHANGED:
				return array( // CHANGED:
					'is_error' => false, // CHANGED:
					'code'     => $code, // CHANGED:
					'body'     => $body, // CHANGED:
					'is_json'  => false, // CHANGED:
					'json'     => array(), // CHANGED:
				); // CHANGED:
			} // CHANGED:

			return array( // CHANGED:
				'is_error' => false, // CHANGED:
				'code'     => $code, // CHANGED:
				'body'     => $body, // CHANGED:
				'is_json'  => true, // CHANGED:
				'json'     => $json, // CHANGED:
			); // CHANGED:
		} // CHANGED:

		/* ─────────────────────────────────────────────────────────────────────
		 * Internals (Django URL + auth + license gate)
		 * ──────────────────────────────────────────────────────────────────── */

		/**
		 * CHANGED: Normalize a Django base URL into a safe, https-schemed, no-trailing-slash URL.
		 *
		 * @param string $base
		 * @return string
		 */
		private static function normalize_django_base( $base ) { // CHANGED:
			$base = trim( (string) $base );
			if ( '' === $base ) { return ''; }

			if ( 0 === strpos( $base, '//' ) ) {
				$base = 'https:' . $base;
			} elseif ( ! preg_match( '~^https?://~i', $base ) ) {
				$base = 'https://' . ltrim( $base, '/' );
			}

			$base = untrailingslashit( esc_url_raw( $base ) );
			return is_string( $base ) ? $base : '';
		}

		/**
		 * Resolve Django base URL with constant/option + filter; sanitized, no trailing slash.
		 *
		 * @return string
		 */
		private static function django_base() { // CHANGED:
			$base_raw = '';
			if ( defined( 'PPA_DJANGO_URL' ) && PPA_DJANGO_URL ) {
				$base_raw = (string) PPA_DJANGO_URL;
			} else {
				$base_raw = (string) get_option( 'ppa_django_url', 'https://apps.techwithwayne.com/postpress-ai/' );
			}

			$base = self::normalize_django_base( $base_raw );
			if ( '' === $base ) {
				$base = self::normalize_django_base( 'https://apps.techwithwayne.com/postpress-ai/' );
			}

			$base = (string) apply_filters( 'ppa_django_base_url', $base );

			$base2 = self::normalize_django_base( $base );
			if ( '' === $base2 ) {
				$base2 = self::normalize_django_base( 'https://apps.techwithwayne.com/postpress-ai/' );
				error_log( 'PPA: django_base invalid after filter; fallback applied' );
			}

			return $base2;
		}

		/**
		 * Resolve license key from option(s) (customer primary).
		 *
		 * @return string
		 */
		private static function license_key() { // CHANGED:
			// Primary known key.
			$k = get_option( 'ppa_license_key', '' );
			if ( is_string( $k ) ) {
				$k = trim( $k );
				if ( '' !== $k ) { return $k; }
			}

			// Compatibility fallbacks (older naming).
			foreach ( array( 'ppa_activation_key', 'ppa_key', 'postpress_ai_license_key' ) as $opt_name ) { // CHANGED:
				$v = get_option( $opt_name, '' ); // CHANGED:
				if ( is_string( $v ) ) { // CHANGED:
					$v = trim( $v ); // CHANGED:
					if ( '' !== $v ) { return $v; } // CHANGED:
				} // CHANGED:
			}

			// Legacy array option read-only fallback (do not re-add it; just read if present).
			$settings = get_option( 'ppa_settings', null ); // CHANGED:
			if ( is_array( $settings ) ) { // CHANGED:
				foreach ( array( 'license_key', 'ppa_license_key', 'ppa_activation_key' ) as $sk ) { // CHANGED:
					if ( isset( $settings[ $sk ] ) && is_string( $settings[ $sk ] ) ) { // CHANGED:
						$v = trim( (string) $settings[ $sk ] ); // CHANGED:
						if ( '' !== $v ) { return $v; } // CHANGED:
					} // CHANGED:
				} // CHANGED:
			} // CHANGED:

			return '';
		}

		/**
		 * Resolve shared key from constant, option, or external filter. Never echo/log this.
		 *
		 * @return string
		 */
		private static function shared_key() { // CHANGED:
			if ( defined( 'PPA_SHARED_KEY' ) && PPA_SHARED_KEY ) {
				return trim( (string) PPA_SHARED_KEY );
			}

			$opt = get_option( 'ppa_shared_key', '' );
			if ( is_string( $opt ) ) {
				$opt = trim( $opt );
				if ( '' !== $opt ) { return $opt; }
			}

			$filtered = apply_filters( 'ppa_shared_key', '' );
			if ( is_string( $filtered ) ) {
				$filtered = trim( $filtered );
				if ( '' !== $filtered ) { return $filtered; }
			}

			return '';
		}

		/**
		 * Sticky proxy capability: does Django accept LICENSE KEY as X-PPA-Key on /preview/ /generate/ /store/? // CHANGED:
		 *
		 * @return string ''|'1'|'0'
		 */
		private static function proxy_license_header_ok() { // CHANGED:
			$v = get_option( 'ppa_proxy_license_header_ok', '' ); // CHANGED:
			if ( is_string( $v ) ) { // CHANGED:
				$v = trim( $v ); // CHANGED:
				if ( $v === '1' || $v === '0' ) { return $v; } // CHANGED:
			} // CHANGED:
			return ''; // CHANGED:
		} // CHANGED:

		/**
		 * Update sticky proxy capability flag (no autoload bloat).                 // CHANGED:
		 *
		 * @param string $v '1' or '0'
		 * @return void
		 */
		private static function set_proxy_license_header_ok( $v ) { // CHANGED:
			$v = ( $v === '1' ) ? '1' : '0'; // CHANGED:
			if ( get_option( 'ppa_proxy_license_header_ok', null ) === null ) { // CHANGED:
				add_option( 'ppa_proxy_license_header_ok', $v, '', false ); // CHANGED:
			} else { // CHANGED:
				update_option( 'ppa_proxy_license_header_ok', $v, false ); // CHANGED:
			} // CHANGED:
		} // CHANGED:

		/**
		 * Decide whether the PROXY endpoints should prefer license key header even if shared exists.
		 *
		 * @return bool
		 */
		private static function proxy_accepts_license_key_header() { // CHANGED:
			$flag = false; // CHANGED:
			if ( defined( 'PPA_PROXY_ACCEPTS_LICENSE_KEY_HEADER' ) ) { // CHANGED:
				$flag = (bool) PPA_PROXY_ACCEPTS_LICENSE_KEY_HEADER; // CHANGED:
			} // CHANGED:
			$flag = (bool) apply_filters( 'ppa_proxy_accepts_license_key_header', $flag, self::$endpoint ); // CHANGED:
			return (bool) $flag; // CHANGED:
		}

		/**
		 * Decide whether a VERIFY probe using license key as X-PPA-Key is allowed.
		 *
		 * @return bool
		 */
		private static function verify_probe_license_key_header_allowed() { // CHANGED:
			$flag = true; // CHANGED: probing is allowed by default (rate-limited)
			if ( defined( 'PPA_VERIFY_PROBE_LICENSE_KEY_HEADER' ) ) { // CHANGED:
				$flag = (bool) PPA_VERIFY_PROBE_LICENSE_KEY_HEADER; // CHANGED:
			} // CHANGED:
			$flag = (bool) apply_filters( 'ppa_verify_probe_license_key_header', $flag ); // CHANGED:
			return (bool) $flag; // CHANGED:
		}

		/**
		 * Resolve the auth token for outbound PROXY calls (preview/store/generate/debug).
		 *
		 * RULES:
		 * - Prefer shared key when present (known-good).
		 * - If shared missing: try license key ONLY if we haven't learned it's rejected.   // CHANGED:
		 * - If learned rejected (ppa_proxy_license_header_ok=0): block cleanly.            // CHANGED:
		 *
		 * @return string
		 */
		private static function proxy_auth_key_or_500() { // CHANGED:
			$license = self::license_key(); // CHANGED:
			$shared  = self::shared_key();  // CHANGED:

			// Explicit preference: allow forcing license-key header even if shared exists.
			if ( self::proxy_accepts_license_key_header() && '' !== $license ) { // CHANGED:
				self::$proxy_auth_mode = 'license'; // CHANGED:
				return $license; // CHANGED:
			} // CHANGED:

			// Default: use shared key when present.
			if ( '' !== $shared ) { // CHANGED:
				self::$proxy_auth_mode = 'shared'; // CHANGED:
				return $shared; // CHANGED:
			} // CHANGED:

			// Shared missing: customer domains. Use license key unless we learned it fails.
			if ( '' !== $license ) { // CHANGED:
				self::$proxy_auth_mode = 'license'; // CHANGED:

				$known = self::proxy_license_header_ok(); // CHANGED:
				if ( $known === '0' ) { // CHANGED:
					// Fail cleanly and do not hammer Django repeatedly.
					wp_send_json_error( // CHANGED:
						self::error_payload( // CHANGED:
							'proxy_auth_unsupported', // CHANGED:
							403, // CHANGED:
							array( // CHANGED:
								'reason'  => 'license_header_rejected', // CHANGED:
								'message' => 'Backend rejected the license key for content endpoints (/preview/, /generate/, /store/). This customer site needs a shared key OR the backend must be updated to accept license-key proxy auth.', // CHANGED:
							) // CHANGED:
						), // CHANGED:
						403 // CHANGED:
					); // CHANGED:
					return ''; // unreachable // CHANGED:
				} // CHANGED:

				return $license; // CHANGED:
			} // CHANGED:

			error_log( 'PPA: server_misconfig (missing proxy auth key)' ); // CHANGED: failure-only, no secrets
			wp_send_json_error( // CHANGED:
				self::error_payload( // CHANGED:
					'server_misconfig', // CHANGED:
					500, // CHANGED:
					array( 'reason' => 'auth_key_missing' ) // CHANGED:
				), // CHANGED:
				500 // CHANGED:
			); // CHANGED:
			return ''; // unreachable // CHANGED:
		}

		/**
		 * If we used license-key proxy auth and Django rejects it, learn + fail cleanly. // CHANGED:
		 *
		 * @param int   $http_code
		 * @param array $json
		 * @return void
		 */
		private static function learn_or_block_license_proxy_auth( $http_code, $json ) { // CHANGED:
			if ( self::$proxy_auth_mode !== 'license' ) { // CHANGED:
				return; // CHANGED:
			} // CHANGED:

			$http_code = (int) $http_code; // CHANGED:

			if ( $http_code >= 200 && $http_code < 300 ) { // CHANGED:
				self::set_proxy_license_header_ok( '1' ); // CHANGED:
				return; // CHANGED:
			} // CHANGED:

			if ( $http_code === 401 || $http_code === 403 ) { // CHANGED:
				self::set_proxy_license_header_ok( '0' ); // CHANGED:

				$backend_msg = ''; // CHANGED:
				if ( is_array( $json ) ) { // CHANGED:
					// Common backend error shapes:
					// {"ok":false,"error":{"type":"forbidden","message":"invalid authentication key"}} // CHANGED:
					if ( isset( $json['error']['message'] ) && is_string( $json['error']['message'] ) ) { // CHANGED:
						$backend_msg = (string) $json['error']['message']; // CHANGED:
					} elseif ( isset( $json['message'] ) && is_string( $json['message'] ) ) { // CHANGED:
						$backend_msg = (string) $json['message']; // CHANGED:
					} // CHANGED:
				} // CHANGED:

				wp_send_json_error( // CHANGED:
					self::error_payload( // CHANGED:
						'proxy_auth_unsupported', // CHANGED:
						403, // CHANGED:
						array( // CHANGED:
							'reason'          => 'license_header_rejected', // CHANGED:
							'backend_message' => $backend_msg, // CHANGED:
							'message'         => 'Backend rejected the license key for content endpoints (/preview/, /generate/, /store/). This customer site needs a shared key OR the backend must be updated to accept license-key proxy auth.', // CHANGED:
						) // CHANGED:
					), // CHANGED:
					403 // CHANGED:
				); // CHANGED:
			} // CHANGED:
		} // CHANGED:

		/**
		 * Normalize site_url strings for safe comparison.
		 *
		 * @param string $u
		 * @return string
		 */
		private static function normalize_site_url( $u ) { // CHANGED:
			$u = trim( (string) $u ); // CHANGED:
			if ( '' === $u ) { return ''; } // CHANGED:

			$parts = wp_parse_url( $u ); // CHANGED:
			if ( ! is_array( $parts ) ) { // CHANGED:
				$u = untrailingslashit( $u ); // CHANGED:
				$u = preg_replace( '~^https?://~i', '', $u ); // CHANGED:
				$u = preg_replace( '~^www\.~i', '', $u ); // CHANGED:
				return strtolower( (string) $u ); // CHANGED:
			} // CHANGED:

			$host = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : ''; // CHANGED:
			$host = preg_replace( '~^www\.~i', '', $host ); // CHANGED:

			$port = isset( $parts['port'] ) ? (int) $parts['port'] : 0; // CHANGED:
			$hp   = $host; // CHANGED:
			if ( $host !== '' && $port > 0 && $port !== 80 && $port !== 443 ) { // CHANGED:
				$hp = $host . ':' . $port; // CHANGED:
			} // CHANGED:

			$path = isset( $parts['path'] ) ? (string) $parts['path'] : ''; // CHANGED:
			$path = '/' . ltrim( $path, '/' ); // CHANGED:
			$path = untrailingslashit( $path ); // CHANGED:
			if ( '/' === $path ) { $path = ''; } // CHANGED:

			return $hp . $path; // CHANGED:
		}

		/**
		 * Current site URL (normalized).
		 *
		 * @return string
		 */
		private static function current_site_url_norm() { // CHANGED:
			return self::normalize_site_url( home_url( '/' ) ); // CHANGED:
		}

		/**
		 * Extract "freshness" timestamp from a license verify payload if present.
		 *
		 * @param array $res
		 * @return int
		 */
		private static function extract_server_verified_epoch( $res ) { // CHANGED:
			if ( ! is_array( $res ) ) { return 0; } // CHANGED:

			$data = isset( $res['data'] ) && is_array( $res['data'] ) ? $res['data'] : array(); // CHANGED:
			$act  = isset( $data['activation'] ) && is_array( $data['activation'] ) ? $data['activation'] : array(); // CHANGED:

			foreach ( array( 'last_verified_at', 'activated_at' ) as $k ) { // CHANGED:
				if ( isset( $act[ $k ] ) && is_string( $act[ $k ] ) ) { // CHANGED:
					$ts = strtotime( (string) $act[ $k ] ); // CHANGED:
					if ( $ts && $ts > 0 ) { return (int) $ts; } // CHANGED:
				} // CHANGED:
			} // CHANGED:

			return 0; // CHANGED:
		}

		/**
		 * Canonical gate max-age seconds (single source of truth).                // CHANGED:
		 *
		 * @return int
		 */
		private static function license_gate_max_age_seconds() { // CHANGED:
			$max_age = (int) apply_filters( 'ppa_license_gate_max_age_seconds', 900, self::$endpoint ); // CHANGED:
			if ( $max_age < 30 ) { $max_age = 30; } // CHANGED:
			return $max_age; // CHANGED:
		} // CHANGED:

		/**
		 * Determine if a cached license result is "fresh enough" to trust for gating.
		 *
		 * @param array $res
		 * @param int|null $max_age Optional override (already-sanitized).         // CHANGED:
		 * @return bool
		 */
		private static function license_cache_is_fresh( $res, $max_age = null ) { // CHANGED:
			$max_age = ( $max_age === null ) ? self::license_gate_max_age_seconds() : (int) $max_age; // CHANGED:
			if ( $max_age < 30 ) { $max_age = 30; } // CHANGED:

			$now         = time(); // CHANGED:
			$wp_checked  = (int) get_option( 'ppa_license_last_checked_at', 0 ); // CHANGED:
			$sv_checked  = (int) self::extract_server_verified_epoch( $res ); // CHANGED:
			$checked_at  = max( $wp_checked, $sv_checked ); // CHANGED:

			if ( $checked_at <= 0 ) { return false; } // CHANGED:
			return ( ( $now - $checked_at ) <= $max_age ); // CHANGED:
		}

		/**
		 * Interpret a license verify payload into a truth state for THIS site.
		 *
		 * @param mixed $res
		 * @return array{state:string,reason:string,source:string}
		 */
		private static function interpret_license_truth( $res ) { // CHANGED:
			if ( ! is_array( $res ) ) { // CHANGED:
				return array( 'state' => 'unknown', 'reason' => 'result_not_array', 'source' => 'none' ); // CHANGED:
			} // CHANGED:

			$ok   = isset( $res['ok'] ) ? (bool) $res['ok'] : null; // CHANGED:
			$data = isset( $res['data'] ) && is_array( $res['data'] ) ? $res['data'] : array(); // CHANGED:

			$lic = isset( $data['license'] ) && is_array( $data['license'] ) ? $data['license'] : array(); // CHANGED:
			$act = isset( $data['activation'] ) && is_array( $data['activation'] ) ? $data['activation'] : array(); // CHANGED:

			$status    = strtolower( (string) ( $lic['status'] ?? '' ) ); // CHANGED:

			$activated = null; // CHANGED:
			if ( array_key_exists( 'activated', $act ) ) { // CHANGED:
				$v = $act['activated']; // CHANGED:
				if ( is_bool( $v ) ) { // CHANGED:
					$activated = $v; // CHANGED:
				} elseif ( is_int( $v ) ) { // CHANGED:
					$activated = ( $v === 1 ); // CHANGED:
				} elseif ( is_string( $v ) ) { // CHANGED:
					$vv = strtolower( trim( $v ) ); // CHANGED:
					if ( in_array( $vv, array( 'true', '1', 'yes', 'on', 'active', 'activated' ), true ) ) { // CHANGED:
						$activated = true; // CHANGED:
					} elseif ( in_array( $vv, array( 'false', '0', 'no', 'off', 'inactive', 'deactivated' ), true ) ) { // CHANGED:
						$activated = false; // CHANGED:
					} // CHANGED:
				} // CHANGED:
			} // CHANGED:

			$site_url   = isset( $act['site_url'] ) ? self::normalize_site_url( (string) $act['site_url'] ) : ''; // CHANGED:
			$site_here  = self::current_site_url_norm(); // CHANGED:
			$site_match = ( $site_url !== '' && $site_url === $site_here ); // CHANGED:

			if ( $site_url !== '' && ! $site_match ) { // CHANGED:
				return array( 'state' => 'unknown', 'reason' => 'site_mismatch', 'source' => 'cache' ); // CHANGED:
			} // CHANGED:

			if ( true === $ok && true === $activated && ( '' === $status || 'active' === $status ) ) { // CHANGED:
				return array( 'state' => 'active', 'reason' => 'activation_active', 'source' => 'cache' ); // CHANGED:
			} // CHANGED:

			if ( true === $ok && ( false === $activated || ( $status !== '' && 'active' !== $status ) ) ) { // CHANGED:
				return array( 'state' => 'inactive', 'reason' => 'activation_inactive', 'source' => 'cache' ); // CHANGED:
			} // CHANGED:

			return array( 'state' => 'unknown', 'reason' => 'insufficient_data', 'source' => 'cache' ); // CHANGED:
		}

		/**
		 * Persist minimal license options (no secrets stored).
		 *
		 * @param array $truth
		 * @return void
		 */
		private static function persist_license_truth( $truth ) { // CHANGED:
			if ( ! is_array( $truth ) || ! isset( $truth['state'] ) ) { return; } // CHANGED:

			$state = (string) $truth['state']; // CHANGED:
			if ( 'active' === $state ) { // CHANGED:
				update_option( 'ppa_license_state', 'active', false ); // CHANGED:
				update_option( 'ppa_license_active_site', self::current_site_url_norm(), false ); // CHANGED:
				update_option( 'ppa_license_last_error_code', '', false ); // CHANGED:
			} elseif ( 'inactive' === $state ) { // CHANGED:
				update_option( 'ppa_license_state', 'inactive', false ); // CHANGED:
				update_option( 'ppa_license_active_site', '', false ); // CHANGED:
				update_option( 'ppa_license_last_error_code', 'inactive', false ); // CHANGED:
			} else { // unknown // CHANGED:
				update_option( 'ppa_license_state', 'unknown', false ); // CHANGED:
				update_option( 'ppa_license_active_site', '', false ); // CHANGED:
			} // CHANGED:

			update_option( 'ppa_license_last_checked_at', time(), false ); // CHANGED:
		}

		/**
		 * Perform a server-side /license/verify/ (rate-limited) to refresh truth for this site.
		 *
		 * @return array{state:string,reason:string,source:string}
		 */
		private static function server_verify_license_truth() { // CHANGED:
			$license = self::license_key(); // CHANGED:
			if ( '' === $license ) { // CHANGED:
				return array( 'state' => 'unknown', 'reason' => 'license_key_missing', 'source' => 'server' ); // CHANGED:
			} // CHANGED:

			$base = self::django_base(); // CHANGED:
			$url  = $base . '/license/verify/'; // CHANGED:

			$payload = array( // CHANGED:
				'license_key' => $license, // CHANGED:
				'site_url'    => home_url( '/' ), // CHANGED:
			); // CHANGED:

			$raw = function_exists( 'wp_json_encode' ) ? wp_json_encode( $payload ) : json_encode( $payload ); // CHANGED:
			$raw = is_string( $raw ) ? $raw : '{}'; // CHANGED:

			$tried_license_header = false; // CHANGED:
			$probe_allowed        = self::verify_probe_license_key_header_allowed(); // CHANGED:

			$shared = self::shared_key(); // CHANGED:

			$attempts = array(); // CHANGED:
			if ( $probe_allowed ) { // CHANGED:
				$attempts[] = array( 'mode' => 'license', 'key' => $license ); // CHANGED:
			} // CHANGED:
			if ( '' !== $shared ) { // CHANGED:
				$attempts[] = array( 'mode' => 'shared', 'key' => $shared ); // CHANGED:
			} // CHANGED:

			foreach ( $attempts as $a ) { // CHANGED:
				$mode = (string) ( $a['mode'] ?? '' ); // CHANGED:
				$key  = (string) ( $a['key']  ?? '' ); // CHANGED:
				if ( '' === $mode || '' === $key ) { continue; } // CHANGED:

				if ( 'license' === $mode ) { $tried_license_header = true; } // CHANGED:

				$headers = array( // CHANGED:
					'Content-Type'     => 'application/json; charset=utf-8', // CHANGED:
					'Accept'           => 'application/json; charset=utf-8', // CHANGED:
					'X-PPA-Key'        => $key, // CHANGED:
					'User-Agent'       => 'PostPressAI-WordPress/' . ( defined( 'PPA_VERSION' ) ? PPA_VERSION : 'dev' ), // CHANGED:
					'X-Requested-With' => 'XMLHttpRequest', // CHANGED:
				); // CHANGED:

				$args = array( // CHANGED:
					'headers' => $headers, // CHANGED:
					'body'    => (string) $raw, // CHANGED:
					'timeout' => 30, // CHANGED:
				); // CHANGED:

				$resp = wp_remote_post( $url, $args ); // CHANGED:
				if ( is_wp_error( $resp ) ) { // CHANGED:
					error_log( 'PPA: license_verify request_failed' ); // CHANGED:
					continue; // CHANGED:
				} // CHANGED:

				$code      = (int) wp_remote_retrieve_response_code( $resp ); // CHANGED:
				$resp_body = (string) wp_remote_retrieve_body( $resp ); // CHANGED:

				$json = json_decode( $resp_body, true ); // CHANGED:
				if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $json ) ) { // CHANGED:
					error_log( 'PPA: license_verify non-json http=' . $code ); // CHANGED:
					continue; // CHANGED:
				} // CHANGED:

				$json['_http_status'] = $code; // CHANGED:
				set_transient( 'ppa_license_last_result', $json, 15 * MINUTE_IN_SECONDS ); // CHANGED:

				$truth = self::interpret_license_truth( $json ); // CHANGED:
				$truth['source'] = 'server:' . $mode; // CHANGED:
				self::persist_license_truth( $truth ); // CHANGED:

				if ( 'license' === $mode ) { // CHANGED:
					update_option( 'ppa_verify_license_header_ok', '1', false ); // CHANGED:
				} // CHANGED:

				return $truth; // CHANGED:
			} // CHANGED:

			if ( $tried_license_header ) { // CHANGED:
				update_option( 'ppa_verify_license_header_ok', '0', false ); // CHANGED:
			} // CHANGED:

			return array( 'state' => 'unknown', 'reason' => 'verify_failed', 'source' => 'server' ); // CHANGED:
		}

		/**
		 * CHANGED: Use persisted license options as a fallback truth source for gating.
		 * This prevents false "unknown" when transients are missing or object cache is quirky.
		 *
		 * Requires:
		 * - ppa_license_state === 'active' or 'inactive'
		 * - ppa_license_active_site matches this site (normalized)
		 * - ppa_license_last_checked_at is fresh (within gate max age)
		 *
		 * @param int    $max_age    Canonical max-age seconds (already sanitized). // CHANGED:
		 * @param string $site_here  Normalized current site string.               // CHANGED:
		 * @return array|false array{state:string,reason:string,source:string} or false if not usable
		 */
		private static function license_options_truth_if_fresh( $max_age, $site_here ) { // CHANGED:
			$max_age   = (int) $max_age; // CHANGED:
			$site_here = is_string( $site_here ) ? trim( $site_here ) : ''; // CHANGED:

			if ( $max_age <= 0 || '' === $site_here ) { // CHANGED:
				return false; // CHANGED:
			} // CHANGED:

			$state_raw = get_option( 'ppa_license_state', '' ); // CHANGED:
			$state     = is_string( $state_raw ) ? strtolower( trim( $state_raw ) ) : ''; // CHANGED:
			if ( $state !== 'active' && $state !== 'inactive' ) { // CHANGED:
				return false; // CHANGED:
			} // CHANGED:

			$site_opt_raw = get_option( 'ppa_license_active_site', '' ); // CHANGED:
			$site_opt     = is_string( $site_opt_raw ) ? trim( $site_opt_raw ) : ''; // CHANGED:
			if ( '' === $site_opt ) { // CHANGED:
				// Safety: do not trust "active" without a site binding. // CHANGED:
				return false; // CHANGED:
			} // CHANGED:

			$site_norm = self::normalize_site_url( $site_opt ); // CHANGED:
			if ( '' === $site_norm || $site_norm !== $site_here ) { // CHANGED:
				return false; // CHANGED:
			} // CHANGED:

			$checked_at = (int) get_option( 'ppa_license_last_checked_at', 0 ); // CHANGED:
			if ( $checked_at <= 0 ) { // CHANGED:
				return false; // CHANGED:
			} // CHANGED:

			if ( ( time() - $checked_at ) > $max_age ) { // CHANGED:
				return false; // CHANGED:
			} // CHANGED:

			return array( // CHANGED:
				'state'  => $state, // CHANGED:
				'reason' => ( $state === 'active' ? 'option_active' : 'option_inactive' ), // CHANGED:
				'source' => 'opt:fresh', // CHANGED:
			); // CHANGED:
		} // CHANGED:

		/**
		 * Rate-limited truth resolver used by the proxy gate.
		 *
		 * @return array{state:string,reason:string,source:string}
		 */
		private static function get_license_truth_for_gate() { // CHANGED:
			$max_age  = self::license_gate_max_age_seconds(); // CHANGED:
			$ttl_gate = min( 60, $max_age ); // CHANGED:
			if ( $ttl_gate < 15 ) { $ttl_gate = 15; } // CHANGED:

			$site_here = self::current_site_url_norm(); // CHANGED:

			$cached = get_transient( 'ppa_license_gate_state' ); // CHANGED:

			// CHANGED: Only trust cached gate state when it is explicitly active/inactive.
			// If cached is "unknown" (array or string), DO NOT early-return; allow opt:fresh to unblock.
			if ( is_array( $cached ) && isset( $cached['state'] ) ) { // CHANGED:
				$s = strtolower( trim( (string) $cached['state'] ) ); // CHANGED:
				if ( $s === 'active' || $s === 'inactive' ) { // CHANGED:
					$cached['state'] = $s; // CHANGED:
					return $cached; // CHANGED:
				} // CHANGED:
			} // CHANGED:
			if ( is_string( $cached ) && $cached !== '' ) { // CHANGED:
				$s = strtolower( trim( $cached ) ); // CHANGED:
				if ( $s === 'active' || $s === 'inactive' ) { // CHANGED:
					return array( 'state' => $s, 'reason' => 'cache_string', 'source' => 'gate' ); // CHANGED:
				} // CHANGED:
			} // CHANGED:

			$last = get_transient( 'ppa_license_last_result' ); // CHANGED:
			if ( is_array( $last ) && self::license_cache_is_fresh( $last, $max_age ) ) { // CHANGED:
				$truth = self::interpret_license_truth( $last ); // CHANGED:
				$truth['source'] = 'cache:fresh'; // CHANGED:
				set_transient( 'ppa_license_gate_state', $truth, $ttl_gate ); // CHANGED:
				return $truth; // CHANGED:
			} // CHANGED:

			$opt_truth = self::license_options_truth_if_fresh( $max_age, $site_here ); // CHANGED:
			if ( is_array( $opt_truth ) && isset( $opt_truth['state'] ) ) { // CHANGED:
				set_transient( 'ppa_license_gate_state', $opt_truth, $ttl_gate ); // CHANGED:
				return $opt_truth; // CHANGED:
			} // CHANGED:

			$min_interval = (int) apply_filters( 'ppa_license_gate_verify_min_interval', 60, self::$endpoint ); // CHANGED:
			if ( $min_interval < 15 ) { $min_interval = 15; } // CHANGED:

			$last_try = (int) get_transient( 'ppa_license_gate_last_verify_at' ); // CHANGED:
			if ( $last_try > 0 && ( time() - $last_try ) < $min_interval ) { // CHANGED:
				$recent = get_transient( 'ppa_license_gate_last_verify_state' ); // CHANGED:
				if ( is_array( $recent ) && isset( $recent['state'] ) ) { // CHANGED:
					set_transient( 'ppa_license_gate_state', $recent, min( 30, $ttl_gate ) ); // CHANGED:
					return $recent; // CHANGED:
				} // CHANGED:
				return array( 'state' => 'unknown', 'reason' => 'verify_rate_limited', 'source' => 'gate' ); // CHANGED:
			} // CHANGED:

			set_transient( 'ppa_license_gate_last_verify_at', time(), $min_interval ); // CHANGED:
			$truth = self::server_verify_license_truth(); // CHANGED:
			set_transient( 'ppa_license_gate_last_verify_state', $truth, 5 * MINUTE_IN_SECONDS ); // CHANGED:
			set_transient( 'ppa_license_gate_state', $truth, $ttl_gate ); // CHANGED:
			return $truth; // CHANGED:
		}

		/**
		 * Enforce: block proxy calls unless license is ACTIVE for this site.
		 *
		 * @return void
		 */
		private static function enforce_license_gate_or_block() { // CHANGED:
			$truth = self::get_license_truth_for_gate(); // CHANGED:
			if ( is_array( $truth ) && (string) ( $truth['state'] ?? '' ) === 'active' ) { // CHANGED:
				return; // CHANGED:
			} // CHANGED:

			$state = (string) ( $truth['state'] ?? 'unknown' ); // CHANGED:
			$code  = ( 'inactive' === $state ) ? 'license_inactive' : 'license_unknown'; // CHANGED:

			// CHANGED: throttle gate-block logging to keep logs clean.
			self::log_throttled( // CHANGED:
				'gate_block_' . self::$endpoint . '_' . $state, // CHANGED:
				60, // CHANGED:
				'PPA: license gate blocked state=' . $state . ' endpoint=' . self::$endpoint // CHANGED:
			); // CHANGED:

			$msg = ( 'inactive' === $state )
				? 'License is not active for this site. Go to PostPress AI → Settings and click “Activate This Site”.' // CHANGED:
				: 'License status is unknown. Go to PostPress AI → Settings, click “Check License”, then “Activate This Site”.'; // CHANGED:

			wp_send_json_error( // CHANGED:
				self::error_payload( // CHANGED:
					$code, // CHANGED:
					403, // CHANGED:
					array( // CHANGED:
						'reason'  => (string) ( $truth['reason'] ?? 'unknown' ), // CHANGED:
						'message' => $msg, // CHANGED:
					) // CHANGED:
				), // CHANGED:
				403 // CHANGED:
			); // CHANGED:
		}

		/**
		 * Build wp_remote_post() args; headers are filterable.
		 *
		 * @param string $raw_json
		 * @return array
		 */
		private static function build_args( $raw_json ) {
			$headers = array(
				'Content-Type'     => 'application/json; charset=utf-8',
				'Accept'           => 'application/json; charset=utf-8',
				'X-PPA-Key'        => self::proxy_auth_key_or_500(), // CHANGED:
				'User-Agent'       => 'PostPressAI-WordPress/' . ( defined( 'PPA_VERSION' ) ? PPA_VERSION : 'dev' ),
				'X-Requested-With' => 'XMLHttpRequest',
			);

			$incoming = function_exists( 'getallheaders' ) ? (array) getallheaders() : array();

			$view = '';
			if ( isset( $_SERVER['HTTP_X_PPA_VIEW'] ) ) {
				$view = (string) $_SERVER['HTTP_X_PPA_VIEW'];
			} elseif ( isset( $incoming['X-PPA-View'] ) ) {
				$view = (string) $incoming['X-PPA-View'];
			}
			if ( $view !== '' ) {
				$headers['X-PPA-View'] = $view;
			}

			$nonce = '';
			if ( isset( $_SERVER['HTTP_X_PPA_NONCE'] ) ) {
				$nonce = (string) $_SERVER['HTTP_X_PPA_NONCE'];
			} elseif ( isset( $_SERVER['HTTP_X_WP_NONCE'] ) ) {
				$nonce = (string) $_SERVER['HTTP_X_WP_NONCE'];
			} elseif ( isset( $incoming['X-PPA-Nonce'] ) ) {
				$nonce = (string) $incoming['X-PPA-Nonce'];
			} elseif ( isset( $incoming['X-WP-Nonce'] ) ) {
				$nonce = (string) $incoming['X-WP-Nonce'];
			}
			if ( $nonce !== '' ) {
				$headers['X-PPA-Nonce'] = $nonce;
			}

			$headers = (array) apply_filters( 'ppa_outgoing_headers', $headers, self::$endpoint );

			$args = array(
				'headers' => $headers,
				'body'    => (string) $raw_json,
				'timeout' => 90,
			);

			$args = (array) apply_filters( 'ppa_outgoing_request_args', $args, self::$endpoint );
			return $args;
		}

		/**
		 * Lightweight logging of successful Django proxy calls (generate/store) into WP.
		 */
		private static function log_proxy_event( $kind, $payload, $json, $http_code ) {
			try {
				$kind = sanitize_key( $kind );
				if ( '' === $kind ) { return; }

				$payload = is_array( $payload ) ? $payload : array();
				$json    = is_array( $json )    ? $json    : array();

				$result = array();
				if ( isset( $json['result'] ) && is_array( $json['result'] ) ) {
					$result = $json['result'];
				}

				$title   = (string) ( $payload['title'] ?? ( $payload['subject'] ?? ( $result['title'] ?? '' ) ) );
				$subject = (string) ( $payload['subject'] ?? '' );
				$wc      = isset( $payload['word_count'] ) ? (int) $payload['word_count'] : 0;

				$provider = '';
				if ( isset( $json['provider'] ) ) {
					$provider = (string) $json['provider'];
				} elseif ( isset( $result['provider'] ) ) {
					$provider = (string) $result['provider'];
				}

				$post_type = post_type_exists( 'ppa_generation' ) ? 'ppa_generation' : 'post';

				$label      = strtoupper( $kind );
				$log_title  = sprintf(
					'[PPA %s] %s',
					$label,
					$title !== '' ? sanitize_text_field( $title ) : '(untitled)'
				);

				$excerpt_bits = array();
				if ( '' !== $subject ) { $excerpt_bits[] = 'Subject: ' . sanitize_text_field( $subject ); }
				if ( $wc > 0 )        { $excerpt_bits[] = 'Word count: ' . $wc; }
				if ( '' !== $provider ) { $excerpt_bits[] = 'Provider: ' . sanitize_text_field( $provider ); }
				$post_excerpt = implode( ' | ', $excerpt_bits );

				$context = array(
					'kind'      => $kind,
					'http_code' => (int) $http_code,
					'provider'  => $provider,
					'payload'   => array(
						'subject'    => $subject,
						'title'      => $title,
						'word_count' => $wc,
					),
				);

				if ( isset( $result['id'] ) ) {
					$context['result_id'] = $result['id'];
				}

				$content_json = function_exists( 'wp_json_encode' )
					? wp_json_encode( $context )
					: json_encode( $context );

				$post_id = wp_insert_post(
					array(
						'post_type'    => $post_type,
						'post_status'  => 'private',
						'post_title'   => $log_title,
						'post_excerpt' => $post_excerpt,
						'post_content' => (string) $content_json,
					),
					true
				);

				if ( is_wp_error( $post_id ) || ! $post_id ) { return; }

				update_post_meta( $post_id, '_ppa_kind', $kind );
				update_post_meta( $post_id, '_ppa_http_code', (int) $http_code );
				if ( '' !== $provider ) {
					update_post_meta( $post_id, '_ppa_provider', $provider );
				}
				if ( isset( $result['id'] ) ) {
					update_post_meta( $post_id, '_ppa_result_id', $result['id'] );
				}
			} catch ( \Throwable $e ) {
				// never break the proxy
			}
		}

		/* ─────────────────────────────────────────────────────────────────────
		 * HTML helpers (preview fallback to guarantee result.html)
		 * ──────────────────────────────────────────────────────────────────── */

		private static function looks_like_html( $s ) {
			$s = (string) $s;
			if ( $s === '' ) { return false; }
			$sn = strtolower( ltrim( $s ) );

			$starts = function( $hay, $needle ) {
				$hay = (string) $hay;
				$needle = (string) $needle;
				return ( $needle !== '' && 0 === strpos( $hay, $needle ) );
			};

			return ( strpos( $s, '<' ) !== false && strpos( $s, '>' ) !== false )
				|| $starts( $sn, '<!doctype' )
				|| $starts( $sn, '<html' )
				|| $starts( $sn, '<p' )
				|| $starts( $sn, '<h' )
				|| $starts( $sn, '<ul' )
				|| $starts( $sn, '<ol' )
				|| $starts( $sn, '<div' )
				|| $starts( $sn, '<section' );
		}

		private static function text_to_html( $txt ) {
			$txt = (string) $txt;
			if ( $txt === '' ) { return ''; }
			$txt   = str_replace( array( "\r\n", "\r" ), "\n", $txt );
			$safe  = esc_html( $txt );
			$parts = array_filter( explode( "\n\n", $safe ), 'strlen' );
			if ( empty( $parts ) ) {
				return '<p>' . str_replace( "\n", '<br>', $safe ) . '</p>';
			}
			$out = '';
			foreach ( $parts as $p ) {
				$out .= '<p>' . str_replace( "\n", '<br>', $p ) . '</p>';
			}
			return $out;
		}

		private static function derive_preview_html( $result, $payload ) {
			$result  = is_array( $result )  ? $result  : array();
			$payload = is_array( $payload ) ? $payload : array();

			$content = (string) ( $result['content'] ?? $payload['content'] ?? '' );
			if ( $content !== '' ) {
				return self::looks_like_html( $content ) ? $content : self::text_to_html( $content );
			}

			$text = (string) ( $payload['text'] ?? $payload['brief'] ?? '' );
			if ( $text !== '' ) {
				return self::text_to_html( $text );
			}

			return '';
		}

		/* ─────────────────────────────────────────────────────────────────────
		 * Endpoints
		 * ──────────────────────────────────────────────────────────────────── */

		public static function ajax_preview() {
			self::preflight_or_die( 'preview', true ); // CHANGED:

			$payload = self::read_json_body();
			$base    = self::django_base();
			$url     = $base . '/preview/'; // CHANGED:

			$r = self::remote_call_parsed( 'POST', $url, self::build_args( $payload['raw'] ) ); // CHANGED:
			if ( ! empty( $r['is_error'] ) ) { // CHANGED:
				self::wp_error_or_die( 'preview', $r['error'] ); // CHANGED:
			} // CHANGED:

			$code = (int) $r['code']; // CHANGED:

			if ( empty( $r['is_json'] ) ) { // CHANGED:
				self::maybe_log_non_json_failure( 'preview', $code ); // CHANGED:
				wp_send_json_success( array( 'raw' => (string) $r['body'] ), $code ); // CHANGED:
			} // CHANGED:

			$json = (array) $r['json']; // CHANGED:

			// CHANGED: If license-key proxy auth was used and rejected, learn + block cleanly.
			self::learn_or_block_license_proxy_auth( $code, $json ); // CHANGED:

			// Guarantee result.html fallback (kept behavior)
			if ( is_array( $json ) ) {
				$res  = ( isset( $json['result'] ) && is_array( $json['result'] ) ) ? $json['result'] : array();
				$html = (string) ( $res['html'] ?? '' );
				if ( $html === '' ) {
					$derived = self::derive_preview_html( $res, $payload['json'] );
					if ( $derived !== '' ) {
						if ( ! isset( $json['result'] ) || ! is_array( $json['result'] ) ) {
							$json['result'] = array();
						}
						$json['result']['html'] = $derived;
					}
				}
			}

			self::maybe_log_http_failure( 'preview', $code ); // CHANGED:
			wp_send_json_success( $json, $code );
		}

		public static function ajax_store() {
			self::preflight_or_die( 'store', true ); // CHANGED:

			$payload = self::read_json_body();
			$base    = self::django_base();
			$url     = $base . '/store/'; // CHANGED:

			$r = self::remote_call_parsed( 'POST', $url, self::build_args( $payload['raw'] ) ); // CHANGED:
			if ( ! empty( $r['is_error'] ) ) { // CHANGED:
				self::wp_error_or_die( 'store', $r['error'] ); // CHANGED:
			} // CHANGED:

			$code = (int) $r['code']; // CHANGED:

			if ( empty( $r['is_json'] ) ) { // CHANGED:
				self::maybe_log_non_json_failure( 'store', $code ); // CHANGED:
				wp_send_json_success( array( 'raw' => (string) $r['body'] ), $code ); // CHANGED:
			} // CHANGED:

			$json = (array) $r['json']; // CHANGED:

			// CHANGED: If license-key proxy auth was used and rejected, learn + block cleanly.
			self::learn_or_block_license_proxy_auth( $code, $json ); // CHANGED:

			// ---------- Local WP create + link injection (kept; hardened) ----------------
			try {
				$dj_ok = ( $code >= 200 && $code < 300 );
				if ( $dj_ok && is_array( $json ) && array_key_exists( 'ok', $json ) ) {
					$dj_ok = (bool) $json['ok'];
				}
				if ( ! $dj_ok ) {
					self::log_proxy_event( 'store', $payload['json'], $json, $code );
					wp_send_json_success( $json, $code );
				}

				$payload_json = $payload['json'];
				$result  = ( isset( $json['result'] ) && is_array( $json['result'] ) )
					? $json['result']
					: ( is_array( $json ) ? $json : array() );

				$already_has_links = (
					( isset( $json['id'] ) && $json['id'] ) ||
					( isset( $json['permalink'] ) && $json['permalink'] ) ||
					( isset( $json['edit_link'] ) && $json['edit_link'] ) ||
					( isset( $result['id'] ) && $result['id'] ) ||
					( isset( $result['permalink'] ) && $result['permalink'] ) ||
					( isset( $result['edit_link'] ) && $result['edit_link'] )
				);
				if ( $already_has_links ) {
					self::log_proxy_event( 'store', $payload['json'], $json, $code );
					wp_send_json_success( $json, $code ); // CHANGED:
				}

				$title   = sanitize_text_field( $payload_json['title']   ?? ( $result['title']   ?? '' ) );
				$content =                         $payload_json['content'] ?? ( $result['content'] ?? ( $result['html'] ?? '' ) );
				$excerpt = sanitize_text_field( $payload_json['excerpt'] ?? ( $result['excerpt'] ?? '' ) );
				$slug    = sanitize_title(      $payload_json['slug']    ?? ( $result['slug']    ?? '' ) );
				$status  = sanitize_key(        $payload_json['status']  ?? ( $result['status']  ?? 'draft' ) );
				$mode    = sanitize_key(        $payload_json['mode']    ?? ( $result['mode']    ?? '' ) );

				$target_sites = (array) ( $payload_json['target_sites'] ?? array() );
				$wants_local  = ( 'update' === $mode )
					|| in_array( 'draft',   $target_sites, true )
					|| in_array( 'publish', $target_sites, true )
					|| in_array( $status, array( 'draft', 'publish', 'pending' ), true );

				if ( $wants_local ) {
					$post_status = in_array( $status, array( 'publish', 'draft', 'pending' ), true ) ? $status : 'draft';
					if ( in_array( $mode, array( 'publish', 'draft', 'pending' ), true ) ) {
						$post_status = $mode;
					}

					$target_post_id = 0;
					if ( ! empty( $payload_json['id'] ) ) {
						$target_post_id = (int) $payload_json['id'];
					} elseif ( ! empty( $payload_json['wp_post_id'] ) ) {
						$target_post_id = (int) $payload_json['wp_post_id'];
					} elseif ( ! empty( $result['id'] ) ) {
						$target_post_id = (int) $result['id'];
					}

					$postarr = array(
						'post_title'   => $title,
						'post_content' => $content,
						'post_excerpt' => $excerpt,
						'post_status'  => $post_status,
						'post_type'    => 'post',
						'post_author'  => get_current_user_id(),
					);
					if ( $slug ) {
						$postarr['post_name'] = $slug;
					}

					$using_update = ( 'update' === $mode && $target_post_id > 0 );
					if ( 'update' === $mode && $target_post_id <= 0 && empty( $json['warning'] ) ) {
						$json['warning'] = array( 'type' => 'update_mode_missing_id' );
					}

					if ( $using_update ) {
						$postarr['ID'] = $target_post_id;
						$post_id = wp_update_post( wp_slash( $postarr ), true );
					} else {
						$post_id = wp_insert_post( wp_slash( $postarr ), true );
					}

					if ( ! is_wp_error( $post_id ) && $post_id ) {
						if ( ! empty( $payload_json['tags'] ) ) {
							$tags = array_map( 'sanitize_text_field', (array) $payload_json['tags'] );
							wp_set_post_terms( $post_id, $tags, 'post_tag', false );
						}
						if ( ! empty( $payload_json['categories'] ) ) {
							$cats = array_map( 'intval', (array) $payload_json['categories'] );
							wp_set_post_terms( $post_id, $cats, 'category', false );
						}

						$edit = get_edit_post_link( $post_id, '' );
						$view = get_permalink( $post_id );

						$json['id']        = $post_id;
						$json['edit_link'] = $edit;
						$json['permalink'] = $view;

						if ( isset( $json['result'] ) && is_array( $json['result'] ) ) {
							$json['result']['id']        = $post_id;
							$json['result']['edit_link'] = $edit;
							$json['result']['permalink'] = $view;
							if ( ! isset( $json['result']['meta'] ) || ! is_array( $json['result']['meta'] ) ) {
								$json['result']['meta'] = array();
							}
							$json['result']['meta']['id']        = $post_id;
							$json['result']['meta']['edit_link'] = $edit;
							$json['result']['meta']['permalink'] = $view;
						}
					} else {
						$warn = is_wp_error( $post_id ) ? $post_id->get_error_message() : 'insert_failed';
						$json['warning'] = array( 'type' => 'wp_insert_post_failed', 'message' => $warn );
					}
				}
			} catch ( \Throwable $e ) {
				$json['warning'] = array( 'type' => 'local_store_exception', 'message' => $e->getMessage() );
			}

			self::log_proxy_event( 'store', $payload['json'], $json, $code );
			self::maybe_log_http_failure( 'store', $code ); // CHANGED:
			wp_send_json_success( $json, $code );
		}

		public static function ajax_generate() {
			self::preflight_or_die( 'generate', true ); // CHANGED:

			$payload = self::read_json_body();
			$base    = self::django_base();
			$url     = $base . '/generate/'; // CHANGED:

			$r = self::remote_call_parsed( 'POST', $url, self::build_args( $payload['raw'] ) ); // CHANGED:
			if ( ! empty( $r['is_error'] ) ) { // CHANGED:
				self::wp_error_or_die( 'generate', $r['error'] ); // CHANGED:
			} // CHANGED:

			$code = (int) $r['code']; // CHANGED:

			if ( empty( $r['is_json'] ) ) { // CHANGED:
				self::maybe_log_non_json_failure( 'generate', $code ); // CHANGED:
				wp_send_json_success( array( 'raw' => (string) $r['body'] ), $code ); // CHANGED:
			} // CHANGED:

			$json = (array) $r['json']; // CHANGED:

			// CHANGED: If license-key proxy auth was used and rejected, learn + block cleanly.
			self::learn_or_block_license_proxy_auth( $code, $json ); // CHANGED:

			self::log_proxy_event( 'generate', $payload['json'], $json, $code );
			self::maybe_log_http_failure( 'generate', $code ); // CHANGED:
			wp_send_json_success( $json, $code );
		}

		public static function ajax_debug_headers() {
			self::preflight_or_die( 'debug_headers', false ); // CHANGED: debug does NOT license-gate

			$payload = self::read_json_body();
			$base    = self::django_base();
			$url     = $base . '/debug/headers/'; // CHANGED:

			$args = self::build_args( $payload['raw'] );
			unset( $args['body'] );

			$r = self::remote_call_parsed( 'GET', $url, $args ); // CHANGED:
			if ( ! empty( $r['is_error'] ) ) { // CHANGED:
				self::wp_error_or_die( 'debug_headers', $r['error'] ); // CHANGED:
			} // CHANGED:

			$code = (int) $r['code']; // CHANGED:

			if ( empty( $r['is_json'] ) ) { // CHANGED:
				self::maybe_log_non_json_failure( 'debug_headers', $code ); // CHANGED:
				wp_send_json_success( array( 'raw' => (string) $r['body'] ), $code ); // CHANGED:
			} // CHANGED:

			$json = (array) $r['json']; // CHANGED:

			self::maybe_log_http_failure( 'debug_headers', $code ); // CHANGED:
			wp_send_json_success( $json, $code );
		}
	}

	add_action( 'init', array( 'PPA_Controller', 'init' ) );
}
