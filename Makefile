# TwoCans — a tiny phone company, run by you.
#
# Thin wrappers over `docker compose` so one command brings the whole line up.
# Most of the time you only need `make up` (and `make migrate` after an update).

COMPOSE := docker compose
-include .env

.PHONY: help install up down restart logs status migrate password passwords backup backups clean

help: ## List available commands
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-11s\033[0m %s\n", $$1, $$2}'

install: ## First-time setup: check prerequisites, write .env, then start
	./install.sh

up: ## Start the whole stack (keeps your data)
	$(COMPOSE) up -d

down: ## Stop the stack (data volumes are kept)
	$(COMPOSE) down

restart: ## Restart the stack
	$(COMPOSE) restart

logs: ## Follow logs from every service
	$(COMPOSE) logs -f --tail=200

status: ## Show container health
	$(COMPOSE) ps

migrate: ## Apply database schema changes (safe to re-run)
	$(COMPOSE) exec php php /var/www/html/bin/migrate.php

password: ## Set/reset a guardian password (prompts): make password EMAIL=you@home.co
	@test -n "$(EMAIL)" || (echo "Usage: make password EMAIL=you@home.co" >&2; exit 1)
	$(COMPOSE) exec -it php php /var/www/html/bin/set-password.php "$(EMAIL)"

passwords: ## List guardians and whether each has a password
	$(COMPOSE) exec php php /var/www/html/bin/set-password.php --list

backup: ## Create a backup of the database, recordings and photos
	$(COMPOSE) exec php php /var/www/html/bin/backup.php

backups: ## List existing backups
	$(COMPOSE) exec php php /var/www/html/bin/backup.php --list

clean: ## Stop and remove the stack's containers (volumes are kept)
	$(COMPOSE) down --remove-orphans
