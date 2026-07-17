.PHONY: verify-intake test-api-smoke test-web-smoke test-api test-web test-web-unit coverage-web lint-api analyse-api scan-secrets audit-dependencies test-e2e test-e2e-w1-1 test-w1-1-api-worker-smoke verify-boundaries verify-ci-config verify-w1-1 verify-w1-1-local test-unit-w11-ops-01 test-integration-w11-ops-01 test-e2e-w11-ops-01-local verify-host-inputs preflight-w11-ops-01-live verify-w11-ops-01-local validate-production-bundle build-production-images verify-production-images test-unit-w11-bld-02 test-integration-w11-bld-02 test-e2e-w11-bld-02-local verify-w11-bld-02-local test-unit-w11-sc-03 test-integration-w11-sc-03 verify-build test-release-descriptor-contract verify-w11-sc-03-local test-unit-w11-net-04 test-integration-w11-net-04 verify-w11-net-04-local verify-w11-net-04-host test-unit-w11-dep-05 test-integration-w11-dep-05 verify-w11-dep-05-local test-unit-w11-dr-06 test-integration-w11-dr-06 verify-w11-dr-06-local test-unit-w11-gate-07 test-integration-w11-gate-07 verify-w11-gate-07-local verify-w1-1-host check-w1-1-live-inputs verify-w1-1-all

PYTHON ?= python3
W11_REVISION ?= $(shell git rev-parse HEAD 2>/dev/null)
NET04_POLICY ?=
NET04_COMPOSE ?= infra/platform/production/compose.yaml
LOCAL_NET04_POLICY ?= infra/platform/network/net04-network-policy.example.json

# Live evidence is intentionally unset. Supplying only part of this set must
# fail closed rather than silently falling back to checked-in placeholders.
HOST_INPUTS ?=
HOST_RECEIPT ?=
NET04_HOST_RECEIPT ?=
NET04_USER_RECEIPT ?=
NET04_MANAGEMENT_RECEIPT ?=
NET04_REVISION ?= $(W11_REVISION)
GATE_MANIFEST ?=
GATE_TRUST_POLICY ?=
GATE_RELEASE_ROOT ?=
GATE_EVIDENCE_ROOT ?=
GATE_RECEIPT ?=
GATE_AS_OF ?=
COSIGN_BINARY ?=
COSIGN_SHA256 ?=
COSIGN_VERSION ?=
RELEASE_DESCRIPTOR ?=
RELEASE_ROOT ?=
COSIGN_PUBLIC_KEY ?=

verify-intake:
	test -f apps/api/composer.lock
	test -f apps/web/package-lock.json
	composer --working-dir=apps/api validate --strict
	npm --prefix apps/web ci --ignore-scripts --dry-run

test-api-smoke:
	cd apps/api && composer test

test-web-smoke:
	npm --prefix apps/web run build
	! grep -Eq 'https?://|//[^/]' apps/web/index.html

test-api:
	cd apps/api && composer test

test-web:
	npm --prefix apps/web run api:check
	npm --prefix apps/web run build
	npm --prefix apps/web run lint
	npm --prefix apps/web run coverage

test-web-unit:
	npm --prefix apps/web run test:unit

coverage-web:
	npm --prefix apps/web run coverage

lint-api:
	composer --working-dir=apps/api lint

analyse-api:
	composer --working-dir=apps/api analyse

scan-secrets:
	gitleaks detect --source . --redact --no-banner

audit-dependencies:
	composer --working-dir=apps/api audit --locked
	npm --prefix apps/web audit --omit=dev

test-e2e: test-e2e-w1-1

test-w1-1-api-worker-smoke:
	./infra/dev/run-w1-1-api-worker-smoke.sh

test-e2e-w1-1:
	./infra/dev/run-w1-1-e2e.sh

test-unit-w11-ops-01:
	python3 -m pytest -q tests/ops/unit/test_host_input_validation.py

test-integration-w11-ops-01:
	python3 -m pytest -q tests/ops/integration/test_host_preflight_probes.py

test-e2e-w11-ops-01-local:
	python3 -m pytest -q tests/ops/e2e

verify-host-inputs:
	receipt=`mktemp`; trap 'rm -f "$$receipt"' EXIT; python3 scripts/host_preflight.py validate --inputs infra/platform/environments/host.example.json --secrets infra/platform/contracts/required-secrets.json --receipt "$$receipt"

preflight-w11-ops-01-live:
	test -n "$(HOST_INPUTS)"
	test -f "$(HOST_INPUTS)"
	test -n "$(HOST_RECEIPT)"
	python3 scripts/host_preflight.py preflight --inputs "$(HOST_INPUTS)" --secrets infra/platform/contracts/required-secrets.json --receipt "$(HOST_RECEIPT)"

verify-boundaries:
	cd apps/api && php artisan test tests/Architecture/ModuleBoundariesTest.php

verify-ci-config:
	ruby scripts/verify_ci_config.rb

verify-w1-1: verify-intake lint-api analyse-api scan-secrets audit-dependencies test-api test-web verify-boundaries verify-ci-config test-w1-1-api-worker-smoke test-e2e-w1-1

verify-w11-ops-01-local: test-unit-w11-ops-01 test-integration-w11-ops-01 test-e2e-w11-ops-01-local verify-host-inputs

validate-production-bundle:
	python3 scripts/production_bundle_policy.py

build-production-images:
	./infra/platform/production/build-images.sh

verify-production-images:
	./infra/platform/production/verify-images.sh

test-unit-w11-bld-02:
	python3 -m pytest -q tests/ops/unit/test_production_bundle_policy.py

test-integration-w11-bld-02: validate-production-bundle build-production-images verify-production-images
	python3 -m pytest -q tests/ops/integration/test_production_bundle_contract.py

test-e2e-w11-bld-02-local:
	./infra/platform/production/run-local-e2e.sh

verify-w11-bld-02-local: test-unit-w11-bld-02 test-integration-w11-bld-02 test-e2e-w11-bld-02-local

test-unit-w11-sc-03:
	python3 -m pytest -q tests/ops/unit/test_release_descriptor_sc03.py tests/ops/unit/test_release_descriptor_ci_sc03.py

test-integration-w11-sc-03:
	python3 -m pytest -q tests/ops/integration/test_release_descriptor_cli_sc03.py

verify-build:
	test -n "$(RELEASE_DESCRIPTOR)"
	test -f "$(RELEASE_DESCRIPTOR)"
	test -n "$(COSIGN_BINARY)"
	test -x "$(COSIGN_BINARY)"
	test -n "$(COSIGN_VERSION)"
	test -n "$(COSIGN_PUBLIC_KEY)"
	test -f "$(COSIGN_PUBLIC_KEY)"
	python3 scripts/release_descriptor.py verify "$(RELEASE_DESCRIPTOR)" --root "$${RELEASE_ROOT:-.}" --cosign-binary "$(COSIGN_BINARY)" --cosign-version "$(COSIGN_VERSION)" --cosign-public-key "$(COSIGN_PUBLIC_KEY)"

test-release-descriptor-contract:
	python3 -m pytest -q tests/ops/unit/test_release_descriptor_sc03.py tests/ops/unit/test_release_descriptor_ci_sc03.py tests/ops/integration/test_release_descriptor_cli_sc03.py

verify-w11-sc-03-local: test-unit-w11-sc-03 test-integration-w11-sc-03 test-release-descriptor-contract

# W11-NET-04: local policy/Compose checks and read-only live host/edge checks.
test-unit-w11-net-04:
	$(PYTHON) -m pytest -q tests/ops/unit/test_net04_network_policy.py

test-integration-w11-net-04:
	$(PYTHON) -m pytest -q tests/ops/integration/test_net04_live_exposure_verifier.py

verify-w11-net-04-local: test-unit-w11-net-04 test-integration-w11-net-04
	receipt=`mktemp`; trap 'rm -f "$$receipt"' EXIT; $(PYTHON) scripts/net04_network_policy.py validate --policy "$(LOCAL_NET04_POLICY)" --compose "$(NET04_COMPOSE)" --receipt "$$receipt"

verify-w11-net-04-host:
	test -n "$(NET04_POLICY)" || { echo 'NET04_POLICY is required' >&2; exit 2; }
	test -f "$(NET04_POLICY)" || { echo "NET04_POLICY does not exist: $(NET04_POLICY)" >&2; exit 2; }
	$(PYTHON) scripts/validate_live_net04_policy.py "$(NET04_POLICY)" infra/platform/network/net04-network-policy.example.json
	test -n "$(NET04_COMPOSE)" || { echo 'NET04_COMPOSE is required' >&2; exit 2; }
	test -f "$(NET04_COMPOSE)" || { echo "NET04_COMPOSE does not exist: $(NET04_COMPOSE)" >&2; exit 2; }
	test -n "$(NET04_HOST_RECEIPT)" || { echo 'NET04_HOST_RECEIPT is required' >&2; exit 2; }
	test -n "$(NET04_USER_RECEIPT)" || { echo 'NET04_USER_RECEIPT is required' >&2; exit 2; }
	test -n "$(NET04_MANAGEMENT_RECEIPT)" || { echo 'NET04_MANAGEMENT_RECEIPT is required' >&2; exit 2; }
	test -n "$(NET04_REVISION)" || { echo 'NET04_REVISION is required' >&2; exit 2; }
	$(PYTHON) scripts/net04_network_policy.py verify-host --policy "$(NET04_POLICY)" --compose "$(NET04_COMPOSE)" --receipt "$(NET04_HOST_RECEIPT)" --revision "$(NET04_REVISION)"
	$(PYTHON) scripts/net04_network_policy.py verify-edge --policy "$(NET04_POLICY)" --perspective user --receipt "$(NET04_USER_RECEIPT)" --revision "$(NET04_REVISION)"
	$(PYTHON) scripts/net04_network_policy.py verify-edge --policy "$(NET04_POLICY)" --perspective management --receipt "$(NET04_MANAGEMENT_RECEIPT)" --revision "$(NET04_REVISION)"

# W11-DEP-05: evidence contract tests are local; external Dokploy evidence is
# consumed by GATE-07 so no local fixture can be mistaken for acceptance.
test-unit-w11-dep-05:
	$(PYTHON) -m pytest -q tests/ops/unit/test_dep_dr_evidence_contracts.py -k 'release or dry_run'

test-integration-w11-dep-05:
	$(PYTHON) -m pytest -q tests/ops/integration/test_w1_1_acceptance_gate.py -k 'release or deployment or gate_consumes'

verify-w11-dep-05-local: test-unit-w11-dep-05 test-integration-w11-dep-05

# W11-DR-06: encrypted backup/separate-restore contract tests are local.
test-unit-w11-dr-06:
	$(PYTHON) -m pytest -q tests/ops/unit/test_dep_dr_evidence_contracts.py -k 'dr or restore or external_commands'

test-integration-w11-dr-06:
	$(PYTHON) -m pytest -q tests/ops/integration/test_w1_1_acceptance_gate.py -k 'gate_consumes_verified_raw_artifacts or wrong_key_role_revision'

verify-w11-dr-06-local: test-unit-w11-dr-06 test-integration-w11-dr-06

# W11-GATE-07: the local suite validates rejection/assembly semantics only.
test-unit-w11-gate-07:
	$(PYTHON) -m pytest -q tests/ops/unit/test_signed_evidence.py tests/ops/unit/test_dep_dr_evidence_contracts.py

test-integration-w11-gate-07:
	$(PYTHON) -m pytest -q tests/ops/integration/test_w1_1_acceptance_gate.py tests/ops/integration/test_signed_evidence_cli.py

verify-w11-gate-07-local: test-unit-w11-gate-07 test-integration-w11-gate-07

verify-w1-1-local: verify-w11-ops-01-local verify-w11-bld-02-local verify-w11-sc-03-local verify-w11-net-04-local verify-w11-dep-05-local verify-w11-dr-06-local verify-w11-gate-07-local

# Live W1.1 host path: host preflight and all three NET-04 perspectives.
verify-w1-1-host: preflight-w11-ops-01-live verify-w11-net-04-host

# Live W1.1 aggregate. Every external input is mandatory; no checked-in
# placeholder, dry-run plan, or unsigned receipt is accepted implicitly.
check-w1-1-live-inputs:
	test -n "$(HOST_INPUTS)" || { echo 'HOST_INPUTS is required' >&2; exit 2; }
	test -f "$(HOST_INPUTS)" || { echo "HOST_INPUTS does not exist: $(HOST_INPUTS)" >&2; exit 2; }
	test -n "$(HOST_RECEIPT)" || { echo 'HOST_RECEIPT is required' >&2; exit 2; }
	test -n "$(NET04_POLICY)" || { echo 'NET04_POLICY is required' >&2; exit 2; }
	test -f "$(NET04_POLICY)" || { echo "NET04_POLICY does not exist: $(NET04_POLICY)" >&2; exit 2; }
	$(PYTHON) scripts/validate_live_net04_policy.py "$(NET04_POLICY)" infra/platform/network/net04-network-policy.example.json
	test -n "$(NET04_COMPOSE)" || { echo 'NET04_COMPOSE is required' >&2; exit 2; }
	test -f "$(NET04_COMPOSE)" || { echo "NET04_COMPOSE does not exist: $(NET04_COMPOSE)" >&2; exit 2; }
	test -n "$(NET04_HOST_RECEIPT)" || { echo 'NET04_HOST_RECEIPT is required' >&2; exit 2; }
	test -n "$(NET04_USER_RECEIPT)" || { echo 'NET04_USER_RECEIPT is required' >&2; exit 2; }
	test -n "$(NET04_MANAGEMENT_RECEIPT)" || { echo 'NET04_MANAGEMENT_RECEIPT is required' >&2; exit 2; }
	test -n "$(W11_REVISION)" || { echo 'W11_REVISION is required' >&2; exit 2; }
	printf '%s\n' "$(W11_REVISION)" | grep -Eq '^[0-9a-f]{40}$$' || { echo 'W11_REVISION must be a full 40-character lowercase Git SHA' >&2; exit 2; }
	test -n "$(NET04_REVISION)" || { echo 'NET04_REVISION is required' >&2; exit 2; }
	printf '%s\n' "$(NET04_REVISION)" | grep -Eq '^[0-9a-f]{40}$$' || { echo 'NET04_REVISION must be a full 40-character lowercase Git SHA' >&2; exit 2; }
	test "$(NET04_REVISION)" = "$(W11_REVISION)" || { echo 'NET04_REVISION must match W11_REVISION' >&2; exit 2; }
	test -n "$(RELEASE_DESCRIPTOR)" || { echo 'RELEASE_DESCRIPTOR is required' >&2; exit 2; }
	test -f "$(RELEASE_DESCRIPTOR)" || { echo "RELEASE_DESCRIPTOR does not exist: $(RELEASE_DESCRIPTOR)" >&2; exit 2; }
	test -n "$(RELEASE_ROOT)" || { echo 'RELEASE_ROOT is required' >&2; exit 2; }
	test -d "$(RELEASE_ROOT)" || { echo "RELEASE_ROOT does not exist: $(RELEASE_ROOT)" >&2; exit 2; }
	test -n "$(GATE_MANIFEST)" || { echo 'GATE_MANIFEST is required' >&2; exit 2; }
	test -f "$(GATE_MANIFEST)" || { echo "GATE_MANIFEST does not exist: $(GATE_MANIFEST)" >&2; exit 2; }
	test -n "$(GATE_TRUST_POLICY)" || { echo 'GATE_TRUST_POLICY is required' >&2; exit 2; }
	test -f "$(GATE_TRUST_POLICY)" || { echo "GATE_TRUST_POLICY does not exist: $(GATE_TRUST_POLICY)" >&2; exit 2; }
	test -n "$(GATE_RELEASE_ROOT)" || { echo 'GATE_RELEASE_ROOT is required' >&2; exit 2; }
	test -d "$(GATE_RELEASE_ROOT)" || { echo "GATE_RELEASE_ROOT does not exist: $(GATE_RELEASE_ROOT)" >&2; exit 2; }
	test -n "$(GATE_EVIDENCE_ROOT)" || { echo 'GATE_EVIDENCE_ROOT is required' >&2; exit 2; }
	test -d "$(GATE_EVIDENCE_ROOT)" || { echo "GATE_EVIDENCE_ROOT does not exist: $(GATE_EVIDENCE_ROOT)" >&2; exit 2; }
	test -n "$(GATE_RECEIPT)" || { echo 'GATE_RECEIPT is required' >&2; exit 2; }
	test -n "$(COSIGN_BINARY)" || { echo 'COSIGN_BINARY is required' >&2; exit 2; }
	test -x "$(COSIGN_BINARY)" || { echo "COSIGN_BINARY is not executable: $(COSIGN_BINARY)" >&2; exit 2; }
	test -n "$(COSIGN_SHA256)" || { echo 'COSIGN_SHA256 is required' >&2; exit 2; }
	printf '%s\n' "$(COSIGN_SHA256)" | grep -Eq '^[0-9a-f]{64}$$' || { echo 'COSIGN_SHA256 must be exactly 64 lowercase hexadecimal characters' >&2; exit 2; }
	test "$(COSIGN_SHA256)" != "$(shell printf '%064d' 0)" || { echo 'COSIGN_SHA256 must be a captured digest, not a placeholder' >&2; exit 2; }
	test -n "$(COSIGN_VERSION)" || { echo 'COSIGN_VERSION is required' >&2; exit 2; }
	test -n "$(COSIGN_PUBLIC_KEY)" || { echo 'COSIGN_PUBLIC_KEY is required' >&2; exit 2; }
	test -f "$(COSIGN_PUBLIC_KEY)" || { echo "COSIGN_PUBLIC_KEY does not exist: $(COSIGN_PUBLIC_KEY)" >&2; exit 2; }
	test -n "$(GATE_AS_OF)" || { echo 'GATE_AS_OF is required' >&2; exit 2; }

verify-w1-1-all: check-w1-1-live-inputs
	$(MAKE) verify-w1-1-local
	$(MAKE) verify-build
	$(MAKE) verify-w1-1-host
	$(PYTHON) scripts/w1_1_acceptance_gate.py --manifest "$(GATE_MANIFEST)" --trust-policy "$(GATE_TRUST_POLICY)" --release-root "$(GATE_RELEASE_ROOT)" --evidence-root "$(GATE_EVIDENCE_ROOT)" --receipt "$(GATE_RECEIPT)" --cosign-binary "$(COSIGN_BINARY)" --cosign-sha256 "$(COSIGN_SHA256)" --cosign-version "$(COSIGN_VERSION)" --as-of "$(GATE_AS_OF)"
