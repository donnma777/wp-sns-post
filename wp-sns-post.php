<?php
/**
 * Plugin Name: SNS Post
 * Description: 記事を公開したら、Bluesky・Threads・X・Facebook ページ・Discord へ自動で投稿します。フォームの送信やコメントも Discord に知らせます。外部サービスを通さず、サイトから各 SNS へ直接送ります。
 * Version: 1.3.0
 * Author: donnma
 * Author URI: https://donnma.com/
 * Plugin URI: https://github.com/donnma777/wp-sns-post
 * License: GPLv2 or later
 * Requires at least: 6.6
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DSP_VERSION', '1.3.0' );
define( 'DSP_FILE', __FILE__ );
define( 'DSP_URL', plugin_dir_url( __FILE__ ) );

define( 'DSP_OPTION', 'dsp_settings' );
define( 'DSP_LOG_OPTION', 'dsp_log' );
// 公開から投稿までの秒数。公開の処理の途中では、アイキャッチなどがまだ保存されていないことがある
define( 'DSP_DELAY', 60 );
// Threads は下書き（コンテナ）を作ってから、少し待って公開する（公式の目安は約30秒）
define( 'DSP_THREADS_WAIT', 40 );
// 失敗したときに試す回数と間隔
define( 'DSP_MAX_ATTEMPTS', 3 );
define( 'DSP_RETRY_INTERVAL', 600 );

define( 'DSP_GRAPH_API', 'https://graph.facebook.com/v26.0' );
define( 'DSP_THREADS_API', 'https://graph.threads.com/v1.0' );
if ( ! defined( 'DSP_BLUESKY_SERVICE' ) ) {
	define( 'DSP_BLUESKY_SERVICE', 'https://bsky.social' );
}

require __DIR__ . '/includes/common.php';
require __DIR__ . '/includes/bluesky.php';
require __DIR__ . '/includes/threads.php';
require __DIR__ . '/includes/x.php';
require __DIR__ . '/includes/facebook.php';
require __DIR__ . '/includes/discord.php';
require __DIR__ . '/includes/publish.php';
require __DIR__ . '/includes/editor.php';
require __DIR__ . '/includes/settings.php';
require __DIR__ . '/includes/oauth.php';

register_activation_hook( __FILE__, 'dsp_schedule_daily' );
register_deactivation_hook( __FILE__, 'dsp_deactivate' );

function dsp_deactivate() {
	wp_clear_scheduled_hook( 'dsp_daily' );
}
