=== atshift Semantic Deterrence ===
Contributors: atshift
Tags: security, 403, automation, firewall, privacy
Requires at least: 6.4
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.1.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

不審な自動探索を観測し、必要に応じて機械可読な状態と自然言語の撤退勧告を返す試作プラグインです。

== Description ==

atshift Semantic Deterrence は、不審な自動探索に対して明確な拒否応答を返した後、同じローカル系列の探索が継続したかを測定するための WordPress 向け初期試作です。

これは WAF、CDN ルール、レート制限、アクセス制御を置き換えるものではありません。通常の HTTP 拒否に加えて、探索が許可されていないこと、実際に記録している場合はその事実、継続しても追加情報を得にくいことを伝える「意味的抑止」レイヤーです。

初期設定は「観測のみ」です。この状態ではローカル集計イベントだけを記録し、HTTP レスポンスは変更しません。

この初期試作に含まれるもの:

* 機密設定ファイル、バックアップアーカイブ、バージョン管理パス、無関係な管理面、パストラバーサル風リクエスト、繰り返し 404、メソッド異常などの高確度カテゴリをローカル検知します。
* サイト固有の高確度パス接頭辞と、開発者向けの構造化ルールフィルターを利用できます。
* 日次ソルト付きの測定用 HMAC と、制御・実験用に分離した HMAC でローカル測定を行います。
* 10 分の観測窓で、警告後に不審探索が継続したかを分類します。
* 同じ系列とカテゴリからの短時間バーストは DB 上の原子的な排他処理と書き込み上限で間引き、探索集中時に全リクエストをイベント行にしないようにします。
* 匿名集計 JSON を手動エクスポートできます。
* 明示的にオプトインした場合だけ、匿名集計を日次ジッター付きで Hub へ送信できます。
* データ提供に参加しなくても、Hub に集まった匿名集計を参照できます。
* 高確度分類に対してのみ、固定カタログ 1〜5 のいずれかの意味的応答を返す抑止モードがあります。
* 継続探索を観測した後に 429 を返せる、抑止 + 一時制限モードがあります。
* 通常 403 の対照群と固定カタログ 1〜5 を使うローカル実験モードがあります。
* 実験モードでは、同じローカル系列に同じ文面を返す固定表示と、継続時に決め打ち順で文面を変える段階表示を選べます。実験開始後、割り当て戦略はローカルデータ削除までロックされます。
* 管理画面では、検知件数、警告件数、警告後に継続を観測しなかった件数、継続件数、判定不能件数、応答別比較、実験群、現在もっとも有効と推定される応答、十分な件数がある場合の通常 403 との差を確認できます。

このプラグインは、結果を「警告後に探索継続を観測しなかった」と表現します。「攻撃を止めた」「AIを検出した」「侵害を防いだ」とは断定しません。

== Privacy ==

この試作版は、初期状態では実験の測定データをプラグイン作者や集計 Hub へ送信しません。WordPress の通常の更新確認では、実験共有とは別に、公開されている GitHub Releases API へプラグインの最新版を問い合わせる場合があります。

ローカル集計レコードには、時刻、検知カテゴリ、レベル、応答バリアント、実験群、応答カタログ ID、応答指紋、HTTP 状態、日次ソルト付き系列 HMAC、追跡件数、結果、プラグインバージョン、ポリシーバージョンが含まれます。管理者がCloudflare連携を有効にした場合だけ、接続元として通知された2文字の国・地域コードもローカル保存します。

ローカル集計レコードには、リクエスト本文、Cookie、認証ヘッダー、フォーム入力、完全な URL、URL クエリ値、平文 IP アドレス、平文 User-Agent、ドメイン、サイト名、管理者情報、WordPress ユーザーの個人情報は含まれません。

集計共有は明示的なオプトイン制です。送信は遅延付きの日次バッチとサイトごとのジッターで行います。共有せずに横断集計だけを読み出すこともできます。

共有する項目は、ランダムな導入サイト仮名、スキーマ・プラグイン・ポリシーのバージョン、固定応答バリアント、実験群、応答カタログ ID、応答指紋、HTTP 状態、限定された検知カテゴリとレベル、結果、追跡時間帯、観測日、イベント件数、追跡件数です。個別リクエストではなく、同じ条件をまとめた集計行として送ります。

共有しない項目は、生 IP、IP ハッシュ、ローカル系列 HMAC、国・地域コード、時間別の参考集計、URL、パス、クエリ、Cookie、認証情報、リクエスト本文、フォーム値、ドメイン、サイト名、管理者情報、メールアドレス、生 User-Agent、WordPress ユーザーデータ、ローカル詳細ログです。導入サイト仮名は期間比較に使う安定 ID であり、Hub 側でさらに HMAC 化してサイト別の認証情報へ結び付けるため、正確には仮名化集計データです。

Hub は、重複する日次スナップショットの置換、公開閾値の判定、不正または壊れた送信の検出、応答別・分類別・実験群別の横断比較に共有データを利用します。既定では 10 サイトかつ 100 イベントに達しない行を公開しません。公開 JSON はプラグイン利用者以外も取得できます。利用目的は、実験評価、実験設計の改善、誤検知分析、応答仮説の議論、標本数と不確実性を伴う集計報告です。訪問者や参加ドメインの特定、サイト別ランキング、広告プロファイル、見込み客販売、サイトの遠隔操作には利用しません。ただし公開集計であるため、第三者が取得済みの閾値通過結果を保存または再利用することを技術的には制御できません。

Hub は各サイトの最新 30 日スナップショットとして送信を受け取り、以前の有効スナップショットを置き換えます。サイト単位のイベント行は定期処理で 90 日後に削除します。署名付きの参加解除では、そのサイトの保存済みバッチと生成済みキャッシュを削除し、再送信を拒否するための鍵付き失効マーカーを残します。解除前に第三者が取得した公開集計や、それを使って公表済みの分析は回収できません。

HTTPS 通信では、通常のWebサービスと同様に、接続元サーバー IP や通信時刻などの通信メタデータがホスティングまたはネットワークの運用ログへ記録される可能性があります。これは実験ペイロードや公開集計の項目には含めません。

集計データの読み出しは判断材料の取得に限ります。Hub から命令、実行可能コード、強制設定、遮断指示を受け取らず、設定変更にはサイト管理者の明示操作が必要です。

== データの利用と公表 ==

実験参加者は、集計 Hub が稼働している間、公開閾値を通過した最新の横断統計をいつでも取得できます。データ提供を選ばず、集計参照だけを有効にした利用者も取得できます。読み出せるのは公開集計 JSON に限り、他サイトの個別行、導入サイト仮名、認証情報、通信ログ、生リクエストは取得できません。

プロジェクト運営者は、Hub に収集したデータを学術論文としてまとめたり、最初の公表権を独占したりしません。この収集の目的は、Web 上の実験を進化させるために、サイト運営者とコミュニティが検討できる共有の判断材料を作ることです。

実験参加者、運営者、その他の閲覧者は、公開された集計結果を事前許可なく分析、要約、比較、可視化、引用、再掲載、公表、発表できます。Web サイト、レポート、記事、講演、カンファレンス発表、教材、次の実験提案などに利用できます。

公表または発表するときは、Semantic Deterrence を出典として示し、取得日、利用可能なスキーマまたはポリシーバージョン、標本数、不確実性を併記してください。結果は「警告後に探索継続を観測しなかった」と表現し、「AI エージェントを特定した」「攻撃を止めた」「脆弱性が存在しないことを証明した」とは表現しないでください。公開集計を他の情報と組み合わせ、訪問者や参加サイトの再識別を試みることは禁止します。

横断統計は、新しい送信、保持期限、参加解除、公開閾値、スキーマ変更によって変化します。再現性が必要な場合は、分析に使った JSON または日付付きエクスポートを保存し、現在値と過去のスナップショットを区別してください。

== External services ==

= GitHub Releases =

WordPress の更新確認時に `https://api.github.com/repos/at-shift/atshift-semantic-deterrence/releases/latest` へ接続し、公開された最新版、配布 ZIP、SHA-256 チェックサムを確認します。実験イベントは GitHub へ送信しません。

GitHub Terms of Service: https://docs.github.com/site-policy/github-terms/github-terms-of-service

GitHub General Privacy Statement: https://docs.github.com/site-policy/privacy-policies/github-general-privacy-statement

= Semantic Deterrence Aggregate Hub =

共有または横断集計の読み出しを管理者が有効にした場合だけ、設定された Hub へ接続します。既定の接続先は `https://aggregate.at-shift.net` です。送信範囲、利用目的、保持、解除時の扱いは上記 Privacy 節および公開リポジトリの Data Sharing Scope And Use に記載しています。

Data Sharing Scope And Use: https://github.com/at-shift/atshift-semantic-deterrence#data-sharing-scope-and-use

== Installation ==

1. プラグインフォルダを `/wp-content/plugins/` にアップロードします。
2. WordPress のプラグイン画面から有効化します。
3. 管理メニューの Semantic Deterrence を開きます。
4. 初期のクライアント試験では、まずモードを「観測のみ」のままにします。
5. 抑止、実験、匿名共有を有効にする前に、ローカル集計の検知内容を確認します。

== Frequently Asked Questions ==

= AIを検出しますか? =

いいえ。訪問者を AI と判定しません。不審なリクエストパターンを分類し、同じローカル系列からの後続動作を測定します。

= 観測のみモードでブロックしますか? =

いいえ。観測のみモードではローカル集計検知だけを記録し、HTTP レスポンスを変更しません。

= 集計サーバへデータを送信しますか? =

初期状態では送信しません。匿名共有を有効にした場合だけ、日次ジッター付きで匿名集計を送信します。集計サーバは比較データの読み出し元であり、遠隔操作システムではありません。

== Changelog ==

= 0.1.4 =
* Made event claims and request-budget updates database-atomic to prevent duplicate processing under concurrent requests.
* Hardened per-minute write budgets and storage cleanup behavior.

= 0.1.3 =
* Clarified when Experiment mode is selected but explicit experiment participation remains disabled.
* Added immediate consent-state feedback in Settings and an accurate inactive status on the Dashboard.
* Added thresholded cross-site response comparisons for readers who do not contribute experiment data.
* Kept observation-only detections out of post-response unknown counts while retaining them in local exposure totals.

= 0.1.2 =
* Added local-only hourly activity and optional Cloudflare country or region reference views.
* Improved mobile table layouts, Japanese wording, aggregate status labels, and dashboard typography.
* Explicitly excluded local reference geography and hourly statistics from Hub sharing.

= 0.1.1 =

* Hub通信をHTTPS必須、リダイレクトなし、応答サイズ上限付きにしました。
* 測定用系列と制御用系列を分け、公開リクエストによる保存件数へ上限を追加しました。
* 実験割り当てをポリシー期間で固定し、ローカルデータ削除時は観測のみへ戻すようにしました。
* エンコードされた探索パスの分類とHub集計JSONの検証を強化しました。

= 0.1.0 =

* ローカル観測、任意の抑止応答、ローカル実験、Dashboard 集計を備えたクライアント試験向け初期試作。
