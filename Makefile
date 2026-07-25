.PHONY: verify-intake api\:inventory test-api-smoke test-web-smoke test-api test-web test-web-unit coverage-web lint-api analyse-api scan-secrets audit-dependencies test-e2e test-e2e-w1-1 test-w1-1-api-worker-smoke verify-boundaries verify-mysql-integration verify-w1-1 verify-w1-2 verify-w1-3 verify-day2 verify-day3 verify-screens check-day3-migrations validate-production-bundle build-production-images verify-production-images verify-w1-1-local deploy-vps

verify-intake:
	test -f apps/api/composer.lock
	test -f apps/web/package-lock.json
	composer --working-dir=apps/api validate --strict
	npm --prefix apps/web ci --ignore-scripts --dry-run

INVENTORY_MODE ?= --check

api\:inventory:
	python3 scripts/inventory-routes.py $(INVENTORY_MODE)

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

# MySQL integration suite (WalkingSkeleton + concurrency). Run against a real
# MySQL instance with the cluster_w12_test database. Skips with a clear
# message if no MySQL is reachable, so it stays a manual gate.
verify-mysql-integration:
	cd apps/api && php -r 'exit(extension_loaded("pdo_mysql") ? 0 : 1);' && \
		cd apps/api && vendor/bin/phpunit -c phpunit.mysql.xml || \
		echo 'pdo_mysql extension not loaded; verify-mysql-integration skipped.'

	cd apps/api && php artisan test tests/Architecture/ModuleBoundariesTest.php

# البوابة المحلية الكاملة: عقود، جودة، اختبارات، حدود، ورحلة E2E.
# التحقق من وجود وثائق المعمارية المطلوبة. الـ validation الكامل (frontmatter + catalog + nav)
# يعمل عبر scripts/validate-docs.sh لكن يحتاج mkdocs.yml و docs/catalog.yaml و frontmatter
# كامل لكل .md تحت docs/؛ هذا الـ target يضمن الحد الأدنى الذي يعتمد عليه الـ architecture test.
docs-validate:
	test -f docs/contracts/api/openapi.yaml
	test -f docs/contracts/api/w1-1.openapi.yaml
	test -f docs/contracts/api/w1-2.openapi.yaml
	test -f docs/contracts/api/r1-screens.openapi.yaml
	test -f docs/architecture/module-catalog.md

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
	python3 scripts/production_bundle_policy.py

build-production-images:
	./infra/platform/production/build-images.sh

verify-production-images:
	./infra/platform/production/verify-images.sh

verify-w1-1-local: validate-production-bundle build-production-images verify-production-images
	./infra/platform/production/run-local-e2e.sh

deploy-vps: validate-production-bundle
	./infra/platform/production/deploy-vps.sh
