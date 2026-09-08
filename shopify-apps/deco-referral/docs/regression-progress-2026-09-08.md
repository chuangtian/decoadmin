# Development and regression progress (not final acceptance)

Target: Deco Referral Test, macfox-test-app.myshopify.com, DecoAdmin staging only.

## Verified locally

- Full backend suite at the initial accounting/portal checkpoint: 630 tests, 9729 assertions, passed.
- Later scoped referral regression: 32 tests, 216 assertions, passed; includes delayed order historical rules, post-reservation refund blocking, variant parent-store isolation, rejection/waitlist transitions, revoked portal tokens and paused-program login.
- Material upload/private storage/executable rejection/delete test: passed, 8 assertions.
- Theme tracking behavior: 3 Node tests passed for consent, first/last referral and expired/malformed tokens.
- Shopify theme validation revision 3: all 4 files valid; Shopify App configuration valid and App build passed.
- Order/refund/collection/reconciliation GraphQL schema validation passed (2026-07).
- Backend frontend builds passed; Vite restarted after builds. Final complete frontend verification remains due after any later edit.

## Live evidence and blocker

- Test App Home returned HTTP 200.
- Read-only call of new order reader against retained Bogus order #1002 failed: `Access denied for customer field. Required access: read_customers access scope.`
- `read_customers` was added only to the local `shopify.app.test.toml`; existing live test App remains on its prior scopes/version.
- Publishing `referral-tracking-20260908` was rejected by automatic approval review: it requires specific approval for the persistent customer-data scope expansion. No release was made. Do not work around this rejection.
- Backend accounting/portal/material/rule-version migrations and code have NOT yet been deployed.

## Remaining work

Use completion-checklist.md. Notification intents/templates, invitation/import, full customer reward lifecycle, manual attribution, richer reporting, privacy lifecycle and staging end-to-end regression are not complete. Do not describe this checkpoint as all functionality complete.

## Tool side effect

`vendor/bin/pint --dirty` unexpectedly traversed untracked `tmp/production-release-20260905/` and formatted several PHP files in that temporary release snapshot. No such files were staged, deployed or committed. Restrict future formatting to explicit Referral file paths. Originals need comparison with the corresponding release archive before restoring any temporary snapshot files.

## Later local checkpoint

- Full backend suite after notification/import/report additions: 639 tests, 9777 assertions passed.
- After manual attribution and refund calculation refactor: 37 scoped tests, 249 assertions passed; backend frontend build passed.
- Added encrypted/deduplicated notification intents, explicit store template controls (off by default), queued portal login email, atomic CSV import, private materials, portal QR/deep links, currency-aware fixed coupons, refund-net metrics/export and manual assignment for previously unattributed paid orders.
- Manual reassignment of already-attributed orders is not implemented; the current action rejects those orders rather than rewriting settled ownership.
- Local portal bundle generated using app-owned qrcode/esbuild dependencies; no external QR service receives referral links.
- The accidental formatter changes to the temporary release snapshot were compared byte-for-byte against formatted copies of archived or committed originals; 11 verified originals restored. For the remaining two temporary test files, the exact formatter-only diff from known source copies applied cleanly in reverse, preserving their different business test content. All 13 temporary formatting side effects were reverted; no unrelated project files staged.
- New customer scope approval requested asynchronously; no answer received at this checkpoint. Publishing remains blocked by automatic review. All new backend migrations remain local and undeployed.

- Final local checkpoint after manual attribution: 640 tests, 9783 assertions passed. App-owned tracking Node tests and portal bundle build passed; backend UI build, manifest assets and Vite port 5173 confirmed. This is a local checkpoint, not completed staging acceptance.
