.PHONY: build test lint test-package
build:
	python3 scripts/build.py
lint:
	php -l zoer-connect.php
	@for file in includes/*.php; do php -l "$$file" || exit 1; done
test: lint
	php tests/run.php
	php tests/storage.php
	php tests/auth.php
	php tests/connection-admin.php
	php tests/file-comparison.php
	php tests/selection.php
	php tests/export.php
	php tests/remote-export.php
	php tests/paged-export.php
	php tests/database-export.php
	php tests/related-replacement.php
	php tests/coordinator.php
	php tests/publication.php
	php tests/chunked-publication.php
	php tests/chunked-failures.php
	php tests/peer-import.php
	php tests/snapshot-stream.php
	php tests/github-updater.php
	php tests/rewrite-refresh.php
	php tests/write-fence.php
	php tests/cache-compatibility.php
	php -d disable_functions=link tests/cache-compatibility.php
	php tests/cache-auth.php
	php tests/transfer-import.php
	php -d disable_functions=link tests/transfer-import.php
	php tests/transfer-import-blocks.php
	php tests/table-stage.php
	php tests/import-admin.php
	php tests/request-drain.php

# Run only after integration release checks pass; this target creates release ZIPs.
test-package:
	python3 tests/build_test.py
