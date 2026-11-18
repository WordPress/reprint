#!/usr/bin/env bash
# Install Ubuntu packages in the WSL distribution of a Windows CI job.
#
# Usage: install-wsl-packages.sh <package>...
#
# WSL writes to its disk slowly, so the package installer's default of
# syncing every unpacked file costs tens of seconds. The distribution is
# discarded with the job, so a lost write after a crash does not matter.
set -euo pipefail

if [ "$#" -eq 0 ]; then
    echo "install-wsl-packages.sh needs at least one package name, got none." >&2
    exit 1
fi

echo force-unsafe-io > /etc/dpkg/dpkg.cfg.d/ci-unsafe-io
# Manual pages and package docs are never read here. Without them, and with
# automatic index updates off, no install waits for a man-db rebuild.
printf 'path-exclude=/usr/share/man/*\npath-exclude=/usr/share/doc/*\n' > /etc/dpkg/dpkg.cfg.d/ci-no-docs
rm -f /var/lib/man-db/auto-update

export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install --yes --no-install-recommends "$@"
