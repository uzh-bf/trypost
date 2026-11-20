# Integrate TryPost v2.1.0

Prepare one draft pull request against the downstream release branch. Keep upstream v2.1.0 as the second parent so the next update has a common baseline.

## Progress

The release source is b569076f. The downstream starting point is d1f55077. The merge base is 7b9eeec9.

- Resolve the media, model and writing-style, routes, storage registration, dependency and browser test overlaps against the new upstream implementations.
- Verify that stored media references still generate private reads, that generation and rewriting retain the selected model and style, and that only the configured channel qualifies for discovery when global discovery is off.
- Keep the existing downstream release image and CI conventions. Retire the legacy timezone package workaround after validating upstream alias handling.
- Compare the final tree with both parents. Exercise existing tests and a synthetic PostgreSQL upgrade from the previous release. Record verification and residual deployment-dependent checks in the draft pull request.

The merge request changes application source. A tagged image and environment rollout are separate decisions. A deployment review should account for the eight upstream migrations, the lifecycle update of pre-existing subscription records, and the private MCP tool allowlist.

## Compatibility checks

Use the previous release image to create a PostgreSQL database with synthetic subscriptions. Upgrade it with the candidate, compare subscription timestamps, and exercise the changelog boot step. Compare the release configuration for forwarded HTTPS headers with the new trusted-proxy default before deploying behind a non-Cloudflare ingress. Configure only the proxies actually used.

Exercise the scoped dispatcher, queued-job guards, normal metrics reads, workspace-bound MCP and the generated media URL path. The consuming agent must explicitly select its tools. Upstream also supports deletion detection for known publications; account discovery, post metrics and calendar import have different entry points.

Run the fork's database and Chromium workflows on the integration tree. Verify the composer and Ideas interface against synthetic data. No external publishing, provider calls or production migration is needed to prepare this source update.

The populated upgrade completed on PostgreSQL 16 for seven synthetic subscription states. A second migrate was a no-op and the changelog import completed. The focused PHP 8.5 tests cover aliases, workspace binding, publisher and analytics behavior, model and style selection, and signed private media. A fake-provider story test exercises signing the derived image. Full merge-candidate CI supplies both database engines and the Chromium shards.
