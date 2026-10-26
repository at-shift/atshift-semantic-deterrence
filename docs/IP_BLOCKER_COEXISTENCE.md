# Manual Server Blocking And SDN Measurement

Requires SDN 0.1.8 and IP Blocker with WAF 0.1.3. This is a local, administrator-triggered integration, not a Hub command, automatic block list, or WAF replacement. Updating either plugin does not enable log reading or server editing.

The blocker keeps its preview, exact public-IP validation, current-address protection, backup download, independent recovery acknowledgement, file hash, lock, atomic replacement, self-check and guarded rollback. SDN modes, response selection, consent and experiment assignment never change.

## Measurement Fence

1. Before a real file-change attempt, the blocker calls `Atshift_Semantic_Deterrence_Server_Blocks::begin_change()` under its server-edit lock. Administrator access is required. An atomic compare-and-swap stores a random revision, local start time and pending flag, with no IP, UA, payload or domain.
2. SDN marks still-open, undecided responded windows as `rate_limited`. This is deliberately conservative across all sources: SDN does not add an IP-to-source-HMAC mapping to identify only the blocked address. Observed continuation and already completed results are retained.
3. Every response event records the measurement revision captured before request classification. Both sides of event insertion check the fresh state. Finalization uses the persisted revision as a backstop against late inserts, failed censor updates and stale per-request option caches. Older undecided revisions, including an unfinalized backlog, are conservatively indeterminate. Follow-up updates cannot overwrite a censored result.
4. After success, failure or rollback, the blocker calls `finish_change(token)`. It censors remaining overlapping windows before clearing pending state. No-op requests and stale previews are rejected or returned before opening a fence.

Only the opaque revision is added to event storage (schema version 5). Explicit aggregate SELECT and JSON field lists exclude it and all fence metadata. The existing `rate_limited` outcome already belongs to the Hub schema, is grouped with indeterminate outcomes, and is excluded from the non-continuation-rate denominator. No Hub upgrade is required for this feature. Blocked requests that never reach WordPress are not counted as detected by SDN.

## Interrupted Changes

Pending state does not expire automatically: a stopped process cannot turn an interrupted experiment into a success after a timer. During pending or unreadable state, new responded observations are indeterminate. Both admin surfaces display guidance; IP Blocker refuses new changes until the pending state is resolved.

Review the managed IP list and hosting/SFTP recovery access, then use **Resume SDN measurement** in IP Blocker's Server blocks view. The administrator-only, nonce-protected action acquires the edit lock, reads and validates the current file, runs the existing site health probe, and completes the pending token. It does not rewrite `.htaccess`. A failed probe or completion leaves the fence pending.

If the actual file remains uncertain, recover it through the host/SFTP first. If a database error prevents even reading the fence, repair that error rather than deleting the fence or guessing a successful measurement result. SDN's explicit uninstall-data deletion removes its owned fence option along with its experiment table; ordinary event deletion does not silently clear a pending change.

## Boundaries

- Older SDN builds retain the original refusal while semantic responses or observation windows are active. Install SDN first, then the blocker; seeing an update message is not an invitation to bypass the guard.
- This protocol does not observe manual hosting edits, other firewall plugins, CDN rules, or `.htaccess` changes made outside IP Blocker.
- Safety censoring can reduce usable samples. `rate_limited` means measurement was limited by site action; it does not prove the selected IP was blocked or that an attacker stopped.
- No production file or remote Hub is modified by the repository's synthetic tests.
