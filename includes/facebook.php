<?php
/**
 * Facebook ページ。ページのアクセストークン（期限なし）で投稿する。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ユーザーの短期トークンから、期限のないページのトークンを作って保存する。
 * アプリシークレットとユーザーのトークンは保存しない。
 *
 * @return string|WP_Error
 */
function dsp_facebook_connect( $app_id, $app_secret, $user_token, $page_id ) {
	// 1. 短期（約1時間）→ 長期のユーザートークン。長期のユーザートークンから取ったページのトークンは期限がない
	list( $code, $data ) = dsp_http(
		'GET',
		add_query_arg(
			array(
				'grant_type'        => 'fb_exchange_token',
				'client_id'         => rawurlencode( $app_id ),
				'client_secret'     => rawurlencode( $app_secret ),
				'fb_exchange_token' => rawurlencode( $user_token ),
			),
			DSP_GRAPH_API . '/oauth/access_token'
		)
	);
	if ( 200 !== $code || empty( $data['access_token'] ) ) {
		return new WP_Error( 'dsp_facebook', '長期トークンに替えられません（' . dsp_error_text( $code, $data ) . '）' );
	}

	// 2. 管理しているページの一覧
	list( $code, $data ) = dsp_http(
		'GET',
		add_query_arg(
			array(
				'fields'       => 'id,name,access_token',
				'limit'        => 100,
				'access_token' => rawurlencode( $data['access_token'] ),
			),
			DSP_GRAPH_API . '/me/accounts'
		)
	);
	if ( 200 !== $code || ! isset( $data['data'] ) ) {
		return new WP_Error( 'dsp_facebook', 'ページの一覧を読めません（' . dsp_error_text( $code, $data ) . '）' );
	}
	$pages = array();
	foreach ( $data['data'] as $page ) {
		if ( ! empty( $page['id'] ) && ! empty( $page['access_token'] ) ) {
			$pages[] = $page;
		}
	}
	if ( ! $pages ) {
		return new WP_Error( 'dsp_facebook', '管理しているページが見つかりません。トークンを作るときに pages_manage_posts などの権限とページを選んでください。' );
	}

	$chosen = null;
	if ( '' !== $page_id ) {
		foreach ( $pages as $page ) {
			if ( (string) $page['id'] === $page_id ) {
				$chosen = $page;
			}
		}
	} elseif ( 1 === count( $pages ) ) {
		$chosen = $pages[0];
	}
	if ( ! $chosen ) {
		$list = array();
		foreach ( $pages as $page ) {
			$list[] = $page['name'] . '（' . $page['id'] . '）';
		}
		return new WP_Error( 'dsp_facebook', 'どのページか決められません。ページ ID を入れてもう一度つないでください：' . implode( '、', $list ) );
	}

	dsp_update_settings(
		array(
			'facebook_page_id'    => (string) $chosen['id'],
			'facebook_page_name'  => isset( $chosen['name'] ) ? (string) $chosen['name'] : '',
			'facebook_page_token' => (string) $chosen['access_token'],
		)
	);
	return 'ページ「' . ( isset( $chosen['name'] ) ? $chosen['name'] : $chosen['id'] ) . '」とつなぎました。';
}

function dsp_facebook_test( $s ) {
	list( $code, $data ) = dsp_http(
		'GET',
		add_query_arg(
			array(
				'fields'       => 'id,name',
				'access_token' => rawurlencode( $s['facebook_page_token'] ),
			),
			DSP_GRAPH_API . '/' . rawurlencode( $s['facebook_page_id'] )
		)
	);
	if ( 200 !== $code || empty( $data['id'] ) ) {
		return new WP_Error( 'dsp_facebook', 'つながりません（' . dsp_error_text( $code, $data ) . '）' );
	}
	return 'ページ「' . ( isset( $data['name'] ) ? $data['name'] : $data['id'] ) . '」につながっています。';
}

function dsp_facebook_post( $content, $s, $state ) {
	list( $code, $data ) = dsp_http(
		'POST',
		DSP_GRAPH_API . '/' . rawurlencode( $s['facebook_page_id'] ) . '/feed',
		array(
			'message'      => $content['text'],
			'link'         => $content['url'],
			'access_token' => $s['facebook_page_token'],
		)
	);
	if ( 200 !== $code || empty( $data['id'] ) ) {
		return array(
			'status'  => 'failed',
			'message' => dsp_error_text( $code, $data ),
		);
	}
	return array(
		'status' => 'success',
		'url'    => 'https://www.facebook.com/' . $data['id'],
	);
}
