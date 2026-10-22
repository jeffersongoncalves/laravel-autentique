# Changelog

All notable changes to this project will be documented in this file.

## 1.1.0 - 2026-10-22

### Added

- Database persistence: `autentique_documents`, `autentique_signatures`, `autentique_webhook_calls` (publish with `--tag=autentique-migrations`, or `php artisan autentique:install`).
- Models `Document` (status pending/signed/rejected, optional `documentable` morph, soft deletes, `forModel()` scope), `Signature`, `WebhookCall` (prunable via `model:prune`). `Document::sync()` upserts API or webhook payloads.
- Jobs: `CreateDocument` (local path or any filesystem disk, links a model, never retried after an error), `SyncDocument` (unique per document for 60s), `ProcessWebhookCall`. All release themselves on rate limits.
- `AUTENTIQUE_QUEUE_CONNECTION` / `AUTENTIQUE_QUEUE`, `AUTENTIQUE_WEBHOOK_PRUNE_AFTER_DAYS`.

### Changed

- The webhook route stores each call once per event id and processes it on the queue; `WebhookReceived` is dispatched after the local tables are updated. **Run the migrations when upgrading from 1.0.0.**
- The client retries only 429/503 (no longer timeouts/502/504, which could create a document twice).

### Fixed

- `WebhookReceived::object()` / `previousAttributes()` for payloads where `event.data` is the object itself.
- README install command and publish tags (`autentique:install`, `autentique-config`, `autentique-migrations`).

## 1.0.0 - 2026-10-22

Initial release.

- Autentique v2 GraphQL client (`Autentique` facade): documents (multipart upload, get, list, update, sign, delete, move to folder), signers (add, remove, resend, signature link), folders, `me()` and raw `query()`.
- Sandbox mode (`AUTENTIQUE_SANDBOX`).
- Rate limiting: client-side throttle shared through the cache (60 req/min by default) that waits up to `max_wait` or throws `RateLimitException`, plus retry of 429/5xx honouring `Retry-After`.
- GraphQL errors as `AutentiqueException` with `validation()` helper.
- HMAC-verified webhook route (`X-Autentique-Signature`) dispatching `WebhookReceived`.

## [Unreleased]
