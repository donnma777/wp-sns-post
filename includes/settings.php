<?php
/**
 * 設定 → SNS 投稿。
 * トークンなどの秘密は画面に出さない（入れ直すときだけ入力する）。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dsp_add_settings_page() {
	add_options_page( 'SNS 投稿', 'SNS 投稿', 'manage_options', 'dsp-settings', 'dsp_render_settings_page' );
}
add_action( 'admin_menu', 'dsp_add_settings_page' );

function dsp_action_links( $links ) {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=dsp-settings' ) ) . '">設定</a>' );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( DSP_FILE ), 'dsp_action_links' );

/**
 * 処理のあとの知らせ（リダイレクトをまたぐので transient に入れる）。
 */
function dsp_flash( $result ) {
	if ( is_wp_error( $result ) ) {
		$notice = array( 'error', $result->get_error_message() );
	} else {
		$notice = array( 'success', '' !== (string) $result ? (string) $result : '保存しました。' );
	}
	set_transient( 'dsp_notice_' . get_current_user_id(), $notice, 60 );
}

function dsp_redirect_back() {
	wp_safe_redirect( admin_url( 'options-general.php?page=dsp-settings' ) );
	exit;
}

function dsp_check_request( $action ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( '権限がありません。' );
	}
	check_admin_referer( $action );
}

function dsp_post_value( $key ) {
	return isset( $_POST[ $key ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
}

/**
 * 基本の設定と Bluesky。
 */
function dsp_handle_save() {
	dsp_check_request( 'dsp_save' );
	$changes = array( 'dry_run' => empty( $_POST['dry_run'] ) ? 0 : 1 );
	foreach ( array_keys( dsp_services() ) as $service ) {
		$changes[ 'enabled_' . $service ] = empty( $_POST[ 'enabled_' . $service ] ) ? 0 : 1;
	}
	if ( ! dsp_from_constant( 'bluesky_identifier' ) ) {
		$changes['bluesky_identifier'] = ltrim( dsp_post_value( 'bluesky_identifier' ), '@' );
	}
	$changes['facebook_config_id'] = preg_replace( '/\D/', '', dsp_post_value( 'facebook_config_id' ) );
	$password = dsp_post_value( 'bluesky_app_password' );
	if ( '' !== $password && ! dsp_from_constant( 'bluesky_app_password' ) ) {
		$changes['bluesky_app_password'] = $password;
	}
	dsp_update_settings( $changes );
	dsp_flash( '' );
	dsp_redirect_back();
}
add_action( 'admin_post_dsp_save', 'dsp_handle_save' );

/**
 * Discord の Webhook。画面では隠して出すので、隠したままの行は前の値に戻す。
 */
function dsp_handle_discord_save() {
	dsp_check_request( 'dsp_discord_save' );
	$old     = dsp_stored_settings();
	$changes = array();
	$keys    = array_merge( array( 'discord_webhook' ), array_map( function ( $c ) {
		return 'discord_webhook_' . $c;
	}, array_keys( dsp_discord_channels() ) ) );
	foreach ( $keys as $key ) {
		$raw = isset( $_POST[ $key ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$value = dsp_discord_unmask( $raw, $old[ $key ] );
		if ( is_wp_error( $value ) ) {
			dsp_flash( $value );
			dsp_redirect_back();
		}
		$changes[ $key ] = $value;
	}
	dsp_update_settings( $changes );
	dsp_flash( '' );
	dsp_redirect_back();
}
add_action( 'admin_post_dsp_discord_save', 'dsp_handle_discord_save' );

function dsp_handle_threads_connect() {
	dsp_check_request( 'dsp_threads_connect' );
	$token = dsp_post_value( 'threads_token' );
	dsp_flash( '' === $token ? new WP_Error( 'dsp', 'トークンを入れてください。' ) : dsp_threads_connect( $token ) );
	dsp_redirect_back();
}
add_action( 'admin_post_dsp_threads_connect', 'dsp_handle_threads_connect' );

function dsp_handle_facebook_connect() {
	dsp_check_request( 'dsp_facebook_connect' );
	$app_id     = dsp_post_value( 'facebook_app_id' );
	$app_secret = dsp_post_value( 'facebook_app_secret' );
	$user_token = dsp_post_value( 'facebook_user_token' );
	$page_id    = dsp_post_value( 'facebook_page_id' );
	if ( '' === $app_id || '' === $app_secret || '' === $user_token ) {
		dsp_flash( new WP_Error( 'dsp', '手で入れるときは、アプリ ID・アプリシークレット・ユーザーのトークンの3つが要ります。ログインしてつなぐときは、上の「Facebook にログインしてつなぐ」を使ってください。' ) );
	} else {
		dsp_flash( dsp_facebook_connect( $app_id, $app_secret, $user_token, $page_id ) );
	}
	dsp_redirect_back();
}
add_action( 'admin_post_dsp_facebook_connect', 'dsp_handle_facebook_connect' );

/**
 * 確かめる・延ばす・切る・ログを消す。
 */
function dsp_handle_action() {
	dsp_check_request( 'dsp_action' );
	$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
	$s  = dsp_settings();

	switch ( $do ) {
		case 'test_bluesky':
			$result = dsp_connected( 'bluesky', $s ) ? dsp_bluesky_test( $s ) : new WP_Error( 'dsp', 'Bluesky の ID とアプリパスワードを入れてください。' );
			break;
		case 'test_threads':
			$result = dsp_connected( 'threads', $s ) ? dsp_threads_test( $s ) : new WP_Error( 'dsp', 'Threads とつながっていません。' );
			break;
		case 'test_facebook':
			$result = dsp_connected( 'facebook', $s ) ? dsp_facebook_test( $s ) : new WP_Error( 'dsp', 'Facebook ページとつながっていません。' );
			break;
		case 'test_discord':
			$result = dsp_discord_test( $s );
			break;
		case 'refresh_threads':
			$result = dsp_threads_refresh( true );
			break;
		case 'disconnect_bluesky':
			dsp_update_settings(
				array(
					'bluesky_identifier'   => '',
					'bluesky_app_password' => '',
				)
			);
			$result = 'Bluesky の ID とアプリパスワードを消しました。Bluesky の設定 → プライバシーとセキュリティ → アプリパスワード からも削除してください。';
			break;
		case 'disconnect_threads':
			dsp_update_settings(
				array(
					'threads_token'         => '',
					'threads_user_id'       => '',
					'threads_username'      => '',
					'threads_token_expires' => 0,
					'threads_refresh_error' => '',
				)
			);
			$result = 'Threads のトークンを消しました。Threads の設定 → アカウント → ウェブサイトのアクセス許可 からアプリも外せます。';
			break;
		case 'disconnect_facebook':
			dsp_update_settings(
				array(
					'facebook_page_id'    => '',
					'facebook_page_name'  => '',
					'facebook_page_token' => '',
				)
			);
			$result = 'Facebook ページのトークンを消しました。Facebook の設定 → ビジネス統合 からアプリも外せます。';
			break;
		case 'clear_log':
			delete_option( DSP_LOG_OPTION );
			$result = 'ログを消しました。';
			break;
		default:
			$result = new WP_Error( 'dsp', '不明な操作です。' );
	}
	dsp_flash( $result );
	dsp_redirect_back();
}
add_action( 'admin_post_dsp_action', 'dsp_handle_action' );

/**
 * Threads のトークンの期限が近い・延ばせないときは、管理画面の上に出す。
 */
function dsp_admin_notices() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$s = dsp_settings();
	if ( '' === $s['threads_token'] || empty( $s['enabled_threads'] ) ) {
		return;
	}
	$left = (int) $s['threads_token_expires'] - time();
	if ( '' !== $s['threads_refresh_error'] || $left < 10 * DAY_IN_SECONDS ) {
		$text = $left > 0
			? sprintf( 'SNS 投稿：Threads のトークンの期限まであと %d 日です。', (int) ceil( $left / DAY_IN_SECONDS ) )
			: 'SNS 投稿：Threads のトークンの期限が切れています。';
		if ( '' !== $s['threads_refresh_error'] ) {
			$text .= ' ' . $s['threads_refresh_error'];
		}
		printf(
			'<div class="notice notice-warning"><p>%s <a href="%s">設定を開く</a></p></div>',
			esc_html( $text ),
			esc_url( admin_url( 'options-general.php?page=dsp-settings' ) )
		);
	}
}
add_action( 'admin_notices', 'dsp_admin_notices' );

function dsp_action_button( $do, $label, $class = 'secondary', $confirm = '' ) {
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin:0 6px 6px 0"
		<?php if ( '' !== $confirm ) : ?>
			onsubmit="return window.confirm(<?php echo esc_attr( wp_json_encode( $confirm ) ); ?>)"
		<?php endif; ?>
	>
		<?php wp_nonce_field( 'dsp_action' ); ?>
		<input type="hidden" name="action" value="dsp_action" />
		<input type="hidden" name="do" value="<?php echo esc_attr( $do ); ?>" />
		<?php submit_button( $label, $class, 'submit', false ); ?>
	</form>
	<?php
}

/**
 * 「ログインしてつなぐ」のフォーム。アプリシークレットは定数がなければここで入れる（保存しない）。
 */
function dsp_oauth_form( $service, $s ) {
	$label     = 'threads' === $service ? 'Threads' : 'Facebook';
	$connected = dsp_connected( $service, $s );
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'dsp_oauth_start' ); ?>
		<input type="hidden" name="action" value="dsp_oauth_start" />
		<input type="hidden" name="service" value="<?php echo esc_attr( $service ); ?>" />
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="dsp-<?php echo esc_attr( $service ); ?>-app-id"><?php echo esc_html( $label ); ?> のアプリ ID</label></th>
				<td>
					<input type="text" id="dsp-<?php echo esc_attr( $service ); ?>-app-id" name="app_id" class="regular-text" autocomplete="off" value="<?php echo esc_attr( dsp_app_id( $service, $s ) ); ?>" />
					<p class="description">
						<?php if ( 'threads' === $service ) : ?>
							Meta のアプリ → ユースケース「Threads API」の設定 にある「Threads アプリ ID」。
						<?php else : ?>
							Meta のアプリ → アプリの設定 → ベーシック の一番上の「アプリ ID」。
						<?php endif; ?>
					</p>
				</td>
			</tr>
			<?php if ( '' === dsp_app_secret_constant( $service ) ) : ?>
				<tr>
					<th scope="row"><label for="dsp-<?php echo esc_attr( $service ); ?>-secret"><?php echo esc_html( $label ); ?> のアプリシークレット</label></th>
					<td>
						<input type="password" id="dsp-<?php echo esc_attr( $service ); ?>-secret" name="app_secret" class="regular-text" autocomplete="new-password" value="" />
						<p class="description">アプリ ID の横（または下）にある app secret。つなぐときに使うだけで、保存しません。</p>
					</td>
				</tr>
			<?php endif; ?>
		</table>
		<?php submit_button( $label . ( $connected ? ' にログインしてつなぎ直す' : ' にログインしてつなぐ' ), $connected ? 'secondary' : 'primary' ); ?>
	</form>
	<?php
}

function dsp_secret_state( $key, $s ) {
	if ( dsp_from_constant( $key ) ) {
		return 'wp-config.php の定数を使っています';
	}
	return '' !== $s[ $key ] ? '保存済み（変えるときだけ入れてください）' : '';
}

function dsp_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$s      = dsp_settings();
	$notice = get_transient( 'dsp_notice_' . get_current_user_id() );
	if ( $notice ) {
		delete_transient( 'dsp_notice_' . get_current_user_id() );
	}
	$log      = get_option( DSP_LOG_OPTION, array() );
	$services = dsp_services();
	?>
	<div class="wrap">
		<h1>SNS 投稿</h1>
		<?php if ( $notice ) : ?>
			<div class="notice notice-<?php echo esc_attr( $notice[0] ); ?> is-dismissible"><p><?php echo esc_html( $notice[1] ); ?></p></div>
		<?php endif; ?>

		<p>記事を公開すると、約1分後に下の SNS へ投稿します。投稿文は記事の編集画面の右側「SNS 投稿」で記事ごとに書けます（空なら記事のタイトル）。フォームの送信やコメントは、下の「Discord」の Webhook へ知らせます。</p>


		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'dsp_save' ); ?>
			<input type="hidden" name="action" value="dsp_save" />
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">テストモード</th>
					<td>
						<label><input type="checkbox" name="dry_run" value="1" <?php checked( ! empty( $s['dry_run'] ) ); ?> /> 実際には送らず、ログに残すだけにする</label>
					</td>
				</tr>
				<tr>
					<th scope="row">送り先</th>
					<td>
						<?php foreach ( $services as $service => $label ) : ?>
							<label style="margin-right:16px">
								<input type="checkbox" name="enabled_<?php echo esc_attr( $service ); ?>" value="1" <?php checked( ! empty( $s[ 'enabled_' . $service ] ) ); ?> />
								<?php echo esc_html( $label ); ?>
								<?php if ( ! dsp_connected( $service, $s ) ) : ?>
									<span class="description">（未接続）</span>
								<?php endif; ?>
							</label>
						<?php endforeach; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="dsp-bsky-id">Bluesky の ID</label></th>
					<td>
						<input type="text" id="dsp-bsky-id" name="bluesky_identifier" class="regular-text" autocomplete="off"
							value="<?php echo esc_attr( $s['bluesky_identifier'] ); ?>" placeholder="example.bsky.social"
							<?php disabled( dsp_from_constant( 'bluesky_identifier' ) ); ?> />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="dsp-bsky-pass">Bluesky のアプリパスワード</label></th>
					<td>
						<input type="password" id="dsp-bsky-pass" name="bluesky_app_password" class="regular-text" autocomplete="new-password" value=""
							<?php disabled( dsp_from_constant( 'bluesky_app_password' ) ); ?> />
						<p class="description">
							<?php echo esc_html( dsp_secret_state( 'bluesky_app_password', $s ) ); ?>
							ログイン用のパスワードではなく、Bluesky の 設定 → プライバシーとセキュリティ → アプリパスワード で作ったものを入れます（いつでも取り消せます）。
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Meta アプリ</th>
					<td>
						<p class="description" style="margin-top:0">Threads と Facebook に「ログインしてつなぐ」ときに使います。1つのアプリに「Threads API」と「ページの管理」の2つのユースケースを入れます。アプリ ID とシークレットは、下の Threads・Facebook の欄で入れます。</p>
						<p>
							<label>Facebook ログイン for Business の設定 ID（任意）<br /><input type="text" name="facebook_config_id" class="regular-text" autocomplete="off" value="<?php echo esc_attr( $s['facebook_config_id'] ); ?>" /></label><br />
							<span class="description">ログインで「権限が無効」などと出るときだけ入れます。空なら権限（<?php echo esc_html( str_replace( ',', '・', DSP_FACEBOOK_SCOPES ) ); ?>）を並べて頼みます。</span>
						</p>
						<p>
							戻り先の URL（Threads の「コールバック URL」と、Facebook ログインの「有効な OAuth リダイレクト URI」の両方に登録）<br />
							<input type="text" class="large-text code" readonly value="<?php echo esc_attr( dsp_oauth_redirect_uri() ); ?>" onfocus="this.select()" />
						</p>
					</td>
				</tr>
			</table>
			<?php submit_button( '保存' ); ?>
		</form>

		<hr />
		<h2>Threads</h2>
		<?php if ( dsp_connected( 'threads', $s ) ) : ?>
			<p>
				<strong>@<?php echo esc_html( '' !== $s['threads_username'] ? $s['threads_username'] : $s['threads_user_id'] ); ?></strong> とつながっています。
				トークンの期限：<?php echo esc_html( wp_date( 'Y-m-d', (int) $s['threads_token_expires'] ) ); ?> ごろ（毎週自動で延ばします）
			</p>
			<?php if ( '' !== $s['threads_refresh_error'] ) : ?>
				<p style="color:#cc1818"><?php echo esc_html( $s['threads_refresh_error'] ); ?></p>
			<?php endif; ?>
		<?php endif; ?>
		<?php dsp_oauth_form( 'threads', $s ); ?>
		<details>
			<summary>トークンを手で入れる</summary>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'dsp_threads_connect' ); ?>
				<input type="hidden" name="action" value="dsp_threads_connect" />
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="dsp-threads-token">長期アクセストークン</label></th>
						<td>
							<input type="password" id="dsp-threads-token" name="threads_token" class="large-text" autocomplete="new-password" value="" />
							<p class="description">Threads API の設定にある「ユーザートークン生成ツール」で作ったトークン（threads_basic と threads_content_publish の権限）。</p>
						</td>
					</tr>
				</table>
				<?php submit_button( 'このトークンでつなぐ', 'secondary' ); ?>
			</form>
		</details>

		<hr />
		<h2>Facebook ページ</h2>
		<?php if ( dsp_connected( 'facebook', $s ) ) : ?>
			<p>
				ページ<strong>「<?php echo esc_html( '' !== $s['facebook_page_name'] ? $s['facebook_page_name'] : $s['facebook_page_id'] ); ?>」</strong>とつながっています（ページのトークンに期限はありません）。
				<?php if ( dsp_from_constant( 'facebook_page_token' ) ) : ?>
					wp-config.php の定数を使っています。
				<?php endif; ?>
			</p>
		<?php endif; ?>
		<?php $pending = dsp_facebook_pending_pages(); ?>
		<?php if ( $pending ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="border-left:4px solid #72aee6;padding:4px 12px;margin:12px 0">
				<?php wp_nonce_field( 'dsp_choose_page' ); ?>
				<input type="hidden" name="action" value="dsp_choose_page" />
				<p><strong>投稿するページを選んでください</strong>（10分以内）</p>
				<?php foreach ( $pending as $i => $page ) : ?>
					<p><label><input type="radio" name="page_id" value="<?php echo esc_attr( $page['id'] ); ?>" <?php checked( 0, $i ); ?> /> <?php echo esc_html( $page['name'] ); ?>（<?php echo esc_html( $page['id'] ); ?>）</label></p>
				<?php endforeach; ?>
				<?php submit_button( 'このページとつなぐ', 'primary', 'submit', false ); ?>
			</form>
		<?php endif; ?>
		<?php dsp_oauth_form( 'facebook', $s ); ?>
		<details>
			<summary>トークンを手で入れる</summary>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'dsp_facebook_connect' ); ?>
			<input type="hidden" name="action" value="dsp_facebook_connect" />
			<p class="description">グラフ API エクスプローラで、アプリを選び <?php echo esc_html( str_replace( ',', '・', DSP_FACEBOOK_SCOPES ) ); ?> の権限でユーザーのトークンを作って入れます。期限のないページのトークンに替えて保存します。アプリシークレットとユーザーのトークンは保存しません。</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="dsp-fb-app">アプリ ID</label></th>
					<td><input type="text" id="dsp-fb-app" name="facebook_app_id" class="regular-text" autocomplete="off" value="<?php echo esc_attr( $s['meta_app_id'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="dsp-fb-secret">アプリシークレット</label></th>
					<td><input type="password" id="dsp-fb-secret" name="facebook_app_secret" class="regular-text" autocomplete="new-password" value="" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="dsp-fb-token">ユーザーのトークン</label></th>
					<td><input type="password" id="dsp-fb-token" name="facebook_user_token" class="large-text" autocomplete="new-password" value="" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="dsp-fb-page">ページ ID</label></th>
					<td>
						<input type="text" id="dsp-fb-page" name="facebook_page_id" class="regular-text" autocomplete="off" value="<?php echo esc_attr( $s['facebook_page_id'] ); ?>" />
						<p class="description">管理しているページが1つなら空でかまいません。</p>
					</td>
				</tr>
			</table>
			<?php submit_button( 'このトークンでつなぐ', 'secondary' ); ?>
		</form>
		</details>

		<hr />
		<h2>Discord</h2>
		<p class="description">チャンネルの設定 → 連携サービス → ウェブフック で作った URL を入れます。1行に1つ書けば、複数のチャンネルへ送ります。用途ごとの欄が空なら「ふだんの送り先」へ送ります。保存した URL は、末尾の4文字だけ見せています。</p>
		<?php if ( defined( 'DSP_DISCORD_WEBHOOK_URL' ) && '' !== (string) DSP_DISCORD_WEBHOOK_URL ) : ?>
			<p>wp-config.php の DSP_DISCORD_WEBHOOK_URL を、すべての用途で使っています。</p>
		<?php else : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'dsp_discord_save' ); ?>
				<input type="hidden" name="action" value="dsp_discord_save" />
				<table class="form-table" role="presentation">
					<?php foreach ( array_merge( array( '' => 'ふだんの送り先' ), dsp_discord_channels() ) as $channel => $label ) : ?>
						<?php $key = '' === $channel ? 'discord_webhook' : 'discord_webhook_' . $channel; ?>
						<tr>
							<th scope="row"><label for="dsp-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
							<td>
								<textarea id="dsp-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" class="large-text code" rows="2" autocomplete="off" spellcheck="false"
									<?php if ( '' !== $channel ) : ?>placeholder="空ならふだんの送り先"<?php endif; ?>
								><?php echo esc_textarea( implode( "
", array_map( 'dsp_discord_mask', dsp_discord_lines( $s[ $key ] ) ) ) ); ?></textarea>
								<?php if ( 'post' === $channel ) : ?>
									<p class="description">記事の公開は、上の「送り先」で Discord にチェックを入れたときだけ送ります（テストモードの間は送りません）。</p>
								<?php elseif ( 'form' === $channel ) : ?>
									<p class="description">フォームのプラグインが <code>do_action( 'dsp_form_submitted', 'フォーム名', array( '項目名' => '値' ) )</code> を呼んだときに送ります。</p>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
				<?php submit_button( 'Discord の設定を保存' ); ?>
			</form>
		<?php endif; ?>
		<?php
		if ( array_filter( array_map( function ( $c ) use ( $s ) {
			return dsp_discord_urls( $c, $s );
		}, array_keys( dsp_discord_channels() ) ) ) ) {
			dsp_action_button( 'test_discord', 'Discord にテスト通知を送る' );
		}
		?>

		<hr />
		<h2>確かめる・切る</h2>
		<p>「確かめる」はつながるかを見るだけで、投稿はしません。</p>
		<p>
			<?php
			foreach ( $services as $service => $label ) {
				if ( 'discord' === $service ) {
					continue;
				}
				if ( dsp_connected( $service, $s ) ) {
					dsp_action_button( 'test_' . $service, $label . ' を確かめる' );
				}
			}
			if ( dsp_connected( 'threads', $s ) ) {
				dsp_action_button( 'refresh_threads', 'Threads のトークンを今すぐ延ばす' );
			}
			?>
		</p>
		<p>
			<?php
			foreach ( $services as $service => $label ) {
				if ( 'discord' !== $service && dsp_connected( $service, $s ) && ! ( 'bluesky' === $service && dsp_from_constant( 'bluesky_app_password' ) ) && ! ( 'facebook' === $service && dsp_from_constant( 'facebook_page_token' ) ) ) {
					dsp_action_button( 'disconnect_' . $service, $label . ' のトークンを消す', 'delete', $label . ' のトークンを消しますか？' );
				}
			}
			?>
		</p>

		<hr />
		<h2>ログ</h2>
		<?php if ( ! $log ) : ?>
			<p>まだありません。</p>
		<?php else : ?>
			<table class="widefat striped">
				<thead><tr><th style="width:9em">日時</th><th style="width:7em">送り先</th><th>記事</th><th>内容</th></tr></thead>
				<tbody>
				<?php foreach ( $log as $row ) : ?>
					<tr>
						<td><?php echo esc_html( wp_date( 'Y-m-d H:i', $row['time'] ) ); ?></td>
						<td><?php echo esc_html( isset( $services[ $row['service'] ] ) ? $services[ $row['service'] ] : $row['service'] ); ?></td>
						<td>
							<?php if ( $row['post'] && get_post( $row['post'] ) ) : ?>
								<a href="<?php echo esc_url( get_edit_post_link( $row['post'] ) ); ?>"><?php echo esc_html( get_the_title( $row['post'] ) ); ?></a>
							<?php endif; ?>
						</td>
						<td style="word-break:break-all"><?php echo esc_html( $row['message'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p><?php dsp_action_button( 'clear_log', 'ログを消す' ); ?></p>
		<?php endif; ?>
	</div>
	<?php
}
