# HRMS Backup & Restore Guide

## Overview

This document describes the backup and restore procedures for HRMS production deployments.

## What Gets Backed Up

### Database
- All tables (60 migrations worth of schema + data)
- Stored procedures, triggers, events
- Binary data (hex-blob encoding)

### Private Files
All files in `storage/app/private/`:
- Attendance selfies
- Medical certificates (leave)
- Employee documents (passports, IDs, visas, contracts)
- Expense receipts
- Training certificates
- Site report photos
- Salary documents (slips, certificates)

### Configuration
- `.env.example` template (for recovery reference)

## Backup Schedule

| Frequency | Retention | Trigger |
|-----------|-----------|---------|
| Daily | 7 days | Cron 02:00 |
| Weekly | 4 weeks | Cron Sunday 03:00 |
| Monthly | 6 months | Cron 1st 04:00 |

## Backup Storage

### Local
- Path: `/var/backups/hrms/{daily|weekly|monthly}/YYYY/MM/DD/`
- Encrypted with GPG
- Manifest with SHA256 checksums

### Off-Site (Recommended)
- S3 (STANDARD_IA)
- Or rclone to any supported remote

## Encryption

All backups encrypted with GPG before storage.

```bash
# Recipient configured in backup script
GPG_RECIPIENT="backup@yourdomain.com"
```

## Running Backups

```bash
# Daily (runs automatically via cron)
./deployment/scripts/backup.sh daily

# Weekly (runs automatically via cron)
./deployment/scripts/backup.sh weekly

# Monthly (runs automatically via cron)
./deployment/scripts/backup.sh monthly

# Manual
./deployment/scripts/backup.sh daily
```

## Restore Procedure

### Prerequisites
- Backup directory path
- Target database (will be overwritten!)
- GPG private key for decryption
- Database credentials

### Quick Restore (Test Database)

```bash
# Safe test restore
./deployment/scripts/restore.sh /var/backups/hrms/daily/2024/01/15/20240115_020000 hrms_restore_test
```

### Production Restore (Emergency)

```bash
# 1. Enable maintenance mode
php artisan down --message="System restore in progress"

# 2. Run restore
./deployment/scripts/restore.sh /var/backups/hrms/daily/2024/01/15/20240115_020000 hrms_laravel

# 3. Run migrations (if needed)
php artisan migrate --force

# 4. Clear caches
php artisan optimize:clear

# 4. Restart workers
systemctl restart hrms-worker@1 hrms-worker@2

# 5. Verify health
curl -f https://yourdomain.com/health

# 6. Disable maintenance mode
php artisan up
```

## Backup Verification

### Automated (in backup script)
- GPG encryption verification
- SHA256 checksums in manifest
- File size validation

### Manual (Quarterly)
```bash
# 1. Pick a backup
BACKUP_DIR="/var/backups/hrms/daily/2024/01/15/20240115_020000"

# 2. Verify checksums
cd "$BACKUP_DIR"
sha256sum -c manifest_*.txt

# 3. Test decrypt (dry run)
gpg --decrypt db_*.sql.gz.gpg | gzip -t
gpg --decrypt files_*.tar.gz.gpg | tar -tzf -
```

## Restore Rehearsal

### Schedule
- Monthly: Restore daily backup to test DB
- Quarterly: Full production-like restore to staging

### Test Restore Steps

```bash
# 1. Create test database
mysql -e "CREATE DATABASE hrms_restore_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 2. Run restore
./deployment/scripts/restore.sh /var/backups/hrms/daily/2024/01/15/20240115_020000 hrms_restore_test

# 3. Verify
mysql -e "SELECT COUNT(*) FROM hrms_restore_test.migrations;"
mysql -e "SELECT COUNT(*) FROM hrms_restore_test.users;"
ls -la /var/www/hrms/backend/storage/app/private | wc -l

# 4. Cleanup
mysql -e "DROP DATABASE hrms_restore_test;"
```

## Retention Policy

| Tier | Frequency | Retention | Cleanup |
|------|-----------|-----------|---------|
| Daily | 02:00 daily | 7 days | Auto (backup script) |
| Weekly | 03:00 Sunday | 4 weeks | Auto (backup script) |
| Monthly | 04:00 1st | 6 months | Auto (backup script) |

Cleanup runs automatically after each backup.

## Monitoring

### Success
- Log file: `/var/backups/hrms/backup_${TYPE}_${TIMESTAMP}.log`
- Check for "Backup completed successfully"

### Failure Alerts
- Script exits non-zero on failure
- Check cron logs: `journalctl -u cron`
- Check log file for ERROR entries

### Common Failures
| Error | Cause | Resolution |
|-------|-------|------------|
| DB connection failed | Wrong credentials/host | Check DB_PASSWORD, host |
| GPG encryption failed | Missing recipient key | Import GPG key |
| Disk full | Backup volume full | Expand volume / cleanup |
| mysqldump timeout | Large DB | Increase timeout |

## Restore Time Estimates

| Database Size | Restore Time |
|---------------|--------------|
| < 100 MB | ~30 seconds |
| 100 MB - 1 GB | ~2-5 minutes |
| 1 GB - 5 GB | ~10-30 minutes |
| > 5 GB | 30+ minutes |

## Security

### Encryption
- All backups GPG encrypted
- Only authorized GPG keys can decrypt
- Private keys never on backup server

### Access Control
- Backup directory: 750 (root:backup)
- Backup files: 640 (root:backup)
- GPG keys: 600 (backup:backup)

### Audit
- Backup logs retained 90 days
- Restore operations logged
- Access to backups audited

## Disaster Recovery

### RTO / RPO
- RPO (Recovery Point Objective): 24 hours (daily backup)
- RTO (Recovery Time Objective): < 2 hours (restore + verify)

### Full Site Loss
1. Provision new server
2. Install dependencies (Phase 14 docs)
3. Restore latest backup
4. Update DNS
5. Verify health

## Testing Schedule

| Test | Frequency | Method |
|------|-----------|--------|
| Daily backup integrity | Daily | Automated (script) |
| Weekly restore test | Weekly | Restore to test DB |
| Monthly full restore | Monthly | Staging environment |
| Quarterly DR drill | Quarterly | Full site recovery |

## Contact

For backup/restore issues:
- Primary: DevOps team
- Escalation: Engineering lead
- Emergency: CTO