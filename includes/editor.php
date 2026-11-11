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
			'_dsp_no_hashtags',
			array(
				'type'          => 'array',
				'single'        => true,
				'default'       => array(),
				'show_in_rest'  => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
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

/**
 * 編集画面のプレビュー。保存していない投稿文・タイトル・抜粋・本文・カテゴリー・タグで、送り先ごとの本文を作る。
 */
function dsp_register_rest() {
	register_rest_route(
		'dsp/v1',
		'/preview',
		array(
			'methods'             => 'POST',
			'permission_callback' => function ( $request ) {
				return current_user_can( 'edit_post', (int) $request['post'] );
			},
			'args'                => array(
				'post' => array(
					'type'     => 'integer',
					'required' => true,
				),
			),
			'callback'            => 'dsp_rest_preview',
		)
	);
}
add_action( 'rest_api_init', 'dsp_register_rest' );

function dsp_rest_preview( $request ) {
	$saved = get_post( (int) $request['post'] );
	if ( ! $saved || ! in_array( $saved->post_type, dsp_post_types(), true ) ) {
		return new WP_Error( 'dsp_not_found', '記事が見つかりません。', array( 'status' => 404 ) );
	}
	// 保存していない値で上書きした写し（DB には書かない）
	$post = clone $saved;
	foreach ( array( 'title' => 'post_title', 'excerpt' => 'post_excerpt', 'content' => 'post_content' ) as $param => $field ) {
		if ( is_string( $request[ $param ] ) ) {
			$post->$field = $request[ $param ];
		}
	}
	$message = is_string( $request['message'] ) ? $request['message'] : null;
	$content = dsp_build_content( $post, $message );
	if ( is_array( $request['no_hashtags'] ) ) {
		$content['no_hashtags'] = array_map( 'strval', $request['no_hashtags'] );
	}
	foreach ( array( 'categories' => 'category_ids', 'tags' => 'tag_ids' ) as $param => $key ) {
		if ( is_array( $request[ $param ] ) ) {
			$content[ $key ] = array_map( 'intval', $request[ $param ] );
		}
	}
	// まだ公開していない記事は、公開したときの URL にする
	if ( ! function_exists( 'get_sample_permalink' ) ) {
		require_once ABSPATH . 'wp-admin/includes/post.php';
	}
	if ( 'publish' !== $saved->post_status ) {
		// スラッグが空なら編集中のタイトルから作る。返る名前は読みやすく戻してあるので、実際の URL と同じくエンコードし直す
		list( $template, $name ) = get_sample_permalink( $saved->ID, $post->post_title, (string) $saved->post_name );
		$content['url']          = str_replace( array( '%postname%', '%pagename%' ), sanitize_title( $name ), $template );
	}

	$s   = dsp_settings();
	$out = array();
	foreach ( dsp_services() as $service => $label ) {
		if ( ! dsp_ready( $service, $s ) ) {
			continue;
		}
		$out[] = array_merge(
			array(
				'id'    => $service,
				'label' => $label,
			),
			dsp_preview_text( $service, $content, $s )
		);
	}
	return rest_ensure_response( $out );
}

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
		// ハッシュタグが付く送り先だけ、記事ごとに外すチェックを出す
		$template = dsp_template( $service, $s );
		$services[] = array(
			'id'       => $service,
			'label'    => $label,
			'send'     => $post_id ? dsp_send_now_url( $post_id, $service ) : '',
			'hashtags' => false !== strpos( $template, '{tags}' ) || ( false !== strpos( $template, '{hashtags}' ) && '' !== trim( $s[ 'hashtags_' . $service ] ) ),
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
		array( 'wp-plugins', 'wp-editor', 'wp-element', 'wp-components', 'wp-data', 'wp-api-fetch' ),
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
