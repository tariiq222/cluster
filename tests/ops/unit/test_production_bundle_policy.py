from __future__ import annotations

import copy

import pytest

from scripts.production_bundle_policy import validate_compose, validate_dockerfile


pytestmark = pytest.mark.unit


PINNED = "sha256:" + "a" * 64


def dockerfile(final_body: str = "") -> str:
  return f"""FROM docker.io/library/alpine:3.21@{PINNED} AS build
RUN echo build-only
FROM docker.io/library/alpine:3.21@{PINNED} AS production
COPY --from=build /etc/alpine-release /app/alpine-release
USER 10001:10001
HEALTHCHECK CMD [\"/bin/true\"]
{final_body}
CMD [\"/bin/true\"]
"""


def valid_compose() -> dict:
  hardened = {
    "read_only": True,
    "security_opt": ["no-new-privileges:true"],
    "cap_drop": ["ALL"],
  }
  application_health = {"test": ["CMD", "/bin/true"]}
  environment = {
    "APP_KEY": "${APP_KEY:?required}",
    "DB_PASSWORD": "${DB_PASSWORD:?required}",
    "VALKEY_PASSWORD": "${VALKEY_PASSWORD:?required}",
  }
  return {
    "services": {
      "web": {
        "image": "${WEB_IMAGE:?digest required}",
        "pull_policy": "never",
        "ports": ["8080:8080"],
        "networks": ["frontend"],
        "healthcheck": application_health,
        **hardened,
      },
      "api": {
        "image": "${API_IMAGE:?digest required}",
        "pull_policy": "never",
        "expose": ["9000"],
        "networks": ["frontend", "state"],
        "environment": environment,
        "healthcheck": application_health,
        **hardened,
      },
      "worker": {
        "image": "${API_IMAGE:?digest required}",
        "pull_policy": "never",
        "networks": ["state"],
        "environment": environment,
        "healthcheck": application_health,
        **hardened,
      },
      "scheduler": {
        "image": "${API_IMAGE:?digest required}",
        "pull_policy": "never",
        "networks": ["state"],
        "environment": environment,
        "healthcheck": application_health,
        **hardened,
      },
      "migrate": {
        "image": "${API_IMAGE:?digest required}",
        "pull_policy": "never",
        "networks": ["state"],
        "environment": environment,
        **hardened,
      },
      "mysql": {
        "image": f"docker.io/library/mysql:8.4.6@{PINNED}",
        "pull_policy": "never",
        "networks": ["state"],
        "environment": {
          "MYSQL_PASSWORD": "${DB_PASSWORD:?required}",
          "MYSQL_ROOT_PASSWORD": "${DB_ROOT_PASSWORD:?required}",
        },
        "volumes": ["mysql-data:/var/lib/mysql"],
        "healthcheck": application_health,
      },
      "valkey": {
        "image": f"docker.io/valkey/valkey:8.1.1@{PINNED}",
        "pull_policy": "never",
        "networks": ["state"],
        "environment": {"VALKEY_PASSWORD": "${VALKEY_PASSWORD:?required}"},
        "volumes": ["valkey-data:/data"],
        "healthcheck": application_health,
      },
    },
    "networks": {"frontend": {}, "state": {"internal": True}},
    "volumes": {"mysql-data": {}, "valkey-data": {}},
  }


def codes(failures):
  return {failure.code for failure in failures}


def test_valid_multistage_dockerfile_is_accepted():
  assert validate_dockerfile(dockerfile(), "Dockerfile") == []


def test_named_stage_reference_does_not_require_a_registry_digest():
  candidate = dockerfile().replace(f"FROM docker.io/library/alpine:3.21@{PINNED} AS production", "FROM build AS production")

  assert validate_dockerfile(candidate, "Dockerfile") == []


def test_latest_base_image_is_rejected():
  candidate = dockerfile().replace(f"alpine:3.21@{PINNED}", "alpine:latest")

  assert "latest_image" in codes(validate_dockerfile(candidate, "Dockerfile"))


def test_base_image_without_digest_is_rejected():
  candidate = dockerfile().replace(f"@{PINNED}", "")

  assert "unpinned_base_image" in codes(validate_dockerfile(candidate, "Dockerfile"))


def test_root_final_image_is_rejected():
  candidate = dockerfile().replace("USER 10001:10001\n", "USER root\n")

  assert "root_runtime" in codes(validate_dockerfile(candidate, "Dockerfile"))


def test_runtime_package_install_is_rejected():
  candidate = dockerfile("RUN apk add curl")

  assert "runtime_install" in codes(validate_dockerfile(candidate, "Dockerfile"))


def test_missing_image_healthcheck_is_rejected():
  candidate = dockerfile().replace('HEALTHCHECK CMD ["/bin/true"]\n', "")

  assert "missing_healthcheck" in codes(validate_dockerfile(candidate, "Dockerfile"))


def test_valid_production_compose_is_accepted():
  assert validate_compose(valid_compose()) == []


@pytest.mark.parametrize("service", ["mysql", "valkey"])
def test_state_service_public_port_is_rejected(service):
  candidate = valid_compose()
  candidate["services"][service]["ports"] = ["3306:3306"]

  assert "state_port_public" in codes(validate_compose(candidate))


def test_missing_service_healthcheck_is_rejected():
  candidate = valid_compose()
  del candidate["services"]["api"]["healthcheck"]

  assert "missing_healthcheck" in codes(validate_compose(candidate))


def test_literal_secret_is_rejected():
  candidate = valid_compose()
  candidate["services"]["api"]["environment"]["DB_PASSWORD"] = "do-not-commit-me"

  assert "literal_secret" in codes(validate_compose(candidate))


def test_runtime_install_command_is_rejected():
  candidate = valid_compose()
  candidate["services"]["worker"]["command"] = "composer install && php artisan queue:work"

  assert "runtime_install" in codes(validate_compose(candidate))


def test_runtime_image_pull_is_rejected():
  candidate = valid_compose()
  candidate["services"]["web"]["pull_policy"] = "always"

  assert "runtime_pull_enabled" in codes(validate_compose(candidate))


def test_bind_mount_is_rejected():
  candidate = valid_compose()
  candidate["services"]["api"]["volumes"] = ["./apps/api:/var/www/html"]

  assert "bind_mount" in codes(validate_compose(candidate))


def test_non_web_service_cannot_publish_ports():
  candidate = valid_compose()
  candidate["services"]["api"]["ports"] = ["9000:9000"]

  assert "unexpected_public_port" in codes(validate_compose(candidate))


def test_state_network_must_be_internal():
  candidate = valid_compose()
  candidate["networks"]["state"]["internal"] = False

  assert "state_network_not_internal" in codes(validate_compose(candidate))


def test_required_application_service_hardening_cannot_be_removed():
  candidate = valid_compose()
  del candidate["services"]["worker"]["read_only"]

  assert "missing_hardening" in codes(validate_compose(candidate))
