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

## Explicit authorization and live rollout

- User explicitly approved persistent `read_customers` for Deco Referral Test and installation/testing exclusively on macfox-test-app.
- Released `referral-tracking-20260908` version 1119836438529; accepted Shopify's customer-data update prompt on the named test store. Independent app reader then read order #1002 successfully.
- Deployed backend images `decoadmin-app:referral-accounting-20260908-staging` and matching nginx image. Dedicated affiliate worker and maintenance use this image; production and unrelated workers were not restarted. Backups: `/opt/decoadmin/staging/.releases/referral-accounting-20260908/`.
- Migrated only the five new Referral accounting/payout/portal/rule-history/notification migrations. Health application/database/Redis passed.
- #1002 live attribution: coupon, base 143910 cents, commission 17269 cents, pending until 2026-10-08T05:10:31Z. Repeated reconciliation preserved one conversion and one accrual.
- Enabled and saved Referral tracking on test theme 165279695096 (Deco Smart Cart Test); reloaded editor confirmed enabled. Public unauthenticated HTML check hit the storefront password page, so it did not verify the tracking script in the authenticated storefront.
- Registered five webhooks. Real shipping refund exposed CLI relative-URI resolution to the App Home path, returning 404. Corrected to explicit `https://testadmin.decomkt.com/api/shopify-app/referral/webhooks` and released `referral-webhooks-urlfix-20260908`, version 1119869272065. Verified all five manifest URLs match. Added project config guard against regression.
- Refunded #1002 Bogus shipping 3.32 USD with notification off, then submitted remaining product refund 1439.10 USD with restock and notification off. Waiting to verify final actual webhook processing at this point. No real transfer occurred.

## Concurrent staging replacement

- During final #1002 refund verification, another deployment replaced staging with origin/test commit 315c7fef5d6fd813bc18c4049a186ac1f610fc41 (Instagram connection changes), removed Referral source and dedicated worker containers. The shared database retained Referral data. App/nginx recovered healthy on that different image.
- Full #1002 Bogus refund is confirmed in Shopify (net payment zero). Its post-fix webhook result is NOT yet verified because the backend was replaced during the test.
- Fetch origin/test and merge its existing upstream changes before reapplying Referral, so the Instagram work is preserved. Do not blindly restore the older source tree or overwrite environment secrets.

## Merged recovery and customer rewards checkpoint

- Recovered staging with merged commit da37a26, preserving upstream Instagram changes from 315c7fe. The test branch was updated; production was not. All staging web and queue processes use the merged image; image tags were persisted in the staging environment file with a private backup.
- Verified #1002 refunds/create webhook processed automatically at 2026-09-08T07:38:28Z, one attempt, no error. Two refunds total; commission 17269 cents and reversed commission 17269 cents, conversion refunded. No manual reconciliation was used to establish this callback evidence.
- Synthetic settlement fixture verified reservation, cancellation, recreation, paid confirmation and repeated confirmation with no duplicate entries or real transfer. Portal HTTP login returned 200, repeated one-time token returned 401, cookie path /referral-portal, no merchant session cookie issued.
- Added customer purchase verification, new-customer review holds, customer-only one-use reward coupons, fixed/percentage/free-shipping rewards, milestones, refund revocation and already-used reward risk holds. Merchant and portal reward views included.
- New-customer friend coupons require an existing Shopify segment with the exact rule number_of_orders = 0. No write_customers scope was added. Incomplete order history is held for review rather than assumed eligible.
- Local full backend regression after rewards: 650 tests, 9827 assertions passed. Three tracking tests and application structure validation passed; frontend build passed. Reward API operations were schema validated against 2026-07. Live reward checkout and remaining completion-checklist items are still pending.
- Expired portal login notifications are suppressed and their encrypted message removed; used milestone rewards after a refunded source create a review flag and hold subsequent rewards.

## Live checkout and settlement regression, later checkpoint

- #1003 (7085291471096), Bogus Gateway, quantity two, goods USD3198, shipping3.32. Actual paid webhook attributed signed_cart_token to the synthetic member, commission31980 cents. No affiliate coupon was used.
- Partial product refund1599 automatically appended15990 cents commission reversal. Registered the remaining15990 cents as TEST-NO-TRANSFER-ORDER1003; repeated confirmation preserved one settlement.
- Final product+shipping refund1602.32 automatically reversed the remaining15990 cents. Member net became -15990 cents, preserving the prior payout record.
- New paid test customer verification succeeded. Existing new-customer segment570764984568 allowed friend coupon DECO-R7A3ME8H (fixed USD10) without any extra scope.
- Friend order7085299269880 used this coupon, passed new-customer checks and generated fixed USD10 plus one-order free-shipping milestone rewards. No cash commission was created for the advocate program.
- Live Shopify rejected explicit subscription fields on a store without subscriptions. Removed those optional fields, verified both reward types were issued successfully.
- Reward checkout rejected a different synthetic buyer email; changing to the reward owner restored the USD10 discount and Bogus payment completed. Subsequent affiliate earnings offset the earlier negative balance, leaving -100 cents.
- Standard staging deployment removed separate affiliate containers. Added staging-only Horizon supervisor and scheduled maintenance; confirmed both affiliate queues running under Horizon. Old dedicated processes were stopped. Production is not enabled.
- Shopify asyncUsageCount lagged a completed checkout. Added verified-paid-order redemption recording and immutable reward event history to prevent delayed counters from misclassifying used rewards after a source refund. These latest changes are local pending final deployment at this checkpoint.
- Native browser automation is interrupted if another operator switches Chrome; independent browser-tab control timed out. Refresh the app binding after any such interruption before taking another action.

## Final development additions before staging acceptance

- Added merchant one-time invitations and real portal acceptance; invite bodies are persisted through controlled model writes and accepting an invitation never approves the member.
- Added optional subscribed-customer post-purchase invitations, checked against plan/store state at order time and current consent/payment status before delivery. Delivery tests use the in-memory transport, not real email.
- Added campaign dates, historical date clearing, scheduled coupon status transitions, dated refund-net reports/ranking/daily results, and retention with preserved aggregate counts.
- Added cash-attribution correction before settlement, immutable before/after snapshots, neutralized prior unsettled entries and correct routing of later refunds. Reserved/settled entries and reward ownership are protected from direct reassignment.
- Added order-velocity, hashed-source and click-burst review flags without returning the hashes.
- Latest complete backend suite before the final risk-review state correction: 662 tests, 9906 assertions passed. Latest scoped Referral suite after that correction: 59 tests, 374 assertions passed. Frontend production build passed.
- Actual #1004 source refund processed on 2026-09-08T09:39:56Z. The used USD10 reward remains redeemed; unused free-shipping milestone revoked; reward_used_after_refund flag created.
- Actual #1005 (7085340819704) Bogus payment used the owner-restricted reward. Its refund and cancellation callbacks processed separately at 09:45:05Z and 09:45:06Z, with one complete commission reversal and no duplicate debit.
- Actual uninstall processed once: installation uninstalled, access/refresh tokens absent, 10 ledger entries and 6 reward-history entries retained. Reinstallation restored active installation with both encrypted credentials present, App Home connected. Theme embed reloaded enabled. Bogus Gateway deactivated afterwards.
- Full staging business/portal integration scripts are prepared under scripts/. Their execution and final release verification remain pending at this checkpoint.
