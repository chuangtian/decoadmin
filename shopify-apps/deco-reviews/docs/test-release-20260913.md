# Core test release — 2026-09-13

This release is not full Loox parity. See `acceptance.md` for remaining scope.

- Backend source commit: `e71c07a4d57a51d5c9fc452a09eb36056b6e5dbd`, fetched from `origin/test`, exported cleanly and built on the staging host.
- Backend URL: `https://testadmin.decomkt.com`.
- Shopify app: **Deco Reviews (Test)**, app ID `422616530945`.
- Shopify version: **deco-reviews-test-20260913-e71c07a**, version ID `1126376308737`.
- Version URL: https://dev.shopify.com/dashboard/74225522/apps/422616530945/versions/1126376308737
- Independent test scopes: `read_products,write_app_proxy`. Order/customer permissions are not requested in this display-test release.
- Shopify CLI 4.8 normalized the deprecated `include_config_on_deploy` field out of the test TOML during release. The follow-up documentation/config normalization commit does not change backend runtime code.

## Verification

- New-app tests: 24 passed / 115 assertions. Shopify HTTP and delivery dependencies mocked; no real email was sent.
- New-app plus existing Community Reviews regression suite: 33 passed / 213 assertions.
- Vue type-check, interface typography check, production frontend build and Vite readiness passed.
- Shopify config validation, Theme Check and app build passed. Theme JS 8,172 bytes; hard build limit below 10,000 bytes.
- Staging health: application/database/Redis OK; Horizon running; scheduled commands registered.
- Runtime hashes of ReviewService and Index.vue match clean source export.
- Test HTTP: protected management 302 to login; app home 200; unsigned proxy 401; static asset 200.
- Browser inspection confirmed draft theme **Macfox 学生折扣app 调试**, ID **192562692461**, draft status and disabled Save before any editing. No theme was saved or published.
- Local browser functional acceptance is incomplete because the login/Chrome UI could not be completed. Five clearly marked DEMO fixtures exist only in local `macfox-test-app`, not staging or Shopify.

## Safety and pending installation

The test plugin version is released but **not installed into Macfox**. Live Macfox theme and Loox were not modified. Real email delivery is disabled and automation store/recipient allowlists are empty. No customer invitations, discounts or live order-triggered actions were performed by this app.

An unfiltered Shopify CLI diagnostic unexpectedly included the new test app secret in tool output. It was not committed to Git. Rotate that test secret and update its ignored local/server environment configuration before installing the app. Do not reproduce the old secret in tickets, documents or logs. Further diagnostics must project only non-sensitive fields before returning output. Other app identities and production secrets were not involved.

Installing into Macfox is a store-level permission action, even if display testing uses only the authorized draft. Obtain explicit confirmation before installation; never publish the draft theme.

### Test credential rotation completed — 2026-09-13

- User explicitly authorized rotation with a strict no-impact-on-store constraint.
- Verified Shopify installation count and backend installation/token count were zero before revocation.
- Generated a new secret and revoked the old secret in the settings of app `422616530945` only. The Dashboard now shows one current secret; its value was not printed.
- Shopify CLI output was captured without returning configuration. The ignored local `.env.test` and protected staging environment were updated; equality checks confirmed the credential changed.
- Staging configuration cache was regenerated using the updated environment and atomically replaced. PHP-FPM was gracefully reloaded; production was neither restarted nor reconfigured.
- A non-mutating webhook probe against an unknown synthetic shop returned **401 for the old signature and 200 for the new signature**. No business webhook was executed.
- Staging health and Horizon status passed. Production environment-file checksum and app container identity/start time were unchanged. Production source is still `b2b0b6c2c058dda277cb0e5cc5e091f4334f1630`.
- Post-rotation state: sending disabled, empty automation/recipient allowlists, zero reviews/invitations/installations in the new staging module. No app installation or theme change was performed in this step.
- Protected configuration backup: `/opt/decoadmin/backups/deco-reviews-key-rotation-20260912T193727Z`. The old key is revoked and must not be restored as an active credential.
- Existing system reviews remain unrelated to this app; no legacy reviews were read, imported, merged or changed.

## Rollback

- Staging backup: `/opt/decoadmin/backups/deco-reviews-test-20260912T191217Z` (previous source, environment, database dump, image tags and source hashes).
- Previous app image: `decoadmin-app:request-finance-workflows-7257d9b-staging`.
- Previous nginx image: `decoadmin-nginx:request-finance-workflows-7257d9b-staging`.
- Current app/nginx: `decoadmin-app:deco-reviews-e71c07a-staging` / `decoadmin-nginx:deco-reviews-e71c07a-staging`.
- Migrations only add app-owned tables, so prior images can be restored while retaining tables. Do not blindly reverse migrations or delete newly submitted data.
- Production source remains `b2b0b6c2c058dda277cb0e5cc5e091f4334f1630`; production was not deployed.
