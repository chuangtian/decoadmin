# Shopify Apps development rules

## Directory layout

- Each Shopify App must live in its own `shopify-apps/<app-name>/` directory.
- Current apps are `commerce-hub/`, `student-discount/`, and `instagram-feed/`; keep them independent and do not merge their functionality or release lifecycle.
- New business domains should default to a new independent app when their Shopify scopes, installation needs, operational ownership, or release cadence differ from an existing app.

## App isolation

Each app must independently maintain:

- `package.json` and its lockfile;
- `extensions/`;
- `shopify.app.local.toml`;
- `shopify.app.test.toml`;
- `shopify.app.production.toml`;
- app-specific README and development commands;
- Shopify client IDs, secrets, scopes, App Proxy paths, webhook URLs, and callback URLs;
- build, validation, and release commands.

Do not move app dependencies into the DecoAdmin root package, and do not share secrets or Shopify configuration files between apps.

## Environment and release safety

- Keep local, test, and production configuration isolated for every app.
- Default to development and validation only. Publishing, deploying, installing, changing distribution, or modifying Shopify organization access requires explicit user authorization for the exact app and environment.
- Before any release, state the app name and target environment, validate the selected Shopify configuration, build that app, and verify that the matching DecoAdmin backend routes are already available.
- Releasing one app must not build, publish, install, or modify another app.
- Never commit secrets. Keep client secrets, API keys, access tokens, and environment credentials in the approved secret store or untracked environment configuration.

## DecoAdmin integration

- Shopify Apps may call DecoAdmin through stable backend services and APIs; do not duplicate DecoAdmin business logic in extension code.
- Backend entry points must validate the Shopify signature or identity token and resolve the trusted Organization and Store server-side.
- Do not trust browser-provided `organization_id`, `store_id`, shop domain, role, or permission claims.
- Preserve the layering `Extension / App Home -> DecoAdmin Controller -> Business Service -> Model / Shopify API`.
- Keep response structures, identifiers, status enums, timestamps, errors, pagination, and audit behavior stable and documented.
