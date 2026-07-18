.PHONY: verify-intake test-api-smoke test-web-smoke test-api test-web test-web-unit coverage-web lint-api analyse-api scan-secrets audit-dependencies test-e2e test-e2e-w1-1 test-w1-1-api-worker-smoke verify-boundaries verify-w1-1 verify-w1-2 verify-w1-3 validate-production-bundle build-production-images verify-production-images verify-w1-1-local deploy-vps

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

verify-boundaries:
	cd apps/api && php artisan test tests/Architecture/ModuleBoundariesTest.php

# البوابة المحلية الكاملة: عقود، جودة، اختبارات، حدود، ورحلة E2E.
verify-w1-1: verify-intake lint-api analyse-api scan-secrets audit-dependencies test-api test-web verify-boundaries test-w1-1-api-worker-smoke test-e2e-w1-1

# بوابة W1.2: عقود الجاهزية ثم حدود الموديولات واختبارات التطبيق المتأثرة.
verify-w1-2:
	./scripts/validate-docs.sh
	$(MAKE) verify-boundaries
	$(MAKE) test-api
	$(MAKE) test-web

# بوابة W1.3: قرار Authorization والعزل والحدود وواجهة الإدارة ورحلة المتصفح الحقيقية.
verify-w1-3:
	cd apps/api && php artisan test Modules/Authorization/Tests Modules/Organization/Tests/SupervisoryRelationshipTest.php
	$(MAKE) verify-boundaries
	npm --prefix apps/web run test:unit -- src/shell/routes.test.ts src/api/w1-3/authorization.test.ts src/api.test.ts src/w1-2-api.test.ts
	npm --prefix apps/web run lint
	npm --prefix apps/web run build
	./infra/dev/run-w1-3-e2e.sh

# حزمة الإنتاج: بناء صور الإنتاج من lockfiles وتشغيلها بحزمة Compose كاملة.
validate-production-bundle:
	python3 scripts/production_bundle_policy.py

build-production-images:
	./infra/platform/production/build-images.sh

verify-production-images:
	./infra/platform/production/verify-images.sh

verify-w1-1-local: validate-production-bundle build-production-images verify-production-images
	./infra/platform/production/run-local-e2e.sh

deploy-vps: validate-production-bundle
	./infra/platform/production/deploy-vps.sh
