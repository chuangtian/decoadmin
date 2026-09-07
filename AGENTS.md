# DecoAdmin project context

## Project identity

- The backend/admin project for this workspace is `decoAdmin`.
- When the user refers to “the backend” or “后台” without further qualification, interpret it as the `decoAdmin` project.

## Environment URLs

- Local development (temporary Cloudflare tunnel): `https://projectors-vast-couple-expand.trycloudflare.com`
- Test environment: `https://testadmin.decomkt.com`
- Production environment: `https://admin.decomkt.com`

## Environment handling

- Keep local, test, and production environments distinct; never treat their URLs, credentials, data, or deployment actions as interchangeable.
- The Cloudflare URL is a temporary local-development tunnel and may change. Treat the URL above as the currently known local URL, not a permanent production endpoint.

## Shopify App architecture

- Store all Shopify plugin and Shopify App files under `shopify-apps/<app-name>/`, including source code, dependencies, extensions, configuration, scripts, and documentation. Do not place these files in the DecoAdmin repository root or elsewhere in the repository.
- Keep different business domains as separate Shopify Apps by default. Do not merge apps unless the user explicitly approves the combined scopes, lifecycle, and release coupling.
- Each Shopify App must independently own its package dependencies, extensions, Shopify client IDs and secrets, environment configuration, validation commands, and release commands.
- Local, test, and production must remain separate Shopify App environments. Never reuse or interchange their app IDs, secrets, URLs, data, or release actions.
- Shopify Apps may reuse DecoAdmin backend services, but backend authorization must enforce User, Organization, Store, and RBAC boundaries.
- A request to develop or release one Shopify App never authorizes changes or releases for another app or environment.
- Treat `shopify-apps/AGENTS.md` as the detailed operating rules for all current and future Shopify Apps in this repository.
