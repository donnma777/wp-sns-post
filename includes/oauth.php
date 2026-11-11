<?php
/**
 * Threads・Facebook に「ログインしてつなぐ」。
 *
 * 1つの Meta アプリに「Threads API」と「ページの管理」の2つのユースケースを入れて使う。
 * ID とシークレットは Threads 用と Facebook 用で別（アプリ設定 → ベーシック に2組ある）。
 * 戻り先は wp-admin/admin-post.php（action なし → admin_post フック）。Meta は戻り先の URL を
 * 完全に一致で見るので、クエリを付けない。どの SNS かは state で見分ける。
 * アプリシークレットは保存しない（wp-config.php の定数か、つなぐときに入れて 10 分だけ一時保存）。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DSP_FACEBOOK_SCOPES', 'pages_show_list,pages_read_engagement,pages_manage_posts' );
define( 'DSP_THREADS_SCOPES', 'threads_basic,threads_content_publish' );

function dsp_oauth_redirect_uri() {
	return admin_url( 'admin-post.php' );
}

/**
 * アプリシークレット。定数があればそれを使う。
 */
function dsp_app_secret_constant( $service ) {
	$name = 'threads' === $service ? 'DSP_THREADS_APP_SECRET' : 'DSP_META_APP_SECRET';
	return defined( $name ) ? (string) constant( $name ) : '';
}

function dsp_app_id( $service, $s = null ) {
	if ( null === $s ) {
		$s = dsp_settings();
	}
	return 'threads' === $service ? $s['threads_app_id'] : $s['meta_app_id'];
}

/**
 * ログインの画面の URL を作る。
 *
 * @return string|WP_Error
 */
function dsp_oauth_start( $service, $secret ) {
	$s      = dsp_settings();
	$app_id = dsp_app_id( $service, $s );
	if ( '' === $app_id ) {
		return new WP_Error( 'dsp', ( 'threads' === $service ? 'Threads' : 'Facebook' ) . ' のアプリ ID を入れてください。' );
	}
	if ( '' === dsp_app_secret_constant( $service ) && '' === $secret ) {
		return new WP_Error( 'dsp', 'アプリシークレットを入れてください。' );
	}

	$state = wp_generate_password( 32, false );
	set_transient(
		'dsp_oauth_' . $state,
		dsp_seal(
			array(
				'service' => $service,
				'user'    => get_current_user_id(),
				'secret'  => $secret,
			)
		),
		10 * MINUTE_IN_SECONDS
	);

	if ( 'threads' === $service ) {
		return add_query_arg(
			array(
				'client_id'     => rawurlencode( $app_id ),
				'redirect_uri'  => rawurlencode( dsp_oauth_redirect_uri() ),
				'scope'         => DSP_THREADS_SCOPES,
				'response_type' => 'code',
				'state'         => $state,
			),
			'https://threads.com/oauth/authorize'
		);
	}

	$args = array(
		'client_id'     => rawurlencode( $app_id ),
		'redirect_uri'  => rawurlencode( dsp_oauth_redirect_uri() ),
		'response_type' => 'code',
		'state'         => $state,
	);
	// 「Facebook ログイン for Business」の設定 ID があればそれで、なければ権限を並べて頼む
	if ( '' !== $s['facebook_config_id'] ) {
		$args['config_id'] = rawurlencode( $s['facebook_config_id'] );
	} else {
		$args['scope'] = DSP_FACEBOOK_SCOPES;
	}
	return add_query_arg( $args, 'https://www.facebook.com/' . basename( DSP_GRAPH_API ) . '/dialog/oauth' );
}

/**
 * ログインから戻ってきたところ。
 *
 * @return string|WP_Error|array 結果の文。Facebook でページが複数あるときは array( 'choose' => true )
 */
function dsp_oauth_callback( $state, $code, $error ) {
	$flow = '' !== $state ? dsp_unseal( get_transient( 'dsp_oauth_' . $state ) ) : null;
	if ( ! is_array( $flow ) ) {
		return new WP_Error( 'dsp', 'ログインの手続きが見つかりません（10分たったか、もう使われています）。もう一度つないでください。' );
	}
	delete_transient( 'dsp_oauth_' . $state );
	if ( (int) $flow['user'] !== get_current_user_id() ) {
		return new WP_Error( 'dsp', 'ログインを始めたユーザーと違います。' );
	}
	if ( '' !== $error ) {
		return new WP_Error( 'dsp', 'ログインが取り消されました（' . $error . '）。' );
	}
	if ( '' === $code ) {
		return new WP_Error( 'dsp', 'コードが返ってきませんでした。' );
	}

	$service = $flow['service'];
	$secret  = dsp_app_secret_constant( $service );
	if ( '' === $secret ) {
		$secret = (string) $flow['secret'];
	}
	$app_id = dsp_app_id( $service );

	if ( 'threads' === $service ) {
		return dsp_threads_oauth_finish( $app_id, $secret, $code );
	}
	return dsp_facebook_oauth_finish( $app_id, $secret, $code );
}

function dsp_threads_oauth_finish( $app_id, $secret, $code ) {
	// コード → 短期トークン（1時間）
	list( $status, $data ) = dsp_http(
		'POST',
		'https://graph.threads.com/oauth/access_token',
		array(
			'client_id'     => $app_id,
			'client_secret' => $secret,
			'grant_type'    => 'authorization_code',
			'redirect_uri'  => dsp_oauth_redirect_uri(),
			'code'          => $code,
		)
	);
	if ( 200 !== $status || empty( $data['access_token'] ) ) {
		return new WP_Error( 'dsp_threads', 'トークンを受け取れません（' . dsp_error_text( $status, $data ) . '）' );
	}
	// 短期 → 長期（60日）
	list( $status, $long ) = dsp_http(
		'GET',
		add_query_arg(
			array(
				'grant_type'    => 'th_exchange_token',
				'client_secret' => rawurlencode( $secret ),
				'access_token'  => rawurlencode( $data['access_token'] ),
			),
			'https://graph.threads.com/access_token'
		)
	);
	if ( 200 !== $status || empty( $long['access_token'] ) ) {
		return new WP_Error( 'dsp_threads', '長期トークンに替えられません（' . dsp_error_text( $status, $long ) . '）' );
	}
	$result = dsp_threads_connect( $long['access_token'] );
	if ( ! is_wp_error( $result ) && ! empty( $long['expires_in'] ) ) {
		dsp_update_settings( array( 'threads_token_expires' => time() + (int) $long['expires_in'] ) );
	}
	return $result;
}

function dsp_facebook_oauth_finish( $app_id, $secret, $code ) {
	// コード → ユーザーの短期トークン
	list( $status, $data ) = dsp_http(
		'GET',
		add_query_arg(
			array(
				'client_id'     => rawurlencode( $app_id ),
				'client_secret' => rawurlencode( $secret ),
				'redirect_uri'  => rawurlencode( dsp_oauth_redirect_uri() ),
				'code'          => rawurlencode( $code ),
			),
			DSP_GRAPH_API . '/oauth/access_token'
		)
	);
	if ( 200 !== $status || empty( $data['access_token'] ) ) {
		return new WP_Error( 'dsp_facebook', 'トークンを受け取れません（' . dsp_error_text( $status, $data ) . '）' );
	}
	$pages = dsp_facebook_pages( $app_id, $secret, $data['access_token'] );
	if ( is_wp_error( $pages ) ) {
		return $pages;
	}
	if ( 1 === count( $pages ) ) {
		return dsp_facebook_save_page( $pages[0] );
	}
	// ページが複数あるときは、設定画面で選んでもらう（ページのトークンは選ぶまでの 10 分だけ置く）
	set_transient( 'dsp_fb_pages_' . get_current_user_id(), dsp_seal( $pages ), 10 * MINUTE_IN_SECONDS );
	return array( 'choose' => true );
}

/**
 * 管理画面で選ばれたページを保存する。
 *
 * @return string|WP_Error
 */
function dsp_facebook_choose_page( $page_id ) {
	$key   = 'dsp_fb_pages_' . get_current_user_id();
	$pages = dsp_unseal( get_transient( $key ) );
	if ( ! is_array( $pages ) ) {
		return new WP_Error( 'dsp', '10分たったので、もう一度つないでください。' );
	}
	foreach ( $pages as $page ) {
		if ( (string) $page['id'] === $page_id ) {
			delete_transient( $key );
			return dsp_facebook_save_page( $page );
		}
	}
	return new WP_Error( 'dsp', 'ページを選んでください。' );
}

function dsp_facebook_pending_pages() {
	$pages = dsp_unseal( get_transient( 'dsp_fb_pages_' . get_current_user_id() ) );
	return is_array( $pages ) ? $pages : array();
}

/**
 * 「ログインしてつなぐ」ボタン。
 */
function dsp_handle_oauth_start() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( '権限がありません。' );
	}
	check_admin_referer( 'dsp_oauth_start' );
	$service = isset( $_POST['service'] ) && 'threads' === $_POST['service'] ? 'threads' : 'facebook';
	$secret  = isset( $_POST['app_secret'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['app_secret'] ) ) ) : '';
	if ( isset( $_POST['app_id'] ) ) {
		$app_id = preg_replace( '/\D/', '', sanitize_text_field( wp_unslash( $_POST['app_id'] ) ) );
		dsp_update_settings( array( ( 'threads' === $service ? 'threads_app_id' : 'meta_app_id' ) => $app_id ) );
	}
	$url = dsp_oauth_start( $service, $secret );
	if ( is_wp_error( $url ) ) {
		dsp_flash( $url );
		dsp_redirect_back();
	}
	wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Meta のログイン画面へ
	exit;
}
add_action( 'admin_post_dsp_oauth_start', 'dsp_handle_oauth_start' );

/**
 * 戻り先（admin-post.php に action なしで戻ってくる）。
 * state がこのプラグインのものでなければ、何もしない（ほかのプラグインの admin_post に任せる）。
 */
function dsp_handle_oauth_callback() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- state で確かめる
	$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
	if ( '' === $state || ! preg_match( '/^[A-Za-z0-9]{32}$/', $state ) || ! get_transient( 'dsp_oauth_' . $state ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( '権限がありません。' );
	}
	$code  = isset( $_GET['code'] ) ? preg_replace( '/#_$/', '', sanitize_text_field( wp_unslash( $_GET['code'] ) ) ) : '';
	$error = isset( $_GET['error_description'] ) ? sanitize_text_field( wp_unslash( $_GET['error_description'] ) ) : ( isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '' );
	// phpcs:enable

	$flow   = dsp_unseal( get_transient( 'dsp_oauth_' . $state ) );
	$tab    = is_array( $flow ) && 'threads' === $flow['service'] ? 'threads' : 'facebook';
	$result = dsp_oauth_callback( $state, $code, $error );
	if ( is_array( $result ) ) {
		$result = 'ページが複数あります。投稿するページを選んでください。';
	}
	dsp_flash( $result );
	dsp_redirect_back( $tab );
}
add_action( 'admin_post', 'dsp_handle_oauth_callback' );

/**
 * 戻ってきたときに WordPress のログインが切れていたら、ログインしてからもう一度ここへ。
 */
function dsp_handle_oauth_callback_nopriv() {
	$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( preg_match( '/^[A-Za-z0-9]{32}$/', $state ) && get_transient( 'dsp_oauth_' . $state ) ) {
		auth_redirect();
	}
}
add_action( 'admin_post_nopriv', 'dsp_handle_oauth_callback_nopriv' );

function dsp_handle_choose_page() {
	dsp_check_request( 'dsp_choose_page' );
	dsp_flash( dsp_facebook_choose_page( dsp_post_value( 'page_id' ) ) );
	dsp_redirect_back();
}
add_action( 'admin_post_dsp_choose_page', 'dsp_handle_choose_page' );
