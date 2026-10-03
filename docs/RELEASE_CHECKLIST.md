# HRMS Release Checklist

## Pre-Release Validation

### Backend Tests
- [ ] `composer audit` - No critical/high vulnerabilities
- [ ] `composer validate` - Valid
- [ ] `vendor/bin/pint --test` - Code style passes
- [ ] `php artisan test` - All tests pass (hrms_testing database)
- [ ] `php artisan route:list` - 223 routes registered
- [ ] `php artisan migrate:status` - 60 migrations, all Ran
- [ ] `php artisan schedule:list` - 3 jobs registered

### Flutter Tests
- [ ] `dart format .` - 285 files, 0 changed
- [ ] `flutter analyze` - No issues found
- [ ] `flutter test` - 654 tests passed
- [ ] `flutter pub outdated` - No critical dependency updates

### Security Checks
- [ ] `composer audit` - Clean
- [ ] Git secrets scan - Clean (no .env, keys, credentials)
- [ ] `firebase/php-jwt` upgraded to v7.0.0 (CVE-2025-45769 fixed)
- [ ] Malware scanner architecture implemented
- [ ] Audit logging covers 14/15 spec mutations
- [ ] Rate limiters configured (7 named limiters)
- [ ] Password reset queued, no enumeration

## Infrastructure Readiness

### Server Requirements
- [ ] Ubuntu 22.04/24.04 LTS or comparable
- [ ] PHP 8.3+ with required extensions
- [ ] MariaDB 10.4+
- [ ] Nginx + PHP-FPM
- [ ] Redis (recommended for queue/cache)
- [ ] ClamAV + clamav-daemon
- [ ] Supervisor or systemd
- [ ] Git (if deployment via pull)
- [ ] Certbot (Let's Encrypt)

### Database
- [ ] Dedicated DB user (not root)
- [ ] Least privilege grants
- [ ] UTF8MB4 charset/collation
- [ ] Daily backups configured
- [ ] Restore procedure tested

### SSL/TLS
- [ ] Domain DNS configured
- [ ] Let's Encrypt / CA certificate ready
- [ ] HTTPS redirect configured
- [ ] HSTS header (after verification)

### Security Headers
- [ ] `Strict-Transport-Security` (after HTTPS verified)
- [ ] `X-Content-Type-Options: nosniff`
- [ ] `X-Frame-Options: DENY`
- [ ] `Referrer-Policy: strict-origin-when-cross-origin`
- [ ] `Permissions-Policy` configured
- [ ] CSP configured (adjust for frontend needs)

### CORS
- [ ] Explicit production origins configured
- [ ] No wildcard origins in production

### ClamAV
- [ ] ClamAV installed
- [ ] clamav-daemon running
- [ ] Signatures updated (`freshclam`)
- [ ] Socket configured (`/run/clamav/clamd.ctl`)
- [ ] Test scan passes (clean file)
- [ ] Malware scan mode: `clamdscan` in production

### Malware Quarantine
- [ ] Quarantine directory exists (`/var/quarantine/clamav`)
- [ ] ClamAV user has write access
- [ ] Cleanup policy documented

### Firebase/FCM
- [ ] Firebase project created
- [ ] Android app configured (google-services.json)
- [ ] iOS app configured (GoogleService-Info.plist)
- [ ] APNs Auth Key uploaded to Firebase
- [ ] Service account stored securely on server (env var)
- [ ] FCM timeout configured (10s default)

### SMTP
- [ ] SMTP provider configured (SES, Postmark, SendGrid, etc.)
- [ ] MAIL_FROM_ADDRESS verified
- [ ] SPF/DKIM/DMARC configured
- [ ] Password reset emails tested

### Android Release
- [ ] Keystore generated (`keytool -genkey ...`)
- [ ] `key.properties` created (git-ignored)
- [ ] `build.gradle.kts` configured for release signing
- [ ] Permissions minimal (location, camera only)
- [ ] Network security config (no cleartext)
- [ ] `flutter build apk --release` tested
- [ ] `flutter build appbundle --release` tested
- [ ] Artifacts validated (apksigner, bundletool)
- [ ] SHA-256 hashes recorded
- [ ] Malware scan performed (if scanner available)

### iOS Release
- [ ] macOS + Xcode available
- [ ] Apple Developer Program active
- [ ] Bundle ID matches Firebase iOS app
- [ ] `GoogleService-Info.plist` in `ios/Runner/`
- [ ] APNs Auth Key uploaded to Firebase
- [ ] Push Notifications capability enabled
- [ ] Background Modes → Remote notifications enabled
- [ ] Info.plist has all usage descriptions
- [ ] ATS configured (HTTPS only)
- [ ] `flutter build ios --release` tested
- [ ] Xcode Archive validates
- [ ] TestFlight internal testing passed

### Backup/Restore
- [ ] Daily DB backups configured
- [ ] Private files backed up
- [ ] Encryption (GPG) configured
- [ ] Off-site copy configured
- [ ] Retention: daily 7, weekly 4, monthly 6
- [ ] Restore procedure documented
- [ ] Restore rehearsal performed (test DB)

### Monitoring
- [ ] Health endpoint: `GET /health`
- [ ] Disk usage alert (>80%)
- [ ] CPU/RAM alerts
- [ ] DB availability
- [ ] Queue backlog alert
- [ ] Failed jobs alert
- [ ] Backup success/failure alerts
- [ ] Certificate expiry monitoring
- [ ] ClamAV health check
- [ ] Disk usage monitoring

### Logging
- [ ] Structured logging
- [ ] No sensitive data in logs
- [ ] Log rotation configured (logrotate)
- [ ] Retention: 30 days minimum

### Firewall
- [ ] Port 80 (HTTP redirect)
- [ ] Port 443 (HTTPS)
- [ ] SSH restricted (key-only, non-root)
- [ ] DB port 3306 not public
- [ ] Redis port 6379 not public
- [ ] ClamAV socket not exposed

### SSH
- [ ] Key-based auth only
- [ ] Password auth disabled
- [ ] Non-root deploy user
- [ ] Fail2ban configured

### Database Network
- [ ] MariaDB bound to localhost/private IP
- [ ] Port 3306 not public

## Deployment Workflow

### Pre-Deployment
- [ ] All tests pass locally
- [ ] Security scan clean
- [ ] Branch is `main`
- [ ] Working tree clean
- [ ] `.env` verified for environment

### Deployment Steps
- [ ] Backup database
- [ ] Backup .env
- [ ] Backup code (exclude vendor/storage)
- [ ] Enable maintenance mode (`php artisan down`)
- [ ] Pull latest code
- [ ] `composer install --no-dev --optimize-autoloader`
- [ ] Run migrations (`php artisan migrate --force`)
- [ ] Optimize caches (`php artisan optimize`)
- [ ] Set permissions (storage, bootstrap/cache)
- [ ] Reload PHP-FPM
- [ ] Restart queue workers
- [ ] Disable maintenance mode (`php artisan up`)
- [ ] Health check passes
- [ ] Smoke tests pass

### Rollback Plan
- [ ] Code rollback documented (`git checkout <previous>`)
- [ ] Database rollback considered (backup restore)
- [ ] Irreversible migrations flagged
- [ ] Queue workers restarted after rollback

## Post-Deployment
- [ ] Health check passes (`/health` returns 200)
- [ ] API responds correctly
- [ ] Queue workers processing
- [ ] Scheduler running
- [ ] Logs clean (no errors)
- [ ] Smoke tests pass
- [ ] Monitoring alerts green

## Documentation
- [ ] README.md updated
- [ ] docs/DEPLOYMENT.md updated
- [ ] docs/SECURITY.md updated
- [ ] docs/ANDROID_RELEASE.md created
- [ ] docs/IOS_RELEASE.md created
- [ ] docs/RELEASE_CHECKLIST.md created
- [ ] docs/SECURITY_CHECKLIST.md created
- [ ] docs/BACKUP_RESTORE.md created
- [ ] docs/OPERATIONS.md created
- [ ] docs/MONITORING.md created

## Git
- [ ] Working tree clean
- [ ] All changes committed
- [ ] Pushed to origin/main
- [ ] Tagged release (e.g., `v1.0.0`)

## Final Sign-off
- [ ] All checks passed
- [ ] No unresolved critical/high security issues
- [ ] Team acknowledges release
- [ ] Rollback plan communicated

---

**Release Approved By:** _________________ **Date:** _______________

**Deployed By:** _________________ **Date:** _______________