#!/bin/bash
set -e
echo "=== ehLib Environment Setup ==="

sudo apt-get update
sudo apt-get install -y python3 python3-venv python3-pip chromium-browser

python3 -m venv venv
source venv/bin/activate

pip install --upgrade pip
pip install -r requirements.txt

echo "=== Setup complete ==="
echo "Run: ./run.sh or source venv/bin/activate"
