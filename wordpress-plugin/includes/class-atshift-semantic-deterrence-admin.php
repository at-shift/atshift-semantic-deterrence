<?php
/**
 * Admin screens for semantic deterrence.
 *
 * @package AtshiftSemanticDeterrence
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Atshift_Semantic_Deterrence_Admin {
	/** @var Atshift_Semantic_Deterrence_Storage */
	private $storage;

	public function __construct( $storage ) {
		$this->storage = $storage;

		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_atsdn_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_atsdn_finalize_windows', array( $this, 'finalize_windows' ) );
		add_action( 'admin_post_atsdn_delete_local_data', array( $this, 'delete_local_data' ) );
		add_action( 'admin_post_atsdn_download_anonymous_batch', array( $this, 'download_anonymous_batch' ) );
		add_action( 'admin_post_atsdn_complete_onboarding', array( $this, 'complete_onboarding' ) );
	}

	public function add_menu() {
		add_menu_page(
			__( 'Semantic Deterrence', 'atshift-semantic-deterrence' ),
			__( 'Semantic Deterrence', 'atshift-semantic-deterrence' ),
			'manage_options',
			'atshift-semantic-deterrence',
			array( $this, 'render_readme_page' ),
			'dashicons-shield-alt',
			81
		);

		add_submenu_page(
			'atshift-semantic-deterrence',
			__( '意味的抑止の概要', 'atshift-semantic-deterrence' ),
			__( '概要', 'atshift-semantic-deterrence' ),
			'manage_options',
			'atshift-semantic-deterrence',
			array( $this, 'render_readme_page' )
		);

		add_submenu_page(
			'atshift-semantic-deterrence',
			__( '意味的抑止ダッシュボード', 'atshift-semantic-deterrence' ),
			__( 'ダッシュボード', 'atshift-semantic-deterrence' ),
			'manage_options',
			'atshift-semantic-deterrence-dashboard',
			array( $this, 'render_dashboard_page' )
		);

		add_submenu_page(
			'atshift-semantic-deterrence',
			__( '意味的抑止の設定', 'atshift-semantic-deterrence' ),
			__( '設定', 'atshift-semantic-deterrence' ),
			'manage_options',
			'atshift-semantic-deterrence-settings',
			array( $this, 'render_settings_page' )
		);
	}

	public function enqueue_assets( $hook_suffix ) {
		unset( $hook_suffix );

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$allowed_pages = array(
			'atshift-semantic-deterrence',
			'atshift-semantic-deterrence-dashboard',
			'atshift-semantic-deterrence-settings',
		);

		if ( ! in_array( $page, $allowed_pages, true ) ) {
			return;
		}

		wp_enqueue_style(
			'atshift-semantic-deterrence-admin',
			ATSHIFT_SEMANTIC_DETERRENCE_URL . 'assets/admin.css',
			array(),
			ATSHIFT_SEMANTIC_DETERRENCE_VERSION
		);

		wp_enqueue_script(
			'atshift-semantic-deterrence-admin',
			ATSHIFT_SEMANTIC_DETERRENCE_URL . 'assets/admin.js',
			array(),
			ATSHIFT_SEMANTIC_DETERRENCE_VERSION,
			true
		);
	}

	public function render_readme_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'このプラグインを管理する権限がありません。', 'atshift-semantic-deterrence' ) );
		}
		?>
		<div class="wrap atsdn-wrap">
			<h1><?php esc_html_e( 'Semantic Deterrence', 'atshift-semantic-deterrence' ); ?></h1>
			<?php $this->render_screen_nav( 'readme' ); ?>
			<?php $this->render_onboarding_modal(); ?>

			<section class="atsdn-status">
				<div class="atsdn-status-head">
					<div>
						<h2><?php esc_html_e( 'このプラグインで検証したいこと', 'atshift-semantic-deterrence' ); ?></h2>
						<p><?php esc_html_e( '不審な自動探索に対して、通常のHTTP拒否だけでなく、機械可読な状態と自然言語の撤退勧告を返したとき、その後の探索継続が減るかを観測します。', 'atshift-semantic-deterrence' ); ?></p>
					</div>
					<span class="atsdn-mode-pill"><?php esc_html_e( 'v0.1系 試験運用', 'atshift-semantic-deterrence' ); ?></span>
				</div>
				<div class="atsdn-signal-strip" aria-hidden="true">
					<span class="atsdn-signal atsdn-signal--observe"></span>
					<span class="atsdn-signal atsdn-signal--deter"></span>
					<span class="atsdn-signal atsdn-signal--measure"></span>
					<span class="atsdn-signal atsdn-signal--share"></span>
				</div>
				<p><?php esc_html_e( 'これはWAF、CDNルール、レート制限、アクセス制御の代替ではありません。エージェントの行動判断へ追加の摩擦を与える「意味的抑止」レイヤーです。', 'atshift-semantic-deterrence' ); ?></p>
			</section>

			<section class="atsdn-section">
				<h2><?php esc_html_e( '運用の前提', 'atshift-semantic-deterrence' ); ?></h2>
				<div class="atsdn-readme-grid">
					<div>
						<h3><?php esc_html_e( '初期状態', 'atshift-semantic-deterrence' ); ?></h3>
						<p><?php esc_html_e( '初期設定は「観測のみ」です。プラグインを有効化しただけでは、HTTPレスポンスを変更しません。', 'atshift-semantic-deterrence' ); ?></p>
					</div>
					<div>
						<h3><?php esc_html_e( '測定すること', 'atshift-semantic-deterrence' ); ?></h3>
						<p><?php esc_html_e( '警告件数、警告後に探索継続を観測しなかった件数、継続件数、判定不能件数、返却文ごとの推定効果を見ます。', 'atshift-semantic-deterrence' ); ?></p>
					</div>
					<div>
						<h3><?php esc_html_e( '言わないこと', 'atshift-semantic-deterrence' ); ?></h3>
						<p><?php esc_html_e( '「攻撃を止めた」「AIを検出した」とは断定しません。「警告後に探索継続を観測しなかった」と表現します。', 'atshift-semantic-deterrence' ); ?></p>
					</div>
					<div>
						<h3><?php esc_html_e( '共有について', 'atshift-semantic-deterrence' ); ?></h3>
						<p><?php esc_html_e( '匿名共有は明示的なオプトイン後だけ行います。IP、URL、Cookie、本文、ドメインを含まない匿名集計だけを扱います。', 'atshift-semantic-deterrence' ); ?></p>
					</div>
				</div>
			</section>

			<section class="atsdn-section">
				<h2><?php esc_html_e( '最初の使い方', 'atshift-semantic-deterrence' ); ?></h2>
				<ol class="atsdn-readme-steps">
					<li><?php esc_html_e( 'まず「観測のみ」で通常運用し、誤検知や管理画面・REST API・Ajaxへの影響がないか確認します。', 'atshift-semantic-deterrence' ); ?></li>
					<li><?php esc_html_e( 'ダッシュボードで検知カテゴリ、件数、直近観測、継続の有無を確認します。', 'atshift-semantic-deterrence' ); ?></li>
					<li><?php esc_html_e( '設定で固定カタログ1〜5の返却内容を確認し、必要な除外パスを設定します。', 'atshift-semantic-deterrence' ); ?></li>
					<li><?php esc_html_e( '問題がなければ、明示的に抑止または実験参加へ切り替えて効果測定を始めます。', 'atshift-semantic-deterrence' ); ?></li>
				</ol>
			</section>

			<section class="atsdn-section">
				<h2><?php esc_html_e( '実験の動作', 'atshift-semantic-deterrence' ); ?></h2>
				<div class="atsdn-readme-grid">
					<div>
						<h3><?php esc_html_e( '固定カタログで比較', 'atshift-semantic-deterrence' ); ?></h3>
						<p><?php esc_html_e( '自由文ではなく、1〜5の返却文と通常403の対照群だけを使います。これにより、各クライアントの結果を後から比較できます。', 'atshift-semantic-deterrence' ); ?></p>
					</div>
					<div>
						<h3><?php esc_html_e( '系列ごとの割り当て', 'atshift-semantic-deterrence' ); ?></h3>
						<p><?php esc_html_e( '同じ探索元らしきローカル系列には同じ応答を返します。途中で文面が変わって測定が濁るのを避けます。', 'atshift-semantic-deterrence' ); ?></p>
					</div>
					<div>
						<h3><?php esc_html_e( '段階表示の検証', 'atshift-semantic-deterrence' ); ?></h3>
						<p><?php esc_html_e( '設定で有効にした場合、同じ系列の継続時に固定順序で文面を変える群も混ぜられます。順序は決め打ちで、途中の成績では変えません。', 'atshift-semantic-deterrence' ); ?></p>
					</div>
					<div>
						<h3><?php esc_html_e( '対照群との差を見る', 'atshift-semantic-deterrence' ); ?></h3>
						<p><?php esc_html_e( '通常403の結果と意味的応答の結果を分けて記録し、十分な件数が集まったときだけ差分を表示します。', 'atshift-semantic-deterrence' ); ?></p>
					</div>
					<div>
						<h3><?php esc_html_e( '中央からは命令しない', 'atshift-semantic-deterrence' ); ?></h3>
						<p><?php esc_html_e( '将来の集計読み出しは判断材料の取得に限ります。中央からコード、強制遮断、強制設定は受け取りません。', 'atshift-semantic-deterrence' ); ?></p>
					</div>
				</div>
			</section>
		</div>
		<?php
	}

	public function render_dashboard_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'このプラグインを管理する権限がありません。', 'atshift-semantic-deterrence' ) );
		}

		$this->storage->finalize_windows();

		$settings      = Atshift_Semantic_Deterrence_Storage::get_settings();
		$summary_30    = $this->storage->get_summary( 30 );
		$summary_today = $this->storage->get_summary( 1 );
		$summary_7     = $this->storage->get_summary( 7 );
		$summary_90    = $this->storage->get_summary( 90 );
		$variant_stats = $this->storage->get_variant_stats( 30 );
		$recent_events = $this->storage->get_recent_events( 10 );
		$local_activity = $this->storage->get_local_activity_counts();
		$hourly_activity = $this->storage->get_hourly_activity( 24 );
		$country_activity = $this->storage->get_country_activity( 24 );
		$rate          = $this->calculate_non_continuation_rate( $summary_30 );
		$improvement   = $this->calculate_control_improvement( $variant_stats );
		$current_best  = $this->get_current_best_variant( $variant_stats );
		$next_share    = Atshift_Semantic_Deterrence_Storage::calculate_next_share_after( $settings );
		?>
		<div class="wrap atsdn-wrap">
			<h1><?php esc_html_e( 'Semantic Deterrence', 'atshift-semantic-deterrence' ); ?></h1>
			<?php $this->render_screen_nav( 'dashboard' ); ?>

			<?php $this->render_notice_messages(); ?>
			<?php $this->render_onboarding_modal(); ?>

			<?php $status_mode = 'experiment' === $settings['mode'] && '1' !== $settings['experiment_enabled'] ? 'experiment-pending' : $settings['mode']; ?>
			<div class="atsdn-status atsdn-status--<?php echo esc_attr( sanitize_html_class( $status_mode ) ); ?>">
				<div class="atsdn-status-head">
					<div>
						<h2><?php esc_html_e( 'このサイトの観測', 'atshift-semantic-deterrence' ); ?></h2>
						<p>
							<?php esc_html_e( 'このプラグインは訪問者をAIと判定しません。不審な探索にこのサイトの応答を返した後、探索が継続したかを測定します。', 'atshift-semantic-deterrence' ); ?>
						</p>
					</div>
					<span class="atsdn-mode-pill"><?php echo esc_html( $this->get_mode_label( $settings['mode'], $settings['experiment_enabled'] ) ); ?></span>
				</div>
				<div class="atsdn-signal-strip" aria-hidden="true">
					<span class="atsdn-signal atsdn-signal--observe"></span>
					<span class="atsdn-signal atsdn-signal--deter"></span>
					<span class="atsdn-signal atsdn-signal--measure"></span>
					<span class="atsdn-signal atsdn-signal--share"></span>
				</div>
				<p>
					<?php esc_html_e( '匿名集計の提供はオプトイン時だけ日次ジッターで送信します。集計参照は提供に参加しなくても利用できます。', 'atshift-semantic-deterrence' ); ?>
				</p>
				<p>
					<strong><?php esc_html_e( '将来の日次共有予定:', 'atshift-semantic-deterrence' ); ?></strong>
					<?php
					printf(
						/* translators: 1: Next local datetime, 2: jitter hours. */
						esc_html__( 'ローカル時刻 %1$s 前後。%2$d時間のジッター窓に分散します。', 'atshift-semantic-deterrence' ),
						esc_html( $next_share ),
						absint( $settings['share_jitter_hours'] )
					);
					?>
				</p>
			</div>

			<?php $this->render_network_aggregate_section( $settings ); ?>

			<section class="atsdn-section">
				<h2><?php esc_html_e( 'このサイトの過去30日', 'atshift-semantic-deterrence' ); ?></h2>
				<p class="atsdn-section-lede"><?php esc_html_e( 'このWordPressサイト内で観測したローカル集計です。共有Hubの集計や他サイトの結果は含みません。', 'atshift-semantic-deterrence' ); ?></p>
				<div class="atsdn-summary-grid">
					<?php
					$this->render_summary_item( __( '不審な探索を検知', 'atshift-semantic-deterrence' ), $summary_30['detected'], 'detect' );
					$this->render_summary_item( __( '警告を返した件数', 'atshift-semantic-deterrence' ), $summary_30['warnings'], 'warn' );
					$this->render_summary_item( __( '警告後に継続を観測しなかった', 'atshift-semantic-deterrence' ), $summary_30['observed_ceased'], 'ceased' );
					$this->render_summary_item( __( '探索継続を観測', 'atshift-semantic-deterrence' ), $summary_30['continued'], 'continued' );
					$this->render_summary_item( __( '判定不能', 'atshift-semantic-deterrence' ), $summary_30['unknown'], 'unknown' );
					$this->render_summary_item( __( '推定継続非観測率', 'atshift-semantic-deterrence' ), $rate, 'rate' );
					$this->render_summary_item( __( '通常403との差', 'atshift-semantic-deterrence' ), $improvement, 'diff' );
					$this->render_summary_item( __( '現在もっとも有効と推定される応答', 'atshift-semantic-deterrence' ), $current_best, 'best' );
					?>
				</div>
			</section>

			<section class="atsdn-section atsdn-local-reference">
				<h2><?php esc_html_e( 'このサイトだけの参考観測', 'atshift-semantic-deterrence' ); ?></h2>
				<p class="atsdn-section-lede"><?php esc_html_e( '実験効果の判定とは分けたローカル参考情報です。記録間引き後の観測件数であり、実際の全リクエスト数ではありません。時間別件数と国・地域コードはHubへ送信しません。', 'atshift-semantic-deterrence' ); ?></p>
				<div class="atsdn-local-reference-layout">
					<div>
						<div class="atsdn-summary-grid atsdn-summary-grid--compact">
							<?php
							$this->render_summary_item( __( '直近1時間の観測', 'atshift-semantic-deterrence' ), $local_activity['one_hour'], 'detect' );
							$this->render_summary_item( __( '直近24時間の観測', 'atshift-semantic-deterrence' ), $local_activity['twenty_four_hours'], 'warn' );
							?>
						</div>
						<h3><?php esc_html_e( '24時間の推移', 'atshift-semantic-deterrence' ); ?></h3>
						<?php $this->render_hourly_activity_chart( $hourly_activity ); ?>
					</div>
					<div class="atsdn-country-panel">
						<h3><?php esc_html_e( '接続元として観測された国・地域', 'atshift-semantic-deterrence' ); ?></h3>
						<p><?php esc_html_e( '攻撃者の所在地を断定するものではありません。プロキシやVPNの出口を示す場合があります。', 'atshift-semantic-deterrence' ); ?></p>
						<?php if ( 'cloudflare' !== $settings['country_header_source'] ) : ?>
							<p class="atsdn-local-reference-empty"><?php esc_html_e( '国・地域の参考表示は無効です。Cloudflare経由のサイトでは、高度な設定から有効にできます。', 'atshift-semantic-deterrence' ); ?></p>
						<?php elseif ( empty( $country_activity ) ) : ?>
							<p class="atsdn-local-reference-empty"><?php esc_html_e( '直近24時間に国・地域コード付きの観測はありません。', 'atshift-semantic-deterrence' ); ?></p>
						<?php else : ?>
							<ul class="atsdn-country-list">
								<?php foreach ( $country_activity as $country ) : ?>
									<li>
										<span><strong><?php echo esc_html( $this->get_country_display_name( $country['country_code'] ) ); ?></strong><code><?php echo esc_html( $country['country_code'] ); ?></code></span>
										<b><?php echo esc_html( absint( $country['event_count'] ) ); ?></b>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</div>
				</div>
			</section>

			<section class="atsdn-section">
				<h2><?php esc_html_e( 'このサイトの観測期間別', 'atshift-semantic-deterrence' ); ?></h2>
				<p class="atsdn-section-lede"><?php esc_html_e( '同じサイト内の観測を期間ごとに見ています。', 'atshift-semantic-deterrence' ); ?></p>
				<table class="widefat striped atsdn-data-table atsdn-window-table">
					<thead>
						<tr>
							<th><?php esc_html_e( '期間', 'atshift-semantic-deterrence' ); ?></th>
							<th><?php esc_html_e( '検知', 'atshift-semantic-deterrence' ); ?></th>
							<th><?php esc_html_e( '警告', 'atshift-semantic-deterrence' ); ?></th>
							<th><?php esc_html_e( '継続なしを観測', 'atshift-semantic-deterrence' ); ?></th>
							<th><?php esc_html_e( '継続', 'atshift-semantic-deterrence' ); ?></th>
							<th><?php esc_html_e( '不明', 'atshift-semantic-deterrence' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						$this->render_window_row( __( '今日', 'atshift-semantic-deterrence' ), $summary_today );
						$this->render_window_row( __( '7日', 'atshift-semantic-deterrence' ), $summary_7 );
						$this->render_window_row( __( '30日', 'atshift-semantic-deterrence' ), $summary_30 );
						$this->render_window_row( __( '90日', 'atshift-semantic-deterrence' ), $summary_90 );
						?>
					</tbody>
				</table>
			</section>

			<section class="atsdn-section">
				<h2><?php esc_html_e( 'このサイトの直近観測', 'atshift-semantic-deterrence' ); ?></h2>
				<p class="atsdn-section-lede"><?php esc_html_e( '他のセキュリティ通知と時刻や分類を見比べるためのローカル観測です。このプラグインはWAFログを読みません。IP、URL、User-Agent、Cookie、リクエスト本文は保存していません。', 'atshift-semantic-deterrence' ); ?></p>
				<table class="widefat striped atsdn-data-table atsdn-recent-table">
					<thead>
						<tr>
							<th><?php esc_html_e( '時刻', 'atshift-semantic-deterrence' ); ?></th>
							<th><?php esc_html_e( '分類', 'atshift-semantic-deterrence' ); ?></th>
							<th><?php esc_html_e( 'レベル', 'atshift-semantic-deterrence' ); ?></th>
							<th><?php esc_html_e( '実験群', 'atshift-semantic-deterrence' ); ?></th>
							<th><?php esc_html_e( '応答', 'atshift-semantic-deterrence' ); ?></th>
							<th><?php esc_html_e( 'HTTP', 'atshift-semantic-deterrence' ); ?></th>
							<th><?php esc_html_e( '結果', 'atshift-semantic-deterrence' ); ?></th>
							<th><?php esc_html_e( '継続数', 'atshift-semantic-deterrence' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $recent_events ) ) : ?>
							<tr class="atsdn-table-empty"><td colspan="8"><?php esc_html_e( '直近の観測はまだありません。', 'atshift-semantic-deterrence' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $recent_events as $row ) : ?>
								<?php $this->render_recent_event_row( $row ); ?>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</section>

			<section class="atsdn-section">
				<h2><?php esc_html_e( 'このサイトの応答別比較', 'atshift-semantic-deterrence' ); ?></h2>
				<p class="atsdn-section-lede"><?php esc_html_e( 'このサイトで返した応答ごとの推定効果です。共有Hubの集計とは分けて表示します。', 'atshift-semantic-deterrence' ); ?></p>
				<table class="widefat striped atsdn-data-table">
					<thead>
						<tr>
							<th><?php esc_html_e( '応答選択肢', 'atshift-semantic-deterrence' ); ?></th>
							<th><?php esc_html_e( '実験群', 'atshift-semantic-deterrence' ); ?></th>
							<th><?php esc_html_e( '内容識別', 'atshift-semantic-deterrence' ); ?></th>
							<th><?php esc_html_e( '件数', 'atshift-semantic-deterrence' ); ?></th>
							<th><?php esc_html_e( '継続なしを観測', 'atshift-semantic-deterrence' ); ?></th>
							<th><?php esc_html_e( '継続', 'atshift-semantic-deterrence' ); ?></th>
							<th><?php esc_html_e( '不明', 'atshift-semantic-deterrence' ); ?></th>
							<th><?php esc_html_e( '率', 'atshift-semantic-deterrence' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $variant_stats ) ) : ?>
							<tr class="atsdn-table-empty"><td colspan="8"><?php esc_html_e( '応答比較データはまだありません。', 'atshift-semantic-deterrence' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $variant_stats as $row ) : ?>
								<?php $rate_label = $this->calculate_non_continuation_rate( array( 'observed_ceased' => $row['ceased'], 'continued' => $row['continued'] ) ); ?>
								<tr>
									<td data-label="<?php esc_attr_e( '応答選択肢', 'atshift-semantic-deterrence' ); ?>"><span class="atsdn-variant-chip"><?php echo esc_html( $this->get_variant_display_label( $row['variant'] ) ); ?></span></td>
									<td data-label="<?php esc_attr_e( '実験群', 'atshift-semantic-deterrence' ); ?>"><?php echo esc_html( $this->get_experiment_arm_label( $row['experiment_arm'] ?? '' ) ); ?></td>
									<td data-label="<?php esc_attr_e( '内容識別', 'atshift-semantic-deterrence' ); ?>"><?php $this->render_response_identity( $row ); ?></td>
									<td data-label="<?php esc_attr_e( '件数', 'atshift-semantic-deterrence' ); ?>"><?php echo esc_html( absint( $row['total'] ) ); ?></td>
									<td data-label="<?php esc_attr_e( '継続なしを観測', 'atshift-semantic-deterrence' ); ?>"><?php echo esc_html( absint( $row['ceased'] ) ); ?></td>
									<td data-label="<?php esc_attr_e( '継続', 'atshift-semantic-deterrence' ); ?>"><?php echo esc_html( absint( $row['continued'] ) ); ?></td>
									<td data-label="<?php esc_attr_e( '不明', 'atshift-semantic-deterrence' ); ?>"><?php echo esc_html( absint( $row['unknown_count'] ) ); ?></td>
									<td data-label="<?php esc_attr_e( '率', 'atshift-semantic-deterrence' ); ?>"><?php $this->render_rate_bar( $rate_label ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</section>
		</div>
		<?php
	}

	private function render_agent_response_section( $settings, $selected_only = false ) {
		$mode         = $settings['mode'];
		$is_experiment_pending = 'experiment' === $mode && '1' !== $settings['experiment_enabled'];
		$is_observing          = 'observe' === $mode || $is_experiment_pending;
		$is_limited   = 'deter_limit' === $mode;
		$variants     = $selected_only ? Atshift_Semantic_Deterrence_Detector::get_semantic_variant_ids() : $this->get_response_preview_variants( $settings );
		?>
		<section class="atsdn-response-preview<?php echo $selected_only ? ' atsdn-response-preview--selected' : ''; ?>" data-atsdn-response-preview>
			<h2><?php esc_html_e( 'エージェントへ返す内容', 'atshift-semantic-deterrence' ); ?></h2>
			<p class="atsdn-section-lede">
				<?php if ( $is_experiment_pending ) : ?>
					<?php esc_html_e( '応答実験を開始していなくても、不審探索の件数と分類はこのサイト内で観測します。開始して保存するまではHTTPレスポンスを変更せず、警告後の継続有無は測定しません。', 'atshift-semantic-deterrence' ); ?>
				<?php elseif ( $is_observing ) : ?>
					<?php esc_html_e( '現在は観測のみのため、不審リクエストのレスポンスは変更しません。下のプレビューは、抑止を有効にした場合に返す内容です。', 'atshift-semantic-deterrence' ); ?>
				<?php elseif ( 'experiment' === $mode && '1' === $settings['experiment_enabled'] ) : ?>
					<?php esc_html_e( '実験モードでは、設定した割り当て戦略に従い、固定カタログと通常403の対照群を決め打ちで割り当てます。途中の成績で自動変更しません。', 'atshift-semantic-deterrence' ); ?>
				<?php else : ?>
					<?php esc_html_e( '高確度の不審探索へ返すHTTP状態、機械可読な状態、自然言語の撤退勧告です。', 'atshift-semantic-deterrence' ); ?>
				<?php endif; ?>
			</p>
			<div class="atsdn-response-grid">
				<?php foreach ( $variants as $variant ) : ?>
					<?php $this->render_response_preview_card( $variant, $settings, $is_limited, $selected_only ); ?>
				<?php endforeach; ?>
			</div>
		</section>
		<?php
	}

	private function render_network_aggregate_section( $settings ) {
		$aggregate = array();
		if ( '' !== $settings['last_aggregate_json'] ) {
			$decoded = json_decode( $settings['last_aggregate_json'], true );
			if ( is_array( $decoded ) ) {
				$aggregate = $decoded;
			}
		}

		$best             = isset( $aggregate['best_variant'] ) && is_array( $aggregate['best_variant'] ) ? $aggregate['best_variant'] : null;
		$network_variants = isset( $aggregate['variants'] ) && is_array( $aggregate['variants'] ) ? $aggregate['variants'] : array();
		?>
		<section class="atsdn-section atsdn-network-section">
			<div class="atsdn-status-head">
				<div>
					<h2><?php esc_html_e( '共有集計の参照', 'atshift-semantic-deterrence' ); ?></h2>
					<p class="atsdn-section-lede"><?php esc_html_e( '参加しなくても、自分のサイトの状況確認と、集まった匿名集計データの参照は可能です。', 'atshift-semantic-deterrence' ); ?></p>
				</div>
				<span class="atsdn-mode-pill"><?php echo esc_html( '1' === $settings['aggregate_read_enabled'] ? __( '参照有効', 'atshift-semantic-deterrence' ) : __( '参照停止中', 'atshift-semantic-deterrence' ) ); ?></span>
			</div>
			<div class="atsdn-summary-grid">
				<?php
				$this->render_summary_item(
					__( '集計参照の状態', 'atshift-semantic-deterrence' ),
					$this->get_aggregate_status_label( $settings['last_aggregate_status'] ),
					'share'
				);
				$this->render_summary_item(
					__( '最終取得', 'atshift-semantic-deterrence' ),
					$settings['last_aggregate_pull'] ? $settings['last_aggregate_pull'] : __( '未実行', 'atshift-semantic-deterrence' ),
					'unknown'
				);
				$this->render_summary_item(
					__( '共有集計で高く見える応答', 'atshift-semantic-deterrence' ),
					$best ? $this->format_network_best_variant( $best ) : __( 'まだ閾値未満', 'atshift-semantic-deterrence' ),
					'best'
				);
				?>
			</div>
			<table class="widefat striped atsdn-data-table atsdn-network-comparison-table">
				<thead>
					<tr>
						<th><?php esc_html_e( '応答選択肢', 'atshift-semantic-deterrence' ); ?></th>
						<th><?php esc_html_e( '実験群', 'atshift-semantic-deterrence' ); ?></th>
						<th><?php esc_html_e( 'サイト数', 'atshift-semantic-deterrence' ); ?></th>
						<th><?php esc_html_e( '件数', 'atshift-semantic-deterrence' ); ?></th>
						<th><?php esc_html_e( '継続なしを観測', 'atshift-semantic-deterrence' ); ?></th>
						<th><?php esc_html_e( '継続', 'atshift-semantic-deterrence' ); ?></th>
						<th><?php esc_html_e( '不明', 'atshift-semantic-deterrence' ); ?></th>
						<th><?php esc_html_e( '率', 'atshift-semantic-deterrence' ); ?></th>
						<th><?php esc_html_e( '通常403との差', 'atshift-semantic-deterrence' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $network_variants ) ) : ?>
						<tr class="atsdn-table-empty"><td colspan="9"><?php esc_html_e( '応答比較データはまだありません。', 'atshift-semantic-deterrence' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $network_variants as $row ) : ?>
							<tr>
								<td data-label="<?php esc_attr_e( '応答選択肢', 'atshift-semantic-deterrence' ); ?>"><span class="atsdn-variant-chip"><?php echo esc_html( $this->get_variant_display_label( $row['variant'] ) ); ?></span></td>
								<td data-label="<?php esc_attr_e( '実験群', 'atshift-semantic-deterrence' ); ?>"><?php echo esc_html( $this->get_experiment_arm_label( $row['experiment_arm'] ) ); ?></td>
								<td data-label="<?php esc_attr_e( 'サイト数', 'atshift-semantic-deterrence' ); ?>"><?php echo esc_html( absint( $row['site_count'] ) ); ?></td>
								<td data-label="<?php esc_attr_e( '件数', 'atshift-semantic-deterrence' ); ?>"><?php echo esc_html( absint( $row['total_events'] ) ); ?></td>
								<td data-label="<?php esc_attr_e( '継続なしを観測', 'atshift-semantic-deterrence' ); ?>"><?php echo esc_html( absint( $row['observed_ceased'] ) ); ?></td>
								<td data-label="<?php esc_attr_e( '継続', 'atshift-semantic-deterrence' ); ?>"><?php echo esc_html( absint( $row['continued'] ) ); ?></td>
								<td data-label="<?php esc_attr_e( '不明', 'atshift-semantic-deterrence' ); ?>"><?php echo esc_html( absint( $row['unknown'] ) ); ?></td>
								<td data-label="<?php esc_attr_e( '率', 'atshift-semantic-deterrence' ); ?>"><?php echo esc_html( null === $row['non_continuation_rate'] ? __( '測定中', 'atshift-semantic-deterrence' ) : sprintf( '%.1f%%', $row['non_continuation_rate'] ) ); ?></td>
								<td data-label="<?php esc_attr_e( '通常403との差', 'atshift-semantic-deterrence' ); ?>"><?php echo esc_html( $this->format_network_control_difference( $row, $network_variants ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</section>
		<?php
	}

	private function render_response_preview_card( $variant, $settings, $is_limited, $hide_when_unselected = false ) {
		$status_label = $is_limited ? '403、継続観測後は429' : '403';
		$profile      = Atshift_Semantic_Deterrence_Detector::get_response_profile( $variant, true, $is_limited, 403 );
		$headers      = $profile['headers'];
		$body         = 'control_generic' === $variant ? __( '(通常403の対照群です。意味的抑止の本文は返しません。)', 'atshift-semantic-deterrence' ) : $profile['body'];
		$options      = Atshift_Semantic_Deterrence_Detector::get_response_options();
		$label        = $options[ $variant ] ?? __( '対照群: 通常403', 'atshift-semantic-deterrence' );
		?>
		<article class="atsdn-response-card<?php echo $hide_when_unselected && $variant !== $settings['preferred_variant'] ? ' is-hidden' : ''; ?>" data-atsdn-response-card="<?php echo esc_attr( $variant ); ?>">
			<div class="atsdn-response-card-head">
				<span class="atsdn-variant-chip"><?php echo esc_html( $label ); ?></span>
				<span class="atsdn-status-code"><?php echo esc_html( $status_label ); ?></span>
			</div>
			<div class="atsdn-response-fingerprint">
				<span><?php esc_html_e( '指紋', 'atshift-semantic-deterrence' ); ?></span>
				<code><?php echo esc_html( substr( $profile['fingerprint'], 0, 16 ) ); ?></code>
			</div>
			<div class="atsdn-response-block">
				<span><?php esc_html_e( 'ヘッダー', 'atshift-semantic-deterrence' ); ?></span>
				<pre><?php echo esc_html( implode( "\n", $headers ) ); ?></pre>
			</div>
			<div class="atsdn-response-block">
				<span><?php esc_html_e( '本文', 'atshift-semantic-deterrence' ); ?></span>
				<pre><?php echo esc_html( $body ); ?></pre>
			</div>
		</article>
		<?php
	}

	private function get_response_preview_variants( $settings ) {
		if ( 'experiment' === $settings['mode'] && '1' === $settings['experiment_enabled'] ) {
			return Atshift_Semantic_Deterrence_Detector::get_variant_ids();
		}

		return array( $settings['preferred_variant'] );
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'このプラグインを管理する権限がありません。', 'atshift-semantic-deterrence' ) );
		}

		$settings = Atshift_Semantic_Deterrence_Storage::get_settings();
		?>
		<div class="wrap atsdn-wrap">
			<h1><?php esc_html_e( 'Semantic Deterrence', 'atshift-semantic-deterrence' ); ?></h1>
			<?php $this->render_screen_nav( 'settings' ); ?>

			<?php $this->render_notice_messages(); ?>
			<?php $this->render_onboarding_modal(); ?>

			<section class="atsdn-section atsdn-section--settings">
				<h2><?php esc_html_e( '設定', 'atshift-semantic-deterrence' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="atsdn_save_settings">
					<?php wp_nonce_field( 'atsdn_save_settings', 'atsdn_nonce' ); ?>
					<table class="form-table atsdn-settings-table" role="presentation">
						<tr>
							<th scope="row"><label for="atsdn-mode"><?php esc_html_e( 'モード', 'atshift-semantic-deterrence' ); ?></label></th>
							<td>
								<select id="atsdn-mode" name="mode">
									<?php foreach ( $this->get_modes() as $value => $label ) : ?>
										<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['mode'], $value ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
								<div class="atsdn-mode-help" data-atsdn-mode-help>
									<p data-atsdn-mode-help-item="observe"><?php esc_html_e( '観測のみ: このサイト内だけで不審な探索を記録します。HTTPレスポンスは変更せず、匿名集計も提供しません。', 'atshift-semantic-deterrence' ); ?></p>
									<p data-atsdn-mode-help-item="deter"><?php esc_html_e( '抑止: このサイト内だけで高確度の不審探索へ意味的応答を返し、その後の継続有無を測定します。', 'atshift-semantic-deterrence' ); ?></p>
									<p data-atsdn-mode-help-item="deter_limit"><?php esc_html_e( '抑止 + 一時制限: このサイト内だけで意味的応答を返し、継続を観測した系列へRetry-Afterを返します。', 'atshift-semantic-deterrence' ); ?></p>
									<p data-atsdn-mode-help-item="experiment"><?php esc_html_e( '実験参加: このサイトでは高確度の不審探索へ意味的応答を返しながら、固定カタログと通常403の対照群を使って測定します。同意した場合だけ匿名集計を共有し、様々なサイトでの結果を比較できるようにします。', 'atshift-semantic-deterrence' ); ?></p>
								</div>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="atsdn-sensitivity"><?php esc_html_e( '検知感度', 'atshift-semantic-deterrence' ); ?></label></th>
							<td>
								<select id="atsdn-sensitivity" name="sensitivity">
									<option value="cautious" <?php selected( $settings['sensitivity'], 'cautious' ); ?>><?php esc_html_e( '慎重', 'atshift-semantic-deterrence' ); ?></option>
									<option value="standard" <?php selected( $settings['sensitivity'], 'standard' ); ?>><?php esc_html_e( '標準', 'atshift-semantic-deterrence' ); ?></option>
									<option value="strong" <?php selected( $settings['sensitivity'], 'strong' ); ?>><?php esc_html_e( '強め', 'atshift-semantic-deterrence' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="atsdn-variant"><?php esc_html_e( '返却文の選択', 'atshift-semantic-deterrence' ); ?></label></th>
							<td>
								<select id="atsdn-variant" name="preferred_variant">
									<?php foreach ( Atshift_Semantic_Deterrence_Detector::get_response_options() as $variant => $label ) : ?>
										<option value="<?php echo esc_attr( $variant ); ?>" <?php selected( $settings['preferred_variant'], $variant ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'クライアント間で比較できるよう、固定カタログ1〜5から選びます。自由文はv0.1の比較対象にしません。', 'atshift-semantic-deterrence' ); ?></p>
							</td>
						</tr>
						<tr data-atsdn-mode-panel="experiment">
							<th scope="row"><?php esc_html_e( '応答実験の開始', 'atshift-semantic-deterrence' ); ?></th>
							<td>
								<label><input id="atsdn-experiment-enabled" type="checkbox" name="experiment_enabled" value="1" <?php checked( $settings['experiment_enabled'], '1' ); ?>> <?php esc_html_e( 'このサイトを抑止しながら、固定カタログと対照群を使ったローカル応答実験を有効にする', 'atshift-semantic-deterrence' ); ?></label>
								<p class="description atsdn-experiment-consent-state" data-atsdn-experiment-consent-state>
									<span data-atsdn-experiment-state="pending"><?php esc_html_e( '応答実験を開始していなくても、不審探索の件数と分類はこのサイト内で観測します。開始して保存するまではHTTPレスポンスを変更せず、警告後の継続有無は測定しません。', 'atshift-semantic-deterrence' ); ?></span>
									<span data-atsdn-experiment-state="enabled"><?php esc_html_e( 'このサイトを抑止しながら実験参加中', 'atshift-semantic-deterrence' ); ?></span>
								</p>
							</td>
						</tr>
						<tr data-atsdn-mode-panel="experiment">
							<th scope="row"><label for="atsdn-experiment-assignment-strategy"><?php esc_html_e( '実験割り当て', 'atshift-semantic-deterrence' ); ?></label></th>
							<td>
								<?php $assignment_locked = '1' === $settings['experiment_assignment_locked']; ?>
								<?php if ( $assignment_locked ) : ?>
									<input type="hidden" name="experiment_assignment_strategy" value="<?php echo esc_attr( $settings['experiment_assignment_strategy'] ); ?>">
								<?php endif; ?>
								<select id="atsdn-experiment-assignment-strategy" name="experiment_assignment_strategy" <?php disabled( $assignment_locked ); ?>>
									<?php foreach ( $this->get_experiment_assignment_strategies() as $value => $label ) : ?>
										<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['experiment_assignment_strategy'], $value ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
								<?php if ( $assignment_locked ) : ?>
									<p class="description">
										<?php
										printf(
											/* translators: %s: local datetime. */
											esc_html__( 'この割り当ては %s にロックされました。途中変更で比較が濁らないよう、このサイトでは変更できません。', 'atshift-semantic-deterrence' ),
											esc_html( $this->format_local_datetime( $settings['experiment_assignment_locked_at'] ) )
										);
										?>
									</p>
								<?php else : ?>
									<p class="description"><?php esc_html_e( '実験モードでだけ使います。統計の確度を守るため、実験開始後は固定され、途中の成績では変えません。', 'atshift-semantic-deterrence' ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
						<tr data-atsdn-mode-panel="limit">
							<th scope="row"><label for="atsdn-limit"><?php esc_html_e( '一時制限秒数', 'atshift-semantic-deterrence' ); ?></label></th>
							<td>
								<input id="atsdn-limit" type="number" min="60" max="86400" step="60" name="limit_seconds" value="<?php echo esc_attr( absint( $settings['limit_seconds'] ) ); ?>">
								<p class="description"><?php esc_html_e( '抑止 + 一時制限モードで、継続観測後に Retry-After として返す秒数です。', 'atshift-semantic-deterrence' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( '集計データ', 'atshift-semantic-deterrence' ); ?></th>
							<td>
								<label><input type="checkbox" name="aggregate_read_enabled" value="1" <?php checked( $settings['aggregate_read_enabled'], '1' ); ?>> <?php esc_html_e( '各クライアントが共有した匿名集計を参照する', 'atshift-semantic-deterrence' ); ?></label><br>
								<label><input type="checkbox" name="sharing_enabled" value="1" <?php checked( $settings['sharing_enabled'], '1' ); ?>> <?php esc_html_e( 'このサイトの匿名集計を日次で提供する', 'atshift-semantic-deterrence' ); ?></label>
								<p class="description"><?php esc_html_e( '参加しなくても集計の参照は可能です。提供する場合も、IP、URL、Cookie、本文、ドメインは送信しません。', 'atshift-semantic-deterrence' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'アンインストール', 'atshift-semantic-deterrence' ); ?></th>
							<td>
								<label><input type="checkbox" name="delete_on_uninstall" value="1" <?php checked( $settings['delete_on_uninstall'], '1' ); ?>> <?php esc_html_e( 'アンインストール時にローカル集計データと設定を削除する', 'atshift-semantic-deterrence' ); ?></label>
							</td>
						</tr>
					</table>

					<div class="atsdn-selected-response-panel">
						<?php $this->render_agent_response_section( $settings, true ); ?>
					</div>

					<details class="atsdn-settings-details">
						<summary><?php esc_html_e( '高度な設定', 'atshift-semantic-deterrence' ); ?></summary>
						<table class="form-table atsdn-settings-table" role="presentation">
							<tr>
								<th scope="row"><label for="atsdn-excluded-paths"><?php esc_html_e( '除外パス', 'atshift-semantic-deterrence' ); ?></label></th>
								<td>
									<textarea id="atsdn-excluded-paths" name="excluded_paths" rows="5" class="large-text code"><?php echo esc_textarea( $settings['excluded_paths'] ); ?></textarea>
									<p class="description"><?php esc_html_e( '1行に1つのパス前方一致を指定します。一致したリクエストは記録もレスポンス変更もしません。', 'atshift-semantic-deterrence' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="atsdn-custom-high-confidence-paths"><?php esc_html_e( '高確度カスタムパス', 'atshift-semantic-deterrence' ); ?></label></th>
								<td>
									<textarea id="atsdn-custom-high-confidence-paths" name="custom_high_confidence_paths" rows="4" class="large-text code"><?php echo esc_textarea( $settings['custom_high_confidence_paths'] ); ?></textarea>
									<p class="description"><?php esc_html_e( '1行に1つのパス前方一致を指定します。一致したものは other_high_confidence として扱います。正当なサイト固有エンドポイントは除外パスへ入れてください。', 'atshift-semantic-deterrence' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="atsdn-excluded-ips"><?php esc_html_e( '除外IP', 'atshift-semantic-deterrence' ); ?></label></th>
								<td>
									<textarea id="atsdn-excluded-ips" name="excluded_ips" rows="4" class="large-text code"><?php echo esc_textarea( $settings['excluded_ips'] ); ?></textarea>
									<p class="description"><?php esc_html_e( '1行に1つの完全一致IPを指定します。IPはローカル除外とHMAC生成にだけ使います。', 'atshift-semantic-deterrence' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="atsdn-country-header-source"><?php esc_html_e( '国・地域の参考表示', 'atshift-semantic-deterrence' ); ?></label></th>
								<td>
									<select id="atsdn-country-header-source" name="country_header_source">
										<option value="disabled" <?php selected( $settings['country_header_source'], 'disabled' ); ?>><?php esc_html_e( '無効', 'atshift-semantic-deterrence' ); ?></option>
										<option value="cloudflare" <?php selected( $settings['country_header_source'], 'cloudflare' ); ?>><?php esc_html_e( 'Cloudflareの国・地域コードを使う', 'atshift-semantic-deterrence' ); ?></option>
									</select>
									<p class="description"><?php esc_html_e( 'Cloudflare経由であることを確認したサイトだけで有効にしてください。CF-IPCountryの2文字コードだけをローカル保存し、IPは保存しません。この情報はHubへ送信しません。', 'atshift-semantic-deterrence' ); ?></p>
								</td>
							</tr>
						</table>
					</details>

					<details class="atsdn-settings-details">
						<summary><?php esc_html_e( 'Hub接続', 'atshift-semantic-deterrence' ); ?></summary>
						<p class="atsdn-details-lede"><?php esc_html_e( '標準の集計Hub以外で集めたい場合、または別の実験用Hubへ接続する場合の設定です。参照だけなら共有秘密鍵は不要です。匿名集計を提供する場合だけ、Key IDと共有秘密鍵で署名します。', 'atshift-semantic-deterrence' ); ?></p>
						<table class="form-table atsdn-settings-table" role="presentation">
							<tr>
								<th scope="row"><label for="atsdn-hub-url"><?php esc_html_e( 'Hub URL', 'atshift-semantic-deterrence' ); ?></label></th>
								<td>
									<input id="atsdn-hub-url" type="url" name="aggregate_hub_url" class="regular-text" value="<?php echo esc_attr( $settings['aggregate_hub_url'] ); ?>">
									<p class="description"><?php esc_html_e( '既定値は https://aggregate.at-shift.net です。中央から命令やコードは受け取りません。', 'atshift-semantic-deterrence' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="atsdn-hub-key-id"><?php esc_html_e( 'Key ID', 'atshift-semantic-deterrence' ); ?></label></th>
								<td><input id="atsdn-hub-key-id" type="text" name="aggregate_hub_key_id" class="regular-text" value="<?php echo esc_attr( $settings['aggregate_hub_key_id'] ); ?>"></td>
							</tr>
							<tr>
								<th scope="row"><label for="atsdn-hub-secret"><?php esc_html_e( '共有秘密鍵', 'atshift-semantic-deterrence' ); ?></label></th>
								<td>
									<input id="atsdn-hub-secret" type="password" name="aggregate_hub_secret" class="regular-text" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( Atshift_Semantic_Deterrence_Storage::has_hub_secret() ? __( '保存済み。変更時だけ入力', 'atshift-semantic-deterrence' ) : __( '未設定', 'atshift-semantic-deterrence' ) ); ?>">
									<p class="description"><?php esc_html_e( '保存後は表示しません。匿名集計POSTのHMAC署名にだけ使います。', 'atshift-semantic-deterrence' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="atsdn-share-jitter-hours"><?php esc_html_e( '送信ジッター', 'atshift-semantic-deterrence' ); ?></label></th>
								<td>
									<input id="atsdn-share-jitter-hours" type="number" min="1" max="24" step="1" name="share_jitter_hours" value="<?php echo esc_attr( absint( $settings['share_jitter_hours'] ) ); ?>">
									<p class="description"><?php esc_html_e( '日次送信を固定時刻へ集中させないため、サイトごとのシードで分散します。', 'atshift-semantic-deterrence' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( '通信状態', 'atshift-semantic-deterrence' ); ?></th>
								<td>
									<?php $this->render_hub_status_summary( $settings ); ?>
								</td>
							</tr>
						</table>
					</details>
					<?php submit_button(); ?>
				</form>
			</section>

			<section class="atsdn-section">
				<h2><?php esc_html_e( 'ローカルデータ操作', 'atshift-semantic-deterrence' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="atsdn-inline-form">
					<input type="hidden" name="action" value="atsdn_finalize_windows">
					<?php wp_nonce_field( 'atsdn_finalize_windows', 'atsdn_nonce' ); ?>
					<?php submit_button( __( '観測窓を今すぐ確定', 'atshift-semantic-deterrence' ), 'secondary', 'submit', false ); ?>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="atsdn-inline-form">
					<input type="hidden" name="action" value="atsdn_delete_local_data">
					<?php wp_nonce_field( 'atsdn_delete_local_data', 'atsdn_nonce' ); ?>
					<?php submit_button( __( 'ローカル集計データを削除', 'atshift-semantic-deterrence' ), 'delete', 'submit', false ); ?>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="atsdn-inline-form">
					<input type="hidden" name="action" value="atsdn_download_anonymous_batch">
					<?php wp_nonce_field( 'atsdn_download_anonymous_batch', 'atsdn_nonce' ); ?>
					<?php submit_button( __( '匿名集計JSONをダウンロード', 'atshift-semantic-deterrence' ), 'secondary', 'submit', false ); ?>
				</form>
			</section>
		</div>
		<?php
	}

	public function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'この設定を保存する権限がありません。', 'atshift-semantic-deterrence' ) );
		}

		check_admin_referer( 'atsdn_save_settings', 'atsdn_nonce' );

		$settings = Atshift_Semantic_Deterrence_Storage::get_settings();
		$previous_settings = $settings;
		$fields   = array( 'mode', 'sensitivity', 'preferred_variant', 'experiment_assignment_strategy', 'limit_seconds', 'excluded_paths', 'excluded_ips', 'custom_high_confidence_paths', 'country_header_source', 'aggregate_hub_url', 'aggregate_hub_key_id', 'share_jitter_hours' );
		foreach ( $fields as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				$settings[ $field ] = wp_unslash( $_POST[ $field ] );
			}
		}
		if ( '1' === $previous_settings['experiment_assignment_locked'] ) {
			$settings['experiment_assignment_strategy'] = $previous_settings['experiment_assignment_strategy'];
			$settings['experiment_assignment_locked'] = '1';
			$settings['experiment_assignment_locked_at'] = $previous_settings['experiment_assignment_locked_at'];
		}
		if ( isset( $_POST['aggregate_hub_secret'] ) && '' !== trim( (string) wp_unslash( $_POST['aggregate_hub_secret'] ) ) ) {
			Atshift_Semantic_Deterrence_Storage::update_hub_secret( wp_unslash( $_POST['aggregate_hub_secret'] ) );
		}
		$settings['experiment_enabled']  = isset( $_POST['experiment_enabled'] ) ? '1' : '0';
		$settings['sharing_enabled']     = isset( $_POST['sharing_enabled'] ) ? '1' : '0';
		$settings['aggregate_read_enabled'] = isset( $_POST['aggregate_read_enabled'] ) ? '1' : '0';
		$settings['delete_on_uninstall'] = isset( $_POST['delete_on_uninstall'] ) ? '1' : '0';
		$settings['local_detail_log']    = '0';
		if ( 'experiment' === $settings['mode'] && '1' === $settings['experiment_enabled'] && '1' !== $settings['experiment_assignment_locked'] ) {
			$settings['experiment_assignment_locked'] = '1';
			$settings['experiment_assignment_locked_at'] = current_time( 'mysql' );
		}

		Atshift_Semantic_Deterrence_Storage::update_settings( $settings );

		wp_safe_redirect( add_query_arg( 'atsdn-updated', '1', admin_url( 'admin.php?page=atshift-semantic-deterrence-settings' ) ) );
		exit;
	}

	public function complete_onboarding() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'この設定を保存する権限がありません。', 'atshift-semantic-deterrence' ) );
		}

		check_admin_referer( 'atsdn_complete_onboarding', 'atsdn_nonce' );

		$settings = Atshift_Semantic_Deterrence_Storage::get_settings();
		$settings['onboarding_completed'] = '1';
		$settings['aggregate_read_enabled'] = isset( $_POST['aggregate_read_enabled'] ) ? '1' : '0';
		$settings['sharing_enabled'] = isset( $_POST['sharing_enabled'] ) ? '1' : '0';

		if ( isset( $_POST['enable_experiment'] ) ) {
			$settings['mode'] = 'experiment';
			$settings['experiment_enabled'] = '1';
			$settings['experiment_assignment_locked'] = '1';
			$settings['experiment_assignment_locked_at'] = current_time( 'mysql' );
		} elseif ( isset( $_POST['enable_deterrence'] ) ) {
			$settings['mode'] = 'deter';
			$settings['experiment_enabled'] = '0';
		} else {
			$settings['mode'] = 'observe';
			$settings['experiment_enabled'] = '0';
		}

		Atshift_Semantic_Deterrence_Storage::update_settings( $settings );

		wp_safe_redirect( add_query_arg( 'atsdn-onboarded', '1', wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=atshift-semantic-deterrence' ) ) );
		exit;
	}

	public function finalize_windows() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '観測窓を確定する権限がありません。', 'atshift-semantic-deterrence' ) );
		}

		check_admin_referer( 'atsdn_finalize_windows', 'atsdn_nonce' );
		$this->storage->finalize_windows();

		wp_safe_redirect( add_query_arg( 'atsdn-finalized', '1', admin_url( 'admin.php?page=atshift-semantic-deterrence-dashboard' ) ) );
		exit;
	}

	public function delete_local_data() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'ローカルデータを削除する権限がありません。', 'atshift-semantic-deterrence' ) );
		}

		check_admin_referer( 'atsdn_delete_local_data', 'atsdn_nonce' );
		$this->storage->delete_all_events();
		$settings = Atshift_Semantic_Deterrence_Storage::get_settings();
		$settings['mode'] = 'observe';
		$settings['experiment_enabled'] = '0';
		$settings['experiment_assignment_locked'] = '0';
		$settings['experiment_assignment_locked_at'] = '';
		$settings['runtime_epoch'] = wp_generate_uuid4();
		Atshift_Semantic_Deterrence_Storage::update_settings( $settings );

		wp_safe_redirect( add_query_arg( 'atsdn-deleted', '1', admin_url( 'admin.php?page=atshift-semantic-deterrence-dashboard' ) ) );
		exit;
	}

	public function download_anonymous_batch() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'ローカル集計データをエクスポートする権限がありません。', 'atshift-semantic-deterrence' ) );
		}

		check_admin_referer( 'atsdn_download_anonymous_batch', 'atsdn_nonce' );

		$this->storage->finalize_windows();
		$payload  = $this->storage->get_anonymous_aggregate_batch( 30 );
		$filename = 'atshift-semantic-deterrence-anonymous-aggregate-' . current_time( 'Ymd-His' ) . '.json';

		nocache_headers();
		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}

	private function render_onboarding_modal() {
		$settings = Atshift_Semantic_Deterrence_Storage::get_settings();
		if ( '1' === $settings['onboarding_completed'] ) {
			return;
		}
		?>
		<div class="atsdn-onboarding" role="dialog" aria-modal="true" aria-labelledby="atsdn-onboarding-title" data-atsdn-onboarding>
			<div class="atsdn-onboarding-dialog">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="atsdn_complete_onboarding">
					<?php wp_nonce_field( 'atsdn_complete_onboarding', 'atsdn_nonce' ); ?>

					<div class="atsdn-onboarding-progress" aria-hidden="true">
						<span class="is-active"></span>
						<span></span>
						<span></span>
						<span></span>
					</div>

					<section class="atsdn-onboarding-step is-active" data-atsdn-onboarding-step>
						<p class="atsdn-kicker"><?php esc_html_e( '最初の確認', 'atshift-semantic-deterrence' ); ?></p>
						<h2 id="atsdn-onboarding-title"><?php esc_html_e( '実験参加と集計参照', 'atshift-semantic-deterrence' ); ?></h2>
						<p><?php esc_html_e( 'このプラグインは、不審な自動探索へ意味的な応答を返した後に、探索継続を観測したかを測定します。', 'atshift-semantic-deterrence' ); ?></p>
						<p><strong><?php esc_html_e( 'このサイトを抑止しながら実験に参加し、匿名集計データを提供しますか？', 'atshift-semantic-deterrence' ); ?></strong></p>
						<p><?php esc_html_e( '参加しなくても、自分のサイトの状況確認と、集まった匿名集計データの参照は可能です。', 'atshift-semantic-deterrence' ); ?></p>
					</section>

					<section class="atsdn-onboarding-step" data-atsdn-onboarding-step>
						<p class="atsdn-kicker"><?php esc_html_e( '1 / 3', 'atshift-semantic-deterrence' ); ?></p>
						<h2><?php esc_html_e( '初期状態は観測のみ', 'atshift-semantic-deterrence' ); ?></h2>
						<p><?php esc_html_e( '有効化しただけではHTTPレスポンスを変更しません。まずは検知件数、分類、警告後の継続有無をローカルに測定します。', 'atshift-semantic-deterrence' ); ?></p>
						<label class="atsdn-choice-line">
							<input type="checkbox" name="aggregate_read_enabled" value="1" checked>
							<span><?php esc_html_e( '匿名集計の参照を有効にする', 'atshift-semantic-deterrence' ); ?></span>
						</label>
					</section>

					<section class="atsdn-onboarding-step" data-atsdn-onboarding-step>
						<p class="atsdn-kicker"><?php esc_html_e( '2 / 3', 'atshift-semantic-deterrence' ); ?></p>
						<h2><?php esc_html_e( '応答変更は明示的に選ぶ', 'atshift-semantic-deterrence' ); ?></h2>
						<p><?php esc_html_e( '抑止を有効にすると、高確度の不審探索へ403と固定カタログの撤退勧告を返します。WAFやレート制限の代替ではありません。', 'atshift-semantic-deterrence' ); ?></p>
						<label class="atsdn-choice-line">
							<input type="checkbox" name="enable_deterrence" value="1">
							<span><?php esc_html_e( '意味的応答を有効にして開始する', 'atshift-semantic-deterrence' ); ?></span>
						</label>
					</section>

					<section class="atsdn-onboarding-step" data-atsdn-onboarding-step>
						<p class="atsdn-kicker"><?php esc_html_e( '3 / 3', 'atshift-semantic-deterrence' ); ?></p>
						<h2><?php esc_html_e( '抑止しながら実験参加', 'atshift-semantic-deterrence' ); ?></h2>
						<p><?php esc_html_e( '実験ではこのサイトの高確度な不審探索に応答しながら、通常403の対照群と5種類の固定応答を決め打ちで割り当てます。匿名提供ではIP、URL、Cookie、本文、ドメインを送りません。', 'atshift-semantic-deterrence' ); ?></p>
						<label class="atsdn-choice-line">
							<input type="checkbox" name="enable_experiment" value="1">
							<span><?php esc_html_e( 'このサイトを抑止しながらローカル応答実験に参加する', 'atshift-semantic-deterrence' ); ?></span>
						</label>
						<label class="atsdn-choice-line">
							<input type="checkbox" name="sharing_enabled" value="1">
							<span><?php esc_html_e( 'このサイトの匿名集計を日次で提供する', 'atshift-semantic-deterrence' ); ?></span>
						</label>
					</section>

					<div class="atsdn-onboarding-actions">
						<button type="button" class="button" data-atsdn-onboarding-back disabled><?php esc_html_e( '戻る', 'atshift-semantic-deterrence' ); ?></button>
						<button type="button" class="button button-primary" data-atsdn-onboarding-next><?php esc_html_e( '次へ', 'atshift-semantic-deterrence' ); ?></button>
						<button type="submit" class="button button-primary is-hidden" data-atsdn-onboarding-submit><?php esc_html_e( '確認して開始', 'atshift-semantic-deterrence' ); ?></button>
					</div>
				</form>
			</div>
		</div>
		<?php
	}

	private function render_hub_status_summary( $settings ) {
		?>
		<div class="atsdn-hub-status">
			<p>
				<strong><?php esc_html_e( '次回共有予定:', 'atshift-semantic-deterrence' ); ?></strong>
				<?php echo esc_html( $settings['next_share_after'] ? $settings['next_share_after'] : Atshift_Semantic_Deterrence_Storage::calculate_next_share_after( $settings ) ); ?>
			</p>
			<p>
				<strong><?php esc_html_e( '最終共有:', 'atshift-semantic-deterrence' ); ?></strong>
				<?php echo esc_html( $settings['last_share_attempt_at'] ? $settings['last_share_attempt_at'] : __( '未実行', 'atshift-semantic-deterrence' ) ); ?>
				<?php if ( '' !== $settings['last_share_status'] ) : ?>
					<span class="atsdn-status-code"><?php echo esc_html( $settings['last_share_status'] ); ?></span>
				<?php endif; ?>
			</p>
			<p>
				<strong><?php esc_html_e( '最終集計取得:', 'atshift-semantic-deterrence' ); ?></strong>
				<?php echo esc_html( $settings['last_aggregate_pull'] ? $settings['last_aggregate_pull'] : __( '未実行', 'atshift-semantic-deterrence' ) ); ?>
				<?php if ( '' !== $settings['last_aggregate_status'] ) : ?>
					<span class="atsdn-status-code"><?php echo esc_html( $this->get_aggregate_status_label( $settings['last_aggregate_status'] ) ); ?></span>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	private function render_notice_messages() {
		if ( isset( $_GET['atsdn-updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( '設定を保存しました。', 'atshift-semantic-deterrence' ) . '</p></div>';
		}
		if ( isset( $_GET['atsdn-finalized'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( '観測窓を確定しました。', 'atshift-semantic-deterrence' ) . '</p></div>';
		}
		if ( isset( $_GET['atsdn-deleted'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'ローカル集計データを削除し、観測のみへ戻しました。実験割り当ては再度選択できます。', 'atshift-semantic-deterrence' ) . '</p></div>';
		}
		if ( isset( $_GET['atsdn-onboarded'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( '初回確認を完了しました。設定はいつでも変更できます。', 'atshift-semantic-deterrence' ) . '</p></div>';
		}
	}

	private function render_screen_nav( $active ) {
		$items = array(
			'readme'    => array(
				'label' => __( '概要', 'atshift-semantic-deterrence' ),
				'url'   => admin_url( 'admin.php?page=atshift-semantic-deterrence' ),
			),
			'dashboard' => array(
				'label' => __( 'ダッシュボード', 'atshift-semantic-deterrence' ),
				'url'   => admin_url( 'admin.php?page=atshift-semantic-deterrence-dashboard' ),
			),
			'settings'  => array(
				'label' => __( '設定', 'atshift-semantic-deterrence' ),
				'url'   => admin_url( 'admin.php?page=atshift-semantic-deterrence-settings' ),
			),
		);
		?>
		<nav class="atsdn-screen-nav" aria-label="<?php esc_attr_e( 'Semantic Deterrence の画面', 'atshift-semantic-deterrence' ); ?>">
			<?php foreach ( $items as $key => $item ) : ?>
				<a class="<?php echo $active === $key ? 'is-active' : ''; ?>" href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html( $item['label'] ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	private function render_summary_item( $label, $value, $tone = 'neutral' ) {
		?>
		<div class="atsdn-summary-item atsdn-summary-item--<?php echo esc_attr( sanitize_html_class( $tone ) ); ?>">
			<span class="atsdn-summary-label"><?php echo esc_html( $label ); ?></span>
			<strong><?php echo esc_html( $value ); ?></strong>
		</div>
		<?php
	}

	private function render_hourly_activity_chart( $activity ) {
		$maximum = 0;
		foreach ( $activity as $hour ) {
			$maximum = max( $maximum, absint( $hour['count'] ) );
		}
		?>
		<div class="atsdn-hourly-chart" role="img" aria-label="<?php esc_attr_e( '直近24時間の時間別観測件数', 'atshift-semantic-deterrence' ); ?>">
			<?php foreach ( $activity as $index => $hour ) : ?>
				<?php $height = $maximum > 0 ? max( 4, round( absint( $hour['count'] ) / $maximum * 100 ) ) : 4; ?>
				<span class="atsdn-hourly-column" title="<?php echo esc_attr( sprintf( '%s: %d', $hour['label'], absint( $hour['count'] ) ) ); ?>">
					<i style="height: <?php echo esc_attr( $height ); ?>%"></i>
					<?php if ( 0 === $index % 4 || count( $activity ) - 1 === $index ) : ?>
						<small><?php echo esc_html( $hour['label'] ); ?></small>
					<?php endif; ?>
				</span>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private function get_country_display_name( $country_code ) {
		$country_code = strtoupper( sanitize_text_field( $country_code ) );
		if ( 'T1' === $country_code ) {
			return __( 'Torネットワーク', 'atshift-semantic-deterrence' );
		}
		if ( 'XX' === $country_code ) {
			return __( '不明な国・地域', 'atshift-semantic-deterrence' );
		}

		if ( class_exists( 'Locale' ) ) {
			$name = \Locale::getDisplayRegion( '-' . $country_code, determine_locale() );
			if ( is_string( $name ) && '' !== $name && $country_code !== $name ) {
				return $name;
			}
		}

		return $country_code;
	}

	private function render_rate_bar( $rate_label ) {
		$rate = is_string( $rate_label ) && false !== strpos( $rate_label, '%' ) ? (float) $rate_label : null;
		?>
		<div class="atsdn-rate-cell">
			<span><?php echo esc_html( $rate_label ); ?></span>
			<?php if ( null !== $rate ) : ?>
				<span class="atsdn-rate-track" aria-hidden="true"><span style="width: <?php echo esc_attr( min( 100, max( 0, $rate ) ) ); ?>%"></span></span>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_response_identity( $row ) {
		$fingerprint = isset( $row['response_fingerprint'] ) ? sanitize_text_field( $row['response_fingerprint'] ) : '';
		$label       = isset( $row['response_label'] ) && '' !== $row['response_label'] ? sanitize_text_field( $row['response_label'] ) : __( '旧形式の応答', 'atshift-semantic-deterrence' );
		?>
		<div class="atsdn-response-identity">
			<span><?php echo esc_html( $label ); ?></span>
			<?php if ( '' !== $fingerprint ) : ?>
				<code><?php echo esc_html( substr( $fingerprint, 0, 16 ) ); ?></code>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_recent_event_row( $row ) {
		?>
		<tr>
			<td data-label="<?php esc_attr_e( '時刻', 'atshift-semantic-deterrence' ); ?>"><?php echo esc_html( $this->format_local_datetime( $row['created_at'] ) ); ?></td>
			<td data-label="<?php esc_attr_e( '分類', 'atshift-semantic-deterrence' ); ?>"><code><?php echo esc_html( sanitize_key( $row['category'] ) ); ?></code></td>
			<td data-label="<?php esc_attr_e( 'レベル', 'atshift-semantic-deterrence' ); ?>"><?php echo esc_html( absint( $row['level'] ) ); ?></td>
			<td data-label="<?php esc_attr_e( '実験群', 'atshift-semantic-deterrence' ); ?>"><?php echo esc_html( $this->get_experiment_arm_label( $row['experiment_arm'] ?? '' ) ); ?></td>
			<td data-label="<?php esc_attr_e( '応答', 'atshift-semantic-deterrence' ); ?>">
				<?php if ( ! empty( $row['responded'] ) ) : ?>
					<span class="atsdn-variant-chip"><?php echo esc_html( $this->get_variant_display_label( $row['variant'] ) ); ?></span>
					<?php $this->render_response_identity( $row ); ?>
				<?php else : ?>
					<span class="atsdn-muted-text"><?php esc_html_e( '観測のみ', 'atshift-semantic-deterrence' ); ?></span>
				<?php endif; ?>
			</td>
			<td data-label="<?php esc_attr_e( 'HTTP', 'atshift-semantic-deterrence' ); ?>"><?php echo esc_html( absint( $row['http_status'] ) ); ?></td>
			<td data-label="<?php esc_attr_e( '結果', 'atshift-semantic-deterrence' ); ?>">
				<?php if ( ! empty( $row['responded'] ) ) : ?>
					<span class="atsdn-outcome-pill atsdn-outcome-pill--<?php echo esc_attr( sanitize_html_class( $row['outcome'] ) ); ?>"><?php echo esc_html( $this->get_outcome_label( $row['outcome'] ) ); ?></span>
				<?php else : ?>
					<span class="atsdn-muted-text"><?php esc_html_e( '観測のみ', 'atshift-semantic-deterrence' ); ?></span>
				<?php endif; ?>
			</td>
			<td data-label="<?php esc_attr_e( '継続数', 'atshift-semantic-deterrence' ); ?>">
				<?php echo esc_html( absint( $row['follow_up_count'] ) ); ?>
				<?php if ( ! empty( $row['last_seen_at'] ) ) : ?>
					<span class="atsdn-muted-text"><?php echo esc_html( $this->format_local_datetime( $row['last_seen_at'] ) ); ?></span>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	private function render_window_row( $label, $summary ) {
		?>
		<tr>
			<th scope="row" data-label="<?php esc_attr_e( '期間', 'atshift-semantic-deterrence' ); ?>"><?php echo esc_html( $label ); ?></th>
			<td data-label="<?php esc_attr_e( '検知', 'atshift-semantic-deterrence' ); ?>"><?php echo esc_html( $summary['detected'] ); ?></td>
			<td data-label="<?php esc_attr_e( '警告', 'atshift-semantic-deterrence' ); ?>"><?php echo esc_html( $summary['warnings'] ); ?></td>
			<td data-label="<?php esc_attr_e( '継続なしを観測', 'atshift-semantic-deterrence' ); ?>"><?php echo esc_html( $summary['observed_ceased'] ); ?></td>
			<td data-label="<?php esc_attr_e( '継続', 'atshift-semantic-deterrence' ); ?>"><?php echo esc_html( $summary['continued'] ); ?></td>
			<td data-label="<?php esc_attr_e( '不明', 'atshift-semantic-deterrence' ); ?>"><?php echo esc_html( $summary['unknown'] ); ?></td>
		</tr>
		<?php
	}

	private function format_local_datetime( $datetime ) {
		$timestamp = strtotime( (string) $datetime );
		if ( ! $timestamp ) {
			return '';
		}

		return wp_date( 'Y-m-d H:i:s', $timestamp );
	}

	private function get_outcome_label( $outcome ) {
		$labels = array(
			'observed_ceased'     => __( '継続なしを観測', 'atshift-semantic-deterrence' ),
			'continued_same'      => __( '継続', 'atshift-semantic-deterrence' ),
			'continued_alternate' => __( '別分類で継続', 'atshift-semantic-deterrence' ),
			'intensified'         => __( '強い探索へ変化', 'atshift-semantic-deterrence' ),
			'unknown'             => __( '判定不能', 'atshift-semantic-deterrence' ),
			'rate_limited'        => __( '一時制限', 'atshift-semantic-deterrence' ),
		);

		return $labels[ $outcome ] ?? $outcome;
	}

	private function calculate_non_continuation_rate( $summary ) {
		$ceased    = isset( $summary['observed_ceased'] ) ? absint( $summary['observed_ceased'] ) : absint( $summary['ceased'] ?? 0 );
		$continued = absint( $summary['continued'] ?? 0 );
		$total     = $ceased + $continued;

		if ( $total < 20 ) {
			return __( '測定中', 'atshift-semantic-deterrence' );
		}

		return sprintf( '%.1f%%', ( $ceased / $total ) * 100 );
	}

	private function calculate_control_improvement( $variant_stats ) {
		$control_rate  = null;
		$semantic_rate = null;
		$semantic_good = 0;
		$semantic_bad  = 0;

		foreach ( $variant_stats as $row ) {
			$ceased    = absint( $row['ceased'] );
			$continued = absint( $row['continued'] );
			$total     = $ceased + $continued;
			if ( $total < 20 ) {
				continue;
			}

			if ( 'control_generic' === $row['variant'] ) {
				$control_rate = $ceased / $total;
			} elseif ( empty( $row['experiment_arm'] ) || 'fixed_series' === $row['experiment_arm'] ) {
				$semantic_good += $ceased;
				$semantic_bad  += $continued;
			}
		}

		if ( $semantic_good + $semantic_bad >= 20 ) {
			$semantic_rate = $semantic_good / ( $semantic_good + $semantic_bad );
		}

		if ( null === $control_rate || null === $semantic_rate ) {
			return __( '測定中', 'atshift-semantic-deterrence' );
		}

		return sprintf( '%+.1fポイント', ( $semantic_rate - $control_rate ) * 100 );
	}

	private function get_current_best_variant( $variant_stats ) {
		$best = null;

		foreach ( $variant_stats as $row ) {
			$ceased    = absint( $row['ceased'] );
			$continued = absint( $row['continued'] );
			$total     = $ceased + $continued;

			if ( $total < 20 || 'control_generic' === $row['variant'] ) {
				continue;
			}

			$rate = $ceased / $total;
			if ( null === $best || $rate > $best['rate'] ) {
				$best = array(
					'variant' => $row['variant'],
					'arm'     => $row['experiment_arm'] ?? '',
					'rate'    => $rate,
					'total'   => $total,
				);
			}
		}

		if ( null === $best ) {
			return __( '測定中', 'atshift-semantic-deterrence' );
		}

		return sprintf(
			/* translators: 1: Response option label, 2: Experiment arm label, 3: percentage, 4: event count. */
			__( '%1$s / %2$s (%3$.1f%%, n=%4$d)', 'atshift-semantic-deterrence' ),
			$this->get_variant_display_label( $best['variant'] ),
			$this->get_experiment_arm_label( $best['arm'] ),
			$best['rate'] * 100,
			$best['total']
		);
	}

	private function format_network_best_variant( $best ) {
		$variant = isset( $best['variant'] ) ? sanitize_key( $best['variant'] ) : '';
		$arm     = isset( $best['experiment_arm'] ) ? sanitize_key( $best['experiment_arm'] ) : '';
		$rate    = isset( $best['non_continuation_rate'] ) && null !== $best['non_continuation_rate'] ? (float) $best['non_continuation_rate'] : null;
		$total   = isset( $best['total_events'] ) ? absint( $best['total_events'] ) : 0;

		if ( null === $rate ) {
			return $this->get_variant_display_label( $variant );
		}

		return sprintf(
			/* translators: 1: Response label, 2: experiment arm, 3: percentage, 4: event count. */
			__( '%1$s / %2$s (%3$.1f%%, n=%4$d)', 'atshift-semantic-deterrence' ),
			$this->get_variant_display_label( $variant ),
			$this->get_experiment_arm_label( $arm ),
			$rate,
			$total
		);
	}

	private function format_network_control_difference( $row, $variants ) {
		if ( 'control_generic' === $row['variant'] || ! is_numeric( $row['non_continuation_rate'] ) ) {
			return '-';
		}

		$control_ceased    = 0;
		$control_continued = 0;
		foreach ( $variants as $control ) {
			if ( 'control_generic' !== $control['variant'] || $control['experiment_arm'] !== $row['experiment_arm'] ) {
				continue;
			}

			$control_ceased    += absint( $control['observed_ceased'] );
			$control_continued += absint( $control['continued'] );
		}

		$control_total = $control_ceased + $control_continued;
		if ( 0 === $control_total ) {
			return __( '測定中', 'atshift-semantic-deterrence' );
		}

		$control_rate = ( $control_ceased / $control_total ) * 100;
		$difference   = (float) $row['non_continuation_rate'] - $control_rate;
		$sign         = $difference > 0 ? '+' : '';
		return $sign . number_format_i18n( $difference, 1 ) . ' pp';
	}

	private function get_modes() {
		return array(
			'observe'     => __( 'このサイトの観測のみ', 'atshift-semantic-deterrence' ),
			'deter'      => __( 'このサイトを抑止', 'atshift-semantic-deterrence' ),
			'deter_limit'=> __( 'このサイトを抑止 + 一時制限', 'atshift-semantic-deterrence' ),
			'experiment' => __( 'このサイトを抑止しながら実験参加', 'atshift-semantic-deterrence' ),
		);
	}

	private function get_experiment_assignment_strategies() {
		return array(
			'fixed_series'             => __( '固定表示のみ', 'atshift-semantic-deterrence' ),
			'mixed_fixed_and_sequence' => __( '固定表示と段階表示を混ぜる', 'atshift-semantic-deterrence' ),
			'sequence_series'          => __( '段階表示のみ', 'atshift-semantic-deterrence' ),
		);
	}

	private function get_experiment_arm_label( $experiment_arm ) {
		$labels = array(
			''                => __( '通常運用', 'atshift-semantic-deterrence' ),
			'fixed_series'    => __( '固定表示', 'atshift-semantic-deterrence' ),
			'sequence_series' => __( '段階表示', 'atshift-semantic-deterrence' ),
		);

		return $labels[ $experiment_arm ] ?? $experiment_arm;
	}

	private function get_mode_label( $mode, $experiment_enabled = '0' ) {
		if ( 'experiment' === $mode && '1' !== $experiment_enabled ) {
			return __( 'ローカル観測中・応答実験は未開始', 'atshift-semantic-deterrence' );
		}

		$labels = array(
			'observe'     => __( 'このサイトを観測中', 'atshift-semantic-deterrence' ),
			'deter'      => __( 'このサイトを抑止中', 'atshift-semantic-deterrence' ),
			'deter_limit'=> __( 'このサイトを一時制限中', 'atshift-semantic-deterrence' ),
			'experiment' => __( 'このサイトを抑止しながら実験参加中', 'atshift-semantic-deterrence' ),
		);

		return $labels[ $mode ] ?? $labels['observe'];
	}

	private function get_aggregate_status_label( $status ) {
		$labels = array(
			''                 => __( '未取得', 'atshift-semantic-deterrence' ),
			'updated'          => __( '更新済み', 'atshift-semantic-deterrence' ),
			'not_modified'     => __( '変更なし', 'atshift-semantic-deterrence' ),
			'failed'           => __( '取得失敗', 'atshift-semantic-deterrence' ),
			'invalid_response' => __( '無効な応答', 'atshift-semantic-deterrence' ),
		);

		return $labels[ $status ] ?? $status;
	}

	private function get_variant_display_label( $variant ) {
		if ( 'control_generic' === $variant ) {
			return __( '対照群: 通常403', 'atshift-semantic-deterrence' );
		}

		$options = Atshift_Semantic_Deterrence_Detector::get_response_options();
		return $options[ $variant ] ?? $variant;
	}
}
