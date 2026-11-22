# SIMRS Backend API (Yii2)

RESTful Backend API untuk **SIMRS** yang dibangun menggunakan **Yii2 Framework** dan database **PostgreSQL** (`simrs_db`), dilengkapi dengan sistem autentikasi **JWT (Bearer Token)**, manajemen **Users**, **Roles**, **Modules**, **Role-Based Module Permissions (CRUD)**, pencatatan aktivitas (**User Logs**), serta penanganan error berbasis **JSON API**.

---

## Fitur Utama

- **Authentication & Authorization (JWT)**
  - Login menggunakan `email` & `password` (kompatibel dengan hash bcrypt `$2b$` / `$2y$`).
  - Stateless JWT (`HS256`) dengan validasi `session_version` untuk mendukung pencabutan sesi instan saat logout atau perubahan password.
  - Role-Based Access Control (RBAC) berbasis `module_permission` JSON (`create`, `read`, `update`, `delete`) per `module_code`.
- **Manajemen Users, Roles, dan Modules**
  - CRUD Users (dengan dukungan *soft delete* via `deleted_at` maupun *hard delete* via `?force=1`).
  - CRUD Roles beserta pengaturan `module_permission` untuk setiap modul.
  - CRUD Modules dengan struktur hierarki menu (`parent_id` & `children` tree).
- **JSON API Error Handler**
  - Seluruh response dan error (`401 Unauthorized`, `403 Forbidden`, `404 Not Found`, `405 Method Not Allowed`, `422 Unprocessable Entity`, hingga `500 Internal Server Error`) dikembalikan secara konsisten dalam format JSON.
- **Konfigurasi Berbasis `.env`**
  - Seluruh pengaturan aplikasi (`APP_NAME`, `APP_VERSION`, `APP_ENV`, `APP_DEBUG`), koneksi database PostgreSQL, dan `JWT_SECRET` dikelola melalui file `.env`.

---

## Struktur Direktori Utama

```text
backend-yii2/
├── components/
│   ├── ApiErrorHandler.php   # Handler error global berbasis JSON API + CORS
│   ├── CorsHelper.php        # Handler CORS global & preflight OPTIONS (via .env)
│   ├── Env.php               # Loader & parser file .env
│   ├── JwtHelper.php         # Generator & validator token JWT (HS256)
│   └── PermissionFilter.php  # Middleware filter hak akses CRUD per module_code
├── config/
│   ├── console.php           # Konfigurasi aplikasi CLI/console
│   ├── db.php                # Konfigurasi koneksi PostgreSQL (via .env)
│   ├── params.php            # Parameter aplikasi & JWT (via .env)
│   └── web.php               # Konfigurasi web, JSON response, & routing REST API
├── controllers/
│   ├── SiteController.php    # Endpoint informasi status API (/) & fallback error JSON
│   └── api/
│       ├── BaseApiController.php # Base controller (CORS, JSON, Bearer Auth)
│       ├── AuthController.php    # Login, register, me, permissions, profile, logout
│       ├── UserController.php    # CRUD Users
│       ├── RoleController.php    # CRUD Roles & update permissions
│       └── ModuleController.php  # CRUD Modules & hierarchical tree
├── migrations/
│   ├── m261122_000001_create_roles_table.php            # Tabel roles + seeder
│   ├── m261122_000002_create_modules_table.php          # Tabel modules + seeder
│   ├── m261122_000003_create_users_table.php            # Tabel users + seeder
│   ├── m261122_000004_create_user_logs_table.php        # Tabel user_logs + seeder
│   └── m261122_000005_create_auth_rate_limits_table.php # Tabel auth_rate_limits + seeder
├── models/
│   ├── LoginForm.php         # Model validasi form login
│   ├── Module.php            # ActiveRecord tabel modules
│   ├── Role.php              # ActiveRecord tabel roles & parser module_permission
│   ├── User.php              # ActiveRecord tabel users & IdentityInterface
│   └── UserLog.php           # ActiveRecord tabel user_logs
├── .env                      # File konfigurasi environment lokal
└── .env.example              # Template konfigurasi environment
```

---

## Persyaratan Sistem

- **PHP** `>= 8.2` (dengan ekstensi `pdo_pgsql`, `pgsql`, `mbstring`, `openssl`, `json`)
- **PostgreSQL** `>= 13`
- **Composer**

---

## Instalasi & Konfigurasi

1. **Install Dependencies**
   ```bash
   composer install
   ```

2. **Konfigurasi Environment (`.env`)**
   Salin file `.env.example` menjadi `.env` dan sesuaikan dengan konfigurasi PostgreSQL lokal Anda:
   ```bash
   cp .env.example .env
   ```
   Contoh isi `.env`:
   ```env
   # Application Settings
   APP_ID=simrs-backend-yii2
   APP_NAME="SIMRS Backend API (Yii2)"
   APP_VERSION=1.0.0
   APP_ENV=dev
   APP_DEBUG=true
   APP_TIMEZONE=Asia/Jakarta
   COOKIE_VALIDATION_KEY=mSiaHuTNwdauegmAUvyFdqp97p7SduO5

   # Database Configuration (PostgreSQL)
   DB_CONNECTION=pgsql
   DB_HOST=127.0.0.1
   DB_PORT=5432
   DB_DATABASE=simrs_db
   DB_USERNAME=postgres
   DB_PASSWORD=postgres
   DB_CHARSET=utf8
   DB_SCHEMA_CACHE=false
   DB_SCHEMA_CACHE_DURATION=60

   # JWT Authentication
   JWT_SECRET=simrs-yii2-jwt-secret-key-2026-change-in-production
   JWT_TTL=604800

   # CORS Configuration (Frontend React: localhost:3000, frontend.example.com, dll)
   CORS_ALLOWED_ORIGINS=http://localhost:3000,http://127.0.0.1:3000,http://localhost:5173,https://frontend.example.com,http://frontend.example.com
   CORS_ALLOW_CREDENTIALS=true
   CORS_ALLOWED_METHODS="GET, POST, PUT, PATCH, DELETE, HEAD, OPTIONS"
   CORS_ALLOWED_HEADERS="Origin, X-Requested-With, Content-Type, Accept, Authorization, Cache-Control, Pragma"
   CORS_EXPOSED_HEADERS="X-Pagination-Total-Count, X-Pagination-Page-Count, X-Pagination-Current-Page, X-Pagination-Per-Page"
   CORS_MAX_AGE=86400
   ```

3. **Jalankan Migrasi & Seeder Database**
   Pastikan database PostgreSQL `simrs_db` sudah tersedia, lalu jalankan migrasi:
   ```bash
   ./yii migrate
   ```

4. **Jalankan Development Server**
   ```bash
   ./yii serve --port=8080
   ```
   Server dapat diakses pada `http://localhost:8080`.

---

## Daftar Migrasi & Akun Seeder Default

Setiap migrasi dijalankan secara berurutan dan langsung mengisi data awal (*seeder*):

| No | File Migrasi | Tabel | Jumlah Data Seeder |
| :- | :--- | :--- | :--- |
| 1 | `m261122_000001_create_roles_table` | `roles` | 4 role (`admin`, `user`, `superadmin`, `test-user`) |
| 2 | `m261122_000002_create_modules_table` | `modules` | 11 module/menu (Dashboard, Settings, Users, Roles, dll.) |
| 3 | `m261122_000003_create_users_table` | `users` | 20 user |
| 4 | `m261122_000004_create_user_logs_table` | `user_logs` | 2 log aktivitas awal |
| 5 | `m261122_000005_create_auth_rate_limits_table` | `auth_rate_limits` | 1 record rate limit |

### Akun Default Hasil Seeder:
| Role | Email | Password |
| :--- | :--- | :--- |
| **Administrator** (`admin`) | `admin@example.com` | `admin123` |
| **Regular User** (`user`) | `user@example.com` | `user123` |
| **Super Administrator** (`superadmin`) | `superadmin@example.com` | *(sesuai hash dump)* |

---

## Dokumentasi Endpoint REST API

Gunakan header berikut untuk endpoint yang membutuhkan autentikasi:
```http
Authorization: Bearer <jwt_token>
Content-Type: application/json
```

### 1. Root / Health Check
- **`GET /`** — Menampilkan informasi aplikasi dari `.env` (`APP_NAME`, `APP_VERSION`, `APP_ENV`).
  ```json
  {
    "success": true,
    "name": "SIMRS Backend API (Yii2)",
    "version": "1.0.0",
    "environment": "dev",
    "status": "online"
  }
  ```

### 2. Auth API (`/api/auth/*`)
| Method | Endpoint | Auth | Deskripsi |
| :--- | :--- | :---: | :--- |
| `POST` | `/api/auth/login` | Publik | Login dengan `email` & `password`, mengembalikan JWT `token`, `user`, `permissions`, dan `menus` |
| `POST` | `/api/auth/register` | Publik | Registrasi user baru |
| `GET` | `/api/auth/me` | Bearer | Mengambil data user yang sedang login beserta role, permissions, dan tree menu |
| `GET` | `/api/auth/permissions` | Bearer | Mengambil daftar permission atau cek akses spesifik `?module_code=users-list&action=create` |
| `PUT/PATCH` | `/api/auth/profile` | Bearer | Update profil user, password, `theme`, atau `sidebar_type` |
| `POST` | `/api/auth/logout` | Bearer | Logout & mencabut token aktif (increment `session_version`) |

### 3. Users API (`/api/users/*`) — Module Code: `users-list`
| Method | Endpoint | Permission | Deskripsi |
| :--- | :--- | :---: | :--- |
| `GET` | `/api/users` | `read` | List user dengan paginasi & filter (`?search=`, `?role_id=`, `?is_active=`, `?include_deleted=`, `?page=`, `?per_page=`) |
| `GET` | `/api/users/{id}` | `read` | Detail user berdasarkan ID |
| `POST` | `/api/users` | `create` | Tambah user baru |
| `PUT/PATCH` | `/api/users/{id}` | `update` | Update data user |
| `DELETE` | `/api/users/{id}` | `delete` | Soft delete user (`deleted_at`), atau hard delete dengan `?force=1` |

### 4. Roles & Permissions API (`/api/roles/*`) — Module Code: `users-roles`
| Method | Endpoint | Permission | Deskripsi |
| :--- | :--- | :---: | :--- |
| `GET` | `/api/roles` | `read` | List seluruh role beserta `module_permission` |
| `GET` | `/api/roles/{id}` | `read` | Detail role berdasarkan ID |
| `POST` | `/api/roles` | `create` | Buat role baru |
| `PUT/PATCH` | `/api/roles/{id}` | `update` | Update `name`, `display_name`, dan `module_permission` |
| `PUT/PATCH` | `/api/roles/{id}/permissions` | `update` | Khusus memperbarui daftar `module_permission` pada role |
| `DELETE` | `/api/roles/{id}` | `delete` | Hapus role |

### 5. Modules API (`/api/modules/*`) — Module Code: `settings-modules`
| Method | Endpoint | Permission | Deskripsi |
| :--- | :--- | :---: | :--- |
| `GET` | `/api/modules` | `read` | List seluruh module (flat) |
| `GET` | `/api/modules/tree` | `read` | List seluruh module dalam bentuk hierarki pohon (`parent_id` -> `children`) |
| `GET` | `/api/modules/{id}` | `read` | Detail module beserta sub-module (`children`) |
| `POST` | `/api/modules` | `create` | Buat module baru |
| `PUT/PATCH` | `/api/modules/{id}` | `update` | Update data module |
| `DELETE` | `/api/modules/{id}` | `delete` | Hapus module |

---

## Contoh Request & Format Error JSON

### Contoh Login
```bash
curl -X POST http://localhost:8080/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email": "admin@example.com", "password": "admin123"}'
```

### Format Standar Error JSON
Seluruh error (`401`, `403`, `404`, `422`, `500`) selalu dikembalikan dalam bentuk JSON:
```json
{
  "success": false,
  "status": 404,
  "error": "Not Found",
  "message": "Page not found."
}
```
