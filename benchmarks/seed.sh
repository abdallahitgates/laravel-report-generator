#!/usr/bin/env bash
# Seeds the benchmark database. Usage: ./seed.sh [fact_rows]   (default 1000000)
# 50M rows per fact table takes roughly 15-40 minutes depending on disk.
set -euo pipefail
cd "$(dirname "$0")"

ROWS="${1:-1000000}"
PORT="${RG_BENCH_PORT:-33061}"

docker compose up -d --wait
echo "Seeding ${ROWS} rows into visits and sales..."
start=$(date +%s)
docker compose exec -T mysql mysql -uroot -pbench rg_bench -e "CALL bench_seed(${ROWS});" 2>&1 | grep -v "Using a password" || true
echo "Done in $(( $(date +%s) - start ))s."
docker compose exec -T mysql mysql -uroot -pbench rg_bench -e \
  "SELECT table_name, table_rows, ROUND((data_length + index_length) / 1024 / 1024) AS size_mb
   FROM information_schema.tables WHERE table_schema = 'rg_bench' ORDER BY size_mb DESC;" 2>&1 | grep -v "Using a password"
echo "Connect with: mysql -h127.0.0.1 -P${PORT} -uroot -pbench rg_bench"
