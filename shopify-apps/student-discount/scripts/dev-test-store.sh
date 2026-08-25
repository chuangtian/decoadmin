#!/bin/sh

set -eu

project_dir=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$project_dir"

if [ -f .env.local ]; then
  set -a
  # shellcheck disable=SC1091
  . ./.env.local
  set +a
fi

deco_student_store=${DECO_STUDENT_STORE:-macfoxebike.myshopify.com}

exec shopify app dev \
  --config local \
  --store "$deco_student_store" \
  --no-update
