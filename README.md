# Campus Facility Reservation

Web application for booking campus buildings and rooms: lecture rooms, labs, halls and
outdoor areas, with multi-level admin validation, class-schedule conflict checks,
reports and bulk import/export.

PHP (no framework) · MySQL/MariaDB · plain HTML/CSS/JS.
The interface is in **Indonesian**. The institution name, e-mail domains and all data in this
repository are **fictional placeholders** (`Universitas Contoh`, `university.example`).

## Features

- **Roles** — main admin, department admins, student-council (DMF) admin, and users.
- **Booking flow** — users request a room with an official letter (upload); the responsible
  admin validates. Availability is checked against approved bookings and the class schedule.
- **Room recommendation** (`user/rekomendasi.php`) — decision-tree style suggestions by
  capacity, facilities and time.
- **Bulk tools** — Excel (`.xlsx`) templates and importers for rooms, class schedules and
  bookings (built with PHP's `ZipArchive`, no third-party library).
- **Reports** — monthly reports with a printable letterhead (`admin/report_print.php`).
- **Outdoor areas** — requests routed to one of four WhatsApp groups by the requester's organisation.
- **Auth** — students sign in with an e-mail **OTP**; admins use e-mail + password.

## Quick start (XAMPP)

1. Start **Apache** and **MySQL**.
2. Create a database `campus_facility_reservation` (utf8mb4) and import
   `database/campus_facility_reservation.sql` (phpMyAdmin → *Import*, or
   `mysql -u root campus_facility_reservation < database/campus_facility_reservation.sql`).
3. Open <http://localhost/campus-facility-reservation/>.

With no extra config the app uses XAMPP defaults (`root`, empty password). No Composer step is needed.

### Demo accounts

Password for all admin accounts: **`Demo@12345`** (demo only — change it before real use).

| Role                  | E-mail                                        |
|-----------------------|-----------------------------------------------|
| Main admin            | `admin.fakultas@university.example`           |
| DMF admin             | `admin.dmf@university.example`                |
| Dept. admin           | `admin.matematika@university.example` (also `fisika`, `kimia`, `biologi`, `pendidikanipa`, `sainsterapan`) |
| Student               | any `…@students.university.example` — signs in via OTP (needs e-mail setup below) |

Admins sign in with an `@university.example` address; students with `@students.university.example`
or `@faculty.university.example`. The accepted domains are checked in `login.php`.

## Configuration

Credentials are never stored in the code. Copy the example and edit it:

```
cp includes/config.local.example.php includes/config.local.php
```

`includes/config.local.php` (git-ignored) defines the database settings and, optionally,
the e-mail settings for OTP delivery via the [Brevo](https://brevo.com) transactional API:

```php
define('BREVO_API_KEY',  'your-brevo-api-key');
define('SMTP_FROM',      'sender-verified-in-brevo@your-domain.com');
define('SMTP_FROM_NAME', 'Campus Facility Reservation');
```

Without a Brevo key, student OTP login fails with a friendly error message; admin login
works normally.

## Project layout

```
admin/      admin pages (bookings, rooms, users, schedule, reports, imports, templates)
user/       user pages (rooms, booking, my bookings, recommendations, OTP verification)
includes/   config, layout, mailer, shared partials
assets/     CSS / JS / images
uploads/    room photos and booking letters (contents are git-ignored)
database/   schema + demo seed
```

### Optional images

The landing and login pages show photos when present and fall back to a gradient otherwise.
Add your own to `assets/images/`: `campus-hero.webp`, `campus-login-bg.jpg`,
`campus-gallery-1.jpg` … `campus-gallery-9.jpg`, and `logo.png` for the report letterhead.

## Notes

- Tested on PHP 8.0 / MariaDB 10.4 (XAMPP). Needs `mysqli`, `curl`, `mbstring`, `zip`.
- Set display of PHP errors off in production (the bundled `.htaccess` does this on Apache).

## License

All rights reserved. This repository is public for portfolio viewing only; see [LICENSE](LICENSE). Reuse requires written permission.
