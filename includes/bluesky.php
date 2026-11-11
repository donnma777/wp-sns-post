<?php
/**
 * Bluesky（AT Protocol）。アプリパスワードでログインして投稿する。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ログインする。
 *
 * @return array|WP_Error セッション（accessJwt・did・handle）
 */
function dsp_bluesky_login( $s ) {
	list( $code, $data ) = dsp_http_json(
		DSP_BLUESKY_SERVICE . '/xrpc/com.atproto.server.createSession',
		array(
			'identifier' => $s['bluesky_identifier'],
			'password'   => $s['bluesky_app_password'],
		)
	);
	if ( 200 !== $code || empty( $data['accessJwt'] ) ) {
		return new WP_Error( 'dsp_bluesky_login', 'ログインできません（' . dsp_error_text( $code, $data ) . '）' );
	}
	return $data;
}

function dsp_bluesky_test( $s ) {
	$session = dsp_bluesky_login( $s );
	if ( is_wp_error( $session ) ) {
		return $session;
	}
	return '@' . $session['handle'] . ' でログインできました。';
}

function dsp_bluesky_post( $content, $s, $state ) {
	$session = dsp_bluesky_login( $s );
	if ( is_wp_error( $session ) ) {
		return array(
			'status'  => 'failed',
			'message' => $session->get_error_message(),
		);
	}
	$auth = array( 'Authorization' => 'Bearer ' . $session['accessJwt'] );

	$external = array(
		'uri'         => $content['url'],
		'title'       => dsp_trim( $content['title'], 300 ),
		'description' => dsp_trim( $content['description'], 300 ),
	);
	// カードの画像。上げられないときは画像なしで出す（Bluesky の上限は 1MB）
	$image = dsp_card_image( $content['post_id'], 950000 );
	if ( $image ) {
		list( $code, $data ) = dsp_http(
			'POST',
			DSP_BLUESKY_SERVICE . '/xrpc/com.atproto.repo.uploadBlob',
			$image['data'],
			array_merge( $auth, array( 'Content-Type' => $image['mime'] ) )
		);
		if ( 200 === $code && isset( $data['blob'] ) ) {
			$external['thumb'] = $data['blob'];
		}
	}

	$text   = dsp_trim( $content['text'], 300 );
	$record = array(
		'$type'     => 'app.bsky.feed.post',
		'text'      => $text,
		'createdAt' => gmdate( 'Y-m-d\TH:i:s.000\Z' ),
		'langs'     => array( 'ja' ),
		'embed'     => array(
			'$type'    => 'app.bsky.embed.external',
			'external' => $external,
		),
	);
	$facets = dsp_bluesky_facets( $text );
	if ( $facets ) {
		$record['facets'] = $facets;
	}

	list( $code, $data ) = dsp_http_json(
		DSP_BLUESKY_SERVICE . '/xrpc/com.atproto.repo.createRecord',
		array(
			'repo'       => $session['did'],
			'collection' => 'app.bsky.feed.post',
			'record'     => $record,
		),
		$auth
	);
	if ( 200 !== $code || empty( $data['uri'] ) ) {
		return array(
			'status'  => 'failed',
			'message' => dsp_error_text( $code, $data ),
		);
	}
	$parts = explode( '/', $data['uri'] );
	return array(
		'status' => 'success',
		'url'    => 'https://bsky.app/profile/' . $session['handle'] . '/post/' . end( $parts ),
	);
}

/**
 * 本文の URL とハッシュタグを、押せるリンクにする（Bluesky では位置を UTF-8 のバイト数で書く）。
 */
function dsp_bluesky_facets( $text ) {
	$facets = array();

	if ( preg_match_all( '#https?://[^\s<>"\x{3000}]+#u', $text, $m, PREG_OFFSET_CAPTURE ) ) {
		foreach ( $m[0] as $hit ) {
			$url = preg_replace( '/[.,;:!?)\]）」』、。]+$/u', '', $hit[0] );
			$facets[] = array(
				'index'    => array(
					'byteStart' => $hit[1],
					'byteEnd'   => $hit[1] + strlen( $url ),
				),
				'features' => array(
					array(
						'$type' => 'app.bsky.richtext.facet#link',
						'uri'   => $url,
					),
				),
			);
		}
	}

	// 行頭か空白のあとの #タグ（全角の ＃ も）
	if ( preg_match_all( '/(?<![^\s\x{3000}])([#＃])([^\s\x{3000}#＃]+)/u', $text, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
		foreach ( $m as $hit ) {
			$tag = preg_replace( '/[^\p{L}\p{N}\p{M}_]+$/u', '', $hit[2][0] );
			if ( '' === $tag || preg_match( '/^\d+$/', $tag ) ) {
				continue;
			}
			$start    = $hit[0][1];
			$facets[] = array(
				'index'    => array(
					'byteStart' => $start,
					'byteEnd'   => $start + strlen( $hit[1][0] ) + strlen( $tag ),
				),
				'features' => array(
					array(
						'$type' => 'app.bsky.richtext.facet#tag',
						'tag'   => $tag,
					),
				),
			);
		}
	}

	return $facets;
}
