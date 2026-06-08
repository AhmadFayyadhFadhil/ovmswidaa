# Scripts Directory

Utility scripts untuk Vehicle Request Management System.

## Directory Structure

### `/setup/`
Scripts untuk setup dan konfigurasi awal sistem:
- `assign_admin.php` - Assign role Admin ke user tertentu
- `create_kepala_hrdga.php` - Create Kepala Departemen HRD&GA user
- `reset_admin_password.php` - Reset password admin user

**Penggunaan:**
```bash
cd scripts/setup
php assign_admin.php
php create_kepala_hrdga.php
php reset_admin_password.php
```

### `/debug/`
Scripts untuk testing dan debugging:
- `call_login.php` - Test login API endpoint
- `check_password.php` - Verify password hash di database
- `debug_user.php` - Debug user data
- `pdo_user.php` - Direct PDO query untuk user management
- `pdo_sqlite_user.php` - SQLite direct query untuk user

**Penggunaan:**
```bash
cd scripts/debug
php call_login.php
php check_password.php
php debug_user.php
```

## Running Scripts

Semua scripts mengasumsikan dijalankan dari root project directory:
```bash
php scripts/setup/create_kepala_hrdga.php
php scripts/debug/call_login.php
```

Atau dari scripts directory:
```bash
cd scripts
php setup/create_kepala_hrdga.php
php debug/call_login.php
```
