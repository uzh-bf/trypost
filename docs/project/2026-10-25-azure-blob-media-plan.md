# Azure Blob media support

Add an optional private Azure Blob disk while preserving the existing S3 and local disks. Physical blob names are flat; a reversible adapter preserves logical paths. Media identity is a stable path or ID. Expiring read URLs are created for authorized consumers rather than persisted in media JSON. Use workload identity and user-delegation SAS, with account keys disabled.

Implement and verify flat filesystem behavior, bounded block uploads, URL authorization/freshness, canonical serialization and credential redaction. Missing owned attachments fail publication before any provider call. Reuse existing media ownership and publication checkpoints. No database migration or existing-media transfer is included.

The source package ends with reviewed, CI-checked draft delivery. Entra/RBAC/SAS acceptance requires a separate synthetic Azure qualification; emulator/fake tests cannot prove it. Provisioning, release, deployment, publishing and data deletion require separate approval.
