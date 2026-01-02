<?php
/**
 * PPA Controller — Server-side AJAX proxy for PostPress AI
 *
 * LOCATION
 * --------
 * /wp-content/plugins/postpress-ai/inc/class-ppa-controller.php
 *
 * CHANGE LOG
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
		 * Register AJAX hooks (admin-only).
		 */
		public static function init() {
			add_action( 'wp_ajax_ppa_preview',        array( __CLASS__, 'ajax_preview' ) );
			add_action( 'wp_ajax_ppa_store',          array( __CLASS__, 'ajax_store' ) );
			add_action( 'wp_ajax_ppa_debug_headers',  array( __CLASS__, 'ajax_debug_headers' ) ); // CHANGED:
			add_action( 'wp_ajax_ppa_generate',       array( __CLASS__, 'ajax_generate' ) );      // CHANGED:
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
		 * Read incoming request body as JSON (robust).
		 *
		 * Why this exists:
		 * - Some UI flows send application/json (php://input is valid JSON)
		 * - Some UI flows send multipart/form-data or x-www-form-urlencoded (php://input is NOT JSON)
		 *
		 * Guarantee:
		 * - We always return raw JSON that is an OBJECT ({} at minimum), never empty string.
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
		 * Hard-require a non-empty shared key; stops with 500 if missing.
		 */
		private static function require_shared_key_or_500() {
			$key = self::shared_key();
			if ( '' === trim( (string) $key ) ) {
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
		private static function build_args( $raw_json ) {
			$headers = array(
				'Content-Type'     => 'application/json; charset=utf-8',
				'Accept'           => 'application/json; charset=utf-8',
				'X-PPA-Key'        => self::require_shared_key_or_500(),
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
			self::$endpoint = 'preview';

			if ( ! current_user_can( 'edit_posts' ) ) {
				wp_send_json_error( self::error_payload( 'forbidden', 403, array( 'reason' => 'capability_missing' ) ), 403 );
			}

			self::must_post();
			self::verify_nonce_or_forbid();

			$payload = self::read_json_body();
			$base    = self::django_base();

			$django_url = $base . '/preview/';
			error_log( 'PPA: preview django_url=' . $django_url );

			$response = wp_remote_post( $django_url, self::build_args( $payload['raw'] ) );

			if ( is_wp_error( $response ) ) {
				error_log( 'PPA: preview request_failed' );
				wp_send_json_error( self::error_payload( 'request_failed', 500, array( 'detail' => $response->get_error_message() ) ), 500 );
			}

			$code      = (int) wp_remote_retrieve_response_code( $response );
			$resp_body = (string) wp_remote_retrieve_body( $response );

			$json = json_decode( $resp_body, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				error_log( 'PPA: preview http ' . $code . ' (non-json)' );
				wp_send_json_success( array( 'raw' => $resp_body ), $code );
			}

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

			error_log( 'PPA: preview http ' . $code );
			wp_send_json_success( $json, $code );
		}

		public static function ajax_store() {
			self::$endpoint = 'store';

			if ( ! current_user_can( 'edit_posts' ) ) {
				wp_send_json_error( self::error_payload( 'forbidden', 403, array( 'reason' => 'capability_missing' ) ), 403 );
			}

			self::must_post();
			self::verify_nonce_or_forbid();

			$payload = self::read_json_body();

			$base = self::django_base();

			$django_url = $base . '/store/';

			$response = wp_remote_post( $django_url, self::build_args( $payload['raw'] ) );

			if ( is_wp_error( $response ) ) {
				error_log( 'PPA: store request_failed' );
				wp_send_json_error( self::error_payload( 'request_failed', 500, array( 'detail' => $response->get_error_message() ) ), 500 );
			}

			$code      = (int) wp_remote_retrieve_response_code( $response );
			$resp_body = (string) wp_remote_retrieve_body( $response );

			$json = json_decode( $resp_body, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				error_log( 'PPA: store http ' . $code . ' (non-json)' );
				wp_send_json_success( array( 'raw' => $resp_body ), $code );
			}

			// ---------- Local WP create + link injection (kept; hardened) ----------------
			try {
				$dj_ok = ( $code >= 200 && $code < 300 );
				if ( $dj_ok && is_array( $json ) && array_key_exists( 'ok', $json ) ) {
					$dj_ok = (bool) $json['ok'];
				}
				if ( ! $dj_ok ) {
					self::log_proxy_event( 'store', $payload['json'], $json, $code );
					error_log( 'PPA: store http ' . $code . ' (no local create)' );
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
					error_log( 'PPA: store http ' . $code . ' (links present)' );
					wp_send_json_success( $json, $code );
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
			if ( $code < 200 || $code >= 300 ) { // CHANGED:
				error_log( 'PPA: store http ' . $code ); // CHANGED:
			}
			wp_send_json_success( $json, $code );
		}

		public static function ajax_generate() {
			self::$endpoint = 'generate';

			if ( ! current_user_can( 'edit_posts' ) ) {
				wp_send_json_error( self::error_payload( 'forbidden', 403, array( 'reason' => 'capability_missing' ) ), 403 );
			}

			self::must_post();
			self::verify_nonce_or_forbid();

			$payload = self::read_json_body();
			$base    = self::django_base();

			$django_url = $base . '/generate/';

			$response = wp_remote_post( $django_url, self::build_args( $payload['raw'] ) );

			if ( is_wp_error( $response ) ) {
				error_log( 'PPA: generate request_failed' );
				wp_send_json_error( self::error_payload( 'request_failed', 500, array( 'detail' => $response->get_error_message() ) ), 500 );
			}

			$code      = (int) wp_remote_retrieve_response_code( $response );
			$resp_body = (string) wp_remote_retrieve_body( $response );

			$json = json_decode( $resp_body, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				error_log( 'PPA: generate http ' . $code . ' (non-json)' );
				wp_send_json_success( array( 'raw' => $resp_body ), $code );
			}

			self::log_proxy_event( 'generate', $payload['json'], $json, $code );
			if ( $code < 200 || $code >= 300 ) { // CHANGED:
				error_log( 'PPA: generate http ' . $code ); // CHANGED:
			}
			wp_send_json_success( $json, $code );
		}

		public static function ajax_debug_headers() {
			self::$endpoint = 'debug_headers';

			if ( ! current_user_can( 'edit_posts' ) ) {
				wp_send_json_error( self::error_payload( 'forbidden', 403, array( 'reason' => 'capability_missing' ) ), 403 );
			}

			self::must_post();
			self::verify_nonce_or_forbid();

			$payload = self::read_json_body();
			$base    = self::django_base();

			$django_url = $base . '/debug/headers/';
			error_log( 'PPA: debug_headers django_url=' . $django_url );

			$args = self::build_args( $payload['raw'] );
			unset( $args['body'] );

			$response = wp_remote_get( $django_url, $args );

			if ( is_wp_error( $response ) ) {
				error_log( 'PPA: debug_headers request_failed' );
				wp_send_json_error( self::error_payload( 'request_failed', 500, array( 'detail' => $response->get_error_message() ) ), 500 );
			}

			$code      = (int) wp_remote_retrieve_response_code( $response );
			$resp_body = (string) wp_remote_retrieve_body( $response );

			$json = json_decode( $resp_body, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				error_log( 'PPA: debug_headers http ' . $code . ' (non-json)' );
				wp_send_json_success( array( 'raw' => $resp_body ), $code );
			}

			error_log( 'PPA: debug_headers http ' . $code );
			wp_send_json_success( $json, $code );
		}
	}

	add_action( 'init', array( 'PPA_Controller', 'init' ) );
}
