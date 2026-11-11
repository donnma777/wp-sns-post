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
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;

	/**
	 * プレビュー：送り先ごとに、ひな形で作った本文と文字数をサーバーで作って見せる。
	 * 開いているあいだだけ、入力が止まって少したったら作り直す。
	 */
	function Preview() {
		var ed = wp.data.useSelect( function ( select ) {
			var e = select( 'core/editor' );
			return {
				post: e.getCurrentPostId(),
				message: ( e.getEditedPostAttribute( 'meta' ) || {} )._dsp_message || '',
				no_hashtags: ( e.getEditedPostAttribute( 'meta' ) || {} )._dsp_no_hashtags || [],
				messages: ( e.getEditedPostAttribute( 'meta' ) || {} )._dsp_messages || {},
				title: e.getEditedPostAttribute( 'title' ) || '',
				excerpt: e.getEditedPostAttribute( 'excerpt' ) || '',
				categories: e.getEditedPostAttribute( 'categories' ) || [],
				tags: e.getEditedPostAttribute( 'tags' ) || [],
			};
		}, [] );
		var openState = useState( false );
		var open = openState[ 0 ];
		var resultState = useState( null );
		var result = resultState[ 0 ];
		var setResult = resultState[ 1 ];
		var key = JSON.stringify( ed );

		useEffect( function () {
			if ( ! open || ! ed.post || ! wp.apiFetch ) {
				return undefined;
			}
			var alive = true;
			var timer = setTimeout( function () {
				wp.apiFetch( { path: '/dsp/v1/preview', method: 'POST', data: ed } ).then( function ( rows ) {
					if ( alive ) {
						setResult( { rows: rows } );
					}
				} ).catch( function ( err ) {
					if ( alive ) {
						setResult( { error: ( err && err.message ) || 'プレビューを作れませんでした。' } );
					}
				} );
			}, 600 );
			return function () {
				alive = false;
				clearTimeout( timer );
			};
		}, [ open, key ] );

		var children = [ el( C.Button, {
			key: 'toggle',
			variant: 'secondary',
			size: 'compact',
			onClick: function () {
				openState[ 1 ]( ! open );
			},
		}, open ? 'プレビューを閉じる' : '送る本文をプレビュー' ) ];

		if ( open ) {
			if ( ! result ) {
				children.push( el( 'p', { key: 'wait' }, '作っています…' ) );
			} else if ( result.error ) {
				children.push( el( 'p', { key: 'err', style: { color: '#cc1818' } }, result.error ) );
			} else {
				result.rows.forEach( function ( r ) {
					var count = r.limit
						? r.length + ' / ' + r.limit + ( r.id === 'x' ? '（X の数え方：全角は2、URL は23）' : '' )
						: r.length + ' 文字';
					children.push( el( 'div', { key: r.id, style: { marginTop: '12px' } },
						el( 'div', { style: { display: 'flex', justifyContent: 'space-between', gap: '8px', fontSize: '12px' } },
							el( 'strong', null, r.label ),
							el( 'span', { style: { color: r.trimmed ? '#cc1818' : '#757575' } }, count )
						),
						r.trimmed ? el( 'div', { style: { color: '#cc1818', fontSize: '12px' } }, '上限を超えるので短くしました' ) : null,
						el( 'pre', { style: { whiteSpace: 'pre-wrap', wordBreak: 'break-all', background: '#f6f7f7', border: '1px solid #dcdcde', padding: '8px', margin: '4px 0 0', fontSize: '12px', fontFamily: 'inherit' } }, r.text )
					) );
				} );
				children.push( el( 'p', { key: 'note', style: { color: '#757575', fontSize: '12px', marginTop: '8px' } },
					'説明文（{excerpt}）が本文の頭から作られるときは、保存してある本文を使います。' ) );
			}
		}
		return el( 'div', { style: { margin: '0 0 12px' } }, children );
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
			children.push( el( C.TextareaControl, {
				key: 'message',
				label: '投稿文（共通）',
				rows: 6,
				value: message,
				placeholder: post.title,
				help: '空なら記事のタイトル。送り先ごとのひな形（設定 → SNS 投稿）の {message} に入ります。記事のリンクはカードで付くので、本文に書かなくて大丈夫です。' +
					'（' + len + ' 文字。送り先ごとの文字数は下のプレビューで）',
				onChange: function ( v ) {
					editPost( { meta: { _dsp_message: v } } );
				},
			} ) );

			// 送り先ごとの投稿文。チェックを入れた送り先だけ欄を出し、共通の文から書き始める
			var own = post.meta._dsp_messages;
			own = own && ! Array.isArray( own ) ? own : {};
			var setOwn = function ( id, value ) {
				var next = Object.assign( {}, own );
				if ( value === null ) {
					delete next[ id ];
				} else {
					next[ id ] = value;
				}
				editPost( { meta: { _dsp_messages: next } } );
			};
			children.push( el( 'fieldset', { key: 'own', style: { margin: '0 0 8px' } },
				el( 'legend', { style: { fontSize: '11px', fontWeight: 500, textTransform: 'uppercase', marginBottom: '4px' } }, '送り先ごとに別の文にする' ),
				data.services.map( function ( s ) {
					var on = Object.prototype.hasOwnProperty.call( own, s.id );
					return el( 'div', { key: s.id },
						el( C.CheckboxControl, {
							label: s.label,
							checked: on,
							onChange: function ( v ) {
								setOwn( s.id, v ? text : null );
							},
						} ),
						on ? el( C.TextareaControl, {
							label: s.label + ' の投稿文',
							hideLabelFromVision: true,
							rows: 4,
							value: own[ s.id ],
							placeholder: text,
							help: '空なら共通の投稿文を使います。',
							onChange: function ( v ) {
								setOwn( s.id, v );
							},
						} ) : null
					);
				} )
			) );
			var tagged = data.services.filter( function ( s ) {
				return s.hashtags;
			} );
			if ( tagged.length ) {
				var noTags = post.meta._dsp_no_hashtags || [];
				children.push( el( 'fieldset', { key: 'hashtags', style: { margin: '0 0 8px' } },
					el( 'legend', { style: { fontSize: '11px', fontWeight: 500, textTransform: 'uppercase', marginBottom: '4px' } }, 'ハッシュタグを付ける' ),
					tagged.map( function ( s ) {
						return el( C.CheckboxControl, {
							key: s.id,
							label: s.label,
							checked: noTags.indexOf( s.id ) === -1,
							onChange: function ( v ) {
								var next = noTags.filter( function ( id ) {
									return id !== s.id;
								} );
								if ( ! v ) {
									next.push( s.id );
								}
								editPost( { meta: { _dsp_no_hashtags: next } } );
							},
						} );
					} )
				) );
			}
			children.push( el( 'p', { key: 'to', style: { color: '#757575', margin: '0 0 8px' } },
				'送り先：' + data.services.map( function ( s ) { return s.label; } ).join( '・' ) +
				'（公開から約1分後）' + ( data.dryRun ? '。今はテストモードなので実際には送りません' : '' ) ) );
			children.push( el( Preview, { key: 'preview' } ) );
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
