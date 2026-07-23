# OpenAPI Checklist

## Operation

- Stable `operationId`
- Correct HTTP method and path
- Path/query parameters typed and required correctly
- Request body media type and schema
- Success status matches semantics
- Relevant 400/401/403/404/409/422/500 responses
- Authorization/security requirement
- Representative request and response examples

## Schema

- Required versus optional is explicit
- Nullable is used only when `null` is a valid value
- IDs, dates, enums, money, and pagination have consistent formats
- No database model leakage
- Error envelope is consistent
- Field-validation errors identify fields
- List metadata supports the intended UI
- Filters and sorts are constrained by enums where possible

## Frontend traceability

For each screen state, identify:

- operation;
- triggering request;
- response or status;
- user-visible behavior;
- retry/recovery path.

## Generation

- Use the repository's existing generator
- Prefer Orval when already established
- Generated output has a stable location
- Generated files are excluded from manual edits
- Generation command succeeds
- Regeneration creates no unexplained diff
