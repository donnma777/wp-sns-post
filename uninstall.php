<?php
/**
 * プラグインを削除したら、設定（トークン）とログを消す。記事ごとの結果（_dsp_results）は残す。
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'dsp_settings' );
delete_option( 'dsp_log' );
wp_clear_scheduled_hook( 'dsp_daily' );
