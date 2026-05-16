#!/usr/bin/env bash
set -euo pipefail

root_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
dist_dir="$root_dir/dist"
stage_dir="$dist_dir/freego-wp"
zip_path="$dist_dir/freego-wp.zip"

rm -rf "$dist_dir"
mkdir -p "$stage_dir"

rsync -a \
  --exclude='.git' \
  --exclude='.github' \
  --exclude='.gitignore' \
  --exclude='.distignore' \
  --exclude='dist' \
  --exclude='build' \
  --exclude='coverage' \
  --exclude='scripts' \
  --exclude='tests' \
  --exclude='tools' \
  --exclude='node_modules' \
  --exclude='vendor' \
  --exclude='*.log' \
  --exclude='*.zip' \
  "$root_dir/" "$stage_dir/"

(
  cd "$dist_dir"
  zip -qr "$zip_path" freego-wp
)

echo "$zip_path"
