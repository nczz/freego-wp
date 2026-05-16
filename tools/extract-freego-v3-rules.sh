#!/usr/bin/env bash
set -euo pipefail

jar_path="${1:-/Applications/Freego.app/Contents/app/freego.jar}"

if [[ ! -f "$jar_path" ]]; then
  echo "freego.jar not found: $jar_path" >&2
  exit 1
fi

tmp_dir="$(mktemp -d)"
trap 'rm -rf "$tmp_dir"' EXIT

jar tf "$jar_path" \
  | rg '^checker/v3/.*C(_1)?\.class$' \
  | sed 's#/#.#g; s#\.class$##' \
  | sort \
  > "$tmp_dir/classes.txt"

printf "%s\t%s\t%s\t%s\t%s\n" "code" "level" "guideline" "web_id" "description"

while IFS= read -r class_name; do
  code="${class_name##*.}"
  case "${code:2:1}" in
    1) level="A" ;;
    2) level="AA" ;;
    3) level="AAA" ;;
    *) level="Aplus" ;;
  esac
  javap -classpath "$jar_path" -p -c "$class_name" > "$tmp_dir/javap.txt"
  sed -n "/public .*${code}();/,/return/p" "$tmp_dir/javap.txt" > "$tmp_dir/ctor.txt"
  guideline="$(rg -m 1 'String [0-9]\.[0-9]\.[0-9]' "$tmp_dir/ctor.txt" | sed -E 's/.*String ([0-9.]+).*/\1/' || true)"
  web_id="$(rg -m 1 'sipush +[0-9]+' "$tmp_dir/ctor.txt" | sed -E 's/.*sipush +([0-9]+).*/\1/' || true)"
  description="$(rg ' // String ' "$tmp_dir/ctor.txt" | sed -E 's/.*String //' | sed -n '3p' || true)"
  printf "%s\t%s\t%s\t%s\t%s\n" "$code" "$level" "$guideline" "$web_id" "$description"
done < "$tmp_dir/classes.txt"
