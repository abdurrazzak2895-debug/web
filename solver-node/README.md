# IPMS Solver Node - Secure Deployment Package

Complete secure solver node implementation for IPMS captcha solving.

## Installation

```bash
# On your VPS
export SLOT_API_KEY="your_api_key_here"
curl -fsSL https://ipms.senda.fit/solver/setup -H "Authorization: Bearer $SLOT_API_KEY" | sudo bash
```

## Structure

- `lib/` - Core modules and services
- `scripts/` - Executable scripts
- `config/` - Configuration files
- `logs/` - Log files (created during install)

## Security Features

1. API keys via environment variables only
2. Secure file permissions (600 for config)
3. No secrets in process list
4. Systemd hardening applied
5. Automatic backups before updates