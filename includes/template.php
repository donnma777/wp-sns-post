<?php
/**
 * 送り先ごとのひな形。{message} などの差し込みを、記事の中身で埋める。
 * 文字数の上限を超えるときは、まず {excerpt}、次に {message} を短くして収める（URL やタグは削らない）。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 記事の公開のひな形（初めの形）。
 */
function dsp_default_templates() {
	return array(
		'bluesky'         => '{message}',
		'threads'         => '{message}',
		'x'               => "{message}\n{url}",
		'facebook'        => '{message}',
		'discord'         => "新しい記事が公開されました！\n**{message}**\n{url}",
		'discord_form'    => "フォームが送信されました。({form})\n{fields}",
		'discord_comment' => "新しいコメントが投稿されました。({status})\n投稿: {title}\n投稿者: {author}\n{url}\n\n{comment}",
	);
}

/**
 * ひな形で使える差し込み（設定画面の説明用）。
 */
function dsp_template_tags( $kind ) {
	if ( 'discord_form' === $kind ) {
		return array(
			'{form}'   => 'フォームの名前',
			'{fields}' => '送られた項目（「**項目名**: 値」を1行ずつ）',
			'{site}'   => 'サイトの名前',
		);
	}
	if ( 'discord_comment' === $kind ) {
		return array(
			'{status}'  => '承認済み・承認待ち',
			'{title}'   => '記事のタイトル',
			'{author}'  => 'コメントした人',
			'{url}'     => 'コメントの URL',
			'{comment}' => 'コメントの本文',
			'{site}'    => 'サイトの名前',
		);
	}
	return array(
		'{message}'    => '記事ごとの投稿文（空なら記事のタイトル）',
		'{title}'      => '記事のタイトル',
		'{url}'        => '記事の URL',
		'{excerpt}'    => '記事の説明文（メタディスクリプション・抜粋・本文の頭）',
		'{categories}' => 'カテゴリー（「、」区切り）',
		'{tags}'       => 'タグをハッシュタグに（#タグ1 #タグ2）',
		'{site}'       => 'サイトの名前',
	);
}

function dsp_template( $kind, $s = null ) {
	if ( null === $s ) {
		$s = dsp_settings();
	}
	$key = 'template_' . $kind;
	return isset( $s[ $key ] ) && '' !== trim( $s[ $key ] ) ? $s[ $key ] : dsp_default_templates()[ $kind ];
}

/**
 * 差し込みを埋める。空の差し込みで残った空行は詰める。
 */
function dsp_fill_template( $template, $vars ) {
	$text = strtr( str_replace( "\r\n", "\n", $template ), $vars );
	$text = preg_replace( "/[ \t]+\n/", "\n", $text );
	$text = preg_replace( "/\n{3,}/", "\n\n", $text );
	return trim( $text );
}

/**
 * 記事の公開の差し込み。
 */
function dsp_post_vars( $content ) {
	$post_id = isset( $content['post_id'] ) ? (int) $content['post_id'] : 0;
	// プレビューでは、保存前のカテゴリー・タグ（ID の配列）を content に入れて渡す
	$terms = array(
		'category' => isset( $content['category_ids'] ) ? array_filter( array_map( 'get_term', (array) $content['category_ids'] ) ) : ( $post_id ? get_the_terms( $post_id, 'category' ) : array() ),
		'post_tag' => isset( $content['tag_ids'] ) ? array_filter( array_map( 'get_term', (array) $content['tag_ids'] ) ) : ( $post_id ? get_the_terms( $post_id, 'post_tag' ) : array() ),
	);
	$categories = array();
	$tags       = array();
	foreach ( (array) $terms['category'] as $term ) {
		if ( $term instanceof WP_Term ) {
			$categories[] = wp_specialchars_decode( $term->name, ENT_QUOTES );
		}
	}
	foreach ( (array) $terms['post_tag'] as $term ) {
		if ( $term instanceof WP_Term ) {
			// ハッシュタグは空白や記号で切れるので、文字・数字・_ だけにする
			$tag = preg_replace( '/[^\p{L}\p{N}_]+/u', '', wp_specialchars_decode( $term->name, ENT_QUOTES ) );
			if ( '' !== $tag ) {
				$tags[] = '#' . $tag;
			}
		}
	}
	return array(
		'{message}'    => $content['text'],
		'{title}'      => $content['title'],
		'{url}'        => $content['url'],
		'{excerpt}'    => $content['description'],
		'{categories}' => implode( '、', $categories ),
		'{tags}'       => implode( ' ', $tags ),
		'{site}'       => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
	);
}

/**
 * 送り先ごとの文字数の数え方と上限。上限のない送り先は null。
 */
function dsp_text_limit( $service ) {
	switch ( $service ) {
		case 'bluesky':
			return array( 'dsp_length', 300 );
		case 'threads':
			return array( 'dsp_length', 500 );
		case 'x':
			return array( 'dsp_x_length', DSP_X_MAX );
		case 'discord':
			return array( 'dsp_length', DSP_DISCORD_MAX );
	}
	return null;
}

/**
 * ひな形を埋めて、上限に収める。
 */
function dsp_fit_template( $template, $vars, $limit ) {
	$text = dsp_fill_template( $template, $vars );
	if ( null === $limit || call_user_func( $limit[0], $text ) <= $limit[1] ) {
		return $text;
	}
	foreach ( array( '{excerpt}', '{message}' ) as $key ) {
		if ( '' === $vars[ $key ] || false === strpos( $template, $key ) ) {
			continue;
		}
		$chars = function_exists( 'grapheme_str_split' ) ? grapheme_str_split( $vars[ $key ] ) : preg_split( '//u', $vars[ $key ], -1, PREG_SPLIT_NO_EMPTY );
		// 収まるいちばん長い長さを二分探索で探す
		$lo = 0;
		$hi = count( $chars );
		while ( $lo < $hi ) {
			$mid           = (int) ceil( ( $lo + $hi ) / 2 );
			$try           = $vars;
			$try[ $key ]   = rtrim( implode( '', array_slice( $chars, 0, $mid ) ) ) . '…';
			if ( call_user_func( $limit[0], dsp_fill_template( $template, $try ) ) <= $limit[1] ) {
				$lo = $mid;
			} else {
				$hi = $mid - 1;
			}
		}
		$vars[ $key ] = $lo > 0 ? rtrim( implode( '', array_slice( $chars, 0, $lo ) ) ) . '…' : '';
		$text         = dsp_fill_template( $template, $vars );
		if ( call_user_func( $limit[0], $text ) <= $limit[1] ) {
			return $text;
		}
	}
	// ひな形そのものが長すぎるときは、各送り先の処理で後ろを切る
	return $text;
}

/**
 * プレビュー：その送り先へ送る本文と、長さ・上限・短くしたか。
 * 各送り先の処理で最後に切る分も、ここで同じように切って見せる。
 */
function dsp_preview_text( $service, $content, $s = null ) {
	$limit = dsp_text_limit( $service );
	$full  = dsp_fill_template( dsp_template( $service, $s ), dsp_post_vars( $content ) );
	$text  = dsp_render_text( $service, $content, $s );
	if ( $limit ) {
		$text = 'x' === $service ? dsp_x_trim( $text, $limit[1] ) : dsp_trim( $text, $limit[1] );
	}
	return array(
		'text'    => $text,
		'length'  => $limit ? call_user_func( $limit[0], $text ) : dsp_length( $text ),
		'limit'   => $limit ? $limit[1] : 0,
		'trimmed' => $limit && call_user_func( $limit[0], $full ) > $limit[1],
	);
}

/**
 * その送り先へ送る本文。
 */
function dsp_render_text( $service, $content, $s = null ) {
	$text = dsp_fit_template( dsp_template( $service, $s ), dsp_post_vars( $content ), dsp_text_limit( $service ) );
	return (string) apply_filters( 'dsp_text', $text, $service, $content );
}
