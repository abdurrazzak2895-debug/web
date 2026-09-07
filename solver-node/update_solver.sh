#!/usr/bin/env bash
# Solver Node Update Script

set -euo pipefail

echo "[UPDATE] Starting solver node update..."

# Backup current scripts
if [[ -d "/opt/ipms-solver" ]]; then
    BACKUP_DIR="/opt/ipms-solver/backups/$(date +%Y%m%d_%H%M%S)"
    mkdir -p "$BACKUP_DIR"
    cp -r /opt/ipms-solver/scripts "$BACKUP_DIR/" 2>/dev/null || true
    echo "[UPDATE] Backed up to $BACKUP_DIR"
fi

# Stop service during update
systemctl stop ipms-solver

# Pull latest codebase
cd /opt/ipms-solver
if [[ -d ".git" ]]; then
    git pull origin main
else
    echo "[UPDATE] No git repo - skipping automatic update"
fi

# Restart service
systemctl start ipms-solver
echo "[UPDATE] Update complete and service restarted"

# Verify
sleep 3
systemctl status ipms-solver --no-pager
