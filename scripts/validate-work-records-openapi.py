#!/usr/bin/env python3

import sys
from pathlib import Path
from typing import Any, Optional

try:
  import yaml
except ImportError:
  print("ERROR: PyYAML is required to validate the WorkRecords contract.", file=sys.stderr)
  raise SystemExit(2)


ROOT = Path(__file__).resolve().parent.parent
OPENAPI_PATH = ROOT / "docs/contracts/api/openapi.yaml"
PROBLEM_REF = "../schemas/problem-details.schema.json"
WORK_RECORD_REF = "../schemas/work-record.schema.json"


def mapping(value: Any) -> dict[str, Any]:
  return value if isinstance(value, dict) else {}


def sequence(value: Any) -> list[Any]:
  return value if isinstance(value, list) else []


def require(failures: list[str], condition: bool, message: str) -> None:
  if not condition:
    failures.append(message)


def response_ref(operation: dict[str, Any], status: str) -> Optional[str]:
  return mapping(mapping(operation.get("responses")).get(status)).get("$ref")


def validate_problem_response(failures: list[str], responses: dict[str, Any], name: str) -> None:
  response = mapping(responses.get(name))
  schema = mapping(mapping(mapping(response.get("content")).get("application/problem+json")).get("schema"))
  require(failures, schema.get("$ref") == PROBLEM_REF, f"components.responses.{name} must use the shared RFC 7807 schema")
  correlation = mapping(mapping(response.get("headers")).get("X-Correlation-ID"))
  require(failures, correlation.get("$ref") == "#/components/headers/Correlation", f"components.responses.{name} must echo X-Correlation-ID")


def main() -> int:
  try:
    document = yaml.safe_load(OPENAPI_PATH.read_text(encoding="utf-8"))
  except (OSError, yaml.YAMLError) as error:
    print(f"ERROR: cannot load {OPENAPI_PATH.relative_to(ROOT)}: {error}", file=sys.stderr)
    return 2

  failures: list[str] = []
  root = mapping(document)
  path = mapping(mapping(root.get("paths")).get("/work-records"))
  detail_path = mapping(mapping(root.get("paths")).get("/work-records/{recordId}"))
  get = mapping(path.get("get"))
  post = mapping(path.get("post"))
  detail_get = mapping(detail_path.get("get"))

  require(failures, {"$ref": "#/components/parameters/CorrelationId"} in sequence(path.get("parameters")), "/work-records must require CorrelationId")
  require(failures, get.get("operationId") == "listWorkRecords", "GET /work-records operationId must be listWorkRecords")
  require(
    failures,
    sequence(get.get("parameters")) == [
      {"$ref": "#/components/parameters/WorkRecordCursor"},
      {"$ref": "#/components/parameters/Limit"},
      {"$ref": "#/components/parameters/Classification"},
    ],
    "GET /work-records must expose cursor, limit, and classification parameters",
  )
  expected_list = {
    "200": "#/components/responses/WorkRecordCollection",
    "400": "#/components/responses/BadRequest",
    "401": "#/components/responses/Unauthorized",
  }
  require(failures, set(mapping(get.get("responses"))) == set(expected_list), "GET /work-records response set must be 200, 400, and 401")
  for status, expected in expected_list.items():
    require(failures, response_ref(get, status) == expected, f"GET /work-records {status} must reference {expected}")

  require(failures, post.get("operationId") == "createWorkRecord", "POST /work-records operationId must be createWorkRecord")
  require(failures, sequence(post.get("parameters")) == [{"$ref": "#/components/parameters/IdempotencyKey"}], "POST /work-records must require Idempotency-Key")
  expected_post = {
    "201": "#/components/responses/WorkRecord",
    "400": "#/components/responses/BadRequest",
    "401": "#/components/responses/Unauthorized",
    "403": "#/components/responses/Forbidden",
    "404": "#/components/responses/NotFound",
    "409": "#/components/responses/Conflict",
    "422": "#/components/responses/UnprocessableEntity",
    "500": "#/components/responses/InternalServerError",
  }
  require(failures, set(mapping(post.get("responses"))) == set(expected_post), "POST /work-records response set does not match the HTTP adapter")
  for status, expected in expected_post.items():
    require(failures, response_ref(post, status) == expected, f"POST /work-records {status} must reference {expected}")

  expected_detail = {
    "200": "#/components/responses/WorkRecord",
    "400": "#/components/responses/BadRequest",
    "401": "#/components/responses/Unauthorized",
    "404": "#/components/responses/NotFound",
  }
  require(failures, set(mapping(detail_get.get("responses"))) == set(expected_detail), "GET /work-records/{recordId} response set does not match concealment semantics")
  for status, expected in expected_detail.items():
    require(failures, response_ref(detail_get, status) == expected, f"GET /work-records/{{recordId}} {status} must reference {expected}")

  components = mapping(root.get("components"))
  parameters = mapping(components.get("parameters"))
  schemas = mapping(components.get("schemas"))
  responses = mapping(components.get("responses"))
  work_record_cursor = mapping(parameters.get("WorkRecordCursor"))
  cursor_description = work_record_cursor.get("description")
  require(
    failures,
    work_record_cursor.get("name") == "cursor"
    and work_record_cursor.get("in") == "query"
    and isinstance(cursor_description, str)
    and all(term in cursor_description for term in ["classification", "principal", "facility", "metadata-safe 400"]),
    "WorkRecordCursor must document query and effective authorization-scope binding with metadata-safe rejection",
  )
  create = mapping(schemas.get("WorkRecordCreate"))
  create_properties = mapping(create.get("properties"))
  require(
    failures,
    create.get("additionalProperties") is False
    and set(sequence(create.get("required"))) == {"work_definition_code", "title", "description"}
    and set(create_properties) == {"work_definition_code", "title", "description"},
    "WorkRecordCreate must be the closed fixture request accepted by Laravel",
  )
  require(failures, mapping(create_properties.get("work_definition_code")).get("const") == "request", "WorkRecordCreate work_definition_code must be request")

  entity = mapping(schemas.get("WorkRecordResponse"))
  entity_data = mapping(mapping(entity.get("properties")).get("data"))
  require(failures, set(sequence(entity.get("required"))) == {"data"} and entity_data.get("$ref") == WORK_RECORD_REF, "WorkRecordResponse must wrap the canonical schema in data")

  collection = mapping(schemas.get("WorkRecordCollection"))
  collection_properties = mapping(collection.get("properties"))
  items = mapping(collection_properties.get("items"))
  require(
    failures,
    collection.get("additionalProperties") is False
    and set(sequence(collection.get("required"))) == {"items", "next_cursor"}
    and mapping(items.get("items")).get("$ref") == WORK_RECORD_REF
    and mapping(collection_properties.get("next_cursor")).get("type") == ["string", "null"],
    "WorkRecordCollection must contain canonical items and a nullable opaque next_cursor",
  )

  collection_response = mapping(responses.get("WorkRecordCollection"))
  collection_schema = mapping(mapping(mapping(collection_response.get("content")).get("application/json")).get("schema"))
  require(failures, collection_schema.get("$ref") == "#/components/schemas/WorkRecordCollection", "WorkRecordCollection response must use the focused collection schema")
  require(failures, "Link" in mapping(collection_response.get("headers")), "WorkRecordCollection response must declare the next Link header")

  for name in ["BadRequest", "Unauthorized", "Forbidden", "NotFound", "Conflict", "UnprocessableEntity", "InternalServerError"]:
    validate_problem_response(failures, responses, name)

  if failures:
    for failure in failures:
      print(f"ERROR: {failure}", file=sys.stderr)
    print(f"WorkRecords OpenAPI validation failed with {len(failures)} error(s).", file=sys.stderr)
    return 1

  print("WorkRecords OpenAPI request, response, pagination, and error validation passed.")
  return 0


if __name__ == "__main__":
  raise SystemExit(main())
