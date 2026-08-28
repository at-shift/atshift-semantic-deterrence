# Semantic Deterrence

**A WordPress research plugin for measuring whether suspicious automated probing continues after a clear, machine-readable refusal.**

Semantic Deterrence explores a small but useful question for the agentic web: when a site returns an ordinary HTTP refusal together with an explicit policy statement and a natural-language recommendation to stop, does the observed probing behavior change?

## Why This Experiment Exists

Websites are routinely explored for exposed files, administration surfaces, backup archives, traversal paths, and other possible weaknesses. Conventional automated tools will not understand a warning, but an AI agent involved in selecting the next request may be able to interpret both machine-readable policy state and natural-language context.

This leads to a deliberately modest hypothesis: if a site clearly states that probing has been recognized, access is not authorized, and continued exploration is unlikely to be useful, a decision-making agent may choose not to continue. We do not know whether this works, how often it works, or which wording matters. The plugin exists to test those questions rather than assume the answers.

Participating sites compare a generic `403` control with a fixed response catalog and measure whether suspicious probing was observed again during a defined follow-up window. Site owners may keep all measurements local, read cross-site aggregate results without contributing, or separately opt in to sharing privacy-bounded aggregate statistics.

If you operate a WordPress site and are comfortable joining an early research pilot, please consider participating. A useful evidence base requires observations from many independently operated sites. Pull Requests and discussions proposing response hypotheses, experiment designs, statistical methods, false-positive cases, translations, and privacy improvements are also welcome.

The plugin does not identify visitors as AI. It classifies a narrow set of suspicious request patterns, returns an optional fixed-catalog response, and measures whether probing from the same local source identity was observed again during the follow-up window. Results are described precisely as **"no continuation observed after the warning"**. They are not described as an attack being stopped, an AI being detected, or an intrusion being prevented.

This is an additional semantic-friction layer. It does not replace a WAF, CDN rule, rate limit, authentication control, or server hardening.

## Screenshots

### Overview

![Semantic Deterrence overview in an English WordPress admin environment](../docs/screenshots/overview-en.png)

### Dashboard

![Local measurements and response comparison in the Semantic Deterrence dashboard](../docs/screenshots/dashboard-en.png)

### Settings

![Mode, response, experiment, advanced, and Hub settings](../docs/screenshots/settings-en.png)

## What This Experiment Tests

- Whether suspicious probing continues after a generic `403` response.
- Whether one of five fixed semantic response variants changes the observed non-continuation rate.
- Whether consistently returning one response differs from using a predetermined response sequence for repeated probes.
- Whether results remain useful across participating sites without collecting raw requests centrally.
- Where false positives occur and which exclusions or classification rules are needed before broader deployment.

The response catalog is intentionally bounded. Arbitrary custom warning text would make cross-site comparisons difficult and could create an ever-growing set of statistically weak variants.

## Operating Modes

- **Observe this site only** records local aggregate events and does not change HTTP responses. This is the default.
- **Deter on this site** returns the selected semantic response for high-confidence classifications.
- **Deter and temporarily limit on this site** can return `429` after continuation is observed.
- **Participate in the experiment while deterring on this site** assigns a fixed experiment strategy, compares the generic `403` control with the five semantic responses, and can share aggregate results when sharing is separately enabled.

Deterrence, experiment participation, aggregate sharing, and aggregate readback each require an explicit choice. Experiment assignment is locked after the experiment starts and becomes selectable again only after local experiment data is deleted; deletion also returns the plugin to observation mode.

## Response Catalog

1. Policy notice
2. Detection and local-recording notice
3. Low-utility-of-continuation notice
4. Machine-readable notice
5. Combined notice

Experiments also include a generic `403` control without a semantic response body. The plugin says that a request was recorded or rate-limited only when those behaviors are actually enabled.

## Local Measurement

The plugin currently classifies high-confidence probes for sensitive configuration files, backup archives, version-control paths, unrelated administration surfaces, traversal-like paths, repeated `404` requests, method anomalies, and administrator-defined path prefixes.

Local event records contain bounded categorical and experimental fields, response fingerprints, HTTP status, follow-up counts, outcome, policy version, and keyed local identifiers. Raw IP addresses, full URLs, query values, cookies, request bodies, authorization headers, raw User-Agent values, domains, site names, and WordPress user data are not written to the event table.

Local source identity currently derives from the server-observed `REMOTE_ADDR`. This deliberately avoids trusting forwarded headers, but it can group multiple visitors behind NAT or a reverse proxy. A future trusted-proxy adapter is needed for deployments where the web server does not expose the actual client address to WordPress.

## Aggregate Sharing

Sharing is off by default. When a site explicitly opts in, the plugin sends delayed daily batches within a site-specific jitter window so participating sites do not all contact the Hub at once.

Shared rows contain aggregate counts and experimental dimensions together with a random installation pseudonym used for deduplication and cross-batch comparison. They do **not** contain IP addresses, URLs, cookies, request bodies, domains, site names, visitor identifiers, or local request HMACs. Because the installation pseudonym is stable, this data is accurately described as **pseudonymous aggregate data**, not unlinkable anonymous data.

A site may read the shared aggregate results without contributing its own data. Readback is decision support: the Hub may report which fixed response currently has the highest estimated non-continuation rate and the supporting sample size. It cannot send executable code, force a response choice, change local settings, or issue a blocking command.

```mermaid
flowchart LR
    A[Participating WordPress sites] -->|Delayed pseudonymous aggregate batches| H[Aggregate Hub]
    H -->|Bounded aggregate results only| A
    H -. no code, commands, or forced settings .-> A
```

## Current Safeguards

- Observation-only default and independent opt-ins.
- Fixed response catalog and high-confidence response threshold.
- Local exclusions for paths and source addresses.
- Daily rotating measurement identifiers and a separate stable experiment assignment key.
- Per-source duplicate suppression and site-wide write budgets.
- Thirty-day local retention for event rows.
- HTTPS-only Hub communication by default, with no redirects and bounded response sizes.
- HMAC-signed batch uploads with timestamp and nonce headers.
- Schema-validated, normalized Hub readback with no remote-control fields.

This is research software in an early pilot. Review classifications locally before enabling response changes, keep an independent WAF in place, and report unexpected classifications.

## Installation

1. Download a release ZIP, or place the contents of `wordpress-plugin/` in `wp-content/plugins/atshift-semantic-deterrence`.
2. Activate **atshift Semantic Deterrence** in WordPress.
3. Open **Semantic Deterrence > Overview** and complete the three-step consent guide.
4. Keep **Observe this site only** selected while reviewing local detections.
5. Enable deterrence, experiment participation, sharing, or readback only after reviewing the relevant boundaries.

Requirements: WordPress 6.4 or later and PHP 7.4 or later.

After installation, WordPress checks the public GitHub Releases feed for newer versions. Release packages are installed only after their accompanying SHA-256 checksum is verified.

## Languages

The plugin includes Japanese, English (US), Spanish, German, French, Brazilian Portuguese, Italian, Russian, Dutch, Simplified Chinese, Polish, Turkish, Indonesian, Traditional Chinese (Taiwan), and Korean translations.

## Contributing Experiments

Interesting ideas are welcome as Pull Requests or GitHub discussions. Particularly useful contributions include:

- A clearly stated response hypothesis that can fit a bounded comparison catalog.
- Better experimental designs for fixed text versus predetermined sequences.
- Statistical methods that communicate uncertainty and minimum sample sizes honestly.
- Reproducible false-positive cases and narrowly scoped classification improvements.
- Privacy-preserving aggregation proposals.
- Trusted adapters for Cloudflare and web-server enforcement layers.
- Accessibility, localization, and dashboard clarity improvements.

Please describe what a proposal intends to test, what outcome would count as evidence, possible confounders, and what data it requires. Changes that silently expand collection, identify visitors as AI, overclaim outcomes, or let the Hub control local execution are out of scope.

## Development

The plugin is intentionally dependency-light. Before opening a Pull Request:

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
for po in languages/*.po; do msgfmt --check-format --check-header -o /dev/null "$po"; done
```

Test observation and deterrence behavior in an isolated WordPress environment. Do not probe systems you do not own or have explicit permission to test.

## Security

Please report vulnerabilities through [GitHub private vulnerability reporting](https://github.com/at-shift/atshift-semantic-deterrence/security/advisories/new). See [SECURITY.md](../SECURITY.md) for scope and handling guidance.

## License

[GPL-2.0-or-later](../LICENSE)
