# HRMS — React Native app (Expo)

The new mobile app, replacing the Flutter app in `../mobile` module by
module. It talks to the same Laravel API (`../backend`).

## Server address (env file)

Copy `.env.example` to `.env` and set:

```
EXPO_PUBLIC_API_URL=https://<your-backend-url>/api/v1
EXPO_PUBLIC_ALLOW_SERVER_SETTING=true   # false for store builds
```

- Laptop testing: `http://192.168.1.164:8000/api/v1`
- Staging (Laravel Cloud): `https://<app>.laravel.cloud/api/v1`

The value is baked in when the app is built — change the file, rebuild.

## Run during development

```
npm install
npx expo run:android      # builds and installs on a connected phone / emulator
```

## Build an APK to install directly

```
npx expo prebuild --platform android
cd android && ./gradlew assembleRelease
# → android/app/build/outputs/apk/release/app-release.apk
```

Or in the cloud (needs a free Expo account): `npx eas-cli@latest build -p android --profile preview`.

## Store builds (later)

`npx eas-cli@latest build -p android` (Play Store .aab) and
`npx eas-cli@latest build -p ios` (App Store) — see `../docs/ANDROID_RELEASE.md`
and `../docs/IOS_RELEASE.md` for signing and store listing.

## What is in this app so far (Stage 1)

- Sign in, forgot password (asks HR), set your own password on first sign-in
- Dashboard: today's attendance, my leave left, notifications bell
- Attendance: check in (location + selfie), check out
- Leave: apply, my requests, approve / reject (with the extra-days choice
  for HR), cancel, medical certificate upload
- Leave balances, notifications

## Checks

```
npm run typecheck
npm run lint
```
