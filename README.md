# SNS Post

記事を公開したら、Bluesky・Threads・Facebook ページへ自動で投稿する WordPress プラグインです。

Jetpack Social などと違い、WordPress.com などの外部サービスを通しません。サイトから各 SNS の API へ直接送るので、サイトのデータが SNS 以外に出ていきません。

## できること

| | Bluesky | Threads | Facebook ページ |
|---|---|---|---|
| リンクのカード | ○（アイキャッチ画像つき） | ○ | ○ |
| ハッシュタグ・URL のリンク | ○ | ○（トピックになるタグは1つだけ） | ○ |
| 文字数の上限 | 300（超えた分は切る） | 500（超えた分は切る） | なし |
| つなぎ方 | アプリパスワード | 長期トークン（毎週自動で延長） | ページのトークン（期限なし） |

- 記事の編集画面の右側「SNS 投稿」で、記事ごとに投稿文を書けます。空なら記事のタイトルを投稿します
- 「この記事は SNS に投稿しない」も選べます
- 公開から約1分後に WP-Cron で送ります。更新しただけでは送りません
- 失敗したら10分おきに3回まで試します。だめなら記事の画面に理由が出て、「今すぐ送る」でやり直せます
- [Discord Post Notify](https://github.com/donnma777/discord-post-notify) が入っていれば、失敗を Discord に知らせます
- テストモードでは、実際には送らずにログに残すだけにできます
- Jetpack Social がすでに投稿した SNS へは送りません（乗り換えの途中の二重投稿を防ぎます）

## インストール

1. [Releases](https://github.com/donnma777/wp-sns-post/releases) から zip をダウンロード
2. 管理画面「プラグイン → 新規追加 → プラグインのアップロード」で zip を選んで有効化
3. 「設定 → SNS 投稿」で SNS とつなぎ、「確かめる」で確認
4. 送り先にチェックを入れて、テストモードを外す

入れたばかりのときはテストモードで、どこにも送りません。

## SNS とつなぐ

### Bluesky

Bluesky の「設定 → プライバシーとセキュリティ → アプリパスワード」でアプリパスワードを作り、ID（example.bsky.social など）と一緒に入れます。

### Threads

1. [Meta for Developers](https://developers.facebook.com/apps/) でアプリを作り、ユースケースで Threads API を選ぶ
2. 権限 `threads_basic` と `threads_content_publish` を足す
3. 「役割 → Threads テスター」に自分の Threads アカウントを足し、Threads の「設定 → アカウント → ウェブサイトのアクセス許可」で承認する
4. Threads API の設定にある「ユーザートークン生成ツール」で長期トークンを作り、設定画面に入れる

トークンの期限は60日です。プラグインが毎週延ばします。サイトに60日アクセスがないと WP-Cron が動かず、期限が切れます。

### Facebook ページ

1. Meta for Developers でアプリを作り、ユースケースで Facebook ページの管理を選ぶ
2. [グラフ API エクスプローラ](https://developers.facebook.com/tools/explorer/) でそのアプリを選び、`pages_manage_posts`・`pages_read_engagement`・`pages_show_list` の権限でユーザーのトークンを作る（投稿するページを選ぶ）
3. 設定画面にアプリ ID・アプリシークレット・ユーザーのトークンを入れて「つなぐ」

期限のないページのトークンに替えて保存します。アプリシークレットとユーザーのトークンは保存しません。

自分のアカウント・自分のページに投稿するだけなら、どちらもアプリの審査は要りません（アプリは開発モードのままで使えます）。

### wp-config.php で指定する（任意）

データベースにトークンを置きたくないときは、`wp-config.php` に書けます。管理画面の値より優先します。

```php
define( 'DSP_BLUESKY_IDENTIFIER', 'example.bsky.social' );
define( 'DSP_BLUESKY_APP_PASSWORD', 'xxxx-xxxx-xxxx-xxxx' );
define( 'DSP_FACEBOOK_PAGE_ID', '123456789' );
define( 'DSP_FACEBOOK_PAGE_TOKEN', 'EAAB...' );
```

Threads のトークンは自動で延ばすため、データベースに置きます。

## フィルター

| フィルター | 用途 |
|---|---|
| `dsp_post_types` | 送る投稿タイプ（初めは `post` だけ） |
| `dsp_content` | 投稿の中身（本文・タイトル・URL・説明文）を変える |

## 動作環境

- WordPress 6.6 以上（ブロックエディター）
- PHP 7.4 以上

## 注意

- トークンはサイトのデータベース（`wp_options`、autoload なし）に置きます。サイトそのものが破られると漏れるので、使わなくなったら SNS 側でも取り消してください
- プラグインを削除すると、設定とログを消します
- 個人で作ったものです。サポートや不具合対応のお約束はできません

## ライセンス

GPLv2 or later
