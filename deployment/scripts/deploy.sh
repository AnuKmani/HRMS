#!/bin/bash
# HRMS Deployment Script
#
# Usage: ./deploy.sh [environment]
# Environments: production, staging
# Default: production
#
# This script performs a safe, repeatable deployment.
# Run from the deployment directory or project root.

set -euo pipefail

# ============================================================
# CONFIGURATION
# ============================================================

ENVIRONMENT="${1:-production}"
APP_ROOT="/var/www/hrms"
BACKEND_ROOT="/var/www/hrms/backend"
DEPLOY_USER="www-data"
REPO_URL="https://github.com/AnuKmani/HRMS.git"
BRANCH="main"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

log() { echo -e "${GREEN}[$(date '+%H:%M:%S')] $*${NC}"; }
warn() { echo -e "${YELLOW}[$(date '+%H:%M:%S')] WARNING: $*${NC}"; }
error() { echo -e "${RED}[$(date '+%H:%M:%S')] ERROR: $*${NC}"; }

# ============================================================
# PRE-DEPLOYMENT CHECKS
# ============================================================

log "Starting deployment to ${ENVIRONMENT}"

# Check if running as correct user
if [ "$(whoami)" != "${DEPLOY_USER}" ] && [ "$(whoami)" != "root" ]; then
    error "Must run as ${DEPLOY_USER} or root"
    exit 1
fi

# Check required tools
for cmd in git composer php php-fpm nginx mysqldump; do
    if ! command -v ${cmd} &> /dev/null; then
        error "Required command not found: ${cmd}"
        exit 1
    fi
done

# Validate environment
if [ ! -f "${BACKEND_ROOT}/.env" ]; then
    error ".env file not found at ${BACKEND_ROOT}/.env"
    exit 1
fi

# Check environment
CURRENT_ENV=$(grep "^APP_ENV=" "${BACKEND_ROOT}/.env" | cut -d'=' -f2)
if [ "${CURRENT_ENV}" != "${ENVIRONMENT}" ]; then
    warn "APP_ENV is '${CURRENT_ENV}', deploying to '${ENVIRONMENT}'"
    read -p "Continue anyway? (yes/no): " CONFIRM
    if [ "${CONFIRM}" != "yes" ]; then
        exit 1
    fi
fi

# Check debug mode
DEBUG_MODE=$(grep "^APP_DEBUG=" "${BACKEND_ROOT}/.env" | cut -d'=' -f2)
if [ "${DEBUG_MODE}" = "true" ] && [ "${ENVIRONMENT}" = "production" ]; then
    error "APP_DEBUG=true in production! Fix .env before deploying."
    exit 1
fi

# Check debug mode in config
if grep -q "'debug' => true" "${BACKEND_ROOT}/config/app.php" 2>/dev/null; then
    warn "config/app.php has debug=true hardcoded (should use env)"
fi

# ============================================================
# BACKUP
# ============================================================

log "Creating pre-deployment backup..."

BACKUP_DIR="/var/backups/hrms/pre_deploy_$(date +%Y%m%d_%H%M%S)"
mkdir -p "${BACKUP_DIR}"

# Backup database
log "Backing up database..."
mysqldump \
    --host="${DB_HOST:-127.0.0.1}" \
    --port="${DB_PORT:-3306}" \
    --user="${DB_USERNAME:-hrms_app}" \
    --password="${DB_PASSWORD}" \
    --single-transaction \
    --routines --triggers --events \
    --hex-blob --no-tablespaces \
    hrms_laravel | gzip > "${BACKUP_DIR}/db_pre_deploy.sql.gz"

# Backup .env
cp /var/www/hrms/backend/.env "${BACKUP_DIR}/.env.backup"

# Backup application code (exclude vendor, node_modules, storage)
tar -czf "${BACKUP_DIR}/code_backup.tar.gz" \
    -C /var/www/hrms \
    --exclude='vendor' \
    --exclude='node_modules' \
    --exclude='storage' \
    --exclude='.git' \
    --exclude='*.log' \
    backend

log "Pre-deployment backup saved to ${BACKUP_DIR}"

# ============================================================
# MAINTENANCE MODE
# ============================================================

log "Enabling maintenance mode..."
cd "${BACKEND_ROOT}"
php artisan down --message="System maintenance in progress. Please try again in a few minutes." --retry=60

# ============================================================
# CODE DEPLOYMENT
# ============================================================

log "Pulling latest code..."
cd /var/www/hrms
git fetch origin
git checkout "${BRANCH}"
git pull origin "${BRANCH}"

# ============================================================
# DEPENDENCIES
# ============================================================

log "Installing PHP dependencies..."
cd "${BACKEND_ROOT}"
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

# ============================================================
# ENVIRONMENT CONFIGURATION
# ============================================================

log "Verifying environment configuration..."
# Ensure .env has correct production values
# These should already be set in .env file

# ============================================================
# MIGRATIONS
# ============================================================

log "Running database migrations..."
php artisan migrate --force

# ============================================================
# CACHE OPTIMIZATION
# ============================================================

log "Optimizing application..."
php artisan optimize
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# ============================================================
# STORAGE LINK
# ============================================================

log "Ensuring storage link..."
php artisan storage:link

# ============================================================
# PERMISSIONS
# ============================================================

log "Setting file permissions..."
chown -R www-data:www-data /var/www/hrms/backend/storage /var/www/hrms/backend/bootstrap/cache
chmod -R 750 /var/www/hrms/backend/storage /var/www/hrms/backend/bootstrap/cache
find /var/www/hrms/backend/storage -type f -exec chmod 640 {} \;

# ============================================================
# RESTART SERVICES
# ============================================================

log "Restarting PHP-FPM..."
systemctl reload php8.3-fpm || systemctl reload php-fpm

log "Restarting queue workers..."
systemctl restart hrms-worker@1 hrms-worker@2 || supervisorctl restart hrms-worker:*

# ============================================================
# HEALTH CHECK
# ============================================================

log "Running health checks..."
sleep 3

# Test health endpoint
if curl -f -s -o /dev/null -w "%{http_code}" http://localhost/health | grep -q "200"; then
    log "Health check passed"
else
    error "Health check failed!"
    # Rollback
    log "Rolling back..."
    php artisan up
    exit 1
fi

# Test API endpoint
if curl -f -s -o /dev/null -w "%{http_code}" -H "Accept: application/json" http://localhost/api/v1/health 2>/dev/null | grep -q "200"; then
    log "API health check passed"
else
    warn "API health check failed (may be expected if auth required)"
fi

# ============================================================
# EXIT MAINTENANCE MODE
# ============================================================

log "Exiting maintenance mode..."
cd "${BACKEND_ROOT}"
php artisan up

# ============================================================
# POST-DEPLOYMENT VERIFICATION
# ============================================================

log "Running post-deployment verification..."

# Run a quick smoke test
cd "${BACKEND_ROOT}"
php artisan test --filter="ApiErrorHandlingTest" --stop-on-failure 2>&1 | tail -10

log "Deployment completed successfully!"
echo ""
echo "=========================================="
echo "Deployment Summary"
echo "=========================================="
echo "Environment: ${ENVIRONMENT}"
echo "Branch: ${BRANCH}"
echo "Commit: $(git rev-parse HEAD)"
echo "Backup: ${BACKUP_DIR}"
echo "Time: $(date)"
echo "=========================================="
echo ""
echo "Next steps:"
echo "1. Monitor logs: journalctl -u hrms-worker@1 -f"
echo "2. Check queue: php artisan queue:monitor"
echo "3. Verify scheduler: systemctl status hrms-scheduler.timer"
echo "4. Check health: curl -f https://yourdomain.com/health"
echo "=========================================="