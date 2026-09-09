# Test source reconciliation — 2026-09-09

The test site was built from Git `test` with additional source overlays. Its exported staging directory had no `.git`; this does not mean that its base source was unrelated to `test`.

## Evidence and merge

- Fetched Git baseline: `05bbf2390ceafd3d07c66e620e47107221175589`.
- Running application image: `decoadmin-app:brand-media-import-menu-20260909-041544-staging`.
- Application image ID: `sha256:2c9ab7fa01a6e8139a3e67e9777eb9f523e7ce3c25ceaed733b2947c0eacbb86`.
- The exported `RELEASE` contained `cb4282716205575a7e6cf8e81cd4e97746bd0a86`, which was stale relative to the actual application source.
- Among Git-tracked files in app/resources/routes/config/database, 982 matched the fetched baseline, 10 differed, and none were missing. Seven additional application source files were present; AppleDouble and `.orig` artifacts were excluded.
- Merged those 17 source files: brand profile pages and authorization, brand media layout/platform imports/recycle bin, shared filter spacing, navigation, and routes. Their bytes were retained from the running source.
- Preserved newer Git versions of four referral documents and two referral tests; the container copies were older. Recovered the existing local brand profile, CSV import, and recycle bin regression tests.

## Validation and boundaries

Validation runs use a temporary checkout, an in-memory SQLite database, and a disposable Redis on an internal Docker network with no published ports. No server environment files, business data, credentials, generated bundles, or runtime artifacts are committed.

- Full PHP suite: 707 tests passed, 10,336 assertions.
- Vue type check: passed.
- Vite production build: passed (existing large-chunk advisory only).
- `git diff --check`: passed.
- Initial isolated test attempts lacked the application test key and Redis/queue defaults; the final run used a fresh test-only key, disposable Redis, and the documented retry window. No business environment configuration was copied into the test checkout.

This reconciliation updates Git and the local working copy. It does not rebuild or restart test/production services. Future test deployments must build from the exact committed `origin/test` revision as described in `deployment.md` and `AGENTS.md`.
