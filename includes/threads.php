<?php
/**
 * Threads。長期トークン（60日）で投稿し、毎日の WP-Cron で期限を延ばす。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * トークンでつなぐ。だれのトークンかを確かめて保存する。
 *
 * @return string|WP_Error
 */
function dsp_threads_connect( $token ) {
	list( $code, $data ) = dsp_http( 'GET', add_query_arg( array( 'fields' => 'id,username', 'access_token' => rawurlencode( $token ) ), DSP_THREADS_API . '/me' ) );
	if ( 200 !== $code || empty( $data['id'] ) ) {
		return new WP_Error( 'dsp_threads', 'トークンを確かめられません（' . dsp_error_text( $code, $data ) . '）' );
	}
	dsp_update_settings(
		array(
			'threads_token'           => $token,
			'threads_user_id'         => (string) $data['id'],
			'threads_username'        => isset( $data['username'] ) ? (string) $data['username'] : '',
			// 発行した日時はわからないので、つないだ日から 60 日とみなす
			'threads_token_expires'   => time() + 60 * DAY_IN_SECONDS,
			'threads_token_refreshed' => time(),
			'threads_refresh_error'   => '',
		)
	);
	return '@' . ( isset( $data['username'] ) ? $data['username'] : $data['id'] ) . ' とつなぎました。';
}

function dsp_threads_test( $s ) {
	list( $code, $data ) = dsp_http( 'GET', add_query_arg( array( 'fields' => 'id,username', 'access_token' => rawurlencode( $s['threads_token'] ) ), DSP_THREADS_API . '/me' ) );
	if ( 200 !== $code || empty( $data['id'] ) ) {
		return new WP_Error( 'dsp_threads', 'つながりません（' . dsp_error_text( $code, $data ) . '）' );
	}
	return '@' . ( isset( $data['username'] ) ? $data['username'] : $data['id'] ) . ' につながっています。';
}

/**
 * トークンの期限を延ばす。発行から 24 時間たっていないと延ばせない。
 *
 * @return string|WP_Error
 */
function dsp_threads_refresh( $force = false ) {
	$s = dsp_settings();
	if ( '' === $s['threads_token'] ) {
		return new WP_Error( 'dsp_threads', 'Threads とつながっていません。' );
	}
	$age = time() - (int) $s['threads_token_refreshed'];
	if ( $age < DAY_IN_SECONDS ) {
		return $force ? new WP_Error( 'dsp_threads', 'つないでから 24 時間たつまでは延ばせません。' ) : '';
	}
	if ( ! $force && $age < 7 * DAY_IN_SECONDS ) {
		return '';
	}
	list( $code, $data ) = dsp_http(
		'GET',
		add_query_arg(
			array(
				'grant_type'   => 'th_refresh_token',
				'access_token' => rawurlencode( $s['threads_token'] ),
			),
			'https://graph.threads.com/refresh_access_token'
		)
	);
	if ( 200 !== $code || empty( $data['access_token'] ) ) {
		$message = 'Threads のトークンを延ばせません（' . dsp_error_text( $code, $data ) . '）';
		dsp_update_settings( array( 'threads_refresh_error' => $message ) );
		dsp_log( 0, 'threads', $message );
		return new WP_Error( 'dsp_threads', $message );
	}
	dsp_update_settings(
		array(
			'threads_token'           => $data['access_token'],
			'threads_token_expires'   => time() + ( isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 60 * DAY_IN_SECONDS ),
			'threads_token_refreshed' => time(),
			'threads_refresh_error'   => '',
		)
	);
	return 'トークンを延ばしました。';
}

/**
 * 1回目は下書き（コンテナ）を作り、待ってから2回目で公開する。
 */
function dsp_threads_post( $content, $s, $state ) {
	$base  = DSP_THREADS_API . '/' . rawurlencode( $s['threads_user_id'] );
	$token = $s['threads_token'];

	if ( empty( $state['container'] ) ) {
		list( $code, $data ) = dsp_http(
			'POST',
			$base . '/threads',
			array(
				'media_type'      => 'TEXT',
				'text'            => dsp_trim( $content['text'], 500 ),
				'link_attachment' => $content['url'],
				'access_token'    => $token,
			)
		);
		if ( 200 !== $code || empty( $data['id'] ) ) {
			return array(
				'status'  => 'failed',
				'message' => dsp_error_text( $code, $data ),
			);
		}
		return array(
			'status' => 'pending',
			'state'  => array( 'container' => (string) $data['id'] ),
			'wait'   => DSP_THREADS_WAIT,
		);
	}

	list( $code, $data ) = dsp_http(
		'POST',
		$base . '/threads_publish',
		array(
			'creation_id'  => $state['container'],
			'access_token' => $token,
		)
	);
	if ( 200 !== $code || empty( $data['id'] ) ) {
		return array(
			'status'  => 'failed',
			'message' => dsp_error_text( $code, $data ),
			// 下書きは作り直す（下書きは 24 時間で使えなくなる）
			'state'   => array(),
		);
	}

	$url = '';
	list( $code, $info ) = dsp_http( 'GET', add_query_arg( array( 'fields' => 'permalink', 'access_token' => rawurlencode( $token ) ), DSP_THREADS_API . '/' . rawurlencode( $data['id'] ) ) );
	if ( 200 === $code && ! empty( $info['permalink'] ) ) {
		$url = $info['permalink'];
	} elseif ( '' !== $s['threads_username'] ) {
		$url = 'https://www.threads.com/@' . $s['threads_username'];
	}
	return array(
		'status' => 'success',
		'url'    => $url,
	);
}
