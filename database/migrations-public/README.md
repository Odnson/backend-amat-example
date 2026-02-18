# Migrations Public (Versi Lite)

Folder ini berisi migration untuk versi public/lite dari aplikasi FOBi.

## Cara Penggunaan

Untuk menggunakan migration ini di environment development:

1. **Copy migrations ke folder utama:**
   ```bash
   cp database/migrations-public/*.php database/migrations/
   ```

2. **Jalankan migration:**
   ```bash
   php artisan migrate
   ```

3. **Seed data demo:**
   ```bash
   php artisan db:seed --class=PublicDemoSeeder
   ```

## Tabel yang Dibuat

| Tabel | Deskripsi |
|-------|-----------|
| `fobi_users` | Data user aplikasi |
| `taxa` | Data taksonomi (spesies, genus, family, dll) |
| `fobi_checklists` | Data observasi/checklist |
| `fobi_checklist_media` | Media (foto, audio) untuk observasi |
| `community_identifications` | Identifikasi dari komunitas |
| `fobi_comments` | Komentar pada observasi |
| `user_followers` | Relasi follow antar user |
| `badges` | Badge/lencana |
| `user_badges` | Badge yang dimiliki user |
| `notifications` | Notifikasi |

## Akun Demo

Setelah menjalankan seeder, Anda dapat login dengan:

| Email | Password | Role |
|-------|----------|------|
| ahmad@example.com | password123 | User (Level 2) |
| siti@example.com | password123 | Curator (Level 3) |
| budi@example.com | password123 | User (Level 1) |

## Catatan

- Migration ini adalah versi **simplified** untuk development dan testing
- Tidak termasuk semua field dan relasi dari versi production
- Cocok untuk kontributor yang ingin mengembangkan fitur frontend
