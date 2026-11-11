<?php
/**
 * 設定・ログ・HTTP など、どこからでも使うもの。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dsp_services() {
	return array(
		'bluesky'  => 'Bluesky',
		'threads'  => 'Threads',
		'facebook' => 'Facebook',
	);
}

function dsp_default_settings() {
	return array(
		// 入れたばかりのときは、どこにも送らない（ほかのプラグインとの二重投稿を防ぐ）
		'dry_run'                 => 1,
		'enabled_bluesky'         => 0,
		'enabled_threads'         => 0,
		'enabled_facebook'        => 0,
		'bluesky_identifier'      => '',
		'bluesky_app_password'    => '',
		'threads_token'           => '',
		'threads_user_id'         => '',
		'threads_username'        => '',
		'threads_token_expires'   => 0,
		'threads_token_refreshed' => 0,
		'threads_refresh_error'   => '',
		'facebook_page_id'        => '',
		'facebook_page_name'      => '',
		'facebook_page_token'     => '',
	);
}

/**
 * wp-config.php の定数で指定できる設定。定数があれば管理画面の値より優先する。
 */
function dsp_constant_settings() {
	return array(
		'bluesky_identifier'   => 'DSP_BLUESKY_IDENTIFIER',
		'bluesky_app_password' => 'DSP_BLUESKY_APP_PASSWORD',
		'facebook_page_id'     => 'DSP_FACEBOOK_PAGE_ID',
		'facebook_page_token'  => 'DSP_FACEBOOK_PAGE_TOKEN',
	);
}

function dsp_from_constant( $key ) {
	$map = dsp_constant_settings();
	return isset( $map[ $key ] ) && defined( $map[ $key ] ) && '' !== (string) constant( $map[ $key ] );
}

function dsp_stored_settings() {
	$stored = get_option( DSP_OPTION, array() );
	return array_merge( dsp_default_settings(), is_array( $stored ) ? $stored : array() );
}

function dsp_settings() {
	$s = dsp_stored_settings();
	foreach ( dsp_constant_settings() as $key => $constant ) {
		if ( dsp_from_constant( $key ) ) {
			$s[ $key ] = (string) constant( $constant );
		}
	}
	return $s;
}

/**
 * 設定の一部を書き換える。トークンが入るので autoload しない。
 */
function dsp_update_settings( $changes ) {
	$s = array_merge( dsp_stored_settings(), $changes );
	update_option( DSP_OPTION, $s, false );
}

/**
 * その SNS に送れる状態か（有効で、つなぐための情報がそろっている）。
 */
function dsp_ready( $service, $s = null ) {
	if ( null === $s ) {
		$s = dsp_settings();
	}
	if ( empty( $s[ 'enabled_' . $service ] ) ) {
		return false;
	}
	return dsp_connected( $service, $s );
}

function dsp_connected( $service, $s = null ) {
	if ( null === $s ) {
		$s = dsp_settings();
	}
	switch ( $service ) {
		case 'bluesky':
			return '' !== $s['bluesky_identifier'] && '' !== $s['bluesky_app_password'];
		case 'threads':
			return '' !== $s['threads_token'] && '' !== $s['threads_user_id'];
		case 'facebook':
			return '' !== $s['facebook_page_id'] && '' !== $s['facebook_page_token'];
	}
	return false;
}

/**
 * ログ（新しい順に最大 100 件）。トークンなどの秘密は書かない。
 */
function dsp_log( $post_id, $service, $message ) {
	$log = get_option( DSP_LOG_OPTION, array() );
	if ( ! is_array( $log ) ) {
		$log = array();
	}
	array_unshift(
		$log,
		array(
			'time'    => time(),
			'post'    => (int) $post_id,
			'service' => $service,
			'message' => $message,
		)
	);
	update_option( DSP_LOG_OPTION, array_slice( $log, 0, 100 ), false );
}

/**
 * HTTP で送って、ステータスコードと JSON の中身（配列）を返す。
 * $body が配列ならフォーム形式、文字列ならそのまま送る。
 */
function dsp_http( $method, $url, $body = null, $headers = array() ) {
	$args = array(
		'method'  => $method,
		'timeout' => 20,
		'headers' => $headers,
	);
	if ( null !== $body ) {
		$args['body'] = $body;
	}
	$res = wp_remote_request( $url, $args );
	if ( is_wp_error( $res ) ) {
		return array( 0, array( 'message' => $res->get_error_message() ) );
	}
	$data = json_decode( wp_remote_retrieve_body( $res ), true );
	return array( (int) wp_remote_retrieve_response_code( $res ), is_array( $data ) ? $data : array() );
}

function dsp_http_json( $url, $data, $headers = array() ) {
	$headers['Content-Type'] = 'application/json';
	return dsp_http( 'POST', $url, wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), $headers );
}

/**
 * API のエラーを短い文にする。
 */
function dsp_error_text( $code, $data ) {
	$message = '';
	if ( isset( $data['error']['message'] ) ) {
		$message = $data['error']['message'];
	} elseif ( isset( $data['message'] ) ) {
		$message = $data['message'];
	} elseif ( isset( $data['error'] ) && is_string( $data['error'] ) ) {
		$message = $data['error'];
	}
	if ( 0 === $code ) {
		return '通信できません ' . $message;
	}
	return trim( $code . ' ' . $message );
}

/**
 * 文字数（見た目の1文字）で切り詰める。
 */
function dsp_length( $text ) {
	if ( function_exists( 'grapheme_strlen' ) ) {
		$len = grapheme_strlen( $text );
		if ( false !== $len && null !== $len ) {
			return $len;
		}
	}
	return mb_strlen( $text );
}

function dsp_trim( $text, $max ) {
	if ( dsp_length( $text ) <= $max ) {
		return $text;
	}
	if ( function_exists( 'grapheme_substr' ) ) {
		$cut = grapheme_substr( $text, 0, $max - 1 );
		if ( false !== $cut ) {
			return $cut . '…';
		}
	}
	return mb_substr( $text, 0, $max - 1 ) . '…';
}

/**
 * カード用の画像（アイキャッチ）。大きすぎるときは縮めて JPEG にする。
 *
 * @return array|null array( 'data' => 中身, 'mime' => 種類 )
 */
function dsp_card_image( $post_id, $max_bytes ) {
	$id = get_post_thumbnail_id( $post_id );
	if ( ! $id ) {
		return null;
	}
	$file = get_attached_file( $id );
	if ( ! $file || ! is_file( $file ) ) {
		return null;
	}
	$type = wp_check_filetype( $file );
	if ( filesize( $file ) <= $max_bytes && in_array( $type['type'], array( 'image/jpeg', 'image/png' ), true ) ) {
		return array(
			'data' => file_get_contents( $file ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			'mime' => $type['type'],
		);
	}

	$editor = wp_get_image_editor( $file );
	if ( is_wp_error( $editor ) ) {
		return null;
	}
	$editor->resize( 1200, 1200, false );
	foreach ( array( 82, 70, 55 ) as $quality ) {
		$editor->set_quality( $quality );
		$tmp   = trailingslashit( get_temp_dir() ) . 'dsp-' . wp_generate_password( 12, false ) . '.jpg';
		$saved = $editor->save( $tmp, 'image/jpeg' );
		if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
			continue;
		}
		$data = file_get_contents( $saved['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		wp_delete_file( $saved['path'] );
		if ( false !== $data && strlen( $data ) <= $max_bytes ) {
			return array(
				'data' => $data,
				'mime' => 'image/jpeg',
			);
		}
	}
	return null;
}

/**
 * Discord Post Notify が入っていれば、失敗を Discord に知らせる。
 */
function dsp_notify_failure( $post_id, $label, $message ) {
	if ( ! function_exists( 'dpn_discord_send_message' ) ) {
		return;
	}
	dpn_discord_send_message(
		sprintf(
			"SNS Post：%s への投稿に失敗しました\n%s\n%s\n%s",
			$label,
			wp_specialchars_decode( get_the_title( $post_id ), ENT_QUOTES ),
			$message,
			admin_url( 'post.php?post=' . (int) $post_id . '&action=edit' )
		),
		'post'
	);
}
