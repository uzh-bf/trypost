# Azure Blob media support

Add an optional private Azure Blob disk while preserving the existing S3 and local disks. Physical blob names are flat; a reversible adapter preserves logical paths. Media identity is a stable path or ID. Expiring read URLs are created for authorized consumers rather than persisted in media JSON. Use workload identity and user-delegation SAS, with account keys disabled.

Implement and verify flat filesystem behavior, bounded block uploads, URL authorization/freshness, canonical serialization and credential redaction. Missing owned attachments fail publication before any provider call. Reuse existing media ownership and publication checkpoints. No database migration or existing-media transfer is included.

The source package ends with reviewed, CI-checked draft delivery. Entra/RBAC/SAS acceptance requires a separate synthetic Azure qualification; emulator/fake tests cannot prove it. Provisioning, release, deployment, publishing and data deletion require separate approval.

## Primitive impact

| Product primitive | Disposition | Contract and consumers | Source evidence |
| --- | --- | --- | --- |
| Owned media | Extend delivery | Stable file identity/owner and existing adopt/copy/delete lifecycle. Browser access uses workspace authorization; API/MCP and publishers receive expiring read links. Missing owned attachments fail before a new provider call. | Media, SyncOwnedMedia, DeleteOwnedMedia, MediaUrl |
| Publication | Reuse | Workers resolve access when executing. Accepted provider checkpoints remain authoritative during retries; no duplicate post or new scheduling state. | PublishToSocialPlatform, PublishCheckpoint, ThreadProgress |
| Workspace membership | Reuse | Browser file access follows the file workspace, independently of another tab's selected workspace. Existing token/workspace guards remain the API/MCP boundary. | WorkspacePolicy, MediaFileController, API resources |

The optional backend changes delivery policy, not media ownership or database identity. Logical paths remain stable over the flat physical layout. No new product primitive is introduced.

Unavailable media in API/MCP/webhook payloads retains its stable identity with a null delivery URL, so one absent attachment does not break a collection or lifecycle event. Browser file fetches still return not-found; confirmed-missing scheduled attachments fail before a provider call. Avatar storage failures yield no avatar, and redacted exception diagnostics retain file, line, safe stack frames and the previous chain. Real-Azure qualification includes API/MCP and avatar fan-out as well as individual redirects.

Publication emails use their existing text and attachment fallback under Azure instead of sending session-only image URLs or bearer links. API/MCP thread previews use the same authorized expiring-link helper as post resources. Ranged reads use explicit connection, request and streamed-read timeouts.
