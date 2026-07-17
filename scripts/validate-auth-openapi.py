#!/usr/bin/env python3

import sys
from pathlib import Path
from typing import Any

try:
    import yaml
except ImportError:
    print("ERROR: PyYAML is required to validate the authentication contract.", file=sys.stderr)
    raise SystemExit(2)


ROOT = Path(__file__).resolve().parent.parent
OPENAPI_PATH = ROOT / "docs/contracts/api/openapi.yaml"


def mapping(value: Any) -> dict[str, Any]:
    return value if isinstance(value, dict) else {}


def require(condition: bool, message: str) -> None:
    if not condition:
        print(f"ERROR: {message}", file=sys.stderr)
        raise SystemExit(1)


document = yaml.safe_load(OPENAPI_PATH.read_text(encoding="utf-8"))
components = mapping(mapping(document).get("components"))
schemas = mapping(components.get("schemas"))
login_path = mapping(mapping(mapping(document).get("paths")).get("/auth/login"))
operation = mapping(login_path.get("post"))

require(
    {"$ref": "#/components/parameters/CorrelationId"} in login_path.get("parameters", []),
    "POST /auth/login must require the shared CorrelationId parameter",
)
require(operation.get("security") == [], "POST /auth/login must be the explicit unauthenticated operation")
request_schema = mapping(
    mapping(mapping(mapping(operation.get("requestBody")).get("content")).get("application/json")).get("schema")
)
require(request_schema.get("$ref") == "#/components/schemas/Login", "login request must use the Login schema")

responses = mapping(operation.get("responses"))
require(set(responses) == {"200", "400", "401"}, "login responses must be exactly 200, 400, and 401")
success = mapping(responses.get("200"))
success_schema = mapping(
    mapping(mapping(success.get("content")).get("application/json")).get("schema")
)
require(success_schema.get("$ref") == "#/components/schemas/SessionResponse", "login success must use SessionResponse")
require(
    mapping(mapping(success.get("headers")).get("X-Correlation-ID")).get("$ref")
    == "#/components/headers/Correlation",
    "login success must echo X-Correlation-ID",
)
require(mapping(responses.get("400")).get("$ref") == "#/components/responses/BadRequest", "login 400 must use BadRequest")
require(mapping(responses.get("401")).get("$ref") == "#/components/responses/Unauthorized", "login 401 must use Unauthorized")

login = mapping(schemas.get("Login"))
require(login.get("additionalProperties") is False, "Login must reject unknown fields")
require(set(login.get("required", [])) == {"username", "password"}, "Login must require username and password")
require(mapping(mapping(login.get("properties")).get("password")).get("minLength") == 12, "Login password minimum must be 12")

session_response = mapping(schemas.get("SessionResponse"))
require(session_response.get("additionalProperties") is False, "SessionResponse must reject unknown fields")
require(session_response.get("required") == ["data"], "SessionResponse must require data")
require(
    mapping(mapping(session_response.get("properties")).get("data")).get("$ref") == "#/components/schemas/Session",
    "SessionResponse.data must use Session",
)

session = mapping(schemas.get("Session"))
require(
    set(session.get("required", [])) == {"access_token", "token_type", "expires_at", "facility", "principal"},
    "Session required fields must match the fixture response",
)
principal = mapping(schemas.get("DevelopmentFixturePrincipal"))
require(
    set(principal.get("required", [])) == {"user_id", "facility_id"} and principal.get("additionalProperties") is False,
    "DevelopmentFixturePrincipal must contain only user_id and facility_id",
)

print("Authentication OpenAPI request, response, and correlation validation passed.")
