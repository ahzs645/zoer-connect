.PHONY: build test lint test-package
build:
	python3 scripts/build.py
lint:
	php -l zoer-connect.php
	@for file in includes/*.php; do php -l "$$file" || exit 1; done
test: lint
	php tests/run.php
	php tests/storage.php
	php tests/transfer-storage.php
	php tests/auth.php
	php tests/connection-admin.php
	php tests/file-comparison.php
	php tests/path-characters.php
	php tests/selection.php
	php tests/export.php
	php tests/export-modes.php
	php tests/remote-export.php
	php tests/paged-export.php
	php tests/paged-export-empty.php
	php tests/export-blocks.php
	php tests/paged-database.php
	php tests/paged-maintenance.php
	php tests/database-export.php
	php tests/database-filters.php
	php tests/diagnostics.php
	php tests/related-replacement.php
	php tests/replacement-rules.php
	php tests/coordinator.php
	php tests/publication.php
	php tests/chunked-publication.php
	php tests/chunked-publication.php --batched
	php tests/chunked-failures.php
	php tests/created-directories.php
	php tests/peer-import.php
	php tests/snapshot-stream.php
	php tests/github-updater.php
	php tests/rewrite-refresh.php
	php tests/cache-purge.php
	php tests/write-fence.php
	php tests/cache-compatibility.php
	php -d disable_functions=link tests/cache-compatibility.php
	php tests/cache-auth.php
	php tests/transfer-import.php
	php -d disable_functions=link tests/transfer-import.php
	php tests/transfer-import-blocks.php
	php tests/batch-upload.php
	php -d disable_functions=inflate_init tests/deflate-availability.php
	php -d disable_functions=inflate_add tests/deflate-availability.php
	php tests/table-stage.php
	php tests/table-create.php
	php tests/import-options.php
	php tests/replace-kind.php
	php tests/import-fixes.php
	php tests/import-admin.php
	php tests/admin-transfers.php
	php tests/request-drain.php

# Creates ZIPs for development/prerelease checks; stable publication requires qualification.
test-package:
	python3 tests/release_check_test.py
	python3 tests/update_manifest_test.py
	python3 tests/build_test.py
