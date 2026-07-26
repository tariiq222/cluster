.PHONY: verify-intake python-bin api\:inventory api\:check test-api-smoke test-web-smoke test-api test-web test-web-unit coverage-web lint-api analyse-api scan-secrets npm-audit audit-dependencies test-e2e test-e2e-w1-1 test-w1-1-api-worker-smoke verify-boundaries verify-mysql-integration docs-validate docs-validate-fast help verify-w1-1 verify-w1-2 verify-w1-3 verify-day2 verify-day3 verify-screens check-day3-migrations validate-production-bundle build-production-images verify-production-images verify-w1-1-local deploy-vps

verify-intake:
	test -f apps/api/composer.lock
	test -f apps/web/package-lock.json
	composer --working-dir=apps/api validate --strict
	npm --prefix apps/web ci --ignore-scripts --dry-run

INVENTORY_MODE ?= --check
PYTHON_BINARY ?= $(shell command -v python3 2>/dev/null || command -v python 2>/dev/null)
DOCS_VALIDATOR := scripts/validate-docs.sh

ifeq ($(strip $(PYTHON_BINARY)),)
PYTHON_BINARY := $(shell command -v python3 2>/dev/null || command -v python 2>/dev/null)
endif

python-bin:
	@version="$$($(PYTHON_BINARY) -c 'import sys; print("{}.{}".format(*sys.version_info[:2]))' 2>/dev/null)" || { \
		printf '%s\n' 'ERROR: no working Python interpreter found; set PYTHON_BINARY.' >&2; \
		exit 2; \
	}; \
	case "$$version" in \
		3.*) printf '%s %s\n' "$(PYTHON_BINARY)" "$$version" ;; \
		*) printf 'ERROR: PYTHON_BINARY must resolve to Python 3, got %s.\n' "$$version" >&2; exit 2 ;; \
	esac

api\:inventory:
	$(PYTHON_BINARY) scripts/inventory-routes.py $(INVENTORY_MODE)

api\:check:
	npm --prefix apps/web run api:check

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

npm-audit:
	npm --prefix apps/web audit --omit=dev

audit-dependencies: npm-audit
	composer --working-dir=apps/api audit --locked

test-e2e: test-e2e-w1-1

test-w1-1-api-worker-smoke:
	./infra/dev/run-w1-1-api-worker-smoke.sh

test-e2e-w1-1:
	./infra/dev/run-w1-1-e2e.sh

verify-boundaries:
	cd apps/api && php artisan test tests/Architecture/ModuleBoundariesTest.php

# MySQL integration suite (WalkingSkeleton + concurrency). Environmental
# prerequisites are reported as a skip; a started runner that fails is a gate
# failure so CI never mistakes test failures for an unavailable local service.
verify-mysql-integration:
	@if ! command -v docker >/dev/null 2>&1; then \
		printf '%s\n' 'SKIP: verify-mysql-integration prereq missing: docker.'; \
		exit 0; \
	fi; \
	if ! (cd apps/api && php -r 'exit(extension_loaded("pdo_mysql") ? 0 : 1);'); then \
		printf '%s\n' 'SKIP: verify-mysql-integration prereq missing: pdo_mysql.'; \
		exit 0; \
	fi; \
	if [ ! -x scripts/run-mysql-integration-tests.sh ]; then \
		printf '%s\n' 'SKIP: verify-mysql-integration prereq missing: runner script scripts/run-mysql-integration-tests.sh.'; \
		exit 0; \
	fi; \
	if ! scripts/run-mysql-integration-tests.sh; then \
		printf '%s\n' 'ERROR: verify-mysql-integration runner failed; failing the gate.' >&2; \
		exit 1; \
	fi

# Documentation validation is deliberately strict about the validator runtime.
# The current lean docs tree has no catalog or MkDocs registry.
docs-validate:
	@failed=0; \
	if [ ! -x "$(DOCS_VALIDATOR)" ]; then \
		printf '%s\n' 'ERROR: scripts/validate-docs.sh is missing.' >&2; \
		failed=1; \
	fi; \
	if ! "$(PYTHON_BINARY)" -c 'import sys; raise SystemExit(0 if sys.version_info >= (3, 0) else 1)' >/dev/null 2>&1; then \
		printf '%s\n' 'ERROR: python3 is missing or PYTHON_BINARY is not Python 3.' >&2; \
		failed=1; \
	fi; \
	if ! "$(PYTHON_BINARY)" -c 'import yaml' >/dev/null 2>&1; then \
		printf '%s\n' 'ERROR: PyYAML is missing; install the documentation validator dependency.' >&2; \
		failed=1; \
	fi; \
	if [ "$$failed" -ne 0 ]; then exit 2; fi; \
	PYTHON_BINARY="$(PYTHON_BINARY)" "$(DOCS_VALIDATOR)"

docs-validate-fast: docs-validate

help:
	@printf '%s\n' \
		'Public CI gates:' \
		'  verify-intake              Validate locked dependency intake.' \
		'  api:check                  Check generated API contracts.' \
		'  docs-validate              Validate documentation contracts.' \
		'  docs-validate-fast         Strict alias for docs-validate.' \
		'  verify-boundaries          Run module architecture boundaries.' \
		'  verify-mysql-integration   Run the isolated MySQL integration suite.' \
		'  npm-audit                  Audit production web dependencies.' \
		'  audit-dependencies         Audit API and web dependencies.' \
		'  python-bin                 Print the resolved Python 3 binary.'
# البوابة المحلية الكاملة: عقود، جودة، اختبارات، حدود، ورحلة E2E.
verify-w1-1: verify-intake lint-api analyse-api scan-secrets audit-dependencies docs-validate test-api test-web verify-boundaries test-w1-1-api-worker-smoke test-e2e-w1-1

# بوابة W1.2: عقود الجاهزية ثم حدود الموديولات واختبارات التطبيق المتأثرة.
verify-w1-2:
	$(MAKE) verify-boundaries
	$(MAKE) test-api
	$(MAKE) test-web

# بوابة W1.3: قرار Authorization والعزل والحدود وواجهة الإدارة ورحلة المتصفح الحقيقية.
verify-w1-3:
	cd apps/api && php artisan test Modules/Authorization/Tests Modules/Organization/Tests/SupervisoryRelationshipTest.php Modules/Identity/Tests/ScopeSelectionHttpAdapterTest.php
	cd apps/api && php artisan test tests/Feature/SecurityJourneyW13Test.php tests/Feature/ProductionAuthorizationBindingTest.php
	$(MAKE) verify-boundaries
	npm --prefix apps/web run test:unit -- src/shell/routes.test.ts src/api/w1-3/authorization.test.ts src/api.test.ts src/w1-2-api.test.ts
	npm --prefix apps/web run lint
	npm --prefix apps/web run build
	./infra/dev/run-w1-3-e2e.sh

# بوابة اليوم الثاني: W1.4–W1.7 من التعريف المنشور إلى الطلب والمسار والمهمة.
verify-day2:
	cd apps/api && php artisan test tests/Feature/Day2HttpVerticalTest.php Modules/WorkDefinitions/Features/PublishRequestFixture/Tests Modules/Workflow/Tests Modules/Tasks/Tests tests/Architecture/ModuleBoundariesTest.php
	$(MAKE) test-api
	composer --working-dir=apps/api lint
	composer --working-dir=apps/api analyse -- --memory-limit=512M
	npm --prefix apps/web run test:unit -- src/api/day2.test.ts src/shell/routes.test.ts
	npm --prefix apps/web run lint
	npm --prefix apps/web run build
	./infra/dev/run-day2-e2e.sh

check-day3-migrations:
	php scripts/check-day3-migrations.php

# بوابة اليوم الثالث: W1.8–W1.10 وإكمال R1 من المستند إلى البحث والتقرير واللوحة.
verify-day3: check-day3-migrations
	cd apps/api && php artisan test Modules/Documents/Tests Modules/Notifications/Features/ConsumeWorkRecordSubmitted/Tests Modules/Notifications/Features/ListMyNotifications/Tests Modules/Search/Tests Modules/Reporting/Tests tests/Feature/Day2HttpVerticalTest.php tests/Architecture/ModuleBoundariesTest.php
	$(MAKE) test-api
	composer --working-dir=apps/api lint
	composer --working-dir=apps/api analyse -- --memory-limit=512M
	npm --prefix apps/web run test:unit -- src/api/day2.test.ts src/shell/routes.test.ts
	npm --prefix apps/web run lint
	npm --prefix apps/web run build
	./infra/dev/run-day3-e2e.sh

# بوابة إغلاق شاشات R1: العقد المولّد، API، الويب، وجميع رحلات المتصفح القائمة والجديدة.
verify-screens: verify-day3
	test ! -e apps/web/src/api/day2.ts
	test ! -e apps/web/src/api/w1-3/authorization.ts

# حزمة الإنتاج: بناء صور الإنتاج من lockfiles وتشغيلها بحزمة Compose كاملة.
validate-production-bundle:
	$(PYTHON_BINARY) scripts/production_bundle_policy.py

build-production-images:
	./infra/platform/production/build-images.sh

verify-production-images:
	./infra/platform/production/verify-images.sh

verify-w1-1-local: validate-production-bundle build-production-images verify-production-images
	./infra/platform/production/run-local-e2e.sh

deploy-vps: validate-production-bundle
	./infra/platform/production/deploy-vps.sh
