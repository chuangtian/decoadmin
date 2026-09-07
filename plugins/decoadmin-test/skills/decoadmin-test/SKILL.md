---
name: decoadmin-test
description: Query the authorized DecoAdmin test environment and prepare confirmed writes for analytics refresh, Shopify sync, safe notification toggles, or student discount status.
---

# DecoAdminTest

Use the bundled DecoAdminTest connection for authorized reads and narrowly scoped confirmed writes against `https://testadmin.decomkt.com` only.

## Required workflow

1. State that the configured environment is the DecoAdmin test environment. Never claim that an action affects production.
2. Call `decoadmin_list_stores` first when the store ID is unknown. Use its stable numeric `store_id` for store-scoped calls.
3. Keep reads and writes separate. Never prepare a write unless the user explicitly requested the change.
4. Summarize conclusions in Chinese. Do not paste raw JSON unless the user explicitly requests it.
5. Report only missing field names and configured/not-configured state for configuration checks. Never request or display secret values.
6. Explain authorization failures as missing connection scope or current DecoAdmin RBAC permission. Never suggest bypassing authorization.
7. Use filters and pagination for order queries. Do not attempt unbounded reads.

## Mandatory confirmation workflow

1. Start an explicitly requested write with exactly one matching `decoadmin_prepare_*` tool.
2. Show the returned Chinese summary, confirmation ID, and expiration time.
3. Stop the turn. Never execute in the same turn as preparation.
4. Execute only when the user's newest subsequent message says exactly `确认执行` and the confirmation remains current.
5. Pass that exact text and confirmation ID to `decoadmin_execute_confirmed_action`.
6. Prepare a new confirmation when parameters change or the previous confirmation expires.
7. After execution, state what changed, the store, whether it was an idempotent replay, and the validation result.

## Safety boundary

The backend independently rechecks the OAuth token scope, current user, organization, store membership, and action-specific RBAC permission during preparation and execution. Role changes take effect immediately. Every executed write is audited and idempotent. Never expose Shopify tokens, client secrets, API keys, passwords, cookies, authorization headers, webhook secrets, customer contact information, or raw configuration values.
