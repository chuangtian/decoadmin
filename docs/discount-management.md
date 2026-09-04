# Discount management authorization

Discount management reuses the current store's existing Commerce Hub `ShopifyConnection`, following the user's request to use the already-published app instead of creating another app. The retired independent Discount Manager scaffold has been removed; no Shopify installations or tokens are deleted by this change.

- List/detail require `read_discounts` (or `write_discounts`) and `read_products` (or `write_products`). Create/update additionally require `write_discounts`.
- `/discounts/connect` explicitly requests the additional discount scopes through the existing OAuth client and `/shopify/oauth/callback`. It retains previously granted scopes. Normal store connection entry points and global scope settings are unchanged.
- Publishing a Shopify app version alone does not grant its new scopes to existing tokens. A store administrator must explicitly approve the additional scopes for that store.
- Missing discount scopes do not invalidate, disconnect, refresh, or mutate the store's existing connection. Reading this page does not change Shopify data.
- Every write/authorization request checks the submitted page store ID against the authoritative current store, along with Organization, membership and RBAC checks. Authorization additionally requires the existing App installation permission.
- Store-local date inputs and displayed dates use the selected store's timezone, not the browser's timezone.
- The user authorized live test data changes only on `macfox-test-app.myshopify.com`. Do not use other stores for mutation or authorization tests.
- No monitoring, expiry changes or automatic notifications are added.

The user manually added discount scopes to the Test Commerce Hub version. This backend fix does not publish or change any Shopify App configuration. Before a future Shopify App release, reconcile the Test configuration with its approved scopes; do not add scopes to Local or Production apps without separate authorization.
