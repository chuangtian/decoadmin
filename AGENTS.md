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

## Interface typography

- User requirement: DecoAdmin interface text must be at least 12 CSS pixels when rendered. Use 14px for normal table text, headings/captions in the brand-media weekly tables at 16px/14px, and 12px only for secondary labels.
- Do not reintroduce global font shrinking or explicit sub-12px utility classes. Keep SVG chart labels readable after viewBox scaling using the shared `readableChart` directive; map label sizing must preserve the same minimum.
- Run `npm run check:typography` for interface changes. The production frontend build includes this check; run `npm run test:typography` when changing responsive typography behavior.

## Git and test deployment source

- Git `origin/test` is the source of truth for test deployments and local synchronization.
- Commit, validate, and push test changes before building a release. Build from a clean export of the exact fetched commit, not a mutable staging directory or an existing image with additional source files copied over it.
- Record the full source commit with each release. Do not treat an old `RELEASE` file or an image tag alone as proof that the running source matches Git; verify source hashes when reconciling drift.
- If test-only changes are discovered, preserve them in an isolated checkout, review and merge them into `test` before the next deployment. Never overwrite newer Git code, tests, or documents with older files from a container.
- Keep environment secrets, databases, storage, generated assets, local tools, and backups separate from source synchronization. Back up local uncommitted work before restoring branch tracking.
- A request to synchronize code does not by itself authorize a new test or production deployment.

## Shopify App architecture

- Store all Shopify plugin and Shopify App files under `shopify-apps/<app-name>/`, including source code, dependencies, extensions, configuration, scripts, and documentation. Do not place these files in the DecoAdmin repository root or elsewhere in the repository.
- Keep different business domains as separate Shopify Apps by default. Do not merge apps unless the user explicitly approves the combined scopes, lifecycle, and release coupling.
- Each Shopify App must independently own its package dependencies, extensions, Shopify client IDs and secrets, environment configuration, validation commands, and release commands.
- Local, test, and production must remain separate Shopify App environments. Never reuse or interchange their app IDs, secrets, URLs, data, or release actions.
- Shopify Apps may reuse DecoAdmin backend services, but backend authorization must enforce User, Organization, Store, and RBAC boundaries.
- A request to develop or release one Shopify App never authorizes changes or releases for another app or environment.
- Treat `shopify-apps/AGENTS.md` as the detailed operating rules for all current and future Shopify Apps in this repository.
