REST API used by the IronPulse Android and iOS apps. The website keeps its own session-based routes; this API is
bearer-token only and is the contract between the Laravel backend and the Flutter app.

**Conventions**

* Base path `/api/v1`. Every response is JSON. Success bodies are `{"data": ...}` (lists may add `meta` / `links`).
* Errors always look like `{"error": {"code", "message", "details", "request_id"}}`. `code` is stable and meant for
  program logic; `message` is human text. Quote `request_id` (also the `X-Request-Id` header) when reporting problems.
* Authentication: `Authorization: Bearer <token>` from `/auth/login`, `/auth/register` or `/auth/guest`. Tokens expire
  (see `auth.token_ttl_days` in `/app/config`), are rotated by `/auth/refresh` and can be revoked at any time.
  A `401` on an authenticated call means the token is no longer valid: sign out.
* Send `X-App-Platform: android|ios` and `X-App-Version: 1.2.3` on every request. Builds below the minimum get `426
  upgrade_required`; during maintenance every call except `/app/config` gets `503 maintenance`.
* Mutating requests may carry `Idempotency-Key: <8-80 chars>`. A retry with the same key and body returns the first
  response (`Idempotent-Replayed: true`); the same key with a different body is `422 idempotency_key_reused`.
* Membership: routes marked `x-entitlement` are refused with `403 entitlement_required` (details name the missing
  entitlement) when the member's plan does not include the feature. The server checks this on every call; hiding a
  button in the app is a courtesy, never the protection.
* Everything about a member is private to that member. Another member's record is answered `404`, never `403`.
* Pagination: page-based (`page`, `per_page`, max 100) for catalogues, cursor-based (`cursor`) for histories.
* Sync: see the `Sync` tag. Read `/content/manifest` first, download collections, then follow `/content/changes`.
* Rate limits return `429 rate_limited` with a `Retry-After` header.

**Not covered by this document**: the website's session endpoints under `/api/...` (same-origin, CSRF protected) and
Apple / Google store server notifications.
