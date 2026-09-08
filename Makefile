.PHONY: build test lint
build:
	python3 scripts/build.py
lint:
	php -l zoer-connect.php
	php -l includes/Plugin.php
	php -l includes/StageStore.php
test: lint
	php tests/run.php
	php tests/auth.php
	python3 tests/build_test.py
