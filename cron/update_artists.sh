#!/bin/bash
# ehLib 已完成任务定期刷新入队脚本
# 由 DSM 任务计划器调用；启用的刷新对象进入 FIFO 爬取队列。

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
LOG_DIR="$ROOT/data/logs"
LOG_FILE="$LOG_DIR/update_artists.log"

mkdir -p "$LOG_DIR" || exit 1
if (umask 000; : >>"$LOG_FILE") 2>/dev/null; then
    chmod 0666 "$LOG_FILE" 2>/dev/null || true
    exec >>"$LOG_FILE" 2>&1
else
    echo "WARNING: cannot append to $LOG_FILE; continuing with DSM/console output" >&2
fi

export TZ=Asia/Tokyo
echo "[$(date '+%Y-%m-%d %H:%M:%S')] update-artists started"

if [ ! -f "$ROOT/venv/bin/activate" ]; then
    echo "ERROR: virtualenv activation script not found: $ROOT/venv/bin/activate"
    exit 1
fi

cd "$ROOT" || {
    echo "ERROR: cannot enter project directory: $ROOT"
    exit 1
}

source "$ROOT/venv/bin/activate"
python -m ehlib update-artists --source exhentai
status=$?

echo "[$(date '+%Y-%m-%d %H:%M:%S')] update-artists finished (exit=$status)"
exit "$status"
