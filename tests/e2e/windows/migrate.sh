#!/usr/bin/env bash
# One full migration: Windows HTTP source -> Linux files, database, and runtime.
set -euo pipefail
source_url=$1
source_manifest=$2
[[ $(uname -s) == Linux ]]
mkdir -p /root/migration
curl --retry 10 --retry-connrefused --retry-delay 1 -fsS "$source_url/migration-check.php" > /root/migration/source.json
php packages/reprint-client/src/import.php pull "$source_url/?reprint-api" \
    --secret=windows-migration-secret \
    --state-dir=/root/migration/state --fs-root=/root/migration/files \
    --target-host=127.0.0.1 --target-user=migration --target-pass=migration --target-db=migration_target \
    --new-site-url=http://127.0.0.1:8881 \
    --flatten-to=/root/migration/site --runtime=php-builtin --start-runtime=none \
    --progress=jsonl 2>&1 | tee /root/migration/pull.log
php tests/e2e/windows/verify-migration.php "$source_manifest"
