#!/usr/bin/env python3

import sys
from pathlib import Path
from typing import Any

try:
  import yaml
except ImportError:
  print("ERROR: PyYAML is required to validate the Notifications contract.", file=sys.stderr)
  raise SystemExit(2)


ROOT = Path(__file__).resolve().parent.parent
OPENAPI_PATH = ROOT / "docs/contracts/api/openapi.yaml"
PROBLEM_SCHEMA_REF = "#/components/schemas/problem-details.schema"
CORRELATION_HEADER_REF = "#/components/headers/Correlation"
NOTIFICATION_FIELDS = {
  "id",
  "title",
  "source",
  "is_read",
  "created_at",
}


class ValidationFailure:
  def __init__(self, code: str, message: str) -> None:
    self.code = code
    self.message = message


def load_yaml(path: Path) -> Any:
  try:
    return yaml.safe_load(path.read_text(encoding="utf-8"))
  except OSError as error:
    print(f"ERROR: cannot read {path.relative_to(ROOT)}: {error}", file=sys.stderr)
    raise SystemExit(2)
  except yaml.YAMLError as error:
    print(f"ERROR: invalid YAML in {path.relative_to(ROOT)}: {error}", file=sys.stderr)
    raise SystemExit(2)


def require(
  failures: list[ValidationFailure],
  condition: bool,
  code: str,
  message: str,
) -> bool:
  if condition:
    return True
  failures.append(ValidationFailure(code, message))
  return False


def mapping(value: Any) -> dict[str, Any]:
  return value if isinstance(value, dict) else {}


def sequence(value: Any) -> list[Any]:
  return value if isinstance(value, list) else []


def validate_named_response(
  failures: list[ValidationFailure],
  responses: dict[str, Any],
  name: str,
  media_type: str,
  schema_ref: str,
) -> None:
  response = mapping(responses.get(name))
  require(failures, bool(response), f"response_{name}", f"components.responses.{name} is required")
  headers = mapping(response.get("headers"))
  correlation = mapping(headers.get("X-Correlation-ID"))
  require(
    failures,
    correlation.get("$ref") == CORRELATION_HEADER_REF,
    f"response_{name}_correlation",
    f"components.responses.{name} must echo the shared X-Correlation-ID header",
  )
  content = mapping(response.get("content"))
  schema = mapping(mapping(content.get(media_type)).get("schema"))
  require(
    failures,
    schema.get("$ref") == schema_ref,
    f"response_{name}_schema",
    f"components.responses.{name} must use {media_type} with schema {schema_ref}",
  )


def validate_openapi(document: Any, failures: list[ValidationFailure]) -> None:
  root = mapping(document)
  require(failures, root.get("openapi") == "3.1.0", "openapi_version", "OpenAPI version must remain 3.1.0")
  require(
    failures,
    root.get("security") == [{"bearerAuth": []}],
    "global_security",
    "the global security policy must require bearerAuth",
  )

  path_item = mapping(mapping(root.get("paths")).get("/notifications"))
  if not require(
    failures,
    bool(path_item),
    "notifications_path",
    "paths./notifications is required",
  ):
    return

  correlation_parameter = {"$ref": "#/components/parameters/CorrelationId"}
  require(
    failures,
    correlation_parameter in sequence(path_item.get("parameters")),
    "notifications_correlation_parameter",
    "/notifications must require the shared CorrelationId parameter",
  )
  operation = mapping(path_item.get("get"))
  if not require(failures, bool(operation), "notifications_get", "GET /notifications is required"):
    return
  require(
    failures,
    "security" not in operation,
    "notifications_security_override",
    "GET /notifications must inherit global bearerAuth without an override",
  )
  require(
    failures,
    operation.get("operationId") == "listMyNotifications",
    "notifications_operation_id",
    "GET /notifications operationId must be listMyNotifications",
  )
  operation_parameters = sequence(operation.get("parameters"))
  require(
    failures,
    {"$ref": "#/components/parameters/Cursor"} in operation_parameters,
    "notifications_cursor",
    "GET /notifications must accept the shared Cursor parameter",
  )
  require(
    failures,
    {"$ref": "#/components/parameters/Limit"} in operation_parameters,
    "notifications_limit",
    "GET /notifications must accept the shared Limit parameter",
  )

  operation_responses = mapping(operation.get("responses"))
  require(
    failures,
    set(operation_responses) == {"200", "400", "401"},
    "notifications_response_set",
    "GET /notifications responses must be exactly 200, 400, and 401",
  )
  expected_response_refs = {
    "200": "#/components/responses/Notifications",
    "400": "#/components/responses/BadRequest",
    "401": "#/components/responses/NotificationUnauthorized",
  }
  for status, expected_ref in expected_response_refs.items():
    response = mapping(operation_responses.get(status))
    require(
      failures,
      response.get("$ref") == expected_ref,
      f"notifications_response_{status}",
      f"GET /notifications {status} must reference {expected_ref}",
    )

  components = mapping(root.get("components"))
  responses = mapping(components.get("responses"))
  validate_named_response(
    failures,
    responses,
    "Notifications",
    "application/json",
    "#/components/schemas/NotificationCollection",
  )
  validate_named_response(
    failures,
    responses,
    "BadRequest",
    "application/problem+json",
    PROBLEM_SCHEMA_REF,
  )
  validate_named_response(
    failures,
    responses,
    "NotificationUnauthorized",
    "application/problem+json",
    PROBLEM_SCHEMA_REF,
  )

  schemas = mapping(components.get("schemas"))
  notification = mapping(schemas.get("Notification"))
  require(failures, bool(notification), "notification_schema", "components.schemas.Notification is required")
  require(
    failures,
    notification.get("type") == "object" and notification.get("additionalProperties") is False,
    "notification_closed",
    "Notification must be a closed object schema",
  )
  properties = mapping(notification.get("properties"))
  require(
    failures,
    set(properties) == NOTIFICATION_FIELDS,
    "notification_fields",
    f"Notification fields must be exactly {sorted(NOTIFICATION_FIELDS)}",
  )
  require(
    failures,
    set(sequence(notification.get("required"))) == NOTIFICATION_FIELDS,
    "notification_required_fields",
    "every Notification field must be required",
  )
  require(
    failures,
    mapping(properties.get("id")).get("$ref") == "#/components/schemas/UUIDv7",
    "notification_id",
    "Notification.id must be a UUIDv7",
  )
  require(
    failures,
    mapping(properties.get("source")).get("$ref") == "#/components/schemas/SourceReference",
    "notification_source",
    "Notification.source must use the shared typed SourceReference schema",
  )
  require(
    failures,
    mapping(properties.get("title")).get("type") == "string",
    "notification_title",
    "Notification.title must be a string",
  )
  require(
    failures,
    mapping(properties.get("is_read")).get("type") == "boolean",
    "notification_read_state",
    "Notification.is_read must be a boolean",
  )
  require(
    failures,
    mapping(properties.get("created_at")).get("$ref") == "#/components/schemas/UtcDateTime",
    "notification_timestamp",
    "Notification.created_at must use UtcDateTime",
  )

  collection = mapping(schemas.get("NotificationCollection"))
  require(failures, bool(collection), "notification_collection", "components.schemas.NotificationCollection is required")
  require(
    failures,
    collection.get("type") == "object" and collection.get("additionalProperties") is False,
    "notification_collection_closed",
    "NotificationCollection must be a closed object schema",
  )
  collection_properties = mapping(collection.get("properties"))
  require(
    failures,
    set(collection_properties) == {"items", "next_cursor"}
    and set(sequence(collection.get("required"))) == {"items", "next_cursor"},
    "notification_collection_fields",
    "NotificationCollection must require only items and next_cursor",
  )
  item_schema = mapping(mapping(collection_properties.get("items")).get("items"))
  require(
    failures,
    mapping(collection_properties.get("items")).get("type") == "array"
    and item_schema.get("$ref") == "#/components/schemas/Notification",
    "notification_collection_items",
    "NotificationCollection.items must contain Notification objects",
  )
  require(
    failures,
    mapping(collection_properties.get("next_cursor")).get("type") == ["string", "null"],
    "notification_collection_cursor",
    "NotificationCollection.next_cursor must be a nullable string",
  )




def main() -> int:
  failures: list[ValidationFailure] = []
  validate_openapi(load_yaml(OPENAPI_PATH), failures)

  if failures:
    for failure in failures:
      print(f"ERROR [{failure.code}]: {failure.message}", file=sys.stderr)
    print(f"Notifications OpenAPI validation failed with {len(failures)} error(s).", file=sys.stderr)
    return 1

  print("Notifications OpenAPI contract validation passed.")
  return 0


if __name__ == "__main__":
  raise SystemExit(main())
