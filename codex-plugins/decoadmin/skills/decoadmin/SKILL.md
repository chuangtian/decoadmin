---
name: decoadmin
description: Query an authorized DecoAdmin environment and prepare confirmed writes for analytics refresh, Shopify sync, safe notification toggles, or student discount status. Use when the user asks to inspect DecoAdmin or explicitly requests one of these changes.
---

# DecoAdmin

Use the bundled `decoadmin` MCP tools for authorized DecoAdmin reads and narrowly scoped confirmed writes.

## Required workflow

1. Treat local, test, and production as different environments. State which configured environment is being queried. Never silently switch environments.
2. Call `decoadmin_list_stores` first when the store ID is unknown. Use the returned stable numeric `store_id` for every store-scoped call.
3. Keep reads and writes separate. Never call a prepare tool unless the user explicitly requested that change.
4. Summarize conclusions in Chinese. Do not paste raw JSON unless the user explicitly requests it.
5. When configuration is incomplete, report only the missing field names and configured/not-configured state. Never request or display secret values.
6. When an authorization error occurs, explain which token ability or DecoAdmin RBAC permission is missing. Do not suggest bypassing authorization.
7. Use filters and pagination for order queries. Do not attempt unbounded collection reads.

## Mandatory confirmation workflow

1. A requested write starts with exactly one matching `decoadmin_prepare_*` tool.
2. Show the returned Chinese summary, confirmation ID, and expiration time to the user.
3. Stop the turn. Never call `decoadmin_execute_confirmed_action` in the same turn as a prepare tool, even if the original request used words such as “直接执行”.
4. Execute only when the user's newest, subsequent message explicitly says `确认执行` and the confirmation ID is still current.
5. Pass that exact text and the returned confirmation ID to `decoadmin_execute_confirmed_action`.
6. If the user changes parameters, hesitates, or the confirmation expires, prepare a new confirmation instead of reusing the old one.
7. After execution, state what changed, the affected store, whether the result was an idempotent replay, and any validation performed.

## Tool selection

- `decoadmin_list_stores`: discover stores available to the authenticated user.
- `decoadmin_get_dashboard`: summarize sales, orders, inventory, alerts, and recent trends for one store.
- `decoadmin_list_orders`: inspect a bounded page of sanitized order facts. Customer contact information is intentionally unavailable.
- `decoadmin_get_operations`: inspect sync, webhook, and recent operational status according to the user's permissions.
- `decoadmin_get_configuration_status`: identify missing system and store configuration without exposing credentials.
- `decoadmin_get_system_status`: inspect application, database, Redis, queues, scheduler, and recent incident counts.
- `decoadmin_prepare_analytics_refresh`: prepare a store analytics cache refresh.
- `decoadmin_prepare_sync`: prepare a products, orders, customers, or inventory sync.
- `decoadmin_prepare_sync_retry`: prepare retrying one failed sync job.
- `decoadmin_prepare_notification_update`: prepare changes to safe notification booleans only.
- `decoadmin_prepare_student_discount_status`: prepare enabling or disabling the student discount campaign.
- `decoadmin_execute_confirmed_action`: execute one current confirmation after a subsequent exact `确认执行` message.

## Safety boundary

The backend independently rechecks the token ability, current user, organization, store membership, and action-specific RBAC permission both when preparing and when executing. Role changes therefore take effect immediately. Every executed write is audited and idempotent. Tool input and output must never contain Shopify access tokens, client secrets, API keys, passwords, cookies, authorization headers, raw webhook secrets, or unnecessary personal information. Secret-bearing configuration remains UI-only.
