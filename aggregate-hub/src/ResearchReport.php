<?php
/**
 * Private operational statistics. Never expose through a public HTTP route.
 */

class Atshift_Semantic_Deterrence_Research_Report {
	private $db;
	private $config;
	private $since;
	const MAX_ROWS = 1000;

	public function __construct( PDO $db, array $config ) {
		$this->db     = $db;
		$this->config = $config;
	}

	public function build( $days = 30 ) {
		if ( ! is_int( $days ) || $days < 1 || $days > 90 ) {
			throw new InvalidArgumentException( 'Days must be between 1 and 90.' );
		}
		// Match the public Hub's inclusive observed-date cutoff.
		$this->since = gmdate( 'Y-m-d', time() - $days * 86400 );
		$summary = $this->query_rows(
			"SELECT COUNT(DISTINCT site_key_hash) AS site_count,
				COALESCE(SUM(event_count), 0) AS total_events,
				COALESCE(SUM(CASE WHEN variant = 'observe_only' THEN event_count ELSE 0 END), 0) AS observation_only_events,
				MIN(observed_date) AS earliest_observed_date, MAX(observed_date) AS latest_observed_date,
				MAX(received_at) AS last_received_at
			FROM atsdn_hub_events WHERE observed_date >= :since"
		)[0];
		foreach ( array( 'site_count', 'total_events', 'observation_only_events' ) as $key ) {
			$summary[$key] = (int) $summary[$key];
		}
		$min_sites  = (int) ( $this->config['privacy_min_sites'] ?? 10 );
		$min_events = (int) ( $this->config['privacy_min_events'] ?? 100 );
		$response_groups = $this->groups( 'variant, experiment_arm, response_catalog_id, response_fingerprint', true );
		foreach ( $response_groups['rows'] as &$row ) {
			$row['missing_sites']  = max( 0, $min_sites - $row['site_count'] );
			$row['missing_events'] = max( 0, $min_events - $row['total_events'] );
			$row['public_threshold_met'] = 0 === $row['missing_sites'] && 0 === $row['missing_events'];
		}
		unset( $row );
		$contributions = $this->query_rows(
			' SELECT COUNT(*) AS contributing_sites, COALESCE(SUM(events), 0) AS total_events,
				MIN(events) AS min_events_per_site, MAX(events) AS max_events_per_site,
				AVG(events) AS mean_events_per_site, SUM(events * events) AS sum_squared_events
			FROM (SELECT site_key_hash, SUM(event_count) AS events FROM atsdn_hub_events
				WHERE observed_date >= :since AND event_count > 0 GROUP BY site_key_hash) AS contributions'
		)[0];
		$total = (int) $contributions['total_events'];
		$squares = (float) $contributions['sum_squared_events'];
		unset( $contributions['sum_squared_events'] );
		foreach ( array( 'contributing_sites', 'total_events', 'min_events_per_site', 'max_events_per_site' ) as $key ) {
			$contributions[$key] = null === $contributions[$key] ? null : (int) $contributions[$key];
		}
		$contributions['mean_events_per_site'] = null === $contributions['mean_events_per_site'] ? null : round( (float) $contributions['mean_events_per_site'], 2 );
		$site_count = (int) $contributions['contributing_sites'];
		$contributions['median_events_per_site'] = null;
		if ( $site_count > 0 ) {
			$offset = (int) floor( ( $site_count - 1 ) / 2 );
			$limit = 0 === $site_count % 2 ? 2 : 1;
			$middle = $this->query_rows(
				"SELECT SUM(event_count) AS events FROM atsdn_hub_events
				WHERE observed_date >= :since AND event_count > 0 GROUP BY site_key_hash
				ORDER BY events ASC LIMIT {$limit} OFFSET {$offset}"
			);
			$contributions['median_events_per_site'] = array_sum( array_column( $middle, 'events' ) ) / count( $middle );
		}
		$contributions['largest_site_share_percent'] = $this->percent( (int) $contributions['max_events_per_site'], $total );
		$contributions['effective_site_count_by_event_share'] = $squares > 0 ? round( $total * $total / $squares, 2 ) : null;
		$contributions['effective_site_count_definition'] = '1 / sum(squared site event shares); a concentration diagnostic, not a statistical sample size.';

		return array(
			'report_schema_version' => '1',
			'generated_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'visibility' => 'operator_only_contains_below_threshold_statistics',
			'window' => array( 'days_parameter' => $days, 'observed_date_since_inclusive' => $this->since, 'cutoff_basis' => 'UTC cutoff applied to client-local observed_date, matching the public Hub' ),
			'configured_client_keys' => count( $this->config['client_keys'] ?? array() ),
			'privacy_thresholds' => array( 'sites' => $min_sites, 'events' => $min_events ),
			'snapshot_summary' => $summary,
			'site_contribution' => $contributions,
			'response_groups' => $response_groups,
			'arms' => $this->groups( 'experiment_arm', true ),
			'controls_by_arm_and_status' => $this->groups( 'experiment_arm, http_status', true, " AND variant = 'control_generic'" ),
			'http_statuses' => $this->groups( 'http_status', true ),
			'plugin_versions' => $this->groups( 'plugin_version' ),
			'categories' => $this->groups( 'category, level' ),
			'observed_dates' => $this->groups( 'observed_date' ),
			'limitations' => array(
				'Counts describe currently retained latest site snapshots, not cumulative historical uploads.',
				'Event counts are throttled response observations, not unique agents, people or independent series.',
				'Non-continuation rates exclude unknown and rate_limited; these are reported separately.',
				'No causal effect, significance test or best response is inferred from pooled rates.',
				'Fixed, sequence and unassigned arms must be evaluated separately; 403 and 429 are different interventions.',
				'Series distributions, 24-hour follow-up and upstream WAF-blocked traffic cannot be reconstructed from Hub data.',
				'Below-threshold statistics are for private operational review and must not be published as public aggregates.',
			),
		);
	}

	private function query_rows( $sql ) {
		$stmt = $this->db->prepare( $sql );
		$stmt->execute( array( ':since' => $this->since ) );
		return $stmt->fetchAll( PDO::FETCH_ASSOC );
	}

	private function groups( $dimensions, $responses_only = false, $extra_condition = '' ) {
		$condition = $responses_only ? " AND variant <> 'observe_only'" : '';
		$rows = $this->query_rows(
			"SELECT {$dimensions}, COUNT(DISTINCT site_key_hash) AS site_count,
				SUM(event_count) AS total_events,
				SUM(CASE WHEN variant <> 'observe_only' AND outcome = 'observed_ceased' THEN event_count ELSE 0 END) AS observed_ceased,
				SUM(CASE WHEN variant <> 'observe_only' AND outcome IN ('continued_same', 'continued_alternate', 'intensified') THEN event_count ELSE 0 END) AS continued,
				SUM(CASE WHEN variant <> 'observe_only' AND outcome = 'unknown' THEN event_count ELSE 0 END) AS unknown,
				SUM(CASE WHEN variant <> 'observe_only' AND outcome = 'rate_limited' THEN event_count ELSE 0 END) AS rate_limited,
				SUM(CASE WHEN variant <> 'observe_only' THEN event_count ELSE 0 END) AS response_events,
				SUM(follow_up_count) AS follow_up_count
			FROM atsdn_hub_events WHERE observed_date >= :since {$condition} {$extra_condition}
			GROUP BY {$dimensions} ORDER BY total_events DESC, {$dimensions} LIMIT 1001"
		);
		$truncated = count( $rows ) > self::MAX_ROWS;
		$rows = array_slice( $rows, 0, self::MAX_ROWS );
		foreach ( $rows as &$row ) {
			foreach ( array( 'site_count', 'total_events', 'observed_ceased', 'continued', 'unknown', 'rate_limited', 'response_events', 'follow_up_count' ) as $key ) {
				$row[$key] = (int) $row[$key];
			}
			$row['classified_response_events'] = $row['observed_ceased'] + $row['continued'];
			$row['non_continuation_rate_percent'] = $this->percent( $row['observed_ceased'], $row['classified_response_events'] );
			$row['indeterminate_rate_percent'] = $this->percent( $row['unknown'] + $row['rate_limited'], $row['response_events'] );
		}
		unset( $row );
		return array( 'rows' => $rows, 'truncated' => $truncated, 'row_limit' => self::MAX_ROWS );
	}

	private function percent( $numerator, $denominator ) {
		return $denominator > 0 ? round( 100 * $numerator / $denominator, 2 ) : null;
	}
}
