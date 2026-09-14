#!/usr/bin/env bash
# One full migration: Windows HTTP source -> Linux files, database, and runtime.
set -euo pipefail
source_url=$1
source_manifest=$2
[[ $(uname -s) == Linux ]]
uname -a
mkdir -p /root/migration
curl --connect-timeout 5 --max-time 10 --retry 10 --retry-connrefused --retry-delay 1 -fsS "$source_url/migration-check.php" > /root/migration/source.json
php tests/e2e/windows/verify-paths.php "$source_manifest"
curl --connect-timeout 5 --max-time 10 -fsS "$source_url/" > /root/migration/source-homepage.log
if ! grep -q 'Windows migration post' /root/migration/source-homepage.log; then
    echo 'The source WordPress homepage does not render the fixture post.'
    exit 1
fi
curl --connect-timeout 5 --max-time 10 -fsS "$source_url/migration-check.php" > /root/migration/source.json
php packages/reprint-client/src/import.php pull "$source_url/?reprint-api" \
    --secret=windows-migration-secret \
    --state-dir=/root/migration/state --fs-root=/root/migration/files \
    --target-engine=mysql --target-host=127.0.0.1 --target-user=migration --target-pass=migration --target-db=migration_target \
    --new-site-url=http://127.0.0.1:8881 \
    --flatten-to=/root/migration/site --runtime=php-builtin --start-runtime=none --output-dir=/root/migration/runtime \
    --progress=jsonl 2>&1 | tee /root/migration/pull.log
# Also generate a runtime for the raw download, without --flatten-to.
php packages/reprint-client/src/import.php apply-runtime "$source_url/?reprint-api" \
    --secret=windows-migration-secret \
    --state-dir=/root/migration/state --fs-root=/root/migration/files \
    --runtime=php-builtin --start-runtime=none --output-dir=/root/migration/raw-runtime \
    --progress=jsonl 2>&1 | tee /root/migration/raw-runtime.log
php tests/e2e/windows/verify-migration.php "$source_manifest"
