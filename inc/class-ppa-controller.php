<?php
/**
 * PPA Controller — Server-side AJAX proxy for PostPress AI
 *
 * LOCATION
 * --------
 * /wp-content/plugins/postpress-ai/inc/class-ppa-controller.php
 *
 * CHANGE LOG
 * 2025-12-28 • FIX: Harden django_base() so blank/malformed ppa_django_url (or missing scheme) can’t cause      // CHANGED:
 *              wp_remote_post “A valid URL was not provided.” Adds https:// normalization + validation fallback. // CHANGED:
 *
 * 2025-11-19 • Expand shared key resolution (constant/option/filter) for wp.org readiness.                // CHANGED:
 * 2025-11-16 • Add generate proxy (ppa_generate) to Django /generate/ for AssistantRunner-backed content.  // CHANGED:
 * 2025-11-16 • Add mode hint support to store proxy (draft/publish/update + update support).           // CHANGED:
 * 2025-11-15 • Add debug headers AJAX proxy (ppa_debug_headers) to call Django /debug/headers/ and surface info      // CHANGED:
 *              to the Testbed UI; reuse shared key + outgoing header filters with GET semantics.                      // CHANGED:
 * 2025-11-13 • Tighten Django parity: pass X-PPA-View and nonce headers through, force X-Requested-With,         // CHANGED:
 *              normalize WP-side error payloads to {ok,error,code,meta}, and set endpoint earlier for logs.      // CHANGED:
 * 2025-11-11 • Preview: guarantee result.html on the WP proxy by deriving from content/text/brief if missing.    // CHANGED:
 *              - New helpers: looks_like_html(), text_to_html(), derive_preview_html().                           // CHANGED:
 *              - No secrets logged; response shape preserved.                                                     // CHANGED:
 * 2025-11-10 • Add shared-key guard (server_misconfig 500), Accept header, and minimal                          // CHANGED:
 *              endpoint logging without secrets or payloads. Keep response shape stable.                         // CHANGED:
 * 2025-11-09 • Security & robustness: POST-only, nonce check from headers, constants override,                   // CHANGED:
 *              URL/headers sanitization, filters for URL/headers/args, safer JSON handling.                      // CHANGED:
 * 2025-11-08 • Post-process /store/: create local WP post (draft/publish) and inject id/permalink/               // CHANGED:
 *              edit_link. Only create locally when Django indicates success (HTTP 2xx and ok).                   // CHANGED:
 *              Set post_author to current user; avoid reinjecting if already present.                            // CHANGED:
 *              Defensive JSON handling across payload/result.                                                    // CHANGED:
 * 2025-10-12 • Initial proxy endpoints to Django (preview/store).
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'PPA_Controller' ) ) {

	class PPA_Controller {

		/**
		 * Current endpoint label used by filters/logging (preview|store|debug_headers|generate).                       // CHANGED:
		 *
		 * @var string
		 */
		private static $endpoint = 'preview';                                                                            // CHANGED:

		/**
		 * Register AJAX hooks (admin-only).
		 */
		public static function init() {
			add_action( 'wp_ajax_ppa_preview',        array( __CLASS__, 'ajax_preview' ) );
			add_action( 'wp_ajax_ppa_store',          array( __CLASS__, 'ajax_store' ) );
			add_action( 'wp_ajax_ppa_debug_headers',  array( __CLASS__, 'ajax_debug_headers' ) );                         // CHANGED:
			add_action( 'wp_ajax_ppa_generate',       array( __CLASS__, 'ajax_generate' ) );                              // CHANGED:
		}

		/* ─────────────────────────────────────────────────────────────────────
		 * Internals
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
		private static function error_payload( $error_code, $http_status, $meta_extra = array() ) {                      // CHANGED:
			$http_status = (int) $http_status;                                                                           // CHANGED:
			$meta_base   = array(                                                                                        // CHANGED:
				'source'   => 'wp_proxy',                                                                               // CHANGED:
				'endpoint' => self::$endpoint,                                                                          // CHANGED:
			);                                                                                                           // CHANGED:
			if ( ! is_array( $meta_extra ) ) {                                                                           // CHANGED:
				$meta_extra = array();                                                                                   // CHANGED:
			}                                                                                                            // CHANGED:
			return array(                                                                                                // CHANGED:
				'ok'    => false,                                                                                       // CHANGED:
				'error' => (string) $error_code,                                                                        // CHANGED:
				'code'  => $http_status,                                                                                // CHANGED:
				'meta'  => array_merge( $meta_base, $meta_extra ),                                                      // CHANGED:
			);                                                                                                           // CHANGED:
		}

		/**
		 * Enforce POST method; send 405 if not.
		 */
		private static function must_post() {                                                                            // CHANGED:
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
		private static function verify_nonce_or_forbid() {                                                               // CHANGED:
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
		 * Read raw JSON body with fallback to posted 'payload'.
		 *
		 * @return array{raw:string,json:array}
		 */
		private static function read_json_body() {                                                                       // CHANGED:
			$raw = file_get_contents( 'php://input' );
			if ( empty( $raw ) && isset( $_POST['payload'] ) ) {
				$raw = wp_unslash( (string) $_POST['payload'] );
			}
			$raw   = (string) $raw;
			$assoc = json_decode( $raw, true );
			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $assoc ) ) {
				$assoc = array();
			}
			return array( 'raw' => $raw, 'json' => $assoc );
		}

		/**
		 * Resolve Django base URL with constant/option + filter; sanitized, no trailing slash.
		 *
		 * @return string
		 */
		private static function django_base() {                                                                          // CHANGED:
			$base = '';                                                                                                   // CHANGED:

			// 1) Constant override (wp-config.php)                                                                       // CHANGED:
			if ( defined( 'PPA_DJANGO_URL' ) && PPA_DJANGO_URL ) {                                                        // CHANGED:
				$base = (string) PPA_DJANGO_URL;                                                                          // CHANGED:
			} else {
				// 2) Option (may exist but be blank; do NOT accept blank as valid)                                       // CHANGED:
				$opt = get_option( 'ppa_django_url', '' );                                                                // CHANGED:
				if ( is_string( $opt ) ) {                                                                                // CHANGED:
					$base = (string) $opt;                                                                                // CHANGED:
				}                                                                                                          // CHANGED:
			}

			$base = trim( (string) $base );                                                                               // CHANGED:

			// CHANGED: Known-good fallback if option/constant is missing or blank.
			if ( '' === $base ) {                                                                                         // CHANGED:
				$base = 'https://apps.techwithwayne.com/postpress-ai/';                                                   // CHANGED:
			}                                                                                                              // CHANGED:

			// CHANGED: If scheme is missing, assume https:// so wp_remote_post() always gets a valid URL.
			if ( ! preg_match( '#^https?://#i', $base ) ) {                                                                // CHANGED:
				$base = 'https://' . ltrim( $base, '/' );                                                                 // CHANGED:
			}                                                                                                              // CHANGED:

			$base = untrailingslashit( $base );                                                                            // CHANGED:

			/** @param string $base */
			$base = (string) apply_filters( 'ppa_django_base_url', $base );                                                // CHANGED:

			$base = trim( (string) $base );                                                                               // CHANGED:

			// CHANGED: Don’t allow filters to blank the base.
			if ( '' === $base ) {                                                                                         // CHANGED:
				$base = 'https://apps.techwithwayne.com/postpress-ai';                                                    // CHANGED:
			}                                                                                                              // CHANGED:

			// CHANGED: Re-apply scheme normalization after filters.
			if ( ! preg_match( '#^https?://#i', $base ) ) {                                                                // CHANGED:
				$base = 'https://' . ltrim( $base, '/' );                                                                 // CHANGED:
			}                                                                                                              // CHANGED:

			// CHANGED: Validate for WP HTTP API; prevents "A valid URL was not provided."
			if ( function_exists( 'wp_http_validate_url' ) ) {                                                            // CHANGED:
				$validated = wp_http_validate_url( $base );                                                               // CHANGED:
				if ( $validated ) {                                                                                       // CHANGED:
					$base = (string) $validated;                                                                           // CHANGED:
				} else {                                                                                                   // CHANGED:
					error_log( 'PPA: django_base invalid; falling back to default' );                                      // CHANGED:
					$base = 'https://apps.techwithwayne.com/postpress-ai';                                                // CHANGED:
				}                                                                                                          // CHANGED:
			} else {                                                                                                       // CHANGED:
				$base = untrailingslashit( esc_url_raw( $base ) );                                                         // CHANGED:
			}                                                                                                              // CHANGED:

			return untrailingslashit( (string) $base );                                                                    // CHANGED:
		}

		/**
		 * Resolve shared key from constant, option, or external filter. Never echo/log this.                           // CHANGED:
		 *
		 * @return string
		 */
		private static function shared_key() {                                                                           // CHANGED:
			// 1) Power-user override via wp-config.php constant.                                                       // CHANGED:
			if ( defined( 'PPA_SHARED_KEY' ) && PPA_SHARED_KEY ) {                                                       // CHANGED:
				return trim( (string) PPA_SHARED_KEY );                                                                  // CHANGED:
			}                                                                                                            // CHANGED:

			// 2) Normal wp.org usage — key stored as an option.                                                        // CHANGED:
			$opt = get_option( 'ppa_shared_key', '' );                                                                   // CHANGED:
			if ( is_string( $opt ) ) {                                                                                   // CHANGED:
				$opt = trim( $opt );                                                                                     // CHANGED:
				if ( '' !== $opt ) {                                                                                     // CHANGED:
					return $opt;                                                                                         // CHANGED:
				}                                                                                                        // CHANGED:
			}                                                                                                            // CHANGED:

			// 3) Future-proof hook: allow external providers to inject a key.                                          // CHANGED:
			$filtered = apply_filters( 'ppa_shared_key', '' );                                                           // CHANGED:
			if ( is_string( $filtered ) ) {                                                                              // CHANGED:
				$filtered = trim( $filtered );                                                                           // CHANGED:
				if ( '' !== $filtered ) {                                                                                // CHANGED:
					return $filtered;                                                                                    // CHANGED:
				}                                                                                                        // CHANGED:
			}                                                                                                            // CHANGED:

			return '';                                                                                                   // CHANGED:
		}                                                                                                                // CHANGED:

		/**
		 * Hard-require a non-empty shared key; stops with 500 if missing.
		 */
		private static function require_shared_key_or_500() {                                                            // CHANGED:
			$key = self::shared_key();
			if ( '' === trim( (string) $key ) ) {
				// Do not leak configuration details to the client.
				wp_send_json_error(
					self::error_payload(
						'server_misconfig',
						500,
						array( 'reason' => 'shared_key_missing' )
					),
					500
				);
			}
			return $key;
		}

		/**
		 * Build wp_remote_post() args; headers are filterable.
		 *
		 * @param string $raw_json
		 * @return array
		 */
		private static function build_args( $raw_json ) {                                                                // CHANGED:
			$headers = array(
				'Content-Type'     => 'application/json; charset=utf-8',
				'Accept'           => 'application/json; charset=utf-8',                                                 // CHANGED:
				'X-PPA-Key'        => self::require_shared_key_or_500(),                                                 // CHANGED:
				'User-Agent'       => 'PostPressAI-WordPress/' . ( defined( 'PPA_VERSION' ) ? PPA_VERSION : 'dev' ),
				'X-Requested-With' => 'XMLHttpRequest',                                                                  // CHANGED:
			);

			// Pass-through select client headers (no secrets): X-PPA-View + nonce.                                     // CHANGED:
			$incoming = function_exists( 'getallheaders' ) ? (array) getallheaders() : array();                         // CHANGED:

			$view = '';                                                                                                  // CHANGED:
			if ( isset( $_SERVER['HTTP_X_PPA_VIEW'] ) ) {                                                                // CHANGED:
				$view = (string) $_SERVER['HTTP_X_PPA_VIEW'];                                                            // CHANGED:
			} elseif ( isset( $incoming['X-PPA-View'] ) ) {                                                              // CHANGED:
				$view = (string) $incoming['X-PPA-View'];                                                                // CHANGED:
			}                                                                                                            // CHANGED:
			if ( $view !== '' ) {                                                                                        // CHANGED:
				$headers['X-PPA-View'] = $view;                                                                          // CHANGED:
			}                                                                                                            // CHANGED:

			$nonce = '';                                                                                                 // CHANGED:
			if ( isset( $_SERVER['HTTP_X_PPA_NONCE'] ) ) {                                                               // CHANGED:
				$nonce = (string) $_SERVER['HTTP_X_PPA_NONCE'];                                                          // CHANGED:
			} elseif ( isset( $_SERVER['HTTP_X_WP_NONCE'] ) ) {                                                          // CHANGED:
				$nonce = (string) $_SERVER['HTTP_X_WP_NONCE'];                                                           // CHANGED:
			} elseif ( isset( $incoming['X-PPA-Nonce'] ) ) {                                                             // CHANGED:
				$nonce = (string) $incoming['X-PPA-Nonce'];                                                              // CHANGED:
			} elseif ( isset( $incoming['X-WP-Nonce'] ) ) {                                                              // CHANGED:
				$nonce = (string) $incoming['X-WP-Nonce'];                                                               // CHANGED:
			}                                                                                                            // CHANGED:
			if ( $nonce !== '' ) {                                                                                       // CHANGED:
				$headers['X-PPA-Nonce'] = $nonce;                                                                        // CHANGED:
			}                                                                                                            // CHANGED:

			/**
			 * Filter the outgoing headers for Django proxy requests.
			 *
			 * @param array  $headers
			 * @param string $endpoint  Either 'preview', 'store', or 'debug_headers'.                                   // CHANGED:
			 */
			$headers = (array) apply_filters( 'ppa_outgoing_headers', $headers, self::$endpoint );                      // CHANGED:

			$args = array(
				'headers' => $headers,
				'body'    => (string) $raw_json,
				'timeout' => 90,
			);
			/**
			 * Filter the full wp_remote_post() args.
			 *
			 * @param array  $args
			 * @param string $endpoint  Either 'preview', 'store', or 'debug_headers'.                                   // CHANGED:
			 */
			$args = (array) apply_filters( 'ppa_outgoing_request_args', $args, self::$endpoint );                       // CHANGED:
			return $args;
		}

		/**
		 * Lightweight logging of successful Django proxy calls (generate/store) into WP.                                // CHANGED:
		 *
		 * @param string $kind       'generate' or 'store'.                                                              // CHANGED:
		 * @param array  $payload    Original JSON payload (assoc).                                                      // CHANGED:
		 * @param array  $json       Decoded Django response (assoc).                                                    // CHANGED:
		 * @param int    $http_code  HTTP status code from Django.                                                       // CHANGED:
		 */
		private static function log_proxy_event( $kind, $payload, $json, $http_code ) {                                  // CHANGED:
			try {                                                                                                        // CHANGED:
				$kind = sanitize_key( $kind );                                                                           // CHANGED:
				if ( '' === $kind ) {                                                                                   // CHANGED:
					return;                                                                                              // CHANGED:
				}                                                                                                        // CHANGED:

				$payload = is_array( $payload ) ? $payload : array();                                                    // CHANGED:
				$json    = is_array( $json )    ? $json    : array();                                                    // CHANGED:

				$result = array();                                                                                       // CHANGED:
				if ( isset( $json['result'] ) && is_array( $json['result'] ) ) {                                         // CHANGED:
					$result = $json['result'];                                                                            // CHANGED:
				}                                                                                                        // CHANGED:

				$title   = (string) ( $payload['title'] ?? ( $payload['subject'] ?? ( $result['title'] ?? '' ) ) );      // CHANGED:
				$subject = (string) ( $payload['subject'] ?? '' );                                                       // CHANGED:
				$wc      = isset( $payload['word_count'] ) ? (int) $payload['word_count'] : 0;                           // CHANGED:

				$provider = '';                                                                                          // CHANGED:
				if ( isset( $json['provider'] ) ) {                                                                      // CHANGED:
					$provider = (string) $json['provider'];                                                              // CHANGED:
				} elseif ( isset( $result['provider'] ) ) {                                                              // CHANGED:
					$provider = (string) $result['provider'];                                                            // CHANGED:
				}                                                                                                        // CHANGED:

				$post_type = post_type_exists( 'ppa_generation' ) ? 'ppa_generation' : 'post';                           // CHANGED:

				$label      = strtoupper( $kind );                                                                       // CHANGED:
				$log_title  = sprintf(                                                                                  // CHANGED:
					'[PPA %s] %s',                                                                                       // CHANGED:
					$label,                                                                                              // CHANGED:
					$title !== '' ? sanitize_text_field( $title ) : '(untitled)'                                        // CHANGED:
				);                                                                                                       // CHANGED:

				$excerpt_bits = array();                                                                                 // CHANGED:
				if ( '' !== $subject ) {                                                                                 // CHANGED:
					$excerpt_bits[] = 'Subject: ' . sanitize_text_field( $subject );                                     // CHANGED:
				}                                                                                                        // CHANGED:
				if ( $wc > 0 ) {                                                                                         // CHANGED:
					$excerpt_bits[] = 'Word count: ' . $wc;                                                              // CHANGED:
				}                                                                                                        // CHANGED:
				if ( '' !== $provider ) {                                                                                // CHANGED:
					$excerpt_bits[] = 'Provider: ' . sanitize_text_field( $provider );                                   // CHANGED:
				}                                                                                                        // CHANGED:
				$post_excerpt = implode( ' | ', $excerpt_bits );                                                         // CHANGED:

				$context = array(                                                                                        // CHANGED:
					'kind'       => $kind,                                                                               // CHANGED:
					'http_code'  => (int) $http_code,                                                                    // CHANGED:
					'provider'   => $provider,                                                                           // CHANGED:
					'payload'    => array(                                                                               // CHANGED:
						'subject'    => $subject,                                                                        // CHANGED:
						'title'      => $title,                                                                          // CHANGED:
						'word_count' => $wc,                                                                             // CHANGED:
					),                                                                                                   // CHANGED:
				);                                                                                                       // CHANGED:

				if ( isset( $result['id'] ) ) {                                                                          // CHANGED:
					$context['result_id'] = $result['id'];                                                               // CHANGED:
				}                                                                                                        // CHANGED:

				$content_json = function_exists( 'wp_json_encode' )                                                      // CHANGED:
					? wp_json_encode( $context )                                                                         // CHANGED:
					: json_encode( $context );                                                                           // CHANGED:

				$post_id = wp_insert_post(                                                                               // CHANGED:
					array(                                                                                               // CHANGED:
						'post_type'    => $post_type,                                                                    // CHANGED:
						'post_status'  => 'private',                                                                     // CHANGED:
						'post_title'   => $log_title,                                                                    // CHANGED:
						'post_excerpt' => $post_excerpt,                                                                 // CHANGED:
						'post_content' => (string) $content_json,                                                        // CHANGED:
					),                                                                                                   // CHANGED:
					true                                                                                                 // CHANGED:
				);                                                                                                       // CHANGED:

				if ( is_wp_error( $post_id ) || ! $post_id ) {                                                           // CHANGED:
					return;                                                                                              // CHANGED:
				}                                                                                                        // CHANGED:

				update_post_meta( $post_id, '_ppa_kind', $kind );                                                        // CHANGED:
				update_post_meta( $post_id, '_ppa_http_code', (int) $http_code );                                        // CHANGED:
				if ( '' !== $provider ) {                                                                                // CHANGED:
					update_post_meta( $post_id, '_ppa_provider', $provider );                                            // CHANGED:
				}                                                                                                        // CHANGED:
				if ( isset( $result['id'] ) ) {                                                                          // CHANGED:
					update_post_meta( $post_id, '_ppa_result_id', $result['id'] );                                       // CHANGED:
				}                                                                                                        // CHANGED:
			} catch ( \Throwable $e ) {                                                                                  // CHANGED:
				// Swallow all logging exceptions; never break the proxy.                                               // CHANGED:
			}                                                                                                            // CHANGED:
		}                                                                                                                // CHANGED:

		/* ─────────────────────────────────────────────────────────────────────
		 * HTML helpers (preview fallback to guarantee result.html)
		 * ──────────────────────────────────────────────────────────────────── */

		/** @return bool */
		private static function looks_like_html( $s ) {                                                                   // CHANGED:
			$s = (string) $s;
			if ( $s === '' ) { return false; }
			$sn = strtolower( ltrim( $s ) );
			return ( strpos( $s, '<' ) !== false && strpos( $s, '>' ) !== false )
				|| str_starts_with( $sn, '<!doctype' )
				|| str_starts_with( $sn, '<html' )
				|| str_starts_with( $sn, '<p' )
				|| str_starts_with( $sn, '<h' )
				|| str_starts_with( $sn, '<ul' )
				|| str_starts_with( $sn, '<ol' )
				|| str_starts_with( $sn, '<div' )
				|| str_starts_with( $sn, '<section' );
		}

		/** @return string */
		private static function text_to_html( $txt ) {                                                                    // CHANGED:
			$txt = (string) $txt;
			if ( $txt === '' ) { return ''; }
			$txt  = str_replace( array("\r\n","\r"), "\n", $txt );
			$safe = esc_html( $txt );
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

		/**
		 * Build preview HTML from available fields if result.html is missing.
		 *
		 * @param array $result  Django 'result' block (may contain content/html)
		 * @param array $payload Original request payload (may contain content/text/brief)
		 * @return string
		 */
		private static function derive_preview_html( $result, $payload ) {                                               // CHANGED:
			$result  = is_array( $result )  ? $result  : array();
			$payload = is_array( $payload ) ? $payload : array();

			$content = (string) ( $result['content']  ?? $payload['content'] ?? '' );
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

		/**
		 * Proxy to Django /preview/.
		 */
		public static function ajax_preview() {
			self::$endpoint = 'preview';                                                                                  // CHANGED:

			if ( ! current_user_can( 'edit_posts' ) ) {
				wp_send_json_error(
					self::error_payload(
						'forbidden',
						403,
						array( 'reason' => 'capability_missing' )
					),
					403
				);
			}
			self::must_post();                                                                                           // CHANGED:
			self::verify_nonce_or_forbid();                                                                              // CHANGED:

			$payload = self::read_json_body();                                                                           // CHANGED:
			$base    = self::django_base();                                                                              // CHANGED:

			$django_url = $base . '/preview/';

			$response = wp_remote_post( $django_url, self::build_args( $payload['raw'] ) );                              // CHANGED:

			if ( is_wp_error( $response ) ) {
				error_log( 'PPA: preview request_failed' );                                                              // CHANGED:
				wp_send_json_error(
					self::error_payload(
						'request_failed',
						500,
						array( 'detail' => $response->get_error_message() )
					),
					500
				);
			}

			$code      = (int) wp_remote_retrieve_response_code( $response );
			$resp_body = (string) wp_remote_retrieve_body( $response );

			$json = json_decode( $resp_body, true );                                                                     // CHANGED:
			if ( json_last_error() !== JSON_ERROR_NONE ) {                                                               // CHANGED:
				error_log( 'PPA: preview http ' . $code . ' (non-json)' );                                              // CHANGED:
				wp_send_json_success( array( 'raw' => $resp_body ), $code );                                            // CHANGED:
			}                                                                                                            // CHANGED:

			// Guarantee result.html for tolerant clients                                                                 // CHANGED:
			if ( is_array( $json ) ) {                                                                                   // CHANGED:
				$res  = ( isset( $json['result'] ) && is_array( $json['result'] ) ) ? $json['result'] : array();         // CHANGED:
				$html = (string) ( $res['html'] ?? '' );                                                                 // CHANGED:
				if ( $html === '' ) {                                                                                    // CHANGED:
					$derived = self::derive_preview_html( $res, $payload['json'] );                                      // CHANGED:
					if ( $derived !== '' ) {                                                                             // CHANGED:
						if ( ! isset( $json['result'] ) || ! is_array( $json['result'] ) ) {                             // CHANGED:
							$json['result'] = array();                                                                   // CHANGED:
						}                                                                                               // CHANGED:
						$json['result']['html'] = $derived;                                                              // CHANGED:
					}                                                                                                   // CHANGED:
				}                                                                                                       // CHANGED:
			}                                                                                                            // CHANGED:

			error_log( 'PPA: preview http ' . $code );                                                                   // CHANGED:
			wp_send_json_success( $json, $code );                                                                        // CHANGED:
		}

		/**
		 * Proxy to Django /store/.
		 * On success, also create a local WP post and inject links for the UI.
		 */
		public static function ajax_store() {
			self::$endpoint = 'store';                                                                                    // CHANGED:

			if ( ! current_user_can( 'edit_posts' ) ) {
				wp_send_json_error(
					self::error_payload(
						'forbidden',
						403,
						array( 'reason' => 'capability_missing' )
					),
					403
				);
			}
			self::must_post();                                                                                           // CHANGED:
			self::verify_nonce_or_forbid();                                                                              // CHANGED:

			$payload = self::read_json_body();                                                                           // CHANGED:
			$base    = self::django_base();                                                                              // CHANGED:

			$django_url = $base . '/store/';

			$response = wp_remote_post( $django_url, self::build_args( $payload['raw'] ) );                              // CHANGED:

			if ( is_wp_error( $response ) ) {
				error_log( 'PPA: store request_failed' );                                                                // CHANGED:
				wp_send_json_error(
					self::error_payload(
						'request_failed',
						500,
						array( 'detail' => $response->get_error_message() )
					),
					500
				);
			}

			$code      = (int) wp_remote_retrieve_response_code( $response );
			$resp_body = (string) wp_remote_retrieve_body( $response );

			$json = json_decode( $resp_body, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				error_log( 'PPA: store http ' . $code . ' (non-json)' );                                                // CHANGED:
				wp_send_json_success( array( 'raw' => $resp_body ), $code );
			}

			// ---------- Local WP create + link injection (kept; hardened) ----------------
			try {
				$dj_ok = ( $code >= 200 && $code < 300 );
				if ( $dj_ok && is_array( $json ) && array_key_exists( 'ok', $json ) ) {
					$dj_ok = (bool) $json['ok'];
				}
				if ( ! $dj_ok ) {
					self::log_proxy_event( 'store', $payload['json'], $json, $code );                                  // CHANGED:
					error_log( 'PPA: store http ' . $code . ' (no local create)' );                                     // CHANGED:
					wp_send_json_success( $json, $code );
				}

				$payload_json = $payload['json']; // assoc array already parsed                                         // CHANGED:
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
					self::log_proxy_event( 'store', $payload['json'], $json, $code );                                  // CHANGED:
					error_log( 'PPA: store http ' . $code . ' (links present)' );                                       // CHANGED:
					wp_send_json_success( $json, $code );
				}

				$title   = sanitize_text_field( $payload_json['title']   ?? ( $result['title']   ?? '' ) );            // CHANGED:
				$content =                         $payload_json['content'] ?? ( $result['content'] ?? ( $result['html'] ?? '' ) ); // CHANGED:
				$excerpt = sanitize_text_field( $payload_json['excerpt'] ?? ( $result['excerpt'] ?? '' ) );            // CHANGED:
				$slug    = sanitize_title(      $payload_json['slug']    ?? ( $result['slug']    ?? '' ) );            // CHANGED:
				$status  = sanitize_key(        $payload_json['status']  ?? ( $result['status']  ?? 'draft' ) );       // CHANGED:
				$mode    = sanitize_key(        $payload_json['mode']    ?? ( $result['mode']    ?? '' ) );            // CHANGED:

				$target_sites = (array) ( $payload_json['target_sites'] ?? array() );                                  // CHANGED:
				$wants_local  = ( 'update' === $mode )                                                                 // CHANGED:
				             || in_array( 'draft',   $target_sites, true )                                             // CHANGED:
				             || in_array( 'publish', $target_sites, true )                                             // CHANGED:
				             || in_array( $status, array( 'draft', 'publish', 'pending' ), true );                     // CHANGED:

				if ( $wants_local ) {
					$post_status = in_array( $status, array( 'publish', 'draft', 'pending' ), true ) ? $status : 'draft';  // CHANGED:
					// Allow explicit mode to override status for post_status when sane.                               // CHANGED:
					if ( in_array( $mode, array( 'publish', 'draft', 'pending' ), true ) ) {                           // CHANGED:
						$post_status = $mode;                                                                           // CHANGED:
					}                                                                                                   // CHANGED:

					$target_post_id = 0;                                                                               // CHANGED:
					if ( ! empty( $payload_json['id'] ) ) {                                                            // CHANGED:
						$target_post_id = (int) $payload_json['id'];                                                   // CHANGED:
					} elseif ( ! empty( $payload_json['wp_post_id'] ) ) {                                              // CHANGED:
						$target_post_id = (int) $payload_json['wp_post_id'];                                           // CHANGED:
					} elseif ( ! empty( $result['id'] ) ) {                                                            // CHANGED:
						$target_post_id = (int) $result['id'];                                                         // CHANGED:
					}                                                                                                   // CHANGED:

					$postarr = array(
						'post_title'   => $title,
						'post_content' => $content, // keep HTML from AI
						'post_excerpt' => $excerpt,
						'post_status'  => $post_status,
						'post_type'    => 'post',
						'post_author'  => get_current_user_id(),
					);
					if ( $slug ) {
						$postarr['post_name'] = $slug;
					}

					$using_update = ( 'update' === $mode && $target_post_id > 0 );                                     // CHANGED:
					if ( 'update' === $mode && $target_post_id <= 0 && empty( $json['warning'] ) ) {                   // CHANGED:
						$json['warning'] = array( 'type' => 'update_mode_missing_id' );                                // CHANGED:
					}                                                                                                   // CHANGED:

					if ( $using_update ) {                                                                              // CHANGED:
						$postarr['ID'] = $target_post_id;                                                               // CHANGED:
						$post_id = wp_update_post( wp_slash( $postarr ), true );                                       // CHANGED:
					} else {                                                                                            // CHANGED:
						$post_id = wp_insert_post( wp_slash( $postarr ), true );                                       // CHANGED:
					}                                                                                                   // CHANGED:

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

						// Inject for tolerant clients (top-level and result)
						$json['id']        = $post_id;
						$json['edit_link'] = $edit;
						$json['permalink'] = $view;

						if ( isset( $json['result'] ) && is_array( $json['result'] ) ) {
							$json['result']['id']        = $post_id;
							$json['result']['edit_link'] = $edit;
							$json['result']['permalink'] = $view;
							// Parity: also mirror into result.meta for clients that read meta only
							if ( ! isset( $json['result']['meta'] ) || ! is_array( $json['result']['meta'] ) ) {
								$json['result']['meta'] = array();
							}
							$json['result']['meta']['id']        = $post_id;                                             // CHANGED:
							$json['result']['meta']['edit_link'] = $edit;                                                // CHANGED:
							$json['result']['meta']['permalink'] = $view;                                                // CHANGED:
						}
					} else {
						$warn = is_wp_error( $post_id ) ? $post_id->get_error_message() : 'insert_failed';
						$json['warning'] = array( 'type' => 'wp_insert_post_failed', 'message' => $warn );
					}
				}
			} catch ( \Throwable $e ) {
				$json['warning'] = array( 'type' => 'local_store_exception', 'message' => $e->getMessage() );
			}
			// ---------- /hardened local create -------------------------------------------
			self::log_proxy_event( 'store', $payload['json'], $json, $code );                                            // CHANGED:
			error_log( 'PPA: store http ' . $code );                                                                    // CHANGED:
			wp_send_json_success( $json, $code );
		}

		/**
		 * Proxy to Django /generate/ for AI content generation.
		 * Wraps the AssistantRunner-backed /generate endpoint into the same JSON contract.                            // CHANGED:
		 */
		public static function ajax_generate() {                                                                        // CHANGED:
			self::$endpoint = 'generate';                                                                                // CHANGED:

			if ( ! current_user_can( 'edit_posts' ) ) {                                                                  // CHANGED:
				wp_send_json_error(                                                                                      // CHANGED:
					self::error_payload(                                                                                // CHANGED:
						'forbidden',                                                                                    // CHANGED:
						403,                                                                                            // CHANGED:
						array( 'reason' => 'capability_missing' )                                                       // CHANGED:
					),                                                                                                  // CHANGED:
					403                                                                                                 // CHANGED:
				);                                                                                                      // CHANGED:
			}                                                                                                            // CHANGED:

			self::must_post();                                                                                           // CHANGED:
			self::verify_nonce_or_forbid();                                                                              // CHANGED:

			$payload = self::read_json_body();                                                                           // CHANGED:
			$base    = self::django_base();                                                                              // CHANGED:

			$django_url = $base . '/generate/';                                                                          // CHANGED:

			$response = wp_remote_post( $django_url, self::build_args( $payload['raw'] ) );                              // CHANGED:

			if ( is_wp_error( $response ) ) {                                                                            // CHANGED:
				error_log( 'PPA: generate request_failed' );                                                            // CHANGED:
				wp_send_json_error(                                                                                    // CHANGED:
					self::error_payload(                                                                              // CHANGED:
						'request_failed',                                                                            // CHANGED:
						500,                                                                                         // CHANGED:
						array( 'detail' => $response->get_error_message() )                                          // CHANGED:
					),                                                                                                // CHANGED:
					500                                                                                               // CHANGED:
				);                                                                                                      // CHANGED:
			}                                                                                                            // CHANGED:

			$code      = (int) wp_remote_retrieve_response_code( $response );                                            // CHANGED:
			$resp_body = (string) wp_remote_retrieve_body( $response );                                                  // CHANGED:

			$json = json_decode( $resp_body, true );                                                                     // CHANGED:
			if ( json_last_error() !== JSON_ERROR_NONE ) {                                                               // CHANGED:
				error_log( 'PPA: generate http ' . $code . ' (non-json)' );                                            // CHANGED:
				wp_send_json_success( array( 'raw' => $resp_body ), $code );                                           // CHANGED:
			}                                                                                                            // CHANGED:

			error_log( 'PPA: generate http ' . $code );                                                                 // CHANGED:
			wp_send_json_success( $json, $code );                                                                       // CHANGED:
		}                                                                                                               // CHANGED:

		/**
		 * Proxy to Django /debug/headers/ for diagnostics.
		 * Returns the Django JSON body (or raw text) for Testbed inspection.
		 */
		public static function ajax_debug_headers() {                                                                     // CHANGED:
			self::$endpoint = 'debug_headers';                                                                            // CHANGED:

			if ( ! current_user_can( 'edit_posts' ) ) {                                                                  // CHANGED:
				wp_send_json_error(                                                                                      // CHANGED:
					self::error_payload(                                                                                // CHANGED:
						'forbidden',                                                                                    // CHANGED:
						403,                                                                                            // CHANGED:
						array( 'reason' => 'capability_missing' )                                                       // CHANGED:
					),                                                                                                  // CHANGED:
					403                                                                                                 // CHANGED:
				);                                                                                                      // CHANGED:
			}                                                                                                            // CHANGED:

			self::must_post();                                                                                           // CHANGED:
			self::verify_nonce_or_forbid();                                                                              // CHANGED:

			$payload = self::read_json_body();                                                                           // CHANGED:
			$base    = self::django_base();                                                                              // CHANGED:

			$django_url = $base . '/debug/headers/';                                                                     // CHANGED:

			// Reuse shared header builder, but drop body for GET semantics.                                             // CHANGED:
			$args = self::build_args( $payload['raw'] );                                                                 // CHANGED:
			unset( $args['body'] );                                                                                      // CHANGED:

			$response = wp_remote_get( $django_url, $args );                                                             // CHANGED:

			if ( is_wp_error( $response ) ) {                                                                            // CHANGED:
				error_log( 'PPA: debug_headers request_failed' );                                                       // CHANGED:
				wp_send_json_error(                                                                                    // CHANGED:
					self::error_payload(                                                                              // CHANGED:
						'request_failed',                                                                            // CHANGED:
						500,                                                                                         // CHANGED:
						array( 'detail' => $response->get_error_message() )                                          // CHANGED:
					),                                                                                                // CHANGED:
					500                                                                                               // CHANGED:
				);                                                                                                      // CHANGED:
			}                                                                                                            // CHANGED:

			$code      = (int) wp_remote_retrieve_response_code( $response );                                            // CHANGED:
			$resp_body = (string) wp_remote_retrieve_body( $response );                                                  // CHANGED:

			$json = json_decode( $resp_body, true );                                                                     // CHANGED:
			if ( json_last_error() !== JSON_ERROR_NONE ) {                                                               // CHANGED:
				error_log( 'PPA: debug_headers http ' . $code . ' (non-json)' );                                       // CHANGED:
				wp_send_json_success( array( 'raw' => $resp_body ), $code );                                            // CHANGED:
			}                                                                                                            // CHANGED:

			error_log( 'PPA: debug_headers http ' . $code );                                                            // CHANGED:
			wp_send_json_success( $json, $code );                                                                        // CHANGED:
		}                                                                                                                // CHANGED:
	} // end class PPA_Controller

	// Initialize hooks.
	add_action( 'init', array( 'PPA_Controller', 'init' ) );
}
