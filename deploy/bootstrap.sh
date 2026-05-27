#!/usr/bin/env bash
set -euo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [ -f "$REPO_DIR/.env" ]; then
  set -a
  # shellcheck disable=SC1091
  . "$REPO_DIR/.env"
  set +a
fi

DATA_DIR="${APP_DATA_DIR:-$REPO_DIR/.docker-data}"

seed_dir_if_empty() {
  local source_dir="$1"
  local target_dir="$2"

  mkdir -p "$target_dir"
  if [ ! -d "$source_dir" ]; then
    return
  fi

  if [ -n "$(find "$target_dir" -mindepth 1 -print -quit 2>/dev/null)" ]; then
    return
  fi

  cp -a "$source_dir/." "$target_dir/"
}

seed_file_if_missing() {
  local source_file="$1"
  local target_file="$2"
  local default_json="$3"

  mkdir -p "$(dirname "$target_file")"
  if [ -f "$target_file" ]; then
    return
  fi

  if [ -f "$source_file" ]; then
    cp -a "$source_file" "$target_file"
    return
  fi

  printf '%s\n' "$default_json" > "$target_file"
}

mkdir -p \
  "$DATA_DIR/midias" \
  "$DATA_DIR/root/playlists" \
  "$DATA_DIR/root/status" \
  "$DATA_DIR/player/playlists" \
  "$DATA_DIR/player/status" \
  "$DATA_DIR/player/logs"

seed_dir_if_empty "$REPO_DIR/midias" "$DATA_DIR/midias"
seed_dir_if_empty "$REPO_DIR/playlists" "$DATA_DIR/root/playlists"
seed_dir_if_empty "$REPO_DIR/status" "$DATA_DIR/root/status"
seed_dir_if_empty "$REPO_DIR/player/playlists" "$DATA_DIR/player/playlists"
seed_dir_if_empty "$REPO_DIR/player/status" "$DATA_DIR/player/status"

seed_file_if_missing "$REPO_DIR/devices.json" "$DATA_DIR/root/devices.json" '{"devices":{}}'
seed_file_if_missing "$REPO_DIR/player/devices.json" "$DATA_DIR/player/devices.json" '{"devices":{}}'

echo "Bootstrap concluido em: $DATA_DIR"
