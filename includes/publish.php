<?php
/**
 * 公開したら WP-Cron で投稿する。結果は記事ごとに _dsp_results に残す。
 *
 * _dsp_results = array(
 *   'bluesky' => array(
 *     'status'   => success | pending | failed | queued | dry_run,
 *     'url'      => 投稿した URL,
 *     'message'  => エラーなど,
 *     'time'     => 最後に動いた時刻,
 *     'attempts' => 失敗した回数,
 *     'next'     => 次に動かす時刻（pending・failed）,
 *     'state'    => SNS ごとの途中の状態（Threads の下書き ID）,
 *   ), ...
 * )
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dsp_post_types() {
	return (array) apply_filters( 'dsp_post_types', array( 'post' ) );
}

function dsp_results( $post_id ) {
	$results = get_post_meta( $post_id, '_dsp_results', true );
	return is_array( $results ) ? $results : array();
}

/**
 * 「公開されていない」→「公開」のときだけ送る（更新のたびには送らない）。
 */
function dsp_on_transition( $new_status, $old_status, $post ) {
	if ( 'publish' !== $new_status || 'publish' === $old_status ) {
		return;
	}
	if ( ! in_array( $post->post_type, dsp_post_types(), true ) ) {
		return;
	}
	dsp_schedule( $post->ID, DSP_DELAY );
}
add_action( 'transition_post_status', 'dsp_on_transition', 10, 3 );

function dsp_schedule( $post_id, $delay ) {
	$args = array( (int) $post_id );
	$when = time() + max( 0, (int) $delay );
	$next = wp_next_scheduled( 'dsp_run', $args );
	if ( $next && $next <= $when ) {
		return;
	}
	if ( $next ) {
		wp_unschedule_event( $next, 'dsp_run', $args );
	}
	wp_schedule_single_event( $when, 'dsp_run', $args );
}

/**
 * 記事から投稿の中身を作る。
 * $message を渡すと、保存してある投稿文の代わりに使う（編集画面のプレビュー用）。
 */
function dsp_build_content( $post, $message = null ) {
	$title   = dsp_plain( get_the_title( $post ) );
	$message = trim( null !== $message ? (string) $message : (string) get_post_meta( $post->ID, '_dsp_message', true ) );

	// カードの説明文：Cocoon の「メタディスクリプション」→ 抜粋 → 本文の頭
	$description = trim( (string) get_post_meta( $post->ID, 'the_page_meta_description', true ) );
	if ( '' === $description && has_excerpt( $post ) ) {
		$description = $post->post_excerpt;
	}
	if ( '' === $description ) {
		$description = wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 120, '…' );
	}
	$description = trim( preg_replace( '/[\s\x{00A0}]+/u', ' ', dsp_plain( $description ) ) );

	return apply_filters(
		'dsp_content',
		array(
			'post_id'     => $post->ID,
			'text'        => '' !== $message ? $message : $title,
			'custom'      => '' !== $message,
			'title'       => $title,
			'url'         => get_permalink( $post ),
			'description' => $description,
			'no_hashtags' => dsp_no_hashtags( $post->ID ),
		),
		$post
	);
}

/**
 * 1つの記事を、送れる SNS すべてへ送る。待ちがあれば、また WP-Cron で呼ぶ。
 */
function dsp_run( $post_id ) {
	$post_id = (int) $post_id;
	$post    = get_post( $post_id );
	if ( ! $post || 'publish' !== $post->post_status || '' !== $post->post_password ) {
		return;
	}
	if ( ! in_array( $post->post_type, dsp_post_types(), true ) || get_post_meta( $post_id, '_dsp_skip', true ) ) {
		return;
	}

	// 同じ記事を2つの WP-Cron が同時に送らないように
	if ( ! add_post_meta( $post_id, '_dsp_lock', time(), true ) ) {
		$locked = (int) get_post_meta( $post_id, '_dsp_lock', true );
		if ( time() - $locked < 300 ) {
			dsp_schedule( $post_id, 60 );
			return;
		}
		update_post_meta( $post_id, '_dsp_lock', time() );
	}

	$s       = dsp_settings();
	$content = dsp_build_content( $post );
	$results = dsp_results( $post_id );
	$wake    = null;

	foreach ( dsp_services() as $service => $label ) {
		if ( ! dsp_ready( $service, $s ) ) {
			continue;
		}

		if ( isset( $results[ $service ] ) ) {
			$r = $results[ $service ];
			if ( in_array( $r['status'], array( 'success', 'dry_run' ), true ) ) {
				continue;
			}
			if ( 'failed' === $r['status'] && (int) $r['attempts'] >= DSP_MAX_ATTEMPTS ) {
				continue;
			}
			if ( ! empty( $r['next'] ) && time() < (int) $r['next'] ) {
				$wake = null === $wake ? (int) $r['next'] : min( $wake, (int) $r['next'] );
				continue;
			}
		} else {
			$r = array();
		}
		$r = array_merge(
			array(
				'attempts' => 0,
				'state'    => array(),
			),
			$r
		);

		// 送り先のひな形で本文を作る
		$service_content         = $content;
		$service_content['text'] = dsp_render_text( $service, $content, $s );

		if ( ! empty( $s['dry_run'] ) ) {
			$results[ $service ] = array(
				'status'  => 'dry_run',
				'message' => 'テストモードなので送っていません：' . dsp_trim( str_replace( "\n", ' / ', $service_content['text'] ), 120 ),
				'time'    => time(),
			);
			dsp_log( $post_id, $service, $results[ $service ]['message'] );
			continue;
		}

		$out = call_user_func( 'dsp_' . $service . '_post', $service_content, $s, $r['state'] );

		if ( 'success' === $out['status'] ) {
			$results[ $service ] = array(
				'status' => 'success',
				'url'    => $out['url'],
				'time'   => time(),
			);
			dsp_log( $post_id, $service, '' !== $out['url'] ? '投稿しました ' . $out['url'] : '送りました' );
		} elseif ( 'pending' === $out['status'] ) {
			$r['status'] = 'pending';
			$r['state']  = $out['state'];
			$r['time']   = time();
			$r['next']   = time() + (int) $out['wait'];
			unset( $r['message'] );
			$results[ $service ] = $r;
			$wake                = null === $wake ? $r['next'] : min( $wake, $r['next'] );
		} else {
			++$r['attempts'];
			$r['status']  = 'failed';
			$r['message'] = $out['message'];
			$r['time']    = time();
			if ( isset( $out['state'] ) ) {
				$r['state'] = $out['state'];
			}
			if ( $r['attempts'] < DSP_MAX_ATTEMPTS ) {
				$r['next'] = time() + DSP_RETRY_INTERVAL;
				$wake      = null === $wake ? $r['next'] : min( $wake, $r['next'] );
				dsp_log( $post_id, $service, sprintf( '失敗（%d回目、あとでもう一度試します）：%s', $r['attempts'], $out['message'] ) );
			} else {
				unset( $r['next'] );
				dsp_log( $post_id, $service, sprintf( '失敗（%d回目、あきらめました）：%s', $r['attempts'], $out['message'] ) );
				dsp_notify_failure( $post_id, $label, $out['message'] );
			}
			$results[ $service ] = $r;
		}
	}

	update_post_meta( $post_id, '_dsp_results', $results );
	delete_post_meta( $post_id, '_dsp_lock' );

	if ( null !== $wake ) {
		dsp_schedule( $post_id, max( 5, $wake - time() ) );
	}
}
add_action( 'dsp_run', 'dsp_run' );

/**
 * 「今すぐ投稿」「もう一度送る」（記事の編集画面から）。
 */
function dsp_handle_send_now() {
	$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
	$service = isset( $_GET['service'] ) ? sanitize_key( wp_unslash( $_GET['service'] ) ) : '';
	check_admin_referer( 'dsp_send_' . $post_id . '_' . $service );
	if ( ! current_user_can( 'edit_post', $post_id ) || ! isset( dsp_services()[ $service ] ) ) {
		wp_die( '権限がありません。' );
	}
	$results             = dsp_results( $post_id );
	$results[ $service ] = array(
		'status'   => 'queued',
		'time'     => time(),
		'attempts' => 0,
		'state'    => array(),
	);
	update_post_meta( $post_id, '_dsp_results', $results );
	dsp_schedule( $post_id, 0 );
	spawn_cron();
	wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'post.php?post=' . $post_id . '&action=edit' ) );
	exit;
}
add_action( 'admin_post_dsp_send_now', 'dsp_handle_send_now' );

function dsp_send_now_url( $post_id, $service ) {
	return wp_nonce_url(
		admin_url( 'admin-post.php?action=dsp_send_now&post=' . (int) $post_id . '&service=' . $service ),
		'dsp_send_' . (int) $post_id . '_' . $service
	);
}

/**
 * 毎日：Threads のトークンを延ばす。延ばせなくなったら（前は延ばせていたなら）Discord に知らせる。
 */
function dsp_schedule_daily() {
	if ( ! wp_next_scheduled( 'dsp_daily' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'dsp_daily' );
	}
}
add_action( 'admin_init', 'dsp_schedule_daily' );

function dsp_daily() {
	$before = dsp_settings();
	$result = dsp_threads_refresh();
	if ( is_wp_error( $result ) && '' !== $before['threads_token'] && '' === $before['threads_refresh_error'] ) {
		dsp_discord_send(
			sprintf(
				"SNS 投稿：%s
期限は %s ごろです。設定画面の「Threads にログインしてつなぎ直す」で直ります。
%s",
				$result->get_error_message(),
				wp_date( 'Y-m-d', (int) $before['threads_token_expires'] ),
				admin_url( 'options-general.php?page=dsp-settings' )
			),
			'alert'
		);
	}
}
add_action( 'dsp_daily', 'dsp_daily' );
