<?php
/**
 * ブロックエディターの右側の「SNS 投稿」パネル（投稿文・投稿しない・結果）。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dsp_register_meta() {
	$auth = function ( $allowed, $meta_key, $post_id ) {
		return current_user_can( 'edit_post', $post_id );
	};
	foreach ( dsp_post_types() as $post_type ) {
		register_post_meta(
			$post_type,
			'_dsp_message',
			array(
				'type'          => 'string',
				'single'        => true,
				'default'       => '',
				'show_in_rest'  => true,
				'auth_callback' => $auth,
			)
		);
		register_post_meta(
			$post_type,
			'_dsp_skip',
			array(
				'type'          => 'boolean',
				'single'        => true,
				'default'       => false,
				'show_in_rest'  => true,
				'auth_callback' => $auth,
			)
		);
	}
}
add_action( 'init', 'dsp_register_meta' );

function dsp_enqueue_editor() {
	$screen = get_current_screen();
	if ( ! $screen || ! in_array( $screen->post_type, dsp_post_types(), true ) ) {
		return;
	}
	$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$s       = dsp_settings();

	$services = array();
	foreach ( dsp_services() as $service => $label ) {
		if ( ! dsp_ready( $service, $s ) ) {
			continue;
		}
		$services[] = array(
			'id'    => $service,
			'label' => $label,
			'send'  => $post_id ? dsp_send_now_url( $post_id, $service ) : '',
		);
	}

	$results = array();
	if ( $post_id ) {
		foreach ( dsp_results( $post_id ) as $service => $r ) {
			$results[ $service ] = array(
				'status'  => $r['status'],
				'url'     => isset( $r['url'] ) ? $r['url'] : '',
				'message' => isset( $r['message'] ) ? $r['message'] : '',
				'time'    => isset( $r['time'] ) ? wp_date( 'n/j H:i', $r['time'] ) : '',
			);
		}
	}

	wp_enqueue_script(
		'dsp-editor',
		DSP_URL . 'assets/editor.js',
		array( 'wp-plugins', 'wp-editor', 'wp-element', 'wp-components', 'wp-data' ),
		DSP_VERSION,
		true
	);
	wp_localize_script(
		'dsp-editor',
		'dspEditor',
		array(
			'services'    => $services,
			'results'     => $results,
			'dryRun'      => ! empty( $s['dry_run'] ),
			'settingsUrl' => admin_url( 'options-general.php?page=dsp-settings' ),
			'canSettings' => current_user_can( 'manage_options' ),
		)
	);
}
add_action( 'enqueue_block_editor_assets', 'dsp_enqueue_editor' );
