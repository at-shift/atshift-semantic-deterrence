# 外部WAF連携とIP取得の調査記録

確認日: 2026-10-22。以下はローカルに取得したソースの確認結果であり、利用サイトの実ログを取得した結果ではありません。

## SDefenceで実装した範囲

- 狭い複合条件によるクエリ型コード注入・他CMS探索の検知。攻撃文字列は実行・保存・共有せず、外部URLやBase64本文にもアクセスしません。
- 初期値が無効の、管理者による外部WAF参考観測のオプトイン。
- CloudSecureの直近24時間の保持ログ件数。最短5分間隔、最新10,000行までの読み取り専用参照。検知ログには非遮断の記録もあり、遮断件数とは呼びません。
- BBQの遮断判断フックからの分類サンプル。製品ごとに60秒で最大1件、表示対象は直近24時間、保存上限は6,000行。全攻撃数や応答配信成功数ではありません。
- サンプルの期限切れ削除は収集時とWP-Cronで行います。アクセスがなくCronが動かない場合、保存済みサンプルの物理削除は遅れますが、24時間より古いサンプルは表示しません。
- 実験結果・通常403との差・Hub送信・匿名エクスポートと外部参考情報を分離。ダッシュボード閲覧ではWAFの再集計やサンプル書き込みを行いません。
- 外部連携を無効化した場合とローカルデータ削除時には、SDefence所有の参考情報だけを消去し、製品側のログは変更しません。
- 生IPを扱う遮断機能や `.htaccess` の編集は実装していません。別プラグインの責務として検討します。

## 各製品からのIP取得可否

| 製品・確認版 | 取得経路 | 制約 |
| --- | --- | --- |
| CloudSecure WP Security 1.4.18 | `${wpdb->prefix}cloudsecurewp_waf_log` の `ip`、`access_at` | 保持された記録に限り取得可能。IPは `REMOTE_ADDR` 由来。WAF検知時は記録後に終了し、専用の通知actionはないため、管理画面からの読み取りが候補。SDefenceの今回のアダプターは `ip` をSELECTしません。 |
| Wordfence 9.0.2 | `wfHits.IP`（16バイトのバイナリ）、`ctime`、`action`等。別途 `wfBlockedIPLog` にIP別の集計記録 | 有効なログ・保持設定に依存。通常訪問やログインイベントとWAF遮断を区別する必要あり。テーブル名はマルチサイトのネットワーク接頭辞に注意。既存のログ取得ヘルパーは位置情報取得等の副作用も確認する必要あり。 |
| NinjaFirewall 4.9.2 | 月次の `firewall_YYYY-MM.php` と、`.htninja` の `nfw_custom_user_log()` | ログにIP欄あり。ただし `anon_ip` が有効な公開IPは末尾が `xxx` になり、完全なIPを復元できません。情報レベルの記録や検出後スキャンは遮断記録とは別で、一部は `0.0.0.0`。フルWAFはWordPressより前に動くため別ブリッジが必要。 |
| SiteGuard WP Plugin 1.8.9 | ログイン履歴の `ip_address`、`operation`、`time`、`type` | ログイン系のIP取得は可能。ただし一般のコード注入WAFの履歴ではありません。成功・通常の失敗・意図的な一度目の失敗・ロックを区別する必要あり。WAFチューニングは別製品のSiteGuard Server Edition向け除外設定です。 |
| BBQ Firewall 20260809 | `bbq_response` 中のサーバー観測IPを、その場で読む実装は可能 | フックの引数は一致文字列であり、IP付きの履歴ではありません。標準の任意ログ出力も一致文字列のみで、後からIP履歴を取り出す仕組みにはなりません。今後記録するには別途オプトインとローカル保存設計が必要。 |

### 根拠となるソース箇所

- CloudSecure: `modules/waf.php` のテーブル作成・ログ保存、`modules/waf-engine.php` のリクエスト取得、`modules/common.php::get_client_ip()`。
- Wordfence: `lib/wfSchema.php` の `wfHits` / `wfBlockedIPLog`、`lib/wfLog.php::getHits()` と `processGetHitsResults()`、IPの `inet_pton` / `inet_ntop` 変換。
- NinjaFirewall: `lib/class-firewall-log.php` のログ生成とカスタム通知、`lib/class-ip.php::anonymize_ip()`。
- SiteGuard: `classes/siteguard-login-history.php`、`classes/siteguard-base.php::get_ip()`、`classes/siteguard-waf-exclude-rule.php`。
- BBQ: `block-bad-queries.php::bbq_response()`。

## 別の手動遮断プラグインで守る境界

1. ローカル管理者だけが、必要な範囲のIPと件数を読み取り、対象を選択する。SDefenceの共有契約に生IPを追加しない。
2. 生IPを新たに複製・保存する場合は、同意、保存期間、削除方法を別途設ける。既存ログを参照するだけで足りるか先に検討する。
3. CDN・リバースプロキシ越しの場合、記録された値が利用者のIPかプロキシのIPか確認する。未検証の転送ヘッダーや匿名化済みの値から遮断対象を作らない。
4. サイト管理者の現在IP、監視元、許可対象を確認し、既存設定の取得・差分・バックアップ・復旧手順を用意する。実サイトへの反映は別途承認を得る。
5. サーバー遮断後はSDefenceに届かず観測できない。将来連動させる場合、影響を受ける進行中の観測窓を判定不能にするなど、警告の効果と混同しない設計を先に決める。

## 検証記録

- `php tests/query-detection.php`: 提示された攻撃構造を無害な固定値へ置換した検体、通常の検索・フォーム、除外条件、応答オプトイン、間引き・書き込み予算を検証。
- `php tests/external-waf.php`: デフォルト無効、厳密な3フィールド境界、200件の通知を1件に間引く処理、24時間・行数上限、期限切れスナップショット、エラー時の動作継続、読み取り専用UIを検証。
- `php tests/external-waf-storage.php`: 実Storageのデフォルト設定、匿名エクスポートへの非混入、削除対象の分離を検証。
- `php tests/admin-permissions.php`: 概要・ダッシュボードの閲覧権限と、管理者限定の設定・操作を検証。
- Hubの検証・非公開調査レポートの既存テスト、全PHP構文チェック、14言語のgettextチェックを実施。
- 隔離したPHP描画フィクスチャとPlaywrightの一時Chromeで1280×900 / 390×844を確認。設定折り畳み、オプトイン操作、既存モード・返却文プレビュー、コンソール、横はみ出し、14言語MOの実表示を検証。
- 実WordPressへのWAF同時インストール、実MySQL・実Cronによる収集、実サイトのPHP修正、更新配布はこの検証には含みません。今回の変更は未公開・未デプロイです。

第三者プラグインのソースは参照のみで、SDefenceへ同梱・改変していません。
