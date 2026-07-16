#!/bin/bash
# ehLib 检索预设更新定时任务脚本
# 按已保存的检索条件执行更新爬取，遇到已下载作品即停止
# 使用方法: 在 DSM 任务计划器中设置定时执行此脚本
# 日志位置: data/logs/update_artists.log

cd "$(dirname "$0")/.."
mkdir -p data/logs
source venv/bin/activate
python -m ehlib update-artists --source exhentai >> data/logs/update_artists.log 2>&1
