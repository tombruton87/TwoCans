# TwoCans — a tiny phone company, run by you.
#
# Thin wrappers over `docker compose` so one command brings the whole line up.
# Most of the time you only need `make up` (and `make migrate` after an update).

COMPOSE := docker compose
-include .env

.PHONY: help install update build up down restart logs status migrate password passwords reset-owner backup backups clean

help: ## List available commands
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-11s\033[0m %s\n", $$1, $$2}'

install: ## Set up: check software, ports and firewall, ask a few questions, start
	./install.sh

update: ## Update to the latest version, keeping your settings
	./twocans update

build: ## Build the twocans images from source and start the stack
	$(COMPOSE) -f compose.yaml -f compose.build.yml up -d --build

up: ## Start the whole stack (keeps your data)
	$(COMPOSE) up -d

down: ## Stop the stack (data volumes are kept)
	$(COMPOSE) down

restart: ## Restart the stack
	$(COMPOSE) restart

logs: ## Follow logs from every service
	./twocans logs all

status: ## Is everything working? Containers, phones, the line, backups
	./twocans status

migrate: ## Apply database schema changes (safe to re-run)
	$(COMPOSE) exec web php /var/www/html/bin/migrate.php

password: ## Set/reset a guardian password (prompts): make password EMAIL=you@home.co
	@test -n "$(EMAIL)" || (echo "Usage: make password EMAIL=you@home.co" >&2; exit 1)
	$(COMPOSE) exec -it web php /var/www/html/bin/set-password.php "$(EMAIL)"

reset-owner: ## Reset the Owner account (password, email, passkeys, sign out everywhere)
	./twocans reset-owner

passwords: ## List guardians and whether each has a password
	$(COMPOSE) exec web php /var/www/html/bin/set-password.php --list

backup: ## Create a backup of the database, recordings and photos
	./twocans backup

backups: ## List existing backups
	./twocans backups

clean: ## Stop and remove the stack's containers (volumes are kept)
	$(COMPOSE) down --remove-orphans
