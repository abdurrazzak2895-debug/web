#!/usr/bin/env bash
# Secure Solver Node Master Setup
set -euo pipefail

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

log_info() { echo -e "${GREEN}[INFO]${NC} $1"; }
log_warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1"; }

# Pre-flight checks
preflight() {
    echo "[SETUP] Running pre-flight checks..."
    
    if [[ $EUID -ne 0 ]]; then
        log_error "This script must be run as root"
        exit 1
    fi
    
    if [[ -z "${SLOT_API_KEY:-}" ]]; then
        log_error "SLOT_API_KEY environment variable not set"
        log_info "Usage: SLOT_API_KEY=your_key_here $0"
        exit 1
    fi
    
    log_info "Pre-flight checks passed"
}

# Install dependencies
install_dependencies() {
    echo "[SETUP] Installing system dependencies..."
    
    apt-get update -qq
    apt-get install -y -qq nodejs npm curl wget jq
    
    if ! command -v node &> /dev/null; then
        log_error "Node.js installation failed"
        exit 1
    fi
    
    log_info "Dependencies ready: Node $(node -v)"
}

# Setup directories
setup_directories() {
    echo "[SETUP] Creating directory structure..."
    
    SOLVER_DIR="/opt/ipms-solver"
    mkdir -p "$SOLVER_DIR"/{logs,config,scripts,backups}
    
    chmod 755 "$SOLVER_DIR"
    chmod 700 "$SOLVER_DIR"/logs "$SOLVER_DIR"/config "$SOLVER_DIR"/backups
    chmod 755 "$SOLVER_DIR"/scripts
    
    log_info "Directory structure created at $SOLVER_DIR"
}

# Create configuration
create_config() {
    echo "[SETUP] Creating secure configuration..."
    
    CONFIG_FILE="/opt/ipms-solver/config/solver.env"
    
    cat > "$CONFIG_FILE" << EOF
# IPMS Solver Configuration
# Generated: $(date -u +"%Y-%m-%dT%H:%M:%SZ")
SLOT_API_KEY=$SLOT_API_KEY
PORTAL_URL=https://ipms.senda.fit
NODE_ENV=production
LOG_LEVEL=INFO
HEARTBEAT_INTERVAL=30000
EOF
    
    chmod 600 "$CONFIG_FILE"
    log_info "Secure configuration written to $CONFIG_FILE"
}

# Create systemd service
create_service() {
    echo "[SETUP] Creating systemd service..."
    
    SERVICE_FILE="/etc/systemd/system/ipms-solver.service"
    
    cat > "$SERVICE_FILE" << 'EOF'
[Unit]
Description=IPMS Captcha Solver Node
After=network.target

[Service]
Type=simple
User=root
Group=root
WorkingDirectory=/opt/ipms-solver
EnvironmentFile=/opt/ipms-solver/config/solver.env
ExecStart=/usr/bin/node /opt/ipms-solver/scripts/captcha_solver.js
Restart=always
RestartSec=10
StandardOutput=journal
StandardError=journal
KillMode=mixed

# Security hardening
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=/opt/ipms-solver/logs /opt/ipms-solver/config
LimitNOFILE=65536

[Install]
WantedBy=multi-user.target
EOF
    
    chmod 644 "$SERVICE_FILE"
    
    systemctl daemon-reload
    systemctl enable ipms-solver
    
    log_info "Systemd service created and enabled"
}

# Verify installation
verify_installation() {
    echo "[SETUP] Running verification checks..."
    
    systemctl daemon-reload
    systemctl start ipms-solver
    
    sleep 3
    
    if systemctl is-active --quiet ipms-solver; then
        log_info "✓ Service is running"
    else
        log_error "✗ Service failed to start"
        systemctl status ipms-solver --no-pager
        exit 1
    fi
    
    if [[ $(stat -c "%a" "/opt/ipms-solver/config/solver.env") == "600" ]]; then
        log_info "✓ Config file permissions secure"
    else
        log_error "✗ Config file permissions too permissive"
        chmod 600 /opt/ipms-solver/config/solver.env
    fi
    
    log_info "Installation verification complete"
}

# Show final status
show_status() {
    cat << EOF

========================================
 IPMS Captcha Solver Node - READY
========================================

Service Status: $(systemctl is-active ipms-solver)
Installation Path: /opt/ipms-solver
Config File: /opt/ipms-solver/config/solver.env
Log Files: /opt/ipms-solver/logs/

Useful Commands:
  systemctl status ipms-solver              # Check service status
  journalctl -u ipms-solver -f              # Follow logs
  systemctl restart ipms-solver             # Restart service
  systemctl stop ipms-solver                # Stop service

Solver node is ready for work!

EOF
}

# Main execution
main() {
    preflight
    install_dependencies
    setup_directories
    create_config
    create_service
    verify_installation
    show_status
}

main "$@"
