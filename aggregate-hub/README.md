# Semantic Deterrence Hub

Semantic Deterrence Hub is a minimal aggregate server for Agentic Web governance experiments.

It receives pseudonymous aggregate batches from opted-in client sites, stores them in MySQL, and returns cached aggregate JSON for clients to read. It does not distribute executable code, forced blocking rules, forced settings, or per-site commands.

## Endpoints

- `POST /v1/events/batch/`
- `GET /v1/aggregates/current/`
- `GET /v1/aggregates/variants/`
- `POST /v1/site/revoke/`

The endpoint directories contain plain `index.php` files so the service can run on simple PHP hosting without a framework.

## Setup

1. Create a MySQL database on the Agentic Web governance experiment server.
2. Import `schema.sql`.
3. Copy `config.example.php` outside the public web root as `semantic-deterrence-hub-config.php`.
4. Set the MySQL DSN, `app_secret`, and one independently generated `client_keys` entry per participating site.
5. Point `aggregate.at-shift.net` to `semantic-deterrence-hub/public`.
6. Schedule `tools/cleanup.php` hourly or daily. Public requests never run retention maintenance.

The default config path used by this repository layout is `../semantic-deterrence-hub-config.php` from the hub directory. If the config file must live elsewhere, set `ATSDN_HUB_CONFIG` to its absolute path.

On Xserver, the default SSH `php` command may be older than the web runtime. Use an explicit PHP 8 binary for CLI setup, for example:

```sh
/usr/bin/php8.4 /home/at-shift/semantic-deterrence-hub/tools/install-schema.php
```

## Request Signing

Clients sign POST request bodies with HMAC-SHA256.

Headers:

```http
X-ATSDN-Key-Id: client-pilot-001
X-ATSDN-Timestamp: 1787820000
X-ATSDN-Nonce: random-string-at-least-12-chars
X-ATSDN-Signature: base64-hmac
```

Signature input:

```text
{timestamp}
{nonce}
{raw_request_body}
```

The timestamp tolerance is 10 minutes. Nonces are retained for the full interval in which a signed timestamp can remain acceptable.

Each client key is site-scoped. Reusing one key across multiple sites is rejected because it would invalidate distinct-site privacy thresholds and revocation ownership. Generate opaque IDs and secrets with a cryptographically secure random source; runtime startup rejects the example placeholders and secrets shorter than 32 characters.

## Privacy Boundary

Accepted batch rows may include:

- `site_pseudonym`
- `plugin_version`
- `policy_version`
- `variant`
- `experiment_arm`
- `response_catalog_id`
- `response_fingerprint`
- `http_status`
- `category`
- `level`
- `outcome`
- `event_count`
- `follow_up_count`
- `time_bucket`
- `observed_date`

Payloads are rejected if they include fields such as IP, URL, path, query, domain, host, cookie, request body, User-Agent, or email.

The hub stores a server-side HMAC of `site_pseudonym` for distinct-site counting and binds that value to the authenticated site key. It does not need domain names or raw site identifiers.

Uploads are treated as the authenticated site's latest bounded 30-day snapshot. A successful upload atomically replaces that site's prior active snapshot so delayed daily uploads do not repeatedly count overlapping days.

## Load Design

- Clients should send delayed daily batches with jitter.
- Ingest is rate-limited per client key by minute and day; revocation is separately limited.
- Aggregate read endpoints return cached JSON with `ETag` and `Last-Modified`.
- Public aggregate rows are suppressed unless configured privacy thresholds are met.
- HTTP responses require cache revalidation so revocation and threshold changes do not leave long-lived external copies.
- Retention cleanup runs only through the bounded CLI maintenance command.

Default thresholds are 10 sites and 100 events.

## Maintenance

Run the following with the same PHP 8 runtime and configuration used for schema installation:

```sh
/usr/bin/php8.4 /home/at-shift/semantic-deterrence-hub/tools/cleanup.php
```

The cleanup command deletes expired nonce and rate-limit rows, events older than 90 days, and orphaned batch metadata in bounded chunks. Run it repeatedly on later schedules when a large historical backlog exists.
