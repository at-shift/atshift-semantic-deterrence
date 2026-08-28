<?php
/**
 * Example configuration for the Semantic Deterrence aggregate hub.
 *
 * Copy this file outside the public web root as semantic-deterrence-hub-config.php.
 */

return array(
	'db' => array(
		'dsn'      => 'mysql:host=mysql.example.jp;dbname=agentic_web_governance;charset=utf8mb4',
		'user'     => 'replace-with-db-user',
		'password' => 'replace-with-db-password',
	),
	'app_secret' => 'replace-with-a-long-random-secret',
	'client_keys' => array(
		// Issue one opaque key ID and secret per participating site.
		'site-001-random-key-id' => 'replace-with-a-long-random-client-secret',
	),
	'privacy_min_sites' => 10,
	'privacy_min_events' => 100,
	'aggregate_cache_seconds' => 300,
	'ingest_rate_limit_per_minute' => 6,
	'ingest_rate_limit_per_day' => 24,
	'revoke_rate_limit_per_day' => 3,
	'environment' => 'production',
);
