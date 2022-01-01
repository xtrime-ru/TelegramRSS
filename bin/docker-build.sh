#!/usr/bin/env bash

SCRIPT_DIR="$( cd -- "$( dirname -- "${BASH_SOURCE[0]}" )" &> /dev/null && pwd )"

docker buildx build --platform linux/amd64,linux/arm64 -t xtrime/telegram-rss:latest "$@" --push "$SCRIPT_DIR/../"