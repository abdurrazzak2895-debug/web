#!/usr/bin/env bash
# Solver Node Health Monitor

SERVICE="ipms-solver"
LOG_FILE="/opt/ipms-solver/logs/solver.log"

echo "=== Solver Node Health Check ==="
echo "Timestamp: $(date -u)"

# Service status
if systemctl is-active --quiet "$SERVICE"; then
    echo "✓ Service Status: Running"
else
    echo "✗ Service Status: Down"
    systemctl status "$SERVICE" --no-pager -l
fi

# Process count
PROCESS_COUNT=$(pgrep -f "captcha_solver.js" | wc -l)
echo "✓ Active Processes: $PROCESS_COUNT"

# Memory usage
MEMORY_USAGE=$(ps aux | grep captcha_solver | grep -v grep | awk '{sum += $4} END {print sum}')
echo "✓ Memory Usage: ${MEMORY_USAGE}%"

# Recent errors
ERROR_COUNT=$(grep -c "\[ERROR\]" "$LOG_FILE" 2>/dev/null || echo "0")
echo "✓ Recent Errors: $ERROR_COUNT"

# Last few log entries
echo ""
echo "=== Recent Logs ==="
tail -n 10 "$LOG_FILE" 2>/dev/null || echo "No logs found"

# Disk space
DISK_USAGE=$(df -h /opt/ipms-solver | awk 'NR==2 {print $5}')
echo ""
echo "✓ Disk Usage: $DISK_USAGE"
