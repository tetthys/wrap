#!/usr/bin/env bash
set -euo pipefail

echo "🧪 Building and running tetthys/wrap tests in dockerimages/phptestenv:php8.3 ..."

# Ensure Docker is installed
if ! command -v docker &>/dev/null; then
  echo "❌ Docker not found. Please install Docker first."
  exit 1
fi

# Rebuild the image only if changed
docker compose build --pull php

# Clean up previous containers and run tests
docker compose down --remove-orphans >/dev/null 2>&1 || true
docker compose run --rm php

echo "✅ All tests finished successfully."
