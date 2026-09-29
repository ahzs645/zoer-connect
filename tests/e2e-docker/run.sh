#!/usr/bin/env bash
# Fresh fixture -> all driver scenarios -> teardown (set KEEP=1 to keep containers).
# DB_IMAGE selects the database server (default mariadb:11.4; e.g. mysql:8.0).
set -uo pipefail
cd "$(dirname "$0")"
export DB_IMAGE="${DB_IMAGE:-mariadb:11.4}"
./setup.sh || { echo "Setup failed"; [ "${KEEP:-0}" = 1 ] || ./teardown.sh; exit 1; }
python3 driver.py "$@"
status=$?
[ "${KEEP:-0}" = 1 ] || ./teardown.sh
exit $status
