<?php
/**
 * Discord の Webhook。
 *
 * - 記事の公開：送り先の1つ（Bluesky などと同じく、テストモード・やり直し・結果の表示がある）
 * - フォームの送信・コメント・SNS 投稿の失敗などのお知らせ：その場で送る（テストモードに関係なく送る）
 *
 * 用途ごとに Webhook URL を分けられる。空の用途は「ふだんの送り先」へ送る。1行に1つ書けば複数へ送る。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Discord の1メッセージの上限は 2000 文字。余裕をみて切る
define( 'DSP_DISCORD_MAX', 1900 );

function dsp_discord_channels() {
	return array(
		'post'    => '記事の公開',
		'form'    => 'フォームの送信',
		'comment' => 'コメント',
		'alert'   => 'SNS 投稿の失敗・トークンの期限',
	);
}

/**
 * 改行区切りの文字列を URL の配列にする。
 */
function dsp_discord_lines( $raw ) {
	$urls = array();
	foreach ( preg_split( '/\r\n|\r|\n/', (string) $raw ) as $line ) {
		$line = trim( $line );
		if ( '' !== $line ) {
			$urls[] = $line;
		}
	}
	return array_values( array_unique( $urls ) );
}

/**
 * その用途の送り先。wp-config.php の DSP_DISCORD_WEBHOOK_URL があれば、すべての用途でそちらを使う。
 */
function dsp_discord_urls( $channel, $s = null ) {
	if ( defined( 'DSP_DISCORD_WEBHOOK_URL' ) && '' !== (string) DSP_DISCORD_WEBHOOK_URL ) {
		return dsp_discord_lines( DSP_DISCORD_WEBHOOK_URL );
	}
	if ( null === $s ) {
		$s = dsp_settings();
	}
	$urls = isset( $s[ 'discord_webhook_' . $channel ] ) ? dsp_discord_lines( $s[ 'discord_webhook_' . $channel ] ) : array();
	return $urls ? $urls : dsp_discord_lines( $s['discord_webhook'] );
}

/**
 * 画面に出すときは、Webhook のトークンの部分を隠す（末尾4文字だけ見せる）。
 */
function dsp_discord_mask( $url ) {
	if ( preg_match( '#^(https://[^/]+/api/webhooks/\d+/)([^/?\s]+)(.*)$#', $url, $m ) ) {
		return $m[1] . '…' . substr( $m[2], -4 ) . $m[3];
	}
	return $url;
}

/**
 * 画面から戻ってきた値を、保存する URL にする。隠したままの行は、前の値に戻す。
 *
 * @return string|WP_Error
 */
function dsp_discord_unmask( $raw, $old ) {
	$known = array();
	foreach ( dsp_discord_lines( $old ) as $url ) {
		$known[ dsp_discord_mask( $url ) ] = $url;
	}
	$urls = array();
	foreach ( dsp_discord_lines( $raw ) as $line ) {
		if ( isset( $known[ $line ] ) ) {
			$urls[] = $known[ $line ];
			continue;
		}
		$url = esc_url_raw( $line, array( 'https' ) );
		if ( '' === $url || false !== strpos( $line, '…' ) || ! wp_http_validate_url( $url ) ) {
			return new WP_Error( 'dsp', 'Webhook の URL ではありません：' . dsp_trim( $line, 60 ) );
		}
		$urls[] = $url;
	}
	return implode( "\n", array_unique( $urls ) );
}

/**
 * 1つの Webhook へ送る。
 *
 * @return true|string 失敗したときはエラーの文
 */
function dsp_discord_send_to( $url, $message ) {
	list( $code, $data ) = dsp_http_json(
		$url,
		array(
			'content'          => dsp_trim( $message, DSP_DISCORD_MAX ),
			// 本文に @everyone / @here / <@id> があっても通知しない（フォームやコメントは外部の人が書ける）
			'allowed_mentions' => array( 'parse' => array() ),
		)
	);
	if ( $code >= 200 && $code < 300 ) {
		return true;
	}
	return 'Discord：' . dsp_error_text( $code, $data );
}

/**
 * お知らせを送る（フォーム・コメント・失敗など）。送り先がなければ何もしない。
 */
function dsp_discord_send( $message, $channel ) {
	foreach ( dsp_discord_urls( $channel ) as $url ) {
		$result = dsp_discord_send_to( $url, $message );
		if ( true !== $result ) {
			dsp_log( 0, 'discord', dsp_discord_channels()[ $channel ] . 'の通知を送れません（' . $result . '）' );
		}
	}
}

/**
 * 記事の公開を送る（publish.php から送り先の1つとして呼ぶ）。
 * Webhook が複数あって一部だけ失敗したときは、やり直しで送れた先へ二重に送らないよう state に残す。
 */
function dsp_discord_post( $content, $s, $state ) {
	$done = isset( $state['done'] ) ? (array) $state['done'] : array();
	$message = $content['text']; // ひな形で作ってある

	$errors = array();
	foreach ( dsp_discord_urls( 'post', $s ) as $url ) {
		$key = md5( $url );
		if ( in_array( $key, $done, true ) ) {
			continue;
		}
		$result = dsp_discord_send_to( $url, $message );
		if ( true === $result ) {
			$done[] = $key;
		} else {
			$errors[] = $result;
		}
	}
	if ( $errors ) {
		return array(
			'status'  => 'failed',
			'message' => implode( ' / ', array_unique( $errors ) ),
			'state'   => array( 'done' => $done ),
		);
	}
	return array(
		'status' => 'success',
		'url'    => '',
	);
}

/**
 * 設定画面の「テスト通知を送る」。使っている Webhook すべてへ、何に使うかを書いて送る。
 *
 * @return string|WP_Error
 */
function dsp_discord_test( $s ) {
	$uses = array();
	foreach ( dsp_discord_channels() as $channel => $label ) {
		foreach ( dsp_discord_urls( $channel, $s ) as $url ) {
			$uses[ $url ][] = $label;
		}
	}
	if ( ! $uses ) {
		return new WP_Error( 'dsp', 'Webhook の URL を入れてください。' );
	}
	$site   = dsp_plain( get_bloginfo( 'name' ) );
	$errors = array();
	foreach ( $uses as $url => $labels ) {
		$result = dsp_discord_send_to( $url, sprintf( "%s：テスト通知です。\nこのチャンネルには「%s」を送ります。", $site, implode( '・', $labels ) ) );
		if ( true !== $result ) {
			$errors[] = dsp_discord_mask( $url ) . ' ' . $result;
		}
	}
	if ( $errors ) {
		return new WP_Error( 'dsp', implode( ' / ', $errors ) );
	}
	return sprintf( '%d か所へテスト通知を送りました。Discord を見てください。', count( $uses ) );
}

/**
 * フォームの送信を知らせる。フォームのプラグインから、送信が済んだところで呼ぶ。
 *   do_action( 'dsp_form_submitted', 'フォーム名', array( '項目名' => '値', ... ) );
 */
function dsp_discord_on_form( $form_title, $fields ) {
	if ( ! dsp_discord_urls( 'form' ) ) {
		return;
	}
	$lines = array();
	foreach ( (array) $fields as $label => $value ) {
		if ( is_array( $value ) ) {
			$value = implode( ', ', $value );
		}
		$value = (string) $value;
		// 複数行の値（本文など）は項目名の次の行から書く
		$lines[] = false !== strpos( $value, "\n" )
			? sprintf( "**%s**:\n%s", $label, $value )
			: sprintf( '**%s**: %s', $label, $value );
	}
	dsp_discord_send(
		dsp_fill_template(
			dsp_template( 'discord_form' ),
			array(
				'{form}'   => $form_title,
				'{fields}' => implode( "\n", $lines ),
				'{site}'   => dsp_plain( get_bloginfo( 'name' ) ),
			)
		),
		'form'
	);
}
add_action( 'dsp_form_submitted', 'dsp_discord_on_form', 10, 2 );

/**
 * コメントを知らせる。スパム・ゴミ箱は送らない。
 */
function dsp_discord_on_comment( $comment_id, $comment ) {
	if ( in_array( $comment->comment_approved, array( 'spam', 'trash' ), true ) || ! dsp_discord_urls( 'comment' ) ) {
		return;
	}
	$post = get_post( $comment->comment_post_ID );
	if ( ! $post ) {
		return;
	}
	dsp_discord_send(
		dsp_fill_template(
			dsp_template( 'discord_comment' ),
			array(
				'{status}'  => '1' === (string) $comment->comment_approved ? '承認済み' : '承認待ち',
				'{title}'   => dsp_plain( get_the_title( $post ) ),
				'{author}'  => $comment->comment_author,
				'{url}'     => get_comment_link( $comment ),
				'{comment}' => wp_strip_all_tags( $comment->comment_content ),
				'{site}'    => dsp_plain( get_bloginfo( 'name' ) ),
			)
		),
		'comment'
	);
}
add_action( 'wp_insert_comment', 'dsp_discord_on_comment', 10, 2 );
