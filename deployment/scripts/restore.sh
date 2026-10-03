#!/bin/bash
# HRMS Restore Script
#
# Usage: ./restore.sh /path/to/backup_directory [target_database]
#
# This script restores:
# 1. Database (MariaDB)
# 2. Private files (storage/app/private)
#
# Requirements:
# - gpg (for decryption)
# - mysql client
# - tar, gzip
#
# WARNING: This will OVERWRITE the target database and files.
# Use with extreme caution. Test first with a test database.

set -euo pipefail

# ============================================================
# VALIDATION
# ============================================================

if [ $# -lt 1 ]; then
    echo "Usage: $0 /path/to/backup_directory [target_database]"
    echo "Example: $0 /var/backups/hrms/daily/2024/01/15/20240115_020000"
    exit 1
fi

BACKUP_DIR="$1"
TARGET_DB="${2:-hrms_laravel_restore_test}"

if [ ! -d "${BACKUP_DIR}" ]; then
    echo "ERROR: Backup directory not found: ${BACKUP_DIR}"
    exit 1
fi

if [ ! -f "${BACKUP_DIR}/manifest_*.txt" ]; then
    echo "ERROR: No manifest found in ${BACKUP_DIR}"
    exit 1
fi

MANIFEST=$(ls "${BACKUP_DIR}"/manifest_*.txt | head -1)
echo "Using manifest: ${MANIFEST}"
cat "${MANIFEST}"

# Confirm before proceeding
read -p "This will OVERWRITE database '${TARGET_DB}' and files. Continue? (yes/no): " CONFIRM
if [ "${CONFIRM}" != "yes" ]; then
    echo "Aborted."
    exit 1
fi

# ============================================================
# VALIDATION
# ============================================================

for cmd in gpg mysql mysqladmin tar gzip; do
    if ! command -v ${cmd} &> /dev/null; then
        echo "ERROR: Required command not found: ${cmd}"
        exit 1
    fi
done

# Check GPG files exist
DB_FILE=$(ls "${BACKUP_DIR}"/db_*.sql.gz.gpg 2>/dev/null | head -1)
FILES_ARCHIVE=$(ls "${BACKUP_DIR}"/files_*.tar.gz.gpg 2>/dev/null | head -1)

if [ -z "${DB_FILE}" ] || [ ! -f "${DB_FILE}" ]; then
    echo "ERROR: Database backup not found in ${BACKUP_DIR}"
    exit 1
fi

if [ -z "${FILES_ARCHIVE}" ] || [ ! -f "${FILES_ARCHIVE}" ]; then
    echo "ERROR: Files archive not found in ${BACKUP_DIR}"
    exit 1
fi

echo "Found database backup: $(basename ${DB_FILE})"
echo "Found files archive: $(basename ${FILES_ARCHIVE})"

# Verify checksums if manifest has them
if grep -q "Checksums" "${MANIFEST}"; then
    echo "Verifying checksums..."
    grep "SHA256:" "${MANIFEST}" | while read -r line; do
        expected=$(echo "${line}" | awk -F': ' '{print $2}')
        file=$(echo "${line}" | awk -F': ' '{print $1}' | sed 's/ *- *//')
        filepath="${BACKUP_DIR}/${file}.gpg"
        if [ -f "${filepath}" ]; then
            actual=$(sha256sum "${filepath}" | cut -d' ' -f1)
            if [ "${actual}" = "${expected}" ]; then
                echo "  ✓ ${file}: OK"
            else
                echo "  ✗ ${file}: CHECKSUM MISMATCH (expected ${expected}, got ${actual})"
                exit 1
            fi
        fi
    done
    echo "All checksums verified."
fi

# ============================================================
# DECRYPTION
# ============================================================

echo "Decrypting backups..."
DB_DUMP_DECRYPTED=$(mktemp --suffix=.sql.gz)
FILES_DECRYPTED=$(mktemp --suffix=.tar.gz)

echo "Decrypting database..."
gpg --decrypt "${DB_FILE}" > "${DB_DUMP_DECRYPTED}"

gpg --decrypt "${FILES_ARCHIVE}" > "${FILES_DECRYPTED}"

echo "Decryption complete."

# Verify decrypted files
if [ ! -s "${DB_DUMP_DECRYPTED}" ]; then
    echo "ERROR: Decrypted database dump is empty"
    exit 1
fi

if [ ! -s "${FILES_DECRYPTED}" ]; then
    echo "ERROR: Decrypted files archive is empty"
    exit 1
fi

# Decompress database
DB_DUMP_SQL=$(mktemp --suffix=.sql)
gzip -dc "${DB_DUMP_DECRYPTED}" > "${DB_DUMP_SQL}"

# ============================================================
# DATABASE RESTORE
# ============================================================

echo "Checking database connection..."

# Parse DB config from .env (simplified - in production, read from actual .env)
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_USERNAME="${DB_USERNAME:-hrms_app}"
DB_PASSWORD="${DB_PASSWORD}"

if [ -z "${DB_PASSWORD}" ]; then
    read -s -p "Enter database password for ${DB_USERNAME}@${DB_HOST}: " DB_PASSWORD
    echo
fi

# Test connection
if ! mysqladmin ping -h"${DB_HOST}" -P"${DB_PORT}" -u"${DB_USERNAME}" -p"${DB_PASSWORD}" --silent; then
    echo "ERROR: Cannot connect to database"
    exit 1
fi

# Check if target database exists, create if not
if ! mysql -h"${DB_HOST}" -P"${DB_PORT}" -u"${DB_USERNAME}" -p"${DB_PASSWORD}" -e "USE \`${TARGET_DB}\`" 2>/dev/null; then
    echo "Database '${TARGET_DB}' does not exist. Creating..."
    mysql -h"${DB_HOST}" -P"${DB_PORT}" -u"${DB_USERNAME}" -p"${DB_PASSWORD}" -e "CREATE DATABASE \`${TARGET_DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    echo "Database created."
else
    echo "Database '${TARGET_DB}' exists. Dropping tables..."
    # Get all tables and drop them
    TABLES=$(mysql -h"${DB_HOST}" -P"${DB_PORT}" -u"${DB_USERNAME}" -p"${DB_PASSWORD}" -N -e "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='${TARGET_DB}';")
    for table in ${TABLES}; do
        mysql -h"${DB_HOST}" -P"${DB_PORT}" -u"${DB_USERNAME}" -p"${DB_PASSWORD}" -e "SET FOREIGN_KEY_CHECKS=0; DROP TABLE IF EXISTS \`${TARGET_DB}\`.\`${table}\`; SET FOREIGN_KEY_CHECKS=1;"
    done
    echo "Existing tables dropped."
fi

echo "Restoring database..."
mysql -h"${DB_HOST}" -P"${DB_PORT}" -u"${DB_USERNAME}" -p"${DB_PASSWORD}" "${TARGET_DB}" < "${DB_DUMP_SQL}"

if [ $? -ne 0 ]; then
    echo "ERROR: Database restore failed"
    exit 1
fi

echo "Database restored successfully."

# Run migrations to ensure schema is up to date
# (Only if this is a fresh restore - skip if restoring to existing prod)
# php artisan migrate --force --path=database/migrations

# ============================================================
# FILES RESTORE
# ============================================================

APP_ROOT="/var/www/hrms"
STORAGE_PATH="${APP_ROOT}/backend/storage/app/private"

echo "Restoring private files..."

# Backup current files (just in case)
if [ -d "${STORAGE_PATH}" ]; then
    BACKUP_SUFFIX=$(date +"%Y%m%d_%H%M%S")
    mv "${STORAGE_PATH}" "${STORAGE_PATH}.backup_${BACKUP_SUFFIX}"
    echo "Previous storage backed up to ${STORAGE_PATH}.backup_${BACKUP_SUFFIX}"
fi

mkdir -p "${STORAGE_PATH}"

echo "Extracting files..."
tar -xzf "${FILES_DECRYPTED}" -C "${APP_ROOT}"

if [ $? -ne 0 ]; then
    echo "ERROR: File extraction failed"
    exit 1
fi

echo "Files restored successfully."

# Fix permissions
chown -R www-data:www-data "${STORAGE_PATH}"
chmod -R 750 "${STORAGE_PATH}"
find "${STORAGE_PATH}" -type f -exec chmod 640 {} \;

echo "Permissions fixed."

# ============================================================
# CLEANUP
# ============================================================

echo "Cleaning up temporary files..."
rm -f "${DB_DUMP_DECRYPTED}" "${FILES_DECRYPTED}" "${DB_DUMP_SQL}"

# ============================================================
# VERIFICATION
# ============================================================

echo "Verifying restore..."

# Check database
TABLE_COUNT=$(mysql -h"${DB_HOST}" -P"${DB_PORT}" -u"${DB_USERNAME}" -p"${DB_PASSWORD}" -N -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='${TARGET_DB}';")
echo "Tables restored: ${TABLE_COUNT}"

# Check migrations table
MIGRATION_COUNT=$(mysql -h"${DB_HOST}" -P"${DB_PORT}" -u"${DB_USERNAME}" -p"${DB_PASSWORD}" -N -e "SELECT COUNT(*) FROM \`${TARGET_DB}\`.\`migrations\`;" 2>/dev/null || echo "0")
echo "Migrations recorded: ${MIGRATION_COUNT}"

# Check files
FILE_COUNT=$(find "${STORAGE_PATH}" -type f | wc -l)
echo "Files restored: ${FILE_COUNT}"

echo ""
echo "========================================"
echo "RESTORE COMPLETED SUCCESSFULLY"
echo "========================================"
echo "Target database: ${TARGET_DB}"
echo "Tables: ${TABLE_COUNT}"
echo "Migrations: ${MIGRATION_COUNT}"
echo "Files: ${FILE_COUNT}"
echo ""
echo "Next steps:"
echo "1. Run: php artisan migrate --force (if needed)"
echo "2. Run: php artisan optimize:clear"
echo "3. Restart queue workers: systemctl restart hrms-worker@*"
echo "4. Run health checks: curl -f https://yourdomain.com/health"
echo "========================================"

exit 0