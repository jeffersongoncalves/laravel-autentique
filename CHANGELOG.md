# Changelog

All notable changes to this project will be documented in this file.

## 1.0.0 - 2026-10-22

Initial release.

- Autentique v2 GraphQL client (`Autentique` facade): documents (multipart upload, get, list, update, sign, delete, move to folder), signers (add, remove, resend, signature link), folders, `me()` and raw `query()`.
- Sandbox mode (`AUTENTIQUE_SANDBOX`).
- Rate limiting: client-side throttle shared through the cache (60 req/min by default) that waits up to `max_wait` or throws `RateLimitException`, plus retry of 429/5xx honouring `Retry-After`.
- GraphQL errors as `AutentiqueException` with `validation()` helper.
- HMAC-verified webhook route (`X-Autentique-Signature`) dispatching `WebhookReceived`.

## [Unreleased]
