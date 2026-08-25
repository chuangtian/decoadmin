# DecoAdmin project context

## Project identity

- The backend/admin project for this workspace is `decoAdmin`.
- When the user refers to “the backend” or “后台” without further qualification, interpret it as the `decoAdmin` project.

## Environment URLs

- Local development (temporary Cloudflare tunnel): `https://wendy-interim-classic-segment.trycloudflare.com`
- Test environment: `https://testadmin.decomkt.com`
- Production environment: `https://admin.decomkt.com`

## Environment handling

- Keep local, test, and production environments distinct; never treat their URLs, credentials, data, or deployment actions as interchangeable.
- The Cloudflare URL is a temporary local-development tunnel and may change. Treat the URL above as the currently known local URL, not a permanent production endpoint.
