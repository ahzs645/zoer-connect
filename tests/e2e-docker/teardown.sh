#!/usr/bin/env bash
# Removes containers, volumes and generated test secrets. The harness files stay.
set -euo pipefail
cd "$(dirname "$0")"
docker compose -f docker-compose.yml down -v --remove-orphans
rm -rf .state
