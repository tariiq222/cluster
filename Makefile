.PHONY: verify-intake test-api-smoke test-web-smoke test-api test-web test-e2e test-e2e-w1-1 test-w1-1-api-worker-smoke verify-boundaries verify-ci-config verify-w1-1

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

verify-boundaries:
	cd apps/api && php artisan test tests/Architecture/ModuleBoundariesTest.php

verify-ci-config:
	ruby -e "require 'yaml'; config = YAML.load_file('.gitlab-ci.yml'); required_stages = %w[validate build test verify]; abort('missing product CI stages') unless (required_stages - config.fetch('stages')).empty?; required_jobs = %w[validate-docs build-docs test-api test-web verify-boundaries verify-ci-config]; abort('missing required CI jobs') unless (required_jobs - config.keys).empty?"

verify-w1-1: verify-intake test-api test-web verify-boundaries verify-ci-config test-w1-1-api-worker-smoke test-e2e-w1-1
