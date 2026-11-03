-- ============================================================
--  Campus Facility Reservation — Database Setup untuk XAMPP
--  Universitas Contoh
-- ============================================================
--  Password semua akun admin : Demo@12345
--  Login mahasiswa           : via OTP ke email @students.university.example
-- ============================================================
--  CARA IMPORT di phpMyAdmin:
--    1. Buka http://localhost/phpmyadmin
--    2. Klik "New" → Nama: campus_facility_reservation → Create
--    3. Klik database campus_facility_reservation → tab "Import"
--    4. Pilih file ini → klik "Go"
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
SET TIME_ZONE = '+07:00';

DROP TABLE IF EXISTS `notifikasi`;
DROP TABLE IF EXISTS `jadwal_kuliah`;
DROP TABLE IF EXISTS `peminjaman_eksternal`;
DROP TABLE IF EXISTS `peminjaman`;
DROP TABLE IF EXISTS `otp_codes`;
DROP TABLE IF EXISTS `ruangan`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `gedung`;
DROP TABLE IF EXISTS `semester`;
DROP TABLE IF EXISTS `app_settings`;

-- ============================================================
--  Tabel: users
-- ============================================================
CREATE TABLE `users` (
  `id`               INT(11)      NOT NULL AUTO_INCREMENT,
  `email`            VARCHAR(150) NOT NULL,
  `no_wa`            VARCHAR(50)  DEFAULT NULL,
  `wa_groups`        TEXT         DEFAULT NULL,
  `password`         VARCHAR(255) NOT NULL,
  `nama`             VARCHAR(150) NOT NULL,
  `role`             ENUM('admin_utama','admin_departemen','admin_dmf','user')
                                  NOT NULL DEFAULT 'user',
  `departemen`       VARCHAR(50)  DEFAULT NULL,
  `nim`              VARCHAR(20)  DEFAULT NULL,
  `prodi`            VARCHAR(100) DEFAULT NULL,
  `created_at`       TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  `remember_token`   VARCHAR(64)  DEFAULT NULL,
  `remember_expires` DATETIME     DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Password hash untuk: Demo@12345
INSERT INTO `users`
  (`id`,`email`,`password`,`nama`,`role`,`departemen`,`nim`,`prodi`,`created_at`)
VALUES
  -- ── Admin Utama ──────────────────────────────────────────
  (1,'admin.fakultas@university.example',
   '$2y$12$Jqn5tp4sfL7F2BP3p6sDZev8oCHOVvr1Ko6Apc4CBBMAB9BvjqNOG',
   'Admin Utama Fakultas Sains','admin_utama',NULL,NULL,NULL,'2026-05-24 21:00:00'),

  -- ── Admin DMF ────────────────────────────────────────────
  (2,'admin.dmf@university.example',
   '$2y$12$Jqn5tp4sfL7F2BP3p6sDZev8oCHOVvr1Ko6Apc4CBBMAB9BvjqNOG',
   'Admin DMF','admin_dmf',NULL,NULL,NULL,'2026-05-24 21:00:00'),

  -- ── Admin Departemen ─────────────────────────────────────
  (3,'admin.matematika@university.example',
   '$2y$12$Jqn5tp4sfL7F2BP3p6sDZev8oCHOVvr1Ko6Apc4CBBMAB9BvjqNOG',
   'Admin Departemen Matematika','admin_departemen','Matematika',NULL,NULL,'2026-05-24 21:00:00'),

  (4,'admin.fisika@university.example',
   '$2y$12$Jqn5tp4sfL7F2BP3p6sDZev8oCHOVvr1Ko6Apc4CBBMAB9BvjqNOG',
   'Admin Departemen Fisika','admin_departemen','Fisika',NULL,NULL,'2026-05-24 21:00:00'),

  (5,'admin.kimia@university.example',
   '$2y$12$Jqn5tp4sfL7F2BP3p6sDZev8oCHOVvr1Ko6Apc4CBBMAB9BvjqNOG',
   'Admin Departemen Kimia','admin_departemen','Kimia',NULL,NULL,'2026-05-24 21:00:00'),

  (6,'admin.biologi@university.example',
   '$2y$12$Jqn5tp4sfL7F2BP3p6sDZev8oCHOVvr1Ko6Apc4CBBMAB9BvjqNOG',
   'Admin Departemen Biologi','admin_departemen','Biologi',NULL,NULL,'2026-05-24 21:00:00'),

  (7,'admin.pendidikanipa@university.example',
   '$2y$12$Jqn5tp4sfL7F2BP3p6sDZev8oCHOVvr1Ko6Apc4CBBMAB9BvjqNOG',
   'Admin Departemen Pendidikan IPA','admin_departemen','Pendidikan IPA',NULL,NULL,'2026-05-24 21:00:00'),

  (8,'admin.sainsterapan@university.example',
   '$2y$12$Jqn5tp4sfL7F2BP3p6sDZev8oCHOVvr1Ko6Apc4CBBMAB9BvjqNOG',
   'Admin Departemen Sains Terapan','admin_departemen','Sains Terapan',NULL,NULL,'2026-05-24 21:00:00');

-- ============================================================
--  Tabel: ruangan
-- ============================================================
CREATE TABLE `ruangan` (
  `id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `nama`        VARCHAR(100) NOT NULL,
  `gedung`      VARCHAR(100) NOT NULL,
  `departemen`  VARCHAR(50)  NOT NULL,
  `tipe`        ENUM('indoor','outdoor') NOT NULL DEFAULT 'indoor',
  `kapasitas`   INT(11)      NOT NULL DEFAULT 30,
  `fasilitas`   TEXT         DEFAULT NULL,
  `deskripsi`   TEXT         DEFAULT NULL,
  `foto`        VARCHAR(255) DEFAULT NULL,
  `tersedia`    TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`  TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `ruangan`
  (`id`,`nama`,`gedung`,`departemen`,`tipe`,`kapasitas`,`fasilitas`,`deskripsi`,`foto`,`tersedia`,`created_at`)
VALUES
  -- ── Matematika ───────────────────────────────────────────
  (1, 'Ruang 301','Gedung Matematika','Matematika','indoor',40,
   'Proyektor,AC,Whiteboard','Ruang kuliah departemen Matematika',NULL,1,'2026-05-24 21:00:00'),
  (2, 'Ruang 302','Gedung Matematika','Matematika','indoor',40,
   'Proyektor,AC,Whiteboard','Ruang kuliah departemen Matematika',NULL,1,'2026-05-24 21:00:00'),
  (3, 'Aula Matematika','Gedung Matematika','Matematika','indoor',200,
   'Proyektor,AC,Sound System,Podium','Aula besar departemen Matematika',NULL,1,'2026-05-24 21:00:00'),
  (4, 'Lab Komputer Matematika','Gedung Matematika','Matematika','indoor',30,
   'Komputer,AC,Proyektor','Laboratorium Komputer Matematika',NULL,1,'2026-05-24 21:00:00'),

  -- ── Fisika ───────────────────────────────────────────────
  (5, 'Ruang Seminar Fisika','Gedung Fisika','Fisika','indoor',80,
   'Proyektor,AC,Sound System','Ruang seminar departemen Fisika',NULL,1,'2026-05-24 21:00:00'),
  (6, 'Lab Fisika Dasar','Gedung Fisika','Fisika','indoor',30,
   'Peralatan Lab,AC','Laboratorium Fisika Dasar',NULL,1,'2026-05-24 21:00:00'),
  (7, 'Ruang 201 Fisika','Gedung Fisika','Fisika','indoor',40,
   'Proyektor,AC,Whiteboard','Ruang kuliah departemen Fisika',NULL,1,'2026-05-24 21:00:00'),

  -- ── Kimia ────────────────────────────────────────────────
  (8, 'Ruang Rapat Kimia','Gedung Kimia','Kimia','indoor',20,
   'Proyektor,AC','Ruang rapat departemen Kimia',NULL,1,'2026-05-24 21:00:00'),
  (9, 'Aula Kimia','Gedung Kimia','Kimia','indoor',150,
   'Proyektor,AC,Sound System','Aula departemen Kimia',NULL,1,'2026-05-24 21:00:00'),
  (10,'Lab Kimia Dasar','Gedung Kimia','Kimia','indoor',30,
   'Peralatan Lab,AC,Lemari Asam','Laboratorium Kimia Dasar',NULL,1,'2026-05-24 21:00:00'),

  -- ── Biologi ──────────────────────────────────────────────
  (11,'Ruang Seminar Biologi','Gedung Biologi','Biologi','indoor',60,
   'Proyektor,AC,Whiteboard','Ruang seminar departemen Biologi',NULL,1,'2026-05-24 21:00:00'),
  (12,'Lab Biologi Dasar','Gedung Biologi','Biologi','indoor',30,
   'Peralatan Lab,AC,Mikroskop','Laboratorium Biologi Dasar',NULL,1,'2026-05-24 21:00:00'),
  (13,'Ruang 101 Biologi','Gedung Biologi','Biologi','indoor',40,
   'Proyektor,AC,Whiteboard','Ruang kuliah departemen Biologi',NULL,1,'2026-05-24 21:00:00'),

  -- ── Pendidikan IPA ───────────────────────────────────────
  (14,'Ruang Kelas IPA 1','Gedung Pendidikan IPA','Pendidikan IPA','indoor',40,
   'Proyektor,AC,Whiteboard','Ruang kelas departemen Pendidikan IPA',NULL,1,'2026-05-24 21:00:00'),
  (15,'Ruang Kelas IPA 2','Gedung Pendidikan IPA','Pendidikan IPA','indoor',40,
   'Proyektor,AC,Whiteboard','Ruang kelas departemen Pendidikan IPA',NULL,1,'2026-05-24 21:00:00'),
  (16,'Lab IPA Terpadu','Gedung Pendidikan IPA','Pendidikan IPA','indoor',35,
   'Peralatan Lab,AC,Proyektor','Laboratorium IPA Terpadu',NULL,1,'2026-05-24 21:00:00'),

  -- ── Sains Terapan ────────────────────────────────────────
  (17,'Ruang Kelas Saintek 1','Gedung Sains Terapan','Sains Terapan','indoor',40,
   'Proyektor,AC,Whiteboard','Ruang kelas departemen Sains Terapan',NULL,1,'2026-05-24 21:00:00'),
  (18,'Lab Sains Terapan','Gedung Sains Terapan','Sains Terapan','indoor',30,
   'Komputer,Peralatan Lab,AC','Laboratorium Sains Terapan',NULL,1,'2026-05-24 21:00:00'),
  (19,'Ruang Seminar Saintek','Gedung Sains Terapan','Sains Terapan','indoor',60,
   'Proyektor,AC,Sound System','Ruang seminar Sains Terapan',NULL,1,'2026-05-24 21:00:00'),

  -- ── DMF — Area Outdoor ───────────────────────────────────
  (20,'Lapangan Fakultas Sains','Area Outdoor Fakultas Sains','Area Outdoor Fakultas Sains','outdoor',500,
   'Sound System Portable','Lapangan terbuka Fakultas Sains untuk kegiatan besar',NULL,1,'2026-05-24 21:00:00'),
  (21,'Taman Fakultas Sains','Area Outdoor Fakultas Sains','Area Outdoor Fakultas Sains','outdoor',100,
   'Bangku Taman','Area taman hijau Fakultas Sains',NULL,1,'2026-05-24 21:00:00'),
  (22,'Gazebo Fakultas Sains','Area Outdoor Fakultas Sains','Area Outdoor Fakultas Sains','outdoor',30,
   'Meja,Kursi','Gazebo pertemuan outdoor Fakultas Sains',NULL,1,'2026-05-24 21:00:00'),
  (23,'Koridor Fakultas Sains','Area Outdoor Fakultas Sains','Area Outdoor Fakultas Sains','outdoor',200,
   '','Koridor utama Fakultas Sains untuk pameran/stand',NULL,1,'2026-05-24 21:00:00');

-- ============================================================
--  Tabel: peminjaman
-- ============================================================
CREATE TABLE `peminjaman` (
  `id`               INT(11)      NOT NULL AUTO_INCREMENT,
  `user_id`          INT(11)      NOT NULL,
  `ruangan_id`       INT(11)      NOT NULL,
  `nama_kegiatan`    VARCHAR(200) NOT NULL,
  `asal_organisasi`  VARCHAR(150) DEFAULT NULL,
  `tanggal_mulai`    DATE         NOT NULL,
  `tanggal_selesai`  DATE         NOT NULL,
  `jam_mulai`        TIME         NOT NULL,
  `jam_selesai`      TIME         NOT NULL,
  `jumlah_peserta`   INT(11)      NOT NULL DEFAULT 1,
  `keterangan`       TEXT         DEFAULT NULL,
  `file_surat`       VARCHAR(255) NOT NULL,
  `status`           ENUM('pending','disetujui','ditolak','selesai','dibatalkan') NOT NULL DEFAULT 'pending',
  `validated_by`     INT(11)      DEFAULT NULL,
  `validated_at`     DATETIME     DEFAULT NULL,
  `alasan_penolakan` TEXT         DEFAULT NULL,
  `created_at`       TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id`    (`user_id`),
  KEY `ruangan_id` (`ruangan_id`),
  CONSTRAINT `peminjaman_ibfk_1`
    FOREIGN KEY (`user_id`)    REFERENCES `users`   (`id`) ON DELETE CASCADE,
  CONSTRAINT `peminjaman_ibfk_2`
    FOREIGN KEY (`ruangan_id`) REFERENCES `ruangan` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- (Kosong — akan terisi saat mahasiswa mengajukan peminjaman)

-- ============================================================
--  Tabel: peminjaman_eksternal  (peminjaman oleh pihak luar)
-- ============================================================
CREATE TABLE `peminjaman_eksternal` (
  `id`              INT(11)      NOT NULL AUTO_INCREMENT,
  `ruangan_id`      INT(11)      NOT NULL,
  `nama_pemohon`    VARCHAR(150) NOT NULL,
  `instansi`        VARCHAR(150) DEFAULT NULL,
  `kontak`          VARCHAR(100) DEFAULT NULL,
  `jenis_pemohon`   ENUM('umum','instansi_luar') NOT NULL DEFAULT 'umum',
  `nama_kegiatan`   VARCHAR(200) NOT NULL,
  `tanggal_mulai`   DATE         NOT NULL,
  `tanggal_selesai` DATE         NOT NULL,
  `jam_mulai`       TIME         NOT NULL,
  `jam_selesai`     TIME         NOT NULL,
  `jumlah_peserta`  INT(11)      NOT NULL DEFAULT 1,
  `keterangan`      TEXT         DEFAULT NULL,
  `file_surat`      VARCHAR(255) DEFAULT NULL,
  `status`          ENUM('pending','disetujui','ditolak') NOT NULL DEFAULT 'pending',
  `dibuat_oleh`     INT(11)      NOT NULL,
  `created_at`      TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ruangan_id` (`ruangan_id`),
  KEY `dibuat_oleh` (`dibuat_oleh`),
  CONSTRAINT `fk_pext_ruangan`
    FOREIGN KEY (`ruangan_id`) REFERENCES `ruangan` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pext_user`
    FOREIGN KEY (`dibuat_oleh`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
--  Tabel: jadwal_kuliah  (jadwal kuliah reguler per semester)
-- ============================================================
CREATE TABLE `jadwal_kuliah` (
  `id`                    INT(11)      NOT NULL AUTO_INCREMENT,
  `ruangan_id`            INT(11)      NOT NULL,
  `nama_matkul`           VARCHAR(200) NOT NULL,
  `kode_matkul`           VARCHAR(20)  DEFAULT NULL,
  `dosen`                 VARCHAR(150) DEFAULT NULL,
  `prodi`                 VARCHAR(100) DEFAULT NULL,
  `kelas`                 VARCHAR(20)  DEFAULT NULL,
  `departemen`            VARCHAR(50)  NOT NULL,
  `hari`                  TINYINT(1)   NOT NULL COMMENT '1=Senin, 2=Selasa, 3=Rabu, 4=Kamis, 5=Jumat, 6=Sabtu, 7=Minggu',
  `jam_mulai`             TIME         NOT NULL,
  `jam_selesai`           TIME         NOT NULL,
  `tgl_mulai_semester`    DATE         NOT NULL,
  `tgl_selesai_semester`  DATE         NOT NULL,
  `tahun_ajaran`          VARCHAR(20)  DEFAULT NULL,
  `semester`              VARCHAR(20)  NOT NULL DEFAULT 'Ganjil',
  `dibuat_oleh`           INT(11)      NOT NULL,
  `created_at`            TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ruangan_id` (`ruangan_id`),
  KEY `dibuat_oleh` (`dibuat_oleh`),
  CONSTRAINT `fk_jk_ruangan`
    FOREIGN KEY (`ruangan_id`) REFERENCES `ruangan` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jk_user`
    FOREIGN KEY (`dibuat_oleh`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
--  Tabel: otp_codes  (login mahasiswa via OTP email)
-- ============================================================
CREATE TABLE `otp_codes` (
  `id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `email`       VARCHAR(150) NOT NULL,
  `otp_code`    VARCHAR(6)   NOT NULL,
  `expires_at`  DATETIME     NOT NULL,
  `used`        TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`  TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
--  Tabel: notifikasi
-- ============================================================
CREATE TABLE `notifikasi` (
  `id`         INT(11)    NOT NULL AUTO_INCREMENT,
  `user_id`    INT(11)    NOT NULL,
  `judul`      VARCHAR(200) NOT NULL,
  `pesan`      TEXT       DEFAULT NULL,
  `tipe`       ENUM('info','success','warning','danger') NOT NULL DEFAULT 'info',
  `link`       VARCHAR(255) DEFAULT NULL,
  `dibaca`     TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP  NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `dibaca`  (`dibaca`),
  CONSTRAINT `fk_notif_user`
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
--  Tabel: gedung  (master daftar gedung/area — dipakai form ruangan)
-- ============================================================
CREATE TABLE `gedung` (
  `id`   INT(11)      NOT NULL AUTO_INCREMENT,
  `nama` VARCHAR(150) NOT NULL,
  `tipe` ENUM('indoor','outdoor') NOT NULL DEFAULT 'indoor',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_gedung_nama` (`nama`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `gedung` (`nama`,`tipe`) VALUES
  ('Gedung Matematika','indoor'),
  ('Gedung Fisika','indoor'),
  ('Gedung Kimia','indoor'),
  ('Gedung Biologi','indoor'),
  ('Gedung Pendidikan IPA','indoor'),
  ('Gedung Sains Terapan','indoor'),
  ('Area Outdoor Fakultas Sains','outdoor');

-- ============================================================
--  Tabel: semester  (rentang tanggal semester untuk jadwal kuliah)
-- ============================================================
CREATE TABLE `semester` (
  `id`           INT(11)     NOT NULL AUTO_INCREMENT,
  `semester`     VARCHAR(20) NOT NULL,
  `tahun_ajaran` VARCHAR(10) NOT NULL,
  `tgl_mulai`    DATE        NOT NULL,
  `tgl_selesai`  DATE        NOT NULL,
  `created_at`   TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sem` (`semester`,`tahun_ajaran`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
--  Tabel: app_settings  (pengaturan key-value: peta grup WA DMF,
--  daftar organisasi/ormawa, kategori organisasi)
-- ============================================================
CREATE TABLE `app_settings` (
  `k` VARCHAR(64) NOT NULL,
  `v` TEXT        DEFAULT NULL,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
--  Ringkasan akun
-- ============================================================
-- | Role                   | Email                              | Password |
-- |------------------------|------------------------------------|----------|
-- | Admin Utama            | admin.fakultas@university.example               | Demo@12345 |
-- | Admin DMF              | admin.dmf@university.example                 | Demo@12345 |
-- | Admin Matematika       | admin.matematika@university.example          | Demo@12345 |
-- | Admin Fisika           | admin.fisika@university.example              | Demo@12345 |
-- | Admin Kimia            | admin.kimia@university.example               | Demo@12345 |
-- | Admin Biologi          | admin.biologi@university.example             | Demo@12345 |
-- | Admin Pendidikan IPA   | admin.pendidikanipa@university.example       | Demo@12345 |
-- | Admin Sains Terapan    | admin.sainsterapan@university.example        | Demo@12345 |
-- | Mahasiswa              | (email @students.university.example apapun)  | via OTP  |
-- ============================================================
