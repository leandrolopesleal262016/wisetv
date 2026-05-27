#!/usr/bin/env bash
set -euo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BRANCH="${DEPLOY_BRANCH:-$(git -C "$REPO_DIR" rev-parse --abbrev-ref HEAD)}"

cd "$REPO_DIR"
git pull --ff-only origin "$BRANCH"
"$REPO_DIR/deploy/bootstrap.sh"
docker compose up -d --build
docker compose ps
