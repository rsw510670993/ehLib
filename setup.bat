@echo off
echo === ehLib Environment Setup ===
python -m venv venv
call venv\Scripts\activate.bat
pip install --upgrade pip
pip install -r requirements.txt
echo === Setup complete ===
echo Run: run.bat or venv\Scripts\activate.bat
