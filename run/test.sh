#!/usr/bin/env bash
set -euo pipefail

echo "🧪 Building and running WrapTest.php via dockerimages/phptestenv:php8.3 ..."

if ! command -v docker &>/dev/null; then
  echo "❌ Docker not found. Please install Docker first."
  exit 1
fi

# Build the custom PHP 8.3 image if needed
docker compose build --pull php

# Ensure clean environment and run only WrapTest.php
docker compose down --remove-orphans >/dev/null 2>&1 || true
docker compose run --rm php

echo "✅ WrapTest.php tests finished successfully."
