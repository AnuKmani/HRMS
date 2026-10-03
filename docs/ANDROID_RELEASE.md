# HRMS Android Release Checklist

## Prerequisites

- [ ] Android Studio installed (latest stable)
- [ ] Flutter SDK 3.13+ installed
- [ ] Java 17 JDK installed
- [ ] Android SDK with API level 34 (compileSdk) and minSdk 24+
- [ ] Google Play Console access (for production release)
- [ ] Firebase project configured for Android app

## Keystore Generation

### Generate Release Keystore

```bash
# Generate keystore (run once, store securely)
keytool -genkey -v \
  -keystore upload-keystore.jks \
  -keyalg RSA \
  -keysize 2048 \
  -validity 10000 \
  -alias upload
```

### Fill in key.properties

```properties
# android/key.properties (git-ignored)
storeFile=../upload-keystore.jks
storePassword=<strong_password>
keyAlias=upload
keyPassword=<strong_password>
```

**Security Notes:**
- Never commit `key.properties`, `*.jks`, `*.keystore`
- Store keystore and passwords in secure password manager
- Backup keystore to multiple secure locations
- Losing keystore = cannot update app on Play Store

## Release Configuration

### Android Manifest Permissions

Current permissions (android/app/src/main/AndroidManifest.xml):

```xml
<!-- GPS Attendance (foreground only) -->
<uses-permission android:name="android.permission.ACCESS_FINE_LOCATION"/>
<uses-permission android:name="android.permission.ACCESS_COARSE_LOCATION"/>

<!-- Check-in Selfie -->
<uses-permission android:name="android.permission.CAMERA"/>
<uses-feature android:name="android.hardware.camera" android:required="false"/>
<uses-feature android:name="android.hardware.camera.front" android:required="false"/>
```

**Review checklist:**
- [ ] No `ACCESS_BACKGROUND_LOCATION` (intentional - app doesn't track in background)
- [ ] No `INTERNET` explicitly needed (Flutter adds automatically)
- [ ] No `READ_EXTERNAL_STORAGE` / `WRITE_EXTERNAL_STORAGE` (using scoped storage via file_picker)
- [ ] No `RECORD_AUDIO` (not used)
- [ ] No `READ_CONTACTS`, `READ_PHONE_STATE`, etc. (not used)

### Network Security

In `android/app/src/main/res/xml/network_security_config.xml`:

```xml
<?xml version="1.0" encoding="utf-8"?>
<network-security-config>
    <domain-config cleartextTrafficPermitted="false">
        <domain includeSubdomains="true">yourdomain.com</domain>
    </domain-config>
    <base-config cleartextTrafficPermitted="false" />
</network-security-config>
```

In AndroidManifest.xml application tag:
```xml
<application
    android:networkSecurityConfig="@xml/network_security_config"
    ... >
```

### Build Configuration

In `android/app/build.gradle.kts`:

```kotlin
android {
    compileSdk = flutter.compileSdkVersion
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    defaultConfig {
        applicationId = "com.example.mobile"
        minSdk = flutter.minSdkVersion
        targetSdk = flutter.targetSdkVersion
        versionCode = flutter.versionCode
        versionName = flutter.versionName
    }

    signingConfigs {
        create("release") {
            val keyProps = Properties()
            val keyPropsFile = rootProject.file("key.properties")
            if (keyPropsFile.exists()) {
                keyProps.load(keyPropsFile.inputStream())
                storeFile = keyPropsFile.parentFile.let { File(it.path, keyProps["storeFile"] as String) }
                storePassword = keyProps["storePassword"] as String
                keyAlias = keyProps["keyAlias"] as String
                keyPassword = keyProps["keyPassword"] as String
            }
        }
    }

    buildTypes {
        release {
            signingConfig = signingConfigs.getByName(
                if (file("key.properties").exists()) "release" else "debug"
            )
        }
    }
}
```

### Version Management

Update version in `pubspec.yaml`:

```yaml
version: 1.0.0+1
# version: <major>.<minor>.<patch>+<build_number>
```

Version code must increment for each Play Store upload.

## Build Commands

### Local Testing (Debug Keys)

```bash
# Quick release build with debug keys (for testing)
flutter build apk --release
flutter build appbundle --release
```

### Production Build (Release Keys)

```bash
# Ensure key.properties exists in android/
flutter build apk --release
flutter build appbundle --release
```

Output locations:
- APK: `build/app/outputs/flutter-apk/app-release.apk`
- AAB: `build/app/outputs/bundle/release/app-release.aab`

## Artifact Validation

### Check Release Build

```bash
# Verify APK is signed
apksigner verify --print-certs build/app/outputs/flutter-apk/app-release.apk

# Verify AAB
bundletool validate --bundle build/app/outputs/bundle/release/app-release.aab

# Check permissions
aapt2 dump badging build/app/outputs/flutter-apk/app-release.apk | grep uses-permission
```

### Verify No Debug/Dev Artifacts

```bash
# Check for debug flags
aapt2 dump badging build/app/outputs/flutter-apk/app-release.apk | grep -i debuggable
# Should return: application: label='HRMS' ... debuggable=false

# Check for development URLs
unzip -l build/app/outputs/flutter-apk/app-release.apk | grep -E "(10\.0\.2\.2|localhost|127\.0\.0\.1)"
# Should return nothing
```

### Security Scan (if available)

```bash
# MobSF (Mobile Security Framework) - if available
# docker run -it --rm -v $(pwd)/build/app/outputs/flutter-apk:/apk opensecurity/mobile-security-framework-mobsf:latest

# Or use Google Play's internal scanning (automatic on upload)
```

### Artifact Hashes

```bash
# Record SHA-256 for integrity verification
sha256sum build/app/outputs/flutter-apk/app-release.apk > app-release.apk.sha256
sha256sum build/app/outputs/bundle/release/app-release.aab > app-release.aab.sha256

# Record in release notes
cat app-release.apk.sha256
cat app-release.aab.sha256
```

## Google Play Console Release

### Internal Testing Track

1. Upload AAB to Internal Testing
2. Add testers (emails)
3. Test on physical devices
4. Verify all flows: login, attendance, leave, payroll, documents, notifications

### Closed Testing Track

1. Promote from Internal Testing
2. Add larger tester group
3. Run pre-launch report (Google runs automatically)

### Open Testing / Production

1. Complete all testing
2. Fill Store Listing (screenshots, descriptions, privacy policy)
3. Content rating questionnaire
4. Target audience
4. Release to production

## Version History Template

```
## [1.0.0] - 2024-01-15
### Added
- Initial release
- Employee attendance with GPS + selfie
- Leave management with approval workflow
- Payroll processing with salary slips
- Document management with expiry tracking
- Training & asset management
- Notifications (FCM + in-app)
- Role-based dashboards
- Reports with CSV/Excel/PDF export

### Security
- FCM push notifications (encrypted payloads)
- Malware scanning for uploads (ClamAV)
- Audit logging for sensitive operations
- Private file storage with encryption

### Fixed
- N/A (initial release)
```

## Post-Release Monitoring

- [ ] Monitor Play Console crash reports (first 48 hours)
- [ ] Monitor Firebase Crashlytics (if enabled)
- [ ] Monitor Play Console ANR rate
- [ ] Monitor API error rates (5xx)
- [ ] Monitor queue worker health
- [ ] Verify FCM delivery rates in Firebase Console

## Rollback Plan

If critical issue found post-release:

1. **Hotfix**: Create hotfix branch, increment version, rebuild, re-release
2. **Rollback**: If severe, unpublish from Play Console (removes from new installs)
3. **Database**: Ensure migrations are backward-compatible or have rollback plan

## Security Notes

- Never commit `key.properties`, `*.jks`, `*.keystore`
- Rotate keystore only when absolutely necessary (blocks updates)
- Use Play App Signing (Google manages release key)
- Enable Play Integrity API for anti-tampering
- Review permissions annually
- Keep Flutter and dependencies updated