.PHONY: verify-intake test-api-smoke test-web-smoke test-api test-web test-e2e test-e2e-w1-1 test-w1-1-api-worker-smoke verify-boundaries verify-ci-config verify-w1-1 test-unit-w11-ops-01 test-integration-w11-ops-01 test-e2e-w11-ops-01-local verify-host-inputs preflight-w11-ops-01-live verify-w11-ops-01-local validate-production-bundle build-production-images verify-production-images test-unit-w11-bld-02 test-integration-w11-bld-02 test-e2e-w11-bld-02-local verify-w11-bld-02-local

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
	npm --prefix apps/web run build
	npm --prefix apps/web run lint

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
	receipt=$$(mktemp); trap 'rm -f "$$receipt"' EXIT; python3 scripts/host_preflight.py validate --inputs infra/platform/environments/host.example.json --secrets infra/platform/contracts/required-secrets.json --receipt "$$receipt"

preflight-w11-ops-01-live:
	test -n "$(HOST_INPUTS)"
	test -f "$(HOST_INPUTS)"
	test -n "$(HOST_RECEIPT)"
	python3 scripts/host_preflight.py preflight --inputs "$(HOST_INPUTS)" --secrets infra/platform/contracts/required-secrets.json --receipt "$(HOST_RECEIPT)"

verify-boundaries:
	cd apps/api && php artisan test tests/Architecture/ModuleBoundariesTest.php

verify-ci-config:
	ruby -e "require 'yaml'; config = YAML.load_file('.gitlab-ci.yml'); required_stages = %w[validate build test verify]; abort('missing product CI stages') unless (required_stages - config.fetch('stages')).empty?; required_jobs = %w[validate-docs build-docs test-api test-web verify-boundaries verify-ci-config]; abort('missing required CI jobs') unless (required_jobs - config.keys).empty?"

verify-w1-1: verify-intake test-api test-web verify-boundaries verify-ci-config test-w1-1-api-worker-smoke test-e2e-w1-1

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
