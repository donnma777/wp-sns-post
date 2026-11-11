<?php
/**
 * X（旧 Twitter）。API v2 の POST /2/tweets に、OAuth 1.0a（開発者コンソールで作る4つの鍵）で送る。
 * 鍵に期限はない。X の API は従量課金で、リンク付きの投稿は1件ごとに料金がかかる。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DSP_X_API', 'https://api.x.com/2' );
// X の文字数の上限（全角は2、URL は 23 と数える）
define( 'DSP_X_MAX', 280 );
define( 'DSP_X_URL_LENGTH', 23 );

/**
 * X の数え方での長さ。ラテン文字などは1、日本語などは2。
 */
function dsp_x_weight( $text ) {
	$n = 0;
	foreach ( preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY ) as $ch ) {
		$cp = dsp_x_codepoint( $ch );
		$n += ( $cp <= 4351 || ( $cp >= 8192 && $cp <= 8205 ) || ( $cp >= 8208 && $cp <= 8223 ) || ( $cp >= 8242 && $cp <= 8247 ) ) ? 1 : 2;
	}
	return $n;
}

/**
 * UTF-8 の1文字のコードポイント（mbstring がなくても動くように）。
 */
function dsp_x_codepoint( $ch ) {
	$b = array_values( unpack( 'C*', $ch ) );
	switch ( count( $b ) ) {
		case 1:
			return $b[0];
		case 2:
			return ( ( $b[0] & 0x1F ) << 6 ) | ( $b[1] & 0x3F );
		case 3:
			return ( ( $b[0] & 0x0F ) << 12 ) | ( ( $b[1] & 0x3F ) << 6 ) | ( $b[2] & 0x3F );
	}
	return ( ( $b[0] & 0x07 ) << 18 ) | ( ( $b[1] & 0x3F ) << 12 ) | ( ( $b[2] & 0x3F ) << 6 ) | ( $b[3] & 0x3F );
}

/**
 * 本文の中の URL を 23 として数えた長さ。
 */
function dsp_x_length( $text ) {
	$urls = 0;
	$rest = preg_replace_callback(
		'#https?://[^\s　]+#u',
		function () use ( &$urls ) {
			++$urls;
			return '';
		},
		$text
	);
	return dsp_x_weight( $rest ) + $urls * DSP_X_URL_LENGTH;
}

/**
 * 上限に収まるよう、見た目の1文字ずつ削って「…」を付ける。
 */
function dsp_x_trim( $text, $max ) {
	if ( dsp_x_length( $text ) <= $max ) {
		return $text;
	}
	$chars = function_exists( 'grapheme_str_split' ) ? grapheme_str_split( $text ) : preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
	while ( $chars && dsp_x_length( implode( '', $chars ) . '…' ) > $max ) {
		array_pop( $chars );
	}
	return rtrim( implode( '', $chars ) ) . '…';
}

/**
 * OAuth 1.0a の Authorization ヘッダー。JSON の本文は署名に含めない。
 * $fixed は nonce・時刻を決めて署名を確かめるとき用。
 */
function dsp_x_auth_header( $method, $url, $s, $query = array(), $fixed = array() ) {
	$oauth = array_merge( array(
		'oauth_consumer_key'     => $s['x_api_key'],
		'oauth_nonce'            => wp_generate_password( 32, false ),
		'oauth_signature_method' => 'HMAC-SHA1',
		'oauth_timestamp'        => (string) time(),
		'oauth_token'            => $s['x_access_token'],
		'oauth_version'          => '1.0',
	), $fixed );
	$params = array_merge( $oauth, $query );
	ksort( $params );
	$pairs = array();
	foreach ( $params as $k => $v ) {
		$pairs[] = rawurlencode( $k ) . '=' . rawurlencode( $v );
	}
	$base                     = strtoupper( $method ) . '&' . rawurlencode( $url ) . '&' . rawurlencode( implode( '&', $pairs ) );
	$key                      = rawurlencode( $s['x_api_secret'] ) . '&' . rawurlencode( $s['x_access_secret'] );
	$oauth['oauth_signature'] = base64_encode( hash_hmac( 'sha1', $base, $key, true ) );

	$parts = array();
	foreach ( $oauth as $k => $v ) {
		$parts[] = rawurlencode( $k ) . '="' . rawurlencode( $v ) . '"';
	}
	return 'OAuth ' . implode( ', ', $parts );
}

/**
 * X のエラーを短い文にする。クレジット切れは分かるように。
 */
function dsp_x_error( $code, $data ) {
	if ( 402 === $code ) {
		return 'API のクレジットが足りません。開発者コンソールでクレジットを買い足してください（' . dsp_error_text( $code, $data ) . '）';
	}
	if ( 401 === $code ) {
		return '鍵が正しくないか、取り消されています（' . dsp_error_text( $code, $data ) . '）';
	}
	if ( 403 === $code && isset( $data['detail'] ) && false !== stripos( $data['detail'], 'oauth1 app permissions' ) ) {
		return 'アプリの権限が「読み取りのみ」です。開発者コンソールで「読み取りと書き込み」にしてから、アクセストークンを作り直してください（' . dsp_error_text( $code, $data ) . '）';
	}
	return dsp_error_text( $code, $data );
}

/**
 * 鍵が使えるか確かめ、アカウント名を覚える（GET /2/users/me。読み取りの料金が少しかかる）。
 *
 * @return string|WP_Error
 */
function dsp_x_test( $s ) {
	$url                 = DSP_X_API . '/users/me';
	list( $code, $data ) = dsp_http( 'GET', $url, null, array( 'Authorization' => dsp_x_auth_header( 'GET', $url, $s ) ) );
	if ( 200 !== $code || empty( $data['data']['username'] ) ) {
		return new WP_Error( 'dsp', 'X：' . dsp_x_error( $code, $data ) );
	}
	if ( ! dsp_from_constant( 'x_access_token' ) ) {
		dsp_update_settings( array( 'x_username' => $data['data']['username'] ) );
	}
	return 'X の @' . $data['data']['username'] . ' とつながっています。';
}

function dsp_x_post( $content, $s, $state ) {
	// 本文はひな形で作ってある（初めの形は「{message} 改行 {url}」）
	$text = dsp_x_trim( $content['text'], DSP_X_MAX );
	$url  = DSP_X_API . '/tweets';

	list( $code, $data ) = dsp_http(
		'POST',
		$url,
		wp_json_encode( array( 'text' => $text ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
		array(
			'Authorization' => dsp_x_auth_header( 'POST', $url, $s ),
			'Content-Type'  => 'application/json',
		)
	);
	if ( ( 200 !== $code && 201 !== $code ) || empty( $data['data']['id'] ) ) {
		return array(
			'status'  => 'failed',
			'message' => 'X：' . dsp_x_error( $code, $data ),
		);
	}
	return array(
		'status' => 'success',
		'url'    => ( '' !== $s['x_username'] ? 'https://x.com/' . rawurlencode( $s['x_username'] ) : 'https://x.com/i/web' ) . '/status/' . rawurlencode( $data['data']['id'] ),
	);
}
