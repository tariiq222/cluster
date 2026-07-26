# Documents Module — Decisions

This file records architectural decisions for the Documents module that
affect cross-module contracts, ownership boundaries, or downstream
production readiness work. Living register; entries are immutable once
recorded, with new decisions appended below.

---

## D-DOCS-001 (2026-07-26) — Close `ARCHITECTURE-CLOSURE:DOCUMENTS-OUTBOX-DECISION`

### Decision

Documents producers write to `document_outbox_events` exclusively through the new module-owned contract:

```text
Modules\Documents\Domain\Contracts\DocumentsOutbox
  └─ Modules\Documents\Infrastructure\Outbox\DocumentsTransactionalOutbox
```

The contract lives in `Modules/Documents/Domain/Contracts/`; the implementation lives in `Infrastructure/Outbox/`. The Documents `ServiceProvider` binds the contract to the implementation. Three production call sites — `DocumentUploadHandler::initiate / complete / scan / promote`, `UpdateDocumentController::__invoke`, and `LinkDocumentController::__invoke` — now inject the contract and delegate their persistence, dropping every direct `DB::table('document_outbox_events')->insert(...)` site.

### Why

`DOCUMENTS-OUTBOX-DECISION` (program orchestration §ARCHITECTURE-CLOSURE) was open. The cross-module boundary rule in `tests/Architecture/ModuleBoundariesTest.php:522` already enforced "Shared-owned outbox_events" access through `Shared\Contracts`. The Documents-owned table had no analogous rule, allowing call sites to migrate silently outside a contract. Adding a `DocumentsOutbox` contract makes the writer surface narrow, testable, and replaceable without touching every controller — and prepares the table for either a future relay worker or a future migration to the Shared outbox without code shifts in the controllers.

### Scope of this decision

- **In-scope:** All three production call sites that today write `document_outbox_events` (`uploadinitiated.v1`, `versionuploaded.v1`, `versionrejected.v1`, `versionpromotionrequested.v1`, `versionavailable.v1`, `metadataupdated.v1`, `linked.v1`).
- **Out-of-scope:** Implementing a relay/worker that flips `published_at`. See "Open gaps" below.

### Evidence

- `Modules/Documents/Domain/Contracts/DocumentsOutbox.php` — contract. Accepts `?DateTimeInterface` so producers passing `Carbon::now()` are accepted without conversion.
- `Modules/Documents/Infrastructure/Outbox/DocumentsTransactionalOutbox.php` — implementation. Normalises to UTC via `DateTimeImmutable::createFromInterface(...)->setTimezone(new DateTimeZone('UTC'))`, writes the columns the existing `documents.document_outbox_events` migration already declares.
- `Modules/Documents/Providers/DocumentsServiceProvider.php:53` — binding `DocumentsOutbox::class => DocumentsTransactionalOutbox::class`.
- `Tests/Architecture/ModuleBoundariesTest.php:526-534` — new rule blocking `DB::table('document_outbox_events')` outside `Infrastructure/Outbox/DocumentsTransactionalOutbox.php` and `/Tests/`.
- `Tests/Architecture/ModuleBoundariesTest.php:test_rejects_raw_document_outbox_access_outside_the_documents_adapter` and `test_allows_raw_document_outbox_access_inside_the_documents_adapter` — fixture-based proof the rule fires and accepts the bound adapter.
- `Modules/Documents/Tests/DocumentUploadCoreTest.php:test_upload_initiated_through_handler_writes_document_outbox_events_row` — runs the real `DocumentUploadHandler::initiate` against SQLite, asserts a row is persisted with `event_type = com.cluster.documents.uploadinitiated.v1`, non-empty `payload`, and `published_at = null`.
- `make verify-boundaries` → 28/28 PASS (was 26/26 before this slice; two new fixtures added).
- `php -d memory_limit=2G ./vendor/bin/phpstan analyse --memory-limit=2G` → 0 errors.
- `composer --working-dir=apps/api test` against the working tree containing this slice → **788 tests, 779 passed, 9 skipped, 3 incomplete, 0 failed**.
  - Pre-slice baseline (verified by `git stash -u` + re-run) reported 2 failures: `Tests\Feature\CiMakeSurfaceTest::test_s9_docs_validate_target_surfaces_missing_prereqs` and `Tests\Feature\CiMakeSurfaceTest::test_s9_docs_validate_fast_target_is_strict_prereq_alias_for_docs_validate`. Both were closed in the same slice (commit `175da30`): the tests asserted strings from a previous contract — `'docs/validate-docs.sh is missing'` and `'docs/catalog.yaml is missing'` — that no longer exist in `Makefile:103-118`. The Makefile intentionally dropped the catalog probe (Makefile:102 comment: "The current lean docs tree has no catalog or MkDocs registry") and emits `'scripts/validate-docs.sh is missing.'` instead. The tests were re-aligned to that recipe.
  - Why the **test** was realigned and the **Makefile** was not changed: the docs tree has no governed catalog and no plans to introduce one in this slice's scope. A future slice that ships a governed docs catalog must reintroduce the catalog probe and re-add the corresponding test assertions, but doing so here would couple a validator slice to a catalog scope that does not yet exist. The carve-out in `scripts/validate-docs.sh` is the unrelated, separate remedy for the 114 unfound references in deferred M0x/P0x plans.

### Open gaps (must be tracked explicitly)

1. **No relay worker.** `document_outbox_events.published_at` never flips. Events accumulate indefinitely. A Documents-owned relay that watches `whereNull('published_at')` and publishes through S3-compatible storage is unowned. Deferred to either P02 (production runtime wiring) or a future workstream; this decision does not implement it.
2. **Two event types without runtime coverage.** `com.cluster.documents.metadataupdated.v1` (UpdateDocumentController, line 89) and `com.cluster.documents.linked.v1` (LinkDocumentController, line 128) are converted to use the contract but are not asserted on by a test in this slice. The middleware/auth/seed path required to exercise `POST /api/v1/documents/{id}/links` and `PATCH /api/v1/documents/{id}` through the real HTTP stack stayed on the queue. Known gaps; closure of DOCUMENTS-OUTBOX-DECISION does not require these as evidence today — the architecture rule + the upload-init assertion prove the contract surface; coverage for the remaining event types is conventional regression work, not a contract boundary.
3. **No event-type catalogue entry.** Shared `OutboxEventType` enum (under `Shared/Infrastructure/Outbox/OutboxEventType.php`) enumerates Organization/Authorization events; the three Documents event types were intentionally not added there because `DocumentsOutbox` is module-owned and the catalogue is Shared-owned. Mixing them would re-open the boundary this decision is closing. A future M01 Audit or P02 workstream that needs type-level guarantees on Documents events can add a Documents-owned catalogue without changing this decision.

### Rollback

Removing the decision is a self-contained revert of:

- `git revert` of the two new files and the four edits
- Removal of the two new `ModuleBoundariesTest` fixtures
- Removal of `DocumentUploadCoreTest`'s outbox assertion

The controllers will fall back to `DB::table('document_outbox_events')->insert(...)`, restoring the pre-decision scattered-write surface and the open `DOCUMENTS-OUTBOX-DECISION`.
