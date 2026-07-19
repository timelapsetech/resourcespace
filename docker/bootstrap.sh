#!/usr/bin/env bash
# Prepare host files for a first Docker run on this Mac.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

echo "==> ResourceSpace Docker bootstrap"

if [[ ! -f .env ]]; then
  cp .env.example .env
  echo "    created .env — edit FILESTORE_HOST_PATH / ORIGINALS_HOST_PATH"
else
  echo "    .env already exists"
fi

if [[ ! -f docker/db.env ]]; then
  cp docker/db.env.example docker/db.env
  echo "    created docker/db.env — change MYSQL_* passwords"
else
  echo "    docker/db.env already exists"
fi

if [[ ! -f docker/config.php ]]; then
  cp docker/config.php.example docker/config.php
  if command -v openssl >/dev/null 2>&1; then
    sk="$(openssl rand -hex 16)"
    ak="$(openssl rand -hex 16)"
    sp="$(openssl rand -hex 12)"
    sed -i.bak "s/\$scramble_key = 'REPLACE_WITH_openssl_rand_hex_16';/\$scramble_key = '${sk}';/" docker/config.php
    sed -i.bak "s/\$api_scramble_key = 'REPLACE_WITH_openssl_rand_hex_16';/\$api_scramble_key = '${ak}';/" docker/config.php
    sed -i.bak "s/\$spider_password = 'REPLACE_WITH_SECURE_PASSWORD';/\$spider_password = '${sp}';/" docker/config.php
    rm -f docker/config.php.bak
    echo "    created docker/config.php with generated scramble keys"
  else
    echo "    created docker/config.php — set scramble keys, baseurl, ollama, match db.env passwords"
  fi
else
  echo "    docker/config.php already exists (left unchanged)"
fi

if [[ ! -f plugins/image_sequence/config/config.php ]]; then
  cp plugins/image_sequence/config/config.php.example plugins/image_sequence/config/config.php
  echo "    created plugins/image_sequence/config/config.php (container paths)"
else
  echo "    plugins/image_sequence/config/config.php already exists (left unchanged)"
fi

# shellcheck disable=SC1091
set -a
# Paths with spaces must be quoted in .env (see .env.example)
# shellcheck source=/dev/null
source .env
set +a

mkdir -p "${FILESTORE_HOST_PATH}" "${SYNCDIR_HOST_PATH:-./syncdir}"
echo "    filestore dir: ${FILESTORE_HOST_PATH}"
echo "    syncdir dir:   ${SYNCDIR_HOST_PATH:-./syncdir}"

if [[ ! -e "${ORIGINALS_HOST_PATH}" ]]; then
  echo "WARNING: ORIGINALS_HOST_PATH does not exist yet:"
  echo "         ${ORIGINALS_HOST_PATH}"
  echo "         Mount the share (and allow it in Docker Desktop → File Sharing) before compose up."
else
  echo "    originals:     ${ORIGINALS_HOST_PATH}"
fi

if grep -q 'change-me' docker/db.env 2>/dev/null; then
  echo
  echo "WARNING: docker/db.env still has change-me passwords — edit before production use."
fi

if grep -q 'REPLACE_WITH_' docker/config.php 2>/dev/null; then
  echo "WARNING: docker/config.php still has REPLACE_WITH_ placeholders — generate scramble keys."
fi

echo
echo "Next:"
echo "  1. Edit .env, docker/db.env, docker/config.php"
echo "  2. docker compose up --build -d"
echo "  3. Open http://localhost:\${RS_HTTP_PORT:-8080} and finish setup if prompted"
echo "See docker/README.md for the full walkthrough."
