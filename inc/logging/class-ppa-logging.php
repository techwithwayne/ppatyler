<?php
// /home/customer/www/techwithwayne.com/public_html/wp-content/plugins/postpress-ai/inc/logging/class-ppa-logging.php
/**
 * PostPress AI — Logging / History (CPT)
 *
 * ========= CHANGE LOG =========
 * 2026-01-01: HARDEN: Register admin-only list-table hooks only in admin context (no bleed / no overhead elsewhere). # CHANGED:
 *
 * 2025-11-04: Admin list niceties + CLI-safe row action.                                            # CHANGED:
 *             - Toolbar filters (Type/Status/Provider).                                             # CHANGED:
 *             - Query parsing to apply filters.                                                     # CHANGED:
 *             - Row action "View details" with fallback link when get_edit_post_link() is empty.    # CHANGED:
 * 2025-11-04: Hardening + polish (no REST, no rewrite).                                             # CHANGED:
 * 2025-11-03: New file: CPT `ppa_generation` + admin columns + log_event() helper.                  # CHANGED:
 * ================================================================================================
 */

namespace PPA\Logging; // CHANGED:

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central logging utilities + CPT registration.
 */
class PPALogging { // CHANGED:

	/** Wire hooks (to be called from plugin bootstrap). */
	public static function init() { // CHANGED:
		// CPT must exist everywhere (frontend + admin) because log_event() can run from either context. // CHANGED:
		add_action( 'init', [ __CLASS__, 'register_cpt' ] );                                   // CHANGED:

		// Admin-only list table niceties (columns/filters/row actions).                          // CHANGED:
		if ( is_admin() ) {                                                                      // CHANGED:
			add_filter( 'manage_edit-ppa_generation_columns', [ __CLASS__, 'admin_columns' ] );    // CHANGED:
			add_action(
				'manage_ppa_generation_posts_custom_column',
				[ __CLASS__, 'admin_column_render' ],
				10,
				2
			);                                                                                     // CHANGED:

			// Admin list niceties                                                                 // CHANGED:
			add_action( 'restrict_manage_posts', [ __CLASS__, 'render_filters' ] );                // CHANGED:
			add_action( 'parse_query', [ __CLASS__, 'apply_filters_to_query' ] );                  // CHANGED:
			add_filter( 'post_row_actions', [ __CLASS__, 'row_actions' ], 10, 2 );                 // CHANGED:
		}                                                                                         // CHANGED:
	} // CHANGED:

	/** Register the `ppa_generation` CPT to store history rows. */
	public static function register_cpt() { // CHANGED:
		$labels = [
			'name'               => __( 'PPA Generations', 'postpress-ai' ),
			'singular_name'      => __( 'PPA Generation', 'postpress-ai' ),
			'add_new_item'       => __( 'Add PPA Generation', 'postpress-ai' ),
			'edit_item'          => __( 'Edit PPA Generation', 'postpress-ai' ),
			'new_item'           => __( 'New PPA Generation', 'postpress-ai' ),
			'view_item'          => __( 'View PPA Generation', 'postpress-ai' ),
			'search_items'       => __( 'Search PPA Generations', 'postpress-ai' ),
			'not_found'          => __( 'No generations found', 'postpress-ai' ),
			'not_found_in_trash' => __( 'No generations found in Trash', 'postpress-ai' ),
			'menu_name'          => __( 'PPA History', 'postpress-ai' ),
		];

		register_post_type(
			'ppa_generation',
			[
				'labels'              => $labels,
				'public'              => false,                   // not publicly queryable
				'show_ui'             => true,                    // visible in admin
				'show_in_menu'        => 'postpress-ai',          // group under our menu
				'show_in_admin_bar'   => false,
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'has_archive'         => false,
				'hierarchical'        => false,
				'supports'            => [ 'title', 'editor', 'author' ], // keep simple; details in meta
				'menu_icon'           => 'dashicons-media-text',
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
				'show_in_rest'        => false,                   // CHANGED: not in REST
				'rewrite'             => false,                   // CHANGED: no permalinks
			]
		);
	} // CHANGED:

	/** Define admin list columns. */
	public static function admin_columns( $cols ) { // CHANGED:
		// Keep checkbox/title/date but add quick info columns.
		$out = [];
		foreach ( $cols as $key => $label ) {
			if ( 'cb' === $key || 'title' === $key ) {
				$out[ $key ] = $label;
			}
		}
		$out['ppa_type']     = __( 'Type', 'postpress-ai' );           // preview|store|error
		$out['ppa_provider'] = __( 'Provider', 'postpress-ai' );       // django|local-fallback
		$out['ppa_status']   = __( 'Status', 'postpress-ai' );         // ok|fail
		$out['ppa_excerpt']  = __( 'Excerpt', 'postpress-ai' );        // short summary
		$out['date']         = __( 'Date', 'postpress-ai' );

		return $out;
	} // CHANGED:

	/** Render custom column values. */
	public static function admin_column_render( $column, $post_id ) { // CHANGED:
		switch ( $column ) {
			case 'ppa_type':
				echo esc_html( get_post_meta( $post_id, '_ppa_type', true ) ?: '-' );
				break;
			case 'ppa_provider':
				echo esc_html( get_post_meta( $post_id, '_ppa_provider', true ) ?: '-' );
				break;
			case 'ppa_status':
				echo esc_html( get_post_meta( $post_id, '_ppa_status', true ) ?: '-' );
				break;
			case 'ppa_excerpt':
				$ex = (string) get_post_meta( $post_id, '_ppa_excerpt', true );
				if ( '' === $ex ) {
					// Fall back to trimmed content if meta missing.
					$raw = get_post_field( 'post_content', $post_id );
					$ex  = wp_trim_words( wp_strip_all_tags( (string) $raw ), 18, '…' );
				}
				echo esc_html( $ex );
				break;
		}
	} // CHANGED:

	/** Toolbar filters above the list table (Type/Status/Provider). */
	public static function render_filters() { // CHANGED:
		global $typenow;
		if ( 'ppa_generation' !== ( $typenow ?? '' ) ) {
			return;
		}

		$type     = isset( $_GET['ppa_type'] ) ? sanitize_text_field( (string) $_GET['ppa_type'] ) : '';        // CHANGED:
		$status   = isset( $_GET['ppa_status'] ) ? sanitize_text_field( (string) $_GET['ppa_status'] ) : '';    // CHANGED:
		$provider = isset( $_GET['ppa_provider'] ) ? sanitize_text_field( (string) $_GET['ppa_provider'] ) : ''; // CHANGED:

		$types     = [ '' => __( 'All Types', 'postpress-ai' ), 'preview' => 'preview', 'store' => 'store', 'error' => 'error' ];
		$statuses  = [ '' => __( 'All Statuses', 'postpress-ai' ), 'ok' => 'ok', 'fail' => 'fail' ];
		$providers = [ '' => __( 'All Providers', 'postpress-ai' ), 'django' => 'django', 'local-fallback' => 'local-fallback' ];

		echo '<select name="ppa_type" id="filter-by-ppa-type">';
		foreach ( $types as $val => $label ) {
			echo '<option value="' . esc_attr( $val ) . '"' . selected( $type, $val, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';

		echo '<select name="ppa_status" id="filter-by-ppa-status" style="margin-left:6px">';
		foreach ( $statuses as $val => $label ) {
			echo '<option value="' . esc_attr( $val ) . '"' . selected( $status, $val, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';

		echo '<select name="ppa_provider" id="filter-by-ppa-provider" style="margin-left:6px">';
		foreach ( $providers as $val => $label ) {
			echo '<option value="' . esc_attr( $val ) . '"' . selected( $provider, $val, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	} // CHANGED:

	/**
	 * Apply toolbar filters to the list table query.
	 * @param \WP_Query $q
	 */
	public static function apply_filters_to_query( $q ) { // CHANGED:
		if ( ! is_admin() || ! $q instanceof \WP_Query ) {
			return;
		}
		$post_type = isset( $_GET['post_type'] ) ? (string) $_GET['post_type'] : ( $q->get( 'post_type' ) ?: '' );
		if ( 'ppa_generation' !== $post_type ) {
			return;
		}

		$type     = isset( $_GET['ppa_type'] ) ? sanitize_text_field( (string) $_GET['ppa_type'] ) : '';
		$status   = isset( $_GET['ppa_status'] ) ? sanitize_text_field( (string) $_GET['ppa_status'] ) : '';
		$provider = isset( $_GET['ppa_provider'] ) ? sanitize_text_field( (string) $_GET['ppa_provider'] ) : '';

		$meta_query = (array) $q->get( 'meta_query' );
		if ( $type !== '' ) {
			$meta_query[] = [ 'key' => '_ppa_type', 'value' => $type, 'compare' => '=' ];
		}
		if ( $status !== '' ) {
			$meta_query[] = [ 'key' => '_ppa_status', 'value' => $status, 'compare' => '=' ];
		}
		if ( $provider !== '' ) {
			$meta_query[] = [ 'key' => '_ppa_provider', 'value' => $provider, 'compare' => '=' ];
		}
		if ( ! empty( $meta_query ) ) {
			$q->set( 'meta_query', $meta_query );
		}
	} // CHANGED:

	/**
	 * Row actions: add "View details". Uses direct admin_url fallback for CLI/non-admin contexts.  # CHANGED:
	 * @param array   $actions
	 * @param \WP_Post $post
	 * @return array
	 */
	public static function row_actions( $actions, $post ) { // CHANGED:
		if ( $post instanceof \WP_Post && $post->post_type === 'ppa_generation' ) {
			$link = get_edit_post_link( $post->ID, '' );
			if ( empty( $link ) ) {                                                                 // CHANGED:
				$link = admin_url( 'post.php?post=' . (int) $post->ID . '&action=edit' );          // CHANGED:
			}
			if ( $link ) {
				$actions['ppa_view'] = '<a href="' . esc_url( $link ) . '">' . esc_html__( 'View details', 'postpress-ai' ) . '</a>';
			}
		}
		return $actions;
	} // CHANGED:

	/** Insert a generation log row. */
	public static function log_event( array $args ) { // CHANGED:
		$defaults = [
			'type'     => 'preview',
			'subject'  => '',
			'provider' => '',
			'status'   => '',
			'message'  => '',
			'excerpt'  => '',
			'content'  => '',
			'meta'     => [],
		];
		$a = array_merge( $defaults, $args );

		$title = trim( sprintf(
			'%s — %s %s',
			$a['subject'] !== '' ? $a['subject'] : __( 'Untitled', 'postpress-ai' ),
			strtoupper( (string) $a['type'] ),
			( $a['status'] ? '[' . $a['status'] . ']' : '' )
		) );

		$post_id = wp_insert_post(
			[
				'post_type'   => 'ppa_generation',
				'post_status' => 'publish', // history rows are immediately visible in admin
				'post_title'  => $title,
				// Allow rich content (already server-side); store as-is.
				'post_content'=> (string) $a['content'],
			],
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Core metadata we expect to query in the list table.
		update_post_meta( $post_id, '_ppa_type',     sanitize_text_field( (string) $a['type'] ) );
		update_post_meta( $post_id, '_ppa_provider', sanitize_text_field( (string) $a['provider'] ) );
		update_post_meta( $post_id, '_ppa_status',   sanitize_text_field( (string) $a['status'] ) );
		update_post_meta( $post_id, '_ppa_message',  sanitize_text_field( (string) $a['message'] ) );
		update_post_meta( $post_id, '_ppa_excerpt',  sanitize_text_field( (string) $a['excerpt'] ) );

		// Any extra keys (e.g., tone/genre/word_count/slug).
		if ( is_array( $a['meta'] ) ) {
			foreach ( $a['meta'] as $k => $v ) {
				if ( is_string( $k ) ) {
					update_post_meta(
						$post_id,
						sanitize_key( $k ),
						is_scalar( $v ) ? (string) $v : wp_json_encode( $v )
					);
				}
			}
		}

		return (int) $post_id;
	} // CHANGED:
} // CHANGED:
