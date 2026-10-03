# HRMS iOS Release Checklist

## Prerequisites

**CRITICAL: iOS builds require macOS + Xcode. Cannot build from Windows/Linux.**

- [ ] macOS (latest stable) with Xcode 15+
- [ ] Apple Developer Program membership ($99/year)
- [ ] Flutter SDK 3.13+ installed
- [ ] CocoaPods installed (`sudo gem install cocoapods`)
- [ ] Apple Developer account with App Store Connect access
- [ ] Firebase project configured for iOS app

## Bundle Identifier & Team

### Bundle ID

```yaml
# In pubspec.yaml
version: 1.0.0+1
```

In Xcode:
- Bundle Identifier: `com.yourcompany.hrms` (must match Firebase iOS app)
- Team: Your Apple Developer Team

### App Store Connect Setup

1. Create app in App Store Connect
2. Bundle ID: `com.yourcompany.hrms`
3. SKU: `HRMS-IOS-001`
3. Bundle ID: Explicit (not wildcard)

## Info.plist Permissions

### Required Permissions (ios/Runner/Info.plist)

```xml
<!-- Camera - Check-in Selfie -->
<key>NSCameraUsageDescription</key>
<string>HRMS needs camera access to capture check-in selfies for attendance verification.</string>

<!-- Location - GPS Attendance -->
<key>NSLocationWhenInUseUsageDescription</key>
<string>HRMS uses your location to verify you are at the work site when checking in/out.</string>
<key>NSLocationAlwaysAndWhenInUseUsageDescription</key>
<string>HRMS uses your location to verify you are at the work site when checking in/out.</string>

<!-- Photos - Document Upload -->
<key>NSPhotoLibraryUsageDescription</key>
<string>HRMS needs photo library access to upload documents, receipts, and certificates.</string>
<key>NSPhotoLibraryAddUsageDescription</key>
<string>HRMS needs permission to save generated documents (salary slips, certificates) to your photo library.</string>

<!-- Notifications -->
<key>UIBackgroundModes</key>
<array>
    <string>remote-notification</string>
</array>
```

### Permission Justification

| Permission | Purpose | Required |
|------------|---------|----------|
| Camera | Check-in selfie for attendance | Yes |
| Location (When In Use) | Geofence verification for attendance | Yes |
| Location (Always) | Optional: background location for future features | No (not requested) |
| Photo Library | Upload documents, receipts, certificates | Yes |
| Photo Library Add | Save generated PDFs (salary slips) | Yes |
| Notifications | FCM push notifications | Yes |
| Background App Refresh | Not required (FCM handles via APNs) | No |

**Do NOT request:**
- `NSLocationAlwaysUsageDescription` (no background tracking)
- `NSMicrophoneUsageDescription` (no audio recording)
- `NSContactsUsageDescription` (no contacts access)
- `NSBluetoothPeripheralUsageDescription` (no Bluetooth)
- `NSSpeechRecognitionUsageDescription` (no speech)

## Firebase iOS Setup

### Firebase Console

1. Add iOS app to Firebase project
2. Bundle ID: `com.yourcompany.hrms`
3. Download `GoogleService-Info.plist`
4. Place in `ios/Runner/GoogleService-Info.plist` (git-ignored)

### APNs Configuration

1. **APNs Auth Key** (recommended):
   - Apple Developer → Certificates, Identifiers & Profiles → Keys
   - Create key with "Apple Push Notifications service (APNs)"
   - Download `.p8` file (save securely)
   - Key ID: `ABC123DEFG`
   - Team ID: `TEAM123456`
   - Upload to Firebase Console → Project Settings → Cloud Messaging → iOS app → APNs Auth Key

2. **Alternative: APNs Certificate** (legacy):
   - Create "Apple Push Notification service SSL (Sandbox & Production)" certificate
   - Export as `.p12`, upload to Firebase

### Firebase Config

```bash
# Add to ios/Runner/GoogleService-Info.plist (git-ignored)
# Already contains: API_KEY, GCM_SENDER_ID, PROJECT_ID, etc.
```

## Capabilities (Xcode)

In Xcode → Runner → Signing & Capabilities:

### Required Capabilities
- [ ] **Push Notifications** (for FCM)
- [ ] **Background Modes** → **Remote notifications** (for FCM background delivery)

### Not Required (Don't Enable)
- [ ] Background App Refresh (handled by APNs)
- [ ] Background Fetch
- [ ] Location Updates (foreground only)

## Build Configuration

### Release Build Settings

In Xcode → Runner → Build Settings:

```bash
# Code Signing
CODE_SIGN_STYLE = Automatic
DEVELOPMENT_TEAM = <Your Team ID>
PROVISIONING_PROFILE_SPECIFIER = ""  # Automatic

# Deployment
IPHONEOS_DEPLOYMENT_TARGET = 13.0  # Minimum iOS version
TARGETED_DEVICE_FAMILY = "1,2"  # iPhone + iPad

# Security
ENABLE_BITCODE = NO  # Deprecated in Xcode 14+
STRIP_INSTALLED_PRODUCT = YES
DEPLOYMENT_POSTPROCESSING = YES
```

### Build Configurations

Ensure `Release` configuration:
- [ ] `DEBUG_INFORMATION_FORMAT = dwarf-with-dsym` (for crash symbolication)
- [ ] `STRIP_INSTALLED_PRODUCT = YES`
- [ ] `DEPLOYMENT_POSTPROCESSING = YES`

## Build Commands

### Local Development (Simulator)

```bash
flutter run --release -d ios
```

### Release Build (Device)

```bash
# Build for device (requires connected iOS device or simulator)
flutter build ios --release

# Or use Xcode directly:
# Product > Archive
```

### Archive & Upload (Xcode)

```bash
# 1. Open ios/Runner.xcworkspace in Xcode
# 2. Select "Any iOS Device (arm64)" as destination
# 3. Product > Archive
# 4. Window > Organizer > Archives
# 5. Select archive > Distribute App
# 6. App Store Connect > Upload
# 6. Validate > Distribute
```

## Artifact Validation

### Check Archive

```bash
# After Archive in Xcode:
# Window > Organizer > Archives
# Select archive > Validate App
```

### Validate No Debug/Dev Artifacts

```bash
# In terminal
cd build/ios/Release-iphoneos
# Check for debug symbols
dsymutil -h Runner.app/Runner

# Verify no debug flags
codesign -dvvv Runner.app | grep -i debuggable
# Should show: flags=0x0 (not debuggable)
```

### Verify No Dev URLs

```bash
# Check for development URLs
strings Runner.app/Runner | grep -E "(10\.0\.2\.2|localhost|127\.0\.0\.1|192\.168\.)"
# Should return nothing
```

### Entitlements Check

```bash
# Check entitlements
codesign -d --entitlements :- Runner.app
# Should show: aps-environment = production, get-task-allow = false
```

## TestFlight & App Store

### TestFlight

1. Upload via Xcode Organizer or `xcrun altool`
2. Add internal testers (up to 100)
3. Add external testers (up to 10,000) after review
4. Test on multiple iOS versions (13, 14, 15, 16, 17)

### App Store Connect

1. Fill App Information:
   - Name: HRMS
   - Subtitle: Human Resource Management System
   - Category: Business
   - Privacy Policy URL: `https://yourdomain.com/privacy`
   - Support URL: `https://yourdomain.com/support`

2. Pricing & Availability:
   - Free
   - All countries (or select)

3. App Privacy:
   - Data Types: Location, Identifiers, Usage Data, Diagnostics
   - Data Linked to You: Yes
   - Data Used to Track You: No

4. Age Rating: 4+ (no objectionable content)

5. Version Information:
   - Version: 1.0.0
   - Build: 1
   - Release Notes: "Initial release with attendance, leave, payroll, documents, notifications"

## App Store Review Guidelines Compliance

### Common Rejection Reasons to Avoid

| Guideline | HRMS Compliance |
|-----------|-----------------|
| 2.1 - App Completeness | All features functional |
| 2.3 - Accurate Metadata | Accurate screenshots, descriptions |
| 2.5.1 - Background Location | Not requested |
| 2.5.2 - Camera Access | Explained in usage description |
| 2.5.3 - Photo Library | Explained in usage description |
| 2.5.4 - Push Notifications | Opt-in, relevant content only |
| 3.1.1 - Payment | No in-app purchases |
| 5.1.1 - Data Collection | Privacy policy, minimal data |
| 5.1.2 - Data Use | Only for stated purposes |
| 5.1.3 - Data Sharing | No third-party sharing |
| 5.1.4 - Data Retention | Documented in privacy policy |

## Version Management

```yaml
# pubspec.yaml
version: 1.0.0+1
# version: <major>.<minor>.<patch>+<build_number>
# build_number must increment for each App Store upload
```

## Build Commands

```bash
# Clean build
flutter clean
flutter pub get
cd ios && pod install && cd ..

# Build for release
flutter build ios --release

# Archive via Xcode (required for App Store)
# Product > Archive in Xcode
```

## Post-Release Monitoring

- [ ] Monitor App Store Connect crash reports (first 48 hours)
- [ ] Monitor App Store Connect analytics
- [ ] Monitor Firebase Crashlytics (if enabled)
- [ ] Monitor API error rates
- [ ] Verify FCM/APNs delivery in Firebase Console

## Rollback Plan

If critical issue:
1. Submit hotfix with incremented build number
2. Or: Remove from sale in App Store Connect (removes from new downloads)
3. Database: Ensure backward compatibility

## iOS-Specific Security

### Keychain Storage

Flutter `flutter_secure_storage` uses iOS Keychain:
- Tokens stored in Keychain (not UserDefaults)
- Keychain items persist after app deletion (by design)
- Use `a` (accessible when unlocked) or `ak` (accessible always) appropriately

### App Transport Security

```xml
<!-- ios/Runner/Info.plist -->
<key>NSAppTransportSecurity</key>
<dict>
    <key>NSAllowsArbitraryLoads</key>
    <false/>
    <key>NSExceptionDomains</key>
    <dict>
        <key>yourdomain.com</key>
        <dict>
            <key>NSIncludesSubdomains</key>
            <true/>
            <key>NSExceptionRequiresForwardSecrecy</key>
            <true/>
            <key>NSExceptionMinimumTLSVersion</key>
            <string>TLSv1.2</string>
        </dict>
    </dict>
</dict>
```

### Code Signing

- Use Automatic signing in Xcode
- Provisioning profiles managed by Xcode
- Export compliance: HRMS uses encryption (HTTPS, Keychain) → Qualifies for "uses encryption" exemption (standard HTTPS)

## Known iOS Limitations

| Feature | Status | Workaround |
|---------|--------|------------|
| Background location | Not implemented | Not requested |
| Background FCM | Works via APNs | Requires `remote-notification` background mode |
| File picker | Works | Uses `UIDocumentPickerViewController` |
| Camera | Works | `UIImagePickerController` via `camera` plugin |
| Biometric auth | Not implemented | Could add via `local_auth` |

## Release Checklist

- [ ] `GoogleService-Info.plist` in `ios/Runner/`
- [ ] APNs Auth Key uploaded to Firebase
- [ ] `Info.plist` has all required usage descriptions
- [ ] Push Notifications capability enabled
- [ ] Background Modes → Remote notifications enabled
- [ ] `NSAppTransportSecurity` configured for HTTPS only
- [ ] Bundle ID matches Firebase iOS app
- [ ] Team selected in Xcode
- [ ] Provisioning profile: Automatic
- [ ] Archive validates in Xcode
- [ ] TestFlight internal testing passed
- [ ] App Store Connect metadata complete
- [ ] Privacy policy URL accessible
- [ ] Support URL accessible
- [ ] Age rating: 4+
- [ ] Release notes written