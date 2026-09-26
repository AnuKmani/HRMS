# HRMS — Advanced HRMS + Multi-Site Workforce Management System

A production-oriented **Human Resource Management System** built for an **MEP / Construction company** with multiple projects and multiple physical sites.

---

## 1. Project Purpose

Construction and MEP companies have a workforce that does not sit at one desk. An employee may have a **primary site**, be **temporarily assigned** to another, or **move between several sites in a single workday**. A generic HRMS does not model this.

This system is built around that real-world reality:

| Capability | Why it matters |
|---|---|
| **Multi-site workforce** | Employees are assigned to sites historically, not overwritten |
| **GPS + geofence attendance** | Check-in only accepted inside the configured site radius |
| **Selfie attendance** | Proof of presence at the site, captured on the front camera |
| **Site visits / movement** | Records travel between sites during the workday |
| **Offline-first attendance** | Construction sites often have poor connectivity |
| **Configurable rules** | Working hours, grace period, OT threshold, geofence radius, leave rules — none hard-coded |
| **Full payroll cycle** | Attendance + approved overtime + LOP feed payroll |
| **Document expiry tracking** | Passport, visa, Emirates ID, certificates with scheduled reminders |

---

## 2. Technology Stack

### Frontend — Flutter

| Item | Value |
|---|---|
| Framework | **Flutter** (Dart) |
| Design system | **Material 3** |
| Target | Android first, architecture ready for iOS |
| State management | **Riverpod** |
| Routing | **GoRouter** |
| HTTP | **Dio** |
| Secure token storage | `flutter_secure_storage` (Keychain / Keystore) |
| Local database (offline) | **Drift** (SQLite) |
| Camera / location | `camera`, `geolocator` |
| Push notifications | **Firebase Cloud Messaging** |
| PDF generation | `pdf` |

**Why Flutter?** One codebase for Android now and iOS later, first-class camera/GPS access, and strong offline database support — all required for site-based attendance.

### Backend — Laravel

| Item | Value |
|---|---|
| Framework | **Laravel** (PHP 8.2+) |
| Interface | **RESTful API** (`/api/v1/`) |
| Authentication | **Laravel Sanctum** (token-based) |
| RBAC | `spatie/laravel-permission` (roles + granular permissions) |
| Audit logging | `spatie/laravel-activitylog` |
| PDF | `barryvdh/laravel-dompdf` |
| Architecture | Controllers → **Services** (business rules) → Models |

**Why Laravel?** All critical business rules — geofence validation, leave balance, LOP conversion, payroll calculation, authorization — must be enforced **server-side**. The mobile app is never trusted with these decisions.

### Database — MariaDB

- **MariaDB 10.4.28** — host `127.0.0.1`, port `3306` (bundled with XAMPP)
- Application database **`hrms_laravel`**, accessed by the dedicated user **`hrms_app`** — never `root`
- Normalized schema with primary keys, foreign keys, indexes, unique constraints
- Soft deletes where records must be recoverable
- Timestamps on all tables

**Why MariaDB?** Mature, reliable, well-understood, and easy to back up and restore.

### Notifications — Firebase Cloud Messaging (FCM)

- Push notifications for approvals, reminders, expiry warnings, payroll availability
- Delivery is handled **server-side** by Laravel via queued jobs
- The Flutter app only registers/unregisters device tokens

### Private File Storage

Selfies, passports, Emirates IDs, visas, contracts and salary documents are stored in **private storage** and served only through **authorized, temporary/signed URLs**. They are never placed in a public directory.

---

## 3. Architecture Overview

```
┌────────────────────┐
│   Flutter Mobile   │  Riverpod → Repository → Dio
└─────────┬──────────┘
          │ HTTPS
┌─────────▼──────────┐
│  Laravel REST API  │  Route → Controller → FormRequest
└─────────┬──────────┘         ↓
          │              Service (business rules)
          │                     ↓
          │              Policy (authorization)
          │                     ↓
┌─────────▼──────────┐   API Resource (response shape)
│     MariaDB        │
└─────────┬──────────┘
          │
┌─────────▼──────────┐
│ Private File Store │  + Queue Worker + Scheduler + FCM
└────────────────────┘
```

**Non-negotiable rule:** the Flutter app may *display* distance-from-site for user experience, but **Laravel performs the final geofence validation**. Client-side GPS is never trusted.

---

## 4. Folder Structure

```
HRMS/
│
├── mobile/                  # Flutter application (not yet created)
│   └── lib/
│       ├── core/            # config, constants, errors, network, storage, utils, widgets
│       └── features/        # auth, dashboard, employees, attendance, site_visits,
│                            # projects, sites, leave, timesheet, overtime, payroll,
│                            # documents, expenses, loans, training, assets, notifications
│
├── backend/                 # Laravel REST API (not yet created)
│   ├── app/
│   │   ├── Http/            # Controllers, FormRequests, Resources
│   │   ├── Models/          # Eloquent models
│   │   ├── Services/        # Business logic (geofence, leave, payroll)
│   │   ├── Policies/        # Authorization
│   │   ├── Jobs/            # Queued work
│   │   └── Notifications/   # FCM + database notifications
│   ├── database/migrations/
│   └── routes/api.php
│
├── docs/                    # Project documentation
│   ├── ARCHITECTURE.md
│   ├── DATABASE.md
│   ├── API_DOCUMENTATION.md
│   ├── FLUTTER_GUIDE.md
│   ├── DEPLOYMENT.md
│   ├── SECURITY.md
│   ├── TESTING.md
│   └── USER_GUIDE.md
│
├── scripts/                 # Local dev helpers (start the database, etc.)
│
├── README.md                # This file
└── .gitignore
```

---

## 5. User Roles

Access is controlled by **roles** *and* **granular permissions**. Hiding a button in Flutter is not authorization — Laravel enforces every rule.

| # | Role |
|---|---|
| 1 | Super Admin |
| 2 | HR Admin |
| 3 | HR Executive |
| 4 | Payroll Admin |
| 5 | Project Manager |
| 6 | Site Engineer |
| 7 | Site Supervisor |
| 8 | Finance |
| 9 | Management |
| 10 | Employee |

Example permissions: `employees.view`, `employees.create`, `attendance.manage`, `leave.approve`, `payroll.manage`, `sites.manage`, `audit.view`.

---

## 6. Getting Started

### Prerequisites

| Tool | Version | Notes |
|---|---|---|
| PHP | 8.2+ | with `pdo_mysql`, `openssl`, `curl`, `mbstring`, `zip`, `gd`, `intl`, `bcmath`, `fileinfo` |
| Composer | 2.x | |
| MariaDB | 10.4.28 | Bundled with XAMPP; connects on `127.0.0.1:3306` |
| Git | 2.x | |
| Flutter | stable | **Installed on a drive with adequate free space** |
| JDK | 17 | Required for Android builds |
| Android SDK | API 35 | With `platform-tools` and `build-tools` |

> **Disk space note:** The Flutter SDK, Android SDK, JDK, Gradle cache and pub cache together need roughly **8–12 GB**. These are installed on a secondary drive (`F:`), *not* on the system drive, to avoid filling the primary disk.

### Backend

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
# configure DB_* in .env
php artisan migrate --seed
php artisan serve          # http://127.0.0.1:8000
```

### Frontend

```bash
cd mobile
flutter pub get
flutter run                # with a device or emulator connected
```

---

## 7. Documentation

| File | Contents |
|---|---|
| [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) | System design, folder layout, key decisions |
| [`docs/DATABASE.md`](docs/DATABASE.md) | Schema, tables, relationships, ERD notes |
| [`docs/API_DOCUMENTATION.md`](docs/API_DOCUMENTATION.md) | Endpoints, request/response formats |
| [`docs/FLUTTER_GUIDE.md`](docs/FLUTTER_GUIDE.md) | Flutter patterns, concepts, how features are built |
| [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) | Server setup, queue worker, scheduler, backups |
| [`docs/SECURITY.md`](docs/SECURITY.md) | Auth, RBAC, file access, secrets handling |
| [`docs/TESTING.md`](docs/TESTING.md) | Test strategy and how to run tests |
| [`docs/USER_GUIDE.md`](docs/USER_GUIDE.md) | End-user workflows by role |

---

## 8. Current Status

### ✅ Phase 1 — Foundation (IN PROGRESS)

| Step | Status |
|---|---|
| Workspace inspection | ✅ Complete |
| Environment inspection (PHP, Composer, MariaDB, Git) | ✅ Complete |
| Git repository initialized | ✅ Complete |
| Monorepo folder structure | ✅ Complete |
| Root `.gitignore` | ✅ Complete |
| Root `README.md` | ✅ Complete |
| `docs/` documentation set | ✅ Complete |
| MariaDB database + dedicated `hrms_app` user | ✅ Complete |
| Laravel 12 API skeleton + Sanctum (`/api/v1/`) | ✅ Complete |
| Flutter toolchain install (on `F:`) | ⬜ Not started |
| Flutter project skeleton | ⬜ Not started |

### Planned Phases

| Phase | Scope |
|---|---|
| **1b** | Flutter toolchain + Flutter project skeleton |
| **2** | Core database schema + seeders (roles, permissions, settings) |
| **3** | Authentication (Sanctum) + Flutter auth feature |
| **4** | Employees, Projects, Sites, Assignments (first vertical slice) |
| **5** | Attendance: geofence, selfie, check-in/out, audit |
| **6** | Offline synchronization |
| **7** | Site visits, movement timeline, activity & daily reports |
| **8** | Shifts, timesheets, overtime |
| **9** | Leave, balances, sick-cert → LOP automation |
| **10** | Holidays, documents + expiry, onboarding, training, assets |
| **11** | Payroll, salary slips, certificates, loans, expenses |
| **12** | FCM notifications, dashboards, reports & exports |
| **13** | Testing, security audit, deployment, backups |

---

## 9. Security Notes

**Never commit:**

```
.env
*.jks / *.keystore / *.pem
google-services.json
GoogleService-Info.plist
*firebase-adminsdk*.json
database passwords / API keys / private certificates
```

All of the above are blocked by the root `.gitignore`. See [`docs/SECURITY.md`](docs/SECURITY.md).

---

## 10. License

Private / proprietary. Not published.
