# Refresh projected-token paths before Azure authentication

## Approval summary

Restore media-copy operations after a projected workload-identity token rotates. PHP caches resolved symlink targets; an isolated PHP 8.4 experiment reproduced a failed read after another process atomically replaced the projection and removed its old target. Clearing only the token filename did not recover; clearing the full realpath cache did.

Explicitly construct a small application credential around new WorkloadIdentityCredential; the wrapper accepts TokenCredential without resolving that binding recursively. Wrap the existing pinned Azure WorkloadIdentityCredential with this application credential that calls clearstatcache(true) immediately before every getToken invocation. Register the wrapper in the existing TokenCredential container binding so Blob requests and user-delegation signing share the correction, including long-lived workers. Forward the exact request context and access-token result; propagate exceptions unchanged. Preserve lazy singleton construction and delegate authentication, HTTP transport and token parsing to the existing SDK. Keep permission, identity, data ownership and storage configuration unchanged.

Authority: the user's “proceed” after the duplication-defect report authorizes investigation, source correction, synthetic local tests, commit, ordinary branch push and draft PR delivery. It does not name merge, release or deployment. Terminal: reviewed draft PR against release/2.0.0-uzh with focused tests and required CI observed; separately present any remaining release/deployment authority. Do not retry the live duplicate until a corrected image is deployed under a named rollout approval.

No new dependency or paid model-generation call is needed. Full realpath-cache eviction has an unmeasured path-resolution cost at credential acquisition; it avoids disabling the cache for every PHP operation. A missing/unreadable token must still fail without fallback or retry.

## Execution details

One owner: main session. One defect package, full path because it touches the Azure credential boundary; no product primitive or public API changes.

Files: add app/Support/Media/RotatingWorkloadIdentityCredential.php and its focused test tests/Unit/Support/Media/RotatingWorkloadIdentityCredentialTest.php; change only the binding and relevant imports in app/Providers/AppServiceProvider.php. Keep this plan at docs/project/2026-10-29-azure-token-rotation-plan.md after critique. No schema, Docker, Helm or dependency changes in this source package.

Test portfolio: add one regression at the credential boundary using real SDK credentials and a fake PSR-18 token endpoint. Warm the path cache, rotate a two-symlink projection in another process, remove its old target, then acquire again with the same credential; assert the newest assertion is sent. Repeat rotation with the same credential instance and synchronize each external process by successful exit. Assert realpath caching, symlinks and process support rather than skipping. Verify missing and genuinely unreadable synthetic token failures send no HTTP request under an unprivileged process. Verify context/result forwarding, unchanged exceptions and the application wrapper binding. Existing media/Idea copy tests protect ownership/rollback and stay unchanged.

Verification: prove the regression fails without cache refresh; pass it with the fix, existing Azure SAS/flat adapter tests and targeted Idea-copy tests; run Pint on changed PHP files; inspect exact diff and hygiene. Test in a disposable local container without cloud credentials, unprivileged with a writable temporary copy. The native Pest setup applies RefreshDatabase to Unit tests; use an isolated PostgreSQL 16 database and establish that it works before relying on results. No new app server or cloud database runtime is needed. Independent final review covers credential delegation, binding, error propagation, repeated-rotation realism and test isolation. No visual UI changes; no new screenshots apply to source package. Live copy/browser acceptance is a later deployment gate.

Failure: correct source/test failures within this defect scope. Stop if a solution needs identity/permission changes or dependency upgrades. Leave live service unchanged. Revert source normally before merge; later rollout recovery requires separately approved GitOps revert.

## Progress

- Source baseline: dabc3c725a85c7e6e2fb8ba3dae01d7c98df0892, resolved from release/2.0.0-uzh. New branch rs/trypost-token-rotation.
- Root-cause experiment: PHP 8.4.27; read succeeds before external rotation, fails after rotation, still fails after filename-only clear, succeeds after full clear. Isolated synthetic files only.
- Current PHP documentation: https://www.php.net/manual/en/function.clearstatcache.php
- Plan critique: revised following the independent review: explicit lazy inner credential construction, exact delegation, synchronized repeated rotation, cache prerequisites, genuinely unreadable-token checks, unprivileged execution and native database setup. Review accepted the minimal wrapper; all actionable revisions are incorporated. Source implemented. Focused PostgreSQL checks: 81 unique tests passed across the rotation, existing SAS/flat adapter, Idea CRUD and Azure media suites. The API subset initially lacked local Passport keys and passed after disposable keys were generated. Rotation regression fails with CredentialUnavailableException when the refresh line is removed. Pint passed. Opengrep ran 85 rules on three files; its only finding is deletion of an internally generated synthetic temporary file in test cleanup. Main-session inspection rejects this as user-input exposure. CI and integrated final review remain pending. Live acceptance: withheld pending rollout authority.

- Package: one isolated regression fix; no structural complexity warrants a separate simplifier. The wrapper is one method delegating to the existing SDK.
- Source-only change; no browser contract or visible UI changes. Live duplication with Azure remains unverified until a corrected release is approved and deployed.
