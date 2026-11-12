#!/usr/bin/env bash
# Runs Windows php.exe from WSL so the e2e harness can time a native Windows
# client. Absolute Linux path arguments, bare or as --option=value, become
# Windows paths. WINDOWS_PHP names php.exe as a WSL path.
set -euo pipefail

windows_path() {
    local linux_path=$1
    if [ -e "$linux_path" ]; then
        wslpath -w "$linux_path"
        return
    fi
    # wslpath needs an existing path; translate the parent instead.
    printf '%s\\%s\n' "$(wslpath -w "$(dirname "$linux_path")")" "$(basename "$linux_path")"
}

arguments=()
for argument in "$@"; do
    case "$argument" in
        /*) arguments+=("$(windows_path "$argument")") ;;
        --*=/*) arguments+=("${argument%%=*}=$(windows_path "${argument#*=}")") ;;
        *) arguments+=("$argument") ;;
    esac
done

exec "$WINDOWS_PHP" "${arguments[@]}"
