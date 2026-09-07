#!/usr/bin/env bash
# secure-install.sh - Secure IPMS Bot Installer
set -euo pipefail

# Configuration
BOT_JAR_URL="https://ipms.senda.fit/api/bot/jar"
BOT_DIR="/opt/ipms-bot"
SERVICE_NAME="ipms-bot"
ENV_FILE="$BOT_DIR/.env"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'

# Helper functions
log_info() { echo -e "${GREEN}[INFO]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1"; }
log_warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }

# Root check
if [[ $EUID -ne 0 ]]; then
   log_error "This script must be run as root"
   exit 1
fi

# API Key validation
if [[ $# -ne 1 ]]; then
   log_error "Usage: $0 <SLOT_API_KEY>"
   log_info "Example: $0 2313423d-3833-41b2-9228-7f099f314729"
   exit 1
fi

SLOT_API_KEY="$1"

# Validate API key format (basic UUID check)
if ! [[ "$SLOT_API_KEY" =~ ^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$|^[A-Za-z0-9_]{20,}$ ]]; then
   log_warn "API key format doesn't match expected patterns - proceeding anyway"
fi

# Install Java 25 with fallback
log_info "Installing Java..."
if apt-cache show openjdk-25-jre-headless > /dev/null 2>&1; then
    apt-get update -qq && apt-get install -y -qq openjdk-25-jre-headless > /dev/null
    log_info "Java 25 installed successfully"
else
    log_warn "Java 25 not available, falling back to Java 21 LTS"
    apt-get update -qq && apt-get install -y -qq openjdk-21-jre-headless > /dev/null
fi

# Verify Java installation
if ! command -v java &> /dev/null; then
    log_error "Java installation failed"
    exit 1
fi

# Create bot directory
log_info "Creating bot directory..."
mkdir -p "$BOT_DIR"/{logs,config}

# Download authenticated JAR
log_info "Downloading bot JAR..."
JAR_PATH="$BOT_DIR/ipvac-booking.jar"
HTTP_RESPONSE=$(curl -s -w "%{http_code}" -H "X-Slot-Api-Key: $SLOT_API_KEY" -o "$JAR_PATH" "$BOT_JAR_URL")

if [[ "$HTTP_RESPONSE" != "200" ]]; then
    log_error "Failed to download JAR (HTTP $HTTP_RESPONSE)"
    exit 1
fi

# Set permissions
chmod 755 "$JAR_PATH"
chown -R root:root "$BOT_DIR"

# Create secure environment file
log_info "Writing environment configuration..."
cat > "$ENV_FILE" << EOF
PORTAL_URL=https://ipms.senda.fit
SLOT_API_KEY=$SLOT_API_KEY
JAVA_OPTS=-Xmx512m -Xms256m
LOG_LEVEL=INFO
SERVER_PORT=8080
EOF

chmod 600 "$ENV_FILE"

# Create systemd service with security hardening
log_info "Installing systemd service..."
cat > "/etc/systemd/system/$SERVICE_NAME.service" << EOF
[Unit]
Description=IPMS Booking Bot ($SLOT_API_KEY)
After=network.target network-online.target

[Service]
Type=simple
User=root
Group=root
WorkingDirectory=$BOT_DIR
EnvironmentFile=$ENV_FILE
ExecStart=/usr/bin/java \$JAVA_OPTS -jar $JAR_PATH
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
ReadWritePaths=$BOT_DIR/logs $BOT_DIR/config

[Install]
WantedBy=multi-user.target
EOF

# Enable and start service
log_info "Enabling and starting service..."
systemctl daemon-reload
systemctl enable --now "$SERVICE_NAME"

# Final verification
sleep 3
if systemctl is-active --quiet "$SERVICE_NAME"; then
    log_info "Installation completed successfully!"
    log_info "Service status: $(systemctl is-active $SERVICE_NAME)"
    log_info "Logs: journalctl -u $SERVICE_NAME -f"
else
    log_error "Installation failed - service not running"
    log_error "Check logs: journalctl -u $SERVICE_NAME -xe"
    exit 1
fi
