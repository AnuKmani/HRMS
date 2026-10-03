# HRMS Security Checklist

## Application Security

### Authentication
- [ ] Laravel Sanctum token-based authentication
- [ ] Tokens never logged or exposed in responses
- [ ] Password hashing: BCrypt (cost 12)
- [ ] Password reset tokens: 60 min expiry, single-use
- [ ] No user enumeration in forgot-password
- [ ] Rate limiting: login (5/min), password reset (5/15min)
- [ ] Session management: list, revoke one, revoke all, revoke by token
- [ ] Password change revokes other sessions
- [ ] Secure token storage in Flutter (flutter_secure_storage → Keychain/Keystore)

### Authorization
- [ ] Spatie Laravel Permission (roles + permissions)
- [ ] Policy-based row-level authorization
- [ ] Visibility scopes for all sensitive resources
- [ ] Employee cannot access other employees' data
- [ ] Project Manager sees only managed projects/sites
- [ ] Finance permissions ≠ HR document access
- [ ] Summary permissions ≠ detailed payroll access
- [ ] Coarse gate + per-block checks for dashboards

### Rate Limiting
- [ ] 7 named rate limiters (login, password_reset, attendance, upload, write, device_token, export)
- [ ] Per-user limits for authenticated endpoints
- [ ] IP-based for unauthenticated (login, password reset)
- [ ] Limits in config/rate_limiting.php (not hardcoded)
- [ ] Tests verify limits trip correctly

### File Upload Security
- [ ] Extension validation (allowlist)
- [ ] MIME type validation (finfo)
- [ ] File size limits per type
- [ ] Image dimension/pixel limits
- [ ] Image re-encoding (removes EXIF/GPS)
- [ ] PDF byte-for-byte (no re-encoding)
- [ ] Byte signature validation
- [ ] Filename sanitization (server-minted names)
- [ ] Private storage (no public URLs)
- [ ] No path traversal in filenames
- [ ] Malware scanning architecture implemented

### Malware Scanning
- [ ] MalwareScanner interface abstraction
- [ ] ClamAvMalwareScanner (production)
- [ ] DisabledMalwareScanner (dev - fails closed)
- [ ] MockMalwareScanner (tests)
- [ ] Fail-closed design
- [ ] Quarantine directory configured
- [ ] ClamAV daemon integration
- [ ] Timeout and size limits

### Private File Access
- [ ] All sensitive files on private disk
- [ ] No public URLs for sensitive files
- [ ] Access via authenticated routes only
- [ ] Row-level policies enforced
- [ ] No paths in API responses
- [ ] Download routes with authentication + policy check
- [ ] `no-store`, `no-cache` headers on downloads

### SQL Injection Prevention
- [ ] Eloquent/Query Builder bindings
- [ ] No raw SQL with request input
- [ ] Filter/sort allow-lists
- [ ] Dynamic ORDER BY validated
- [ ] Search input parameterized

### XSS/Content Safety
- [ ] API returns JSON only
- [ ] Flutter treats text as text
- [ ] PDF generation escapes output
- [ ] No untrusted HTML rendering

### API Error Security
- [ ] APP_DEBUG=false in production
- [ ] Safe structured error responses
- [ ] No stack traces in production
- [ ] No SQL queries in responses
- [ ] No filesystem paths in responses
- [ ] No credentials in responses

### HTTPS/TLS
- [ ] HTTPS only in production
- [ ] HSTS after verification
- [ ] Secure cookies (SESSION_SECURE_COOKIE=true)
- [ ] Secure links for password reset

### Security Headers
- [ ] Strict-Transport-Security (after HTTPS verified)
- [ ] X-Content-Type-Options: nosniff
- [ ] X-Frame-Options: DENY
- [ ] Referrer-Policy: strict-origin-when-cross-origin
- [ ] Permissions-Policy configured
- [ ] CSP where applicable

### CORS
- [ ] Explicit production origins
- [ ] No wildcard in production

### CSRF
- [ ] Sanctum bearer-token API (no CSRF needed)
- [ ] No cookie-based session routes without CSRF

### Firebase/FCM
- [ ] Service account via env (not committed)
- [ ] No sensitive data in push payloads
- [ ] Payload contains resource references only
- [ ] App re-authorizes after notification navigation
- [ ] DisabledFcmGateway when no credentials (fails closed)

### Audit Logging
- [ ] 15 models audited
- [ ] 14/15 spec mutations covered
- [ ] Sensitive data redacted (password, token, bank_*, _path, etc.)
- [ ] Module + action naming convention
- [ ] Filters: user, module, action, record type, date range
- [ ] Pagination with standard envelope
- [ ] Audit logs immutable (no update/delete by app users)

### Logging Security
- [ ] Structured logs
- [ ] No passwords/tokens in logs
- [ ] No FCM tokens in logs
- [ ] No bank details in logs
- [ ] No document content in logs
- [ ] No salary data unnecessarily

### Database Security
- [ ] Dedicated DB user (not root)
- [ ] Least privilege grants
- [ ] Encrypted casts for bank data
- [ ] APP_KEY rotation documented
- [ ] No root in application

### Backup Security
- [ ] Daily DB + file backups
- [ ] GPG encryption
- [ ] Off-site copy
- [ ] Retention documented
- [ ] Restore procedure documented
- [ ] Restore rehearsal performed

### Firebase/FCM
- [ ] Service account via env (file path or base64)
- [ ] No service account JSON in repo
- [ ] APNs Auth Key in Firebase (not committed)
- [ ] google-services.json / GoogleService-Info.plist git-ignored
- [ ] No sensitive data in push payloads

### Android Security
- [ ] Minimal permissions (location, camera only)
- [ ] No background location
- [ ] No cleartext traffic
- [ ] Network security config
- [ ] Release signing configured
- [ ] Keystore git-ignored
- [ ] Play App Signing recommended

### iOS Security
- [ ] ATS configured (HTTPS only)
- [ ] Keychain for secure storage
- [ ] Minimal permissions
- [ ] APNs Auth Key in Firebase
- [ ] Provisioning profiles managed by Xcode

### Build/Supply Chain
- [ ] composer.lock tracked
- [ ] pubspec.lock tracked
- [ ] Gradle wrapper trusted
- [ ] No binaries committed
- [ ] No untrusted build scripts

## Infrastructure Security

### Network
- [ ] Firewall: 80, 443 public; SSH, DB, Redis private
- [ ] DB bound to localhost/private IP
- [ ] Redis not public
- [ ] ClamAV socket not exposed

### SSH
- [ ] Key-based auth only
- [ ] Password auth disabled
- [ ] Non-root deploy user
- [ ] Fail2ban configured

### Database
- [ ] Dedicated user (not root)
- [ ] Least privilege
- [ ] Bound to localhost/private network
- [ ] Port 3306 not public

### Server Hardening
- [ ] Non-root deploy user
- [ ] SSH key-only auth
- [ ] Password auth disabled
- [ ] Fail2ban
- [ ] Unattended upgrades
- [ ] Log rotation

### Monitoring
- [ ] Health endpoint: /health
- [ ] Disk/CPU/RAM alerts
- [ ] Queue backlog alert
- [ ] Failed jobs alert
- [ ] Backup alerts
- [ ] Certificate expiry alerts
- [ ] ClamAV health
- [ ] Failed jobs monitoring

## Mobile Security

### Android
- [ ] Minimal permissions
- [ ] Network security config (no cleartext)
- [ ] Release signing configured
- [ ] Keystore secured
- [ ] Play App Signing enabled
- [ ] Permissions reviewed annually

### iOS
- [ ] ATS configured (HTTPS only)
- [ ] Keychain for tokens
- [ ] Minimal permissions
- [ ] APNs Auth Key in Firebase
- [ ] Provisioning profiles managed by Xcode

## Operational Security

### Secrets Management
- [ ] No secrets in Git
- [ ] .env not tracked
- [ ] Firebase creds via env
- [ ] SMTP creds via env
- [ ] DB passwords via env
- [ ] Signing keys via env
- [ ] GPG keys for backups via env

### Access Control
- [ ] Principle of least privilege
- [ ] Non-root deploy user
- [ ] SSH key-only auth
- [ ] Sudo restricted
- [ ] Audit trail for admin actions

### Incident Response
- [ ] Security incident runbook
- [ ] Breach notification procedure
- [ ] Key rotation procedure
- [ ] Backup restoration tested

## Compliance

### Data Protection
- [ ] Minimal data collection
- [ ] Purpose limitation
- [ ] Data retention documented
- [ ] Right to access/erasure supported
- [ ] Privacy policy accessible

### Audit Trail
- [ ] All sensitive mutations logged
- [ ] Audit logs immutable
- [ ] Audit logs access controlled
- [ ] Regular audit log review

## Testing Security

### Automated Tests
- [ ] IDOR tests
- [ ] Salary isolation tests
- [ ] Document isolation tests
- [ ] Private file access tests
- [ ] Malware scan flow tests
- [ ] Quarantine behavior tests
- [ ] Invalid MIME tests
- [ ] Disguised executable upload tests
- [ ] Rate limiting tests
- [ ] Password reset enumeration tests
- [ ] Session revocation tests
- [ ] Audit redaction tests
- [ ] Production error redaction tests
- [ ] Report/export authorization tests

### Manual Review
- [ ] Code review for security-sensitive changes
- [ ] Dependency audit monthly
- [ ] Penetration test annually
- [ ] Security training for team