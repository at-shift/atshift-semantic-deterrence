CREATE TABLE IF NOT EXISTS atsdn_hub_batches (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	key_id VARCHAR(80) NOT NULL,
	site_key_hash CHAR(64) NOT NULL,
	payload_hash CHAR(64) NOT NULL,
	event_count INT UNSIGNED NOT NULL DEFAULT 0,
	received_at DATETIME NOT NULL,
	PRIMARY KEY (id),
	UNIQUE KEY key_payload_hash (key_id, payload_hash),
	KEY received_at (received_at),
	KEY site_received (site_key_hash, received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS atsdn_hub_events (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	batch_id BIGINT UNSIGNED NOT NULL,
	received_at DATETIME NOT NULL,
	observed_date DATE NOT NULL,
	site_key_hash CHAR(64) NOT NULL,
	plugin_version VARCHAR(40) NOT NULL DEFAULT '',
	policy_version VARCHAR(80) NOT NULL DEFAULT '',
	variant VARCHAR(40) NOT NULL DEFAULT '',
	experiment_arm VARCHAR(40) NOT NULL DEFAULT '',
	response_catalog_id VARCHAR(120) NOT NULL DEFAULT '',
	response_fingerprint CHAR(64) NOT NULL DEFAULT '',
	http_status SMALLINT UNSIGNED NOT NULL DEFAULT 0,
	category VARCHAR(40) NOT NULL DEFAULT '',
	level TINYINT UNSIGNED NOT NULL DEFAULT 0,
	outcome VARCHAR(40) NOT NULL DEFAULT '',
	time_bucket VARCHAR(20) NOT NULL DEFAULT '',
	event_count INT UNSIGNED NOT NULL DEFAULT 0,
	follow_up_count INT UNSIGNED NOT NULL DEFAULT 0,
	PRIMARY KEY (id),
	KEY batch_id (batch_id),
	KEY received_at (received_at),
	KEY observed_date (observed_date),
	KEY aggregate_variant (observed_date, variant, experiment_arm, outcome),
	KEY aggregate_category (observed_date, category, level, outcome),
	KEY response_fingerprint (response_fingerprint),
	KEY site_observed (site_key_hash, observed_date),
	CONSTRAINT atsdn_hub_events_batch_fk FOREIGN KEY (batch_id) REFERENCES atsdn_hub_batches (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS atsdn_hub_nonces (
	key_id VARCHAR(80) NOT NULL,
	nonce_hash CHAR(64) NOT NULL,
	created_at DATETIME NOT NULL,
	expires_at DATETIME NOT NULL,
	PRIMARY KEY (key_id, nonce_hash),
	KEY expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS atsdn_hub_rate_limits (
	bucket_key CHAR(64) NOT NULL,
	request_count INT UNSIGNED NOT NULL DEFAULT 1,
	window_started_at DATETIME NOT NULL,
	updated_at DATETIME NOT NULL,
	PRIMARY KEY (bucket_key),
	KEY updated_at (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS atsdn_hub_cache (
	cache_key VARCHAR(120) NOT NULL,
	etag CHAR(66) NOT NULL,
	body_json MEDIUMTEXT NOT NULL,
	generated_at DATETIME NOT NULL,
	expires_at DATETIME NOT NULL,
	PRIMARY KEY (cache_key),
	KEY expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS atsdn_hub_revoked_sites (
	site_key_hash CHAR(64) NOT NULL,
	key_id VARCHAR(80) NOT NULL,
	revoked_at DATETIME NOT NULL,
	PRIMARY KEY (site_key_hash),
	KEY key_id (key_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS atsdn_hub_sites (
	site_key_hash CHAR(64) NOT NULL,
	key_id VARCHAR(80) NOT NULL,
	registered_at DATETIME NOT NULL,
	last_seen_at DATETIME NOT NULL,
	PRIMARY KEY (site_key_hash),
	UNIQUE KEY key_id (key_id),
	KEY last_seen_at (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS atsdn_hub_state (
	id TINYINT UNSIGNED NOT NULL,
	aggregate_generation BIGINT UNSIGNED NOT NULL DEFAULT 1,
	updated_at DATETIME NOT NULL,
	PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO atsdn_hub_state (id, aggregate_generation, updated_at)
VALUES (1, 1, UTC_TIMESTAMP());
