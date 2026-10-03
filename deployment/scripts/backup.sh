#!/bin/bash
# HRMS Backup Script
#
# Usage: ./backup.sh [daily|weekly|monthly]
# Default: daily
#
# This script creates encrypted backups of:
# 1. Database (MariaDB)
# 2. Private files (storage/app/private)
# 3. Application .env (for recovery)
#
# Requirements:
# - mysqldump
# - gpg (for encryption)
# - awscli/rclone for off-site copy (optional)
#
# Configure via environment variables or edit the CONFIG section below.

set -euo pipefail

# ============================================================
# CONFIGURATION - EDIT THESE VALUES
# ============================================================

# Application paths
APP_ROOT="/var/www/hrms"
BACKEND_ROOT="${APP_ROOT}/backend"
STORAGE_PATH="${BACKEND_ROOT}/storage/app/private"

# Database
DB_CONNECTION="mysql"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_DATABASE="${DB_DATABASE:-hrms_laravel}"
DB_USERNAME="${DB_USERNAME:-hrms_app}"
DB_PASSWORD="${DB_PASSWORD}"

# Backup settings
BACKUP_ROOT="/var/backups/hrms"
RETENTION_DAILY=7
RETENTION_WEEKLY=4
RETENTION_MONTHLY=6

# Encryption (GPG)
GPG_RECIPIENT="${GPG_RECIPIENT:-backup@yourdomain.com}"

# Off-site copy (optional - configure rclone or awscli)
# RCLONE_REMOTE="s3:hrms-backups"
# AWS_S3_BUCKET="your-bucket"

# Notification (optional)
# SLACK_WEBHOOK_URL=""
# EMAIL_TO="admin@yourdomain.com"

# ============================================================
# INTERNAL VARIABLES
# ============================================================

TYPE="${1:-daily}"
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
DATE_DIR=$(date +"%Y/%m/%d")
BACKUP_DIR="${BACKUP_ROOT}/${TYPE}/${DATE_DIR}"
LOG_FILE="${BACKUP_ROOT}/backup_${TYPE}_${TIMESTAMP}.log"

# Ensure backup directory exists
mkdir -p "${BACKUP_DIR}"

# Logging function
log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "${LOG_FILE}"
}

error() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] ERROR: $*" | tee -a "${LOG_FILE}" >&2
}

cleanup_on_exit() {
    local exit_code=$?
    if [ $exit_code -ne 0 ]; then
        error "Backup ${TYPE} failed with exit code ${exit_code}"
        # Send notification if configured
        # if [ -n "${SLACK_WEBHOOK_URL}" ]; then curl -X POST -H 'Content-type: application/json' --data "{\"text\":\"HRMS ${TYPE} backup failed\"}" ${SLACK_WEBHOOK_URL}; fi
    else
        log "Backup ${TYPE} completed successfully"
    fi
    exit $exit_code
}
trap cleanup_on_exit EXIT

# ============================================================
# VALIDATION
# ============================================================

log "Starting ${TYPE} backup at ${TIMESTAMP}"

# Validate required variables
if [ -z "${DB_PASSWORD}" ]; then
    error "DB_PASSWORD not set"
    exit 1
fi

if [ ! -d "${BACKEND_ROOT}" ]; then
    error "Backend root not found: ${BACKEND_ROOT}"
    exit 1
fi

if [ ! -d "${STORAGE_PATH}" ]; then
    error "Storage path not found: ${STORAGE_PATH}"
    exit 1
fi

# Check required tools
for cmd in mysqldump gpg tar gzip; do
    if ! command -v ${cmd} &> /dev/null; then
        error "Required command not found: ${cmd}"
        exit 1
    fi
done

# ============================================================
# DATABASE BACKUP
# ============================================================

DB_DUMP_FILE="${BACKUP_DIR}/db_${TIMESTAMP}.sql"
log "Dumping database to ${DB_DUMP_FILE}"

mysqldump \
    --host="${DB_HOST}" \
    --port="${DB_PORT}" \
    --user="${DB_USERNAME}" \
    --password="${DB_PASSWORD}" \
    --single-transaction \
    --routines \
    --triggers \
    --events \
    --hex-blob \
    --default-character-set=utf8mb4 \
    --no-tablespaces \
    "${DB_DATABASE}" > "${DB_DUMP_FILE}"

if [ ! -s "${DB_DUMP_FILE}" ]; then
    error "Database dump is empty"
    exit 1
fi

log "Compressing database dump"
gzip "${DB_DUMP_FILE}"
DB_DUMP_FILE="${DB_DUMP_FILE}.gz"

log "Database backup size: $(du -h ${DB_DUMP_FILE} | cut -f1)"

# ============================================================
# FILES BACKUP
# ============================================================

FILES_BACKUP_FILE="${BACKUP_DIR}/files_${TIMESTAMP}.tar.gz"
log "Archiving private files to ${FILES_BACKUP_FILE}"

tar -czf "${FILES_BACKUP_FILE}" -C "${APP_ROOT}" storage/app/private

if [ ! -s "${FILES_BACKUP_FILE}" ]; then
    error "Files backup is empty"
    exit 1
fi

log "Files backup size: $(du -h ${FILES_BACKUP_FILE} | cut -f1)"

# ============================================================
# ENV BACKUP (without sensitive values - template only)
# ============================================================

ENV_TEMPLATE_FILE="${BACKUP_DIR}/env_template_${TIMESTAMP}.txt"
log "Creating .env template"
cp "${BACKEND_ROOT}/.env.example" "${ENV_TEMPLATE_FILE}"

# ============================================================
# ENCRYPTION
# ============================================================

log "Encrypting backups with GPG (recipient: ${GPG_RECIPIENT})"

for file in "${DB_DUMP_FILE}" "${FILES_BACKUP_FILE}" "${ENV_TEMPLATE_FILE}"; do
    log "Encrypting $(basename ${file})"
    gpg --trust-model always --encrypt --recipient "${GPG_RECIPIENT}" --output "${file}.gpg" "${file}"
    shred -u "${file}"  # Securely delete unencrypted file
    log "Encrypted: $(basename ${file}).gpg ($(du -h ${file}.gpg | cut -f1))"
done

# ============================================================
# MANIFEST
# ============================================================

MANIFEST_FILE="${BACKUP_DIR}/manifest_${TIMESTAMP}.txt"
cat > "${MANIFEST_FILE}" <<EOF
HRMS Backup Manifest
====================
Type: ${TYPE}
Timestamp: ${TIMESTAMP}
Hostname: $(hostname)
Database: ${DB_DATABASE}
Files:
  - $(basename ${DB_DUMP_FILE}).gpg
  - $(basename ${FILES_BACKUP_FILE}).gpg
  - $(basename ${ENV_TEMPLATE_FILE}).gpg
Sizes:
  - DB: $(du -h ${DB_DUMP_FILE}.gpg | cut -f1)
  - Files: $(du -h ${FILES_BACKUP_FILE}.gpg | cut -f1)
Checksums (SHA256):
  - DB: $(sha256sum ${DB_DUMP_FILE}.gpg | cut -d' ' -f1)
  - Files: $(sha256sum ${FILES_BACKUP_FILE}.gpg | cut -d' ' -f1)
Encrypted with GPG for: ${GPG_RECIPIENT}
EOF

log "Manifest created: ${MANIFEST_FILE}"

# ============================================================
# OFF-SITE COPY (Optional)
# ============================================================

# if [ -n "${RCLONE_REMOTE}" ] && command -v rclone &> /dev/null; then
#     log "Copying to off-site storage: ${RCLONE_REMOTE}"
#     rclone copy "${BACKUP_DIR}" "${RCLONE_REMOTE}/hrms/${TYPE}/${DATE_DIR}/" --progress
#     log "Off-site copy completed"
# fi

# if [ -n "${AWS_S3_BUCKET}" ] && command -v aws &> /dev/null; then
#     log "Copying to S3: s3://${AWS_S3_BUCKET}/hrms/${TYPE}/${DATE_DIR}/"
#     aws s3 sync "${BACKUP_DIR}" "s3://${AWS_S3_BUCKET}/hrms/${TYPE}/${DATE_DIR}/" --storage-class STANDARD_IA
#     log "S3 copy completed"
# fi

# ============================================================
# RETENTION CLEANUP
# ============================================================

cleanup_old_backups() {
    local type=$1
    local retention=$2
    local path="${BACKUP_ROOT}/${type}"

    if [ ! -d "${path}" ]; then
        return
    fi

    log "Cleaning up ${type} backups older than ${retention} periods"

    # Find and remove directories older than retention
    find "${path}" -mindepth 1 -maxdepth 1 -type d -mtime +$((retention * 30)) -exec rm -rf {} \; 2>/dev/null || true
}

case "${TYPE}" in
    daily)
        cleanup_old_backups "daily" ${RETENTION_DAILY}
        ;;
    weekly)
        cleanup_old_backups "weekly" $((RETENTION_WEEKLY * 7))
        ;;
    monthly)
        cleanup_old_backups "monthly" $((RETENTION_MONTHLY * 30))
        ;;
esac

log "Retention cleanup completed"

# ============================================================
# FINALIZE
# ============================================================

log "Backup ${TYPE} completed successfully at $(date '+%Y-%m-%d %H:%M:%S')"
log "Backup location: ${BACKUP_DIR}"
log "Files created:"
ls -lh "${BACKUP_DIR}" | tee -a "${LOG_FILE}"

exit 0