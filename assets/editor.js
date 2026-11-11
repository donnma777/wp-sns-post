/* SNS Post：ブロックエディターの「SNS 投稿」パネル（ビルドなしで動くように createElement で書く） */
( function ( wp, data ) {
	if ( ! wp || ! data || ! wp.plugins ) {
		return;
	}
	var el = wp.element.createElement;
	var Panel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );
	var C = wp.components;
	if ( ! Panel ) {
		return;
	}

	var STATUS = {
		success: '投稿済み',
		pending: '投稿中',
		queued: '送る予定',
		failed: '失敗',
		dry_run: 'テストモード（送っていない）',
	};
	var LIMIT = { bluesky: 300, threads: 500 };
	// X は全角を2、URL を23と数えて280まで。記事の URL（改行＋23）の分を引く
	var X_LIMIT = 280 - 24;

	function xLength( text ) {
		var urls = 0;
		var rest = text.replace( /https?:\/\/[^\s　]+/g, function () {
			urls++;
			return '';
		} );
		var n = 0;
		for ( var ch of rest ) { // eslint-disable-line no-unused-vars
			var cp = ch.codePointAt( 0 );
			n += ( cp <= 4351 || ( cp >= 8192 && cp <= 8205 ) || ( cp >= 8208 && cp <= 8223 ) || ( cp >= 8242 && cp <= 8247 ) ) ? 1 : 2;
		}
		return n + urls * 23;
	}

	function length( text ) {
		if ( window.Intl && Intl.Segmenter ) {
			var n = 0;
			var it = new Intl.Segmenter( 'ja', { granularity: 'grapheme' } ).segment( text );
			for ( var s of it ) { // eslint-disable-line no-unused-vars
				n++;
			}
			return n;
		}
		return Array.from( text ).length;
	}

	function SnsPanel() {
		var post = wp.data.useSelect( function ( select ) {
			var editor = select( 'core/editor' );
			return {
				meta: editor.getEditedPostAttribute( 'meta' ) || {},
				title: editor.getEditedPostAttribute( 'title' ) || '',
				status: editor.getEditedPostAttribute( 'status' ),
				saved: editor.getCurrentPostAttribute( 'status' ),
			};
		}, [] );
		var editPost = wp.data.useDispatch( 'core/editor' ).editPost;

		if ( ! post.meta || ! ( '_dsp_message' in post.meta ) ) {
			return null;
		}

		var children = [];
		if ( ! data.services.length ) {
			children.push( el( 'p', { key: 'none' }, '送り先の SNS がまだありません。',
				data.canSettings ? el( 'a', { href: data.settingsUrl }, '設定 → SNS 投稿' ) : null ) );
			return el( Panel, { name: 'dsp-sns-post', title: 'SNS 投稿' }, children );
		}

		var message = post.meta._dsp_message || '';
		var skip = !! post.meta._dsp_skip;
		var text = message.trim() !== '' ? message : post.title;
		var len = length( text );

		children.push( el( C.CheckboxControl, {
			key: 'skip',
			label: 'この記事は SNS に投稿しない',
			checked: skip,
			onChange: function ( v ) {
				editPost( { meta: { _dsp_skip: v } } );
			},
		} ) );

		if ( ! skip ) {
			var over = data.services.filter( function ( s ) {
				return LIMIT[ s.id ] && len > LIMIT[ s.id ];
			} ).map( function ( s ) {
				return s.label + ' は ' + LIMIT[ s.id ] + ' 文字まで（超えた分は切ります）';
			} );
			var hasX = data.services.some( function ( s ) {
				return s.id === 'x';
			} );
			if ( hasX && xLength( text ) > X_LIMIT ) {
				over.push( 'X は全角 ' + ( X_LIMIT / 2 ) + ' 文字（半角 ' + X_LIMIT + ' 文字）まで（超えた分は切ります）' );
			}
			children.push( el( C.TextareaControl, {
				key: 'message',
				label: '投稿文',
				rows: 6,
				value: message,
				placeholder: post.title,
				help: '空なら記事のタイトル。送り先ごとのひな形（設定 → SNS 投稿）の {message} に入ります。記事のリンクはカードで付くので、本文に書かなくて大丈夫です。' +
					'（' + len + ' 文字）' + ( over.length ? ' ' + over.join( '、' ) : '' ),
				onChange: function ( v ) {
					editPost( { meta: { _dsp_message: v } } );
				},
			} ) );
			children.push( el( 'p', { key: 'to', style: { color: '#757575', margin: '0 0 8px' } },
				'送り先：' + data.services.map( function ( s ) { return s.label; } ).join( '・' ) +
				'（公開から約1分後）' + ( data.dryRun ? '。今はテストモードなので実際には送りません' : '' ) ) );
		}

		var published = post.saved === 'publish';
		var rows = data.services.map( function ( s ) {
			var r = data.results[ s.id ];
			var parts = [ el( 'strong', { key: 'l' }, s.label + '：' ) ];
			if ( r ) {
				parts.push( STATUS[ r.status ] || r.status );
				if ( r.time ) {
					parts.push( '（' + r.time + '）' );
				}
				if ( r.url ) {
					parts.push( ' ', el( 'a', { key: 'u', href: r.url, target: '_blank', rel: 'noopener noreferrer' }, '見る' ) );
				}
				if ( r.status === 'failed' && r.message ) {
					parts.push( el( 'div', { key: 'm', style: { color: '#cc1818', wordBreak: 'break-all' } }, r.message ) );
				}
			} else {
				parts.push( published ? 'まだ' : '公開したら送ります' );
			}
			if ( published && s.send && ( ! r || r.status !== 'pending' ) ) {
				var again = r && ( r.status === 'success' );
				parts.push( ' ', el( 'a', {
					key: 's',
					href: s.send,
					onClick: function ( e ) {
						if ( again && ! window.confirm( s.label + ' にもう一度投稿しますか？' ) ) {
							e.preventDefault();
						}
					},
				}, again ? 'もう一度送る' : '今すぐ送る' ) );
			}
			return el( 'li', { key: s.id, style: { marginBottom: '6px' } }, parts );
		} );
		children.push( el( 'ul', { key: 'results', style: { margin: '8px 0 0' } }, rows ) );
		if ( published ) {
			children.push( el( 'p', { key: 'note', style: { color: '#757575', fontSize: '12px' } },
				'結果はページを読み込み直すと新しくなります。「今すぐ送る」は保存していない変更を捨てるので、先に更新してください。' ) );
		}

		return el( Panel, { name: 'dsp-sns-post', title: 'SNS 投稿' }, children );
	}

	wp.plugins.registerPlugin( 'dsp-sns-post', { render: SnsPanel, icon: 'share' } );
}( window.wp, window.dspEditor ) );
