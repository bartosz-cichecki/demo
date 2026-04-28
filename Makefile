.PHONY: help install cs-check cs-fix phpstan deptrac deptrac-ci shell qa qa-fix test behat \
        up-prod up-prod-build down-prod logs-prod shell-prod \
        db-validate db-create db-drop migrations-status migrations-diff-client migrations-migrate-client \
        migrations-diff-user migrations-migrate-user

COMPOSE_DEV = docker compose -p demo-dev -f compose.yaml -f compose.dev.override.yaml
COMPOSE_PROD = docker compose -p demo-prod -f compose.yaml -f compose.prod.override.yaml
DEMO_HTTP_PORT ?= 8082

DOCKER_COMPOSE = $(COMPOSE_DEV)
PHP_CONTAINER = php
EXEC_PHP = $(DOCKER_COMPOSE) exec $(PHP_CONTAINER)
EXEC_PHP_PROD = $(COMPOSE_PROD) exec $(PHP_CONTAINER)

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

up: ## Start containers (no rebuild)
	$(DOCKER_COMPOSE) up -d

up-build: ## Start containers (build if needed)
	$(DOCKER_COMPOSE) up -d --build

down: ## Stop containers
	$(DOCKER_COMPOSE) down

install: ## Install composer dependencies
	$(EXEC_PHP) composer install

cs-check: ## Check code style (dry-run)
	$(EXEC_PHP) composer cs:check

cs-fix: ## Fix code style
	$(EXEC_PHP) composer cs:fix

phpstan: ## Run PHPStan (level max)
	$(EXEC_PHP) sh -lc 'cd /var/www/app && vendor/bin/phpstan analyse -c phpstan.neon.dist'

deptrac: ## Run Deptrac
	$(EXEC_PHP) sh -lc 'cd /var/www/app && vendor/bin/deptrac analyse'

deptrac-ci: ## Run Deptrac (CI mode, fails on uncovered)
	$(EXEC_PHP) sh -lc 'cd /var/www/app && vendor/bin/deptrac analyse --report-uncovered --no-interaction'

smoke: ## Smoke tests (console about + health endpoints)
	$(EXEC_PHP) sh -lc 'cd /var/www/app && php bin/console about'
	@curl -fsS http://localhost:$(DEMO_HTTP_PORT)/health >/dev/null && echo "OK /health"
	@curl -fsS http://localhost:$(DEMO_HTTP_PORT)/api/health >/dev/null && echo "OK /api/health"

health: ## Check health endpoints only
	@curl -i http://localhost:$(DEMO_HTTP_PORT)/health
	@echo
	@curl -i http://localhost:$(DEMO_HTTP_PORT)/api/health

logs: ## Tail logs
	$(DOCKER_COMPOSE) logs -f

shell: ## Open shell in PHP container
	$(EXEC_PHP) bash

cc: ## Clear cache (dev)
	$(EXEC_PHP) sh -lc 'cd /var/www/app && bin/console ca:cl'

cc-test: ## Clear cache (test)
	$(EXEC_PHP) sh -lc 'cd /var/www/app && bin/console ca:cl --env=test'

qa: ## Run full quality gate (cs-check + phpstan + deptrac-ci)
	$(MAKE) cs-check
	$(MAKE) phpstan
	$(MAKE) deptrac-ci

qa-fix: ## Fix code style, then run full quality gate
	$(MAKE) cs-fix
	$(MAKE) qa

test: ## Run PHPUnit tests
	$(EXEC_PHP) sh -lc 'cd /var/www/app && vendor/bin/phpunit'

behat: ## Run Behat tests
	$(EXEC_PHP) sh -lc 'cd /var/www/app && php bin/console doctrine:migrations:migrate --no-interaction --env=test'
	$(EXEC_PHP) sh -lc 'cd /var/www/app && vendor/bin/behat -c behat.yml'

# Pseudo-prod targets
up-prod: ## Start pseudo-prod containers (no rebuild)
	$(COMPOSE_PROD) up -d

up-prod-build: ## Start pseudo-prod containers (build if needed)
	$(COMPOSE_PROD) up -d --build

down-prod: ## Stop pseudo-prod containers
	$(COMPOSE_PROD) down

logs-prod: ## Tail pseudo-prod logs
	$(COMPOSE_PROD) logs -f

shell-prod: ## Open shell in pseudo-prod PHP container
	$(EXEC_PHP_PROD) bash

# Database / Doctrine targets
db-validate: ## Validate Doctrine schema
	$(EXEC_PHP) sh -lc 'cd /var/www/app && php bin/console doctrine:schema:validate'

db-create: ## Create database
	$(EXEC_PHP) sh -lc 'cd /var/www/app && php bin/console doctrine:database:create --if-not-exists'

db-drop: ## Drop database (use with caution)
	$(EXEC_PHP) sh -lc 'cd /var/www/app && php bin/console doctrine:database:drop --force --if-exists'

migrations-status: ## Show migrations status
	$(EXEC_PHP) sh -lc 'cd /var/www/app && php bin/console doctrine:migrations:status'

migrations-diff-client: ## Generate migration for Client context
	$(EXEC_PHP) sh -lc 'cd /var/www/app && php bin/console doctrine:migrations:diff --namespace="App\\Client\\Infrastructure\\Resource\\Migrations"'

migrations-migrate-client: ## Run migrations for Client context
	$(EXEC_PHP) sh -lc 'cd /var/www/app && php bin/console doctrine:migrations:migrate --no-interaction'

migrations-diff-user: ## Generate migration for User context
	$(EXEC_PHP) sh -lc 'cd /var/www/app && php bin/console doctrine:migrations:diff --namespace="App\\User\\Infrastructure\\Resource\\Migrations"'

migrations-migrate-user: ## Run migrations for User context
	$(EXEC_PHP) sh -lc 'cd /var/www/app && php bin/console doctrine:migrations:migrate --no-interaction'
