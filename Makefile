.PHONY: build test lint
build:
	python3 scripts/build.py
lint:
	php -l zoer-connect.php
	php -l includes/Plugin.php
	php -l includes/StageStore.php
	php -l includes/TableStage.php
	php -l includes/FilePublication.php
test: lint
	php tests/run.php
	php tests/auth.php
	php tests/coordinator.php
	php tests/publication.php
	python3 tests/build_test.py
