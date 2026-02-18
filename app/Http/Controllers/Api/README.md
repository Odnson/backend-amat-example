# API Controllers - Refactored Structure

Struktur folder yang telah di-refactor untuk organisasi kode yang lebih baik dan maintainability yang lebih tinggi.

## 📁 Struktur Folder

```
app/Http/Controllers/Api-Refactored/
├── Auth/                          # Authentication & Identification
│   ├── AuthController.php
│   ├── ForgotPasswordController.php
│   ├── BirdIdentificationController.php
│   └── ButterflyIdentificationController.php
│
├── Observations/                  # Observation Management
│   ├── ObservationController.php
│   ├── FobiObservationApiController.php
│   ├── BurungnesiaObservationApiController.php
│   ├── KupunesiaObservationApiController.php
│   ├── KupunesiaObservationController.php
│   ├── FobiGeneralObservationController.php
│   ├── UnifiedObservationController.php
│   ├── FobiUserObservationController.php
│   ├── BirdObservationController.php
│   └── ObservationShowController.php
│
├── Checklists/                    # Checklist Management
│   ├── ChecklistController.php
│   ├── ChecklistDetailController.php
│   ├── ChecklistObservationController.php
│   ├── ChecklistCommentController.php
│   ├── BurungnesiaChecklistController.php
│   ├── KupunesiaChecklistController.php
│   └── FobiChecklistTaxaController.php
│
├── Quality/                       # Quality Assessment System
│   ├── QualityAssessmentController.php
│   ├── ChecklistQualityAssessmentController.php
│   ├── TaxaQualityAssessmentController.php
│   └── KupunesiaQualityAssessmentController.php
│
├── Taxa/                          # Taxonomic Data Management
│   ├── TaxaController.php
│   ├── TaxaDetailController.php
│   ├── TaxaNamingFlagController.php
│   ├── TaxonBrowserController.php
│   ├── SpeciesController.php
│   ├── SpeciesSearchController.php
│   ├── SpeciesSuggestionController.php
│   └── FavoriteTaxaController.php
│
├── Users/                         # User Management
│   ├── ProfileController.php
│   ├── FobiUserController.php
│   ├── FobiUserApiController.php
│   └── FollowController.php
│
├── Media/                         # Media Management
│   ├── AuthMediaController.php
│   ├── FobiMediaController.php
│   ├── SpectrogramController.php
│   └── GalleryController.php
│
├── Gallery/                       # Taxonomic Gallery Views
│   ├── SpeciesGalleryController.php
│   ├── GenusGalleryController.php
│   ├── FamilyGalleryController.php
│   ├── OrderGalleryController.php
│   ├── ClassGalleryController.php
│   ├── PhylumGalleryController.php
│   ├── KingdomGalleryController.php
│   └── TaxonGalleryController.php
│
├── Grid/                          # Map & Grid System
│   ├── GridCellController.php
│   ├── GridSpeciesController.php
│   ├── PolygonSpeciesController.php
│   ├── PolygonSidebarController.php
│   ├── MarkerController.php
│   ├── FobiMarkerController.php
│   └── LocationLabelController.php
│
└── General/                       # General Utilities
    ├── HomeController.php
    ├── SearchController.php
    ├── BadgeController.php
    ├── NotificationController.php
    ├── MessageController.php
    ├── HistoryController.php
    ├── StatisticsController.php
    ├── CommentController.php
    ├── UserReportController.php
    ├── EmailVerificationController.php
    ├── PageContentController.php
    └── SettingFaqController.php
```

## 🎯 Tujuan Refactoring

### 1. **Separation of Concerns**
Setiap folder mengelompokkan controller berdasarkan domain/fungsi:
- **Auth**: Autentikasi dan identifikasi spesies
- **Observations**: CRUD dan manajemen observasi
- **Checklists**: Manajemen checklist dan detail
- **Quality**: Sistem penilaian kualitas data
- **Taxa**: Manajemen data taksonomi
- **Users**: Profil dan manajemen pengguna
- **Media**: Upload dan manajemen media
- **Gallery**: Tampilan galeri per rank taksonomi
- **Grid**: Sistem peta dan grid
- **General**: Utilitas umum

### 2. **Improved Maintainability**
- Mudah menemukan controller yang relevan
- Mengurangi cognitive load saat development
- Struktur yang konsisten dan predictable

### 3. **Better Scalability**
- Mudah menambah controller baru di folder yang sesuai
- Memungkinkan team development yang lebih efisien
- Mendukung modular architecture

## 📊 Statistik

| Folder | Jumlah Controllers | Deskripsi |
|--------|-------------------|-----------|
| Auth | 4 | Authentication & Species ID |
| Observations | 10 | Observation Management |
| Checklists | 7 | Checklist System |
| Quality | 4 | Quality Assessment |
| Taxa | 8 | Taxonomic Data |
| Users | 4 | User Management |
| Media | 4 | Media Handling |
| Gallery | 8 | Gallery Views |
| Grid | 7 | Map & Grid System |
| General | 12 | General Utilities |
| **Total** | **68** | - |

## 🔧 Namespace Convention (Jika Diimplementasikan)

```php
// Contoh namespace untuk struktur baru
namespace App\Http\Controllers\Api\Observations;
namespace App\Http\Controllers\Api\Checklists;
namespace App\Http\Controllers\Api\Quality;
namespace App\Http\Controllers\Api\Taxa;
namespace App\Http\Controllers\Api\Users;
namespace App\Http\Controllers\Api\Media;
namespace App\Http\Controllers\Api\Gallery;
namespace App\Http\Controllers\Api\Grid;
namespace App\Http\Controllers\Api\General;
namespace App\Http\Controllers\Api\Auth;
```

## 📝 Catatan

> **⚠️ PENTING**: Ini adalah struktur refactored untuk showcase/dokumentasi. 
> Struktur asli di `app/Http/Controllers/Api/` tetap digunakan untuk production.

---

## 📄 Routes File

File routes yang sesuai dengan struktur refactored ini tersedia di:

```
routes/api-refactored.php
```

### Perbedaan dengan `routes/api.php`:

| Aspek | api.php (Original) | api-refactored.php |
|-------|-------------------|-------------------|
| Namespace | `App\Http\Controllers\Api\*` | `App\Http\Controllers\Api\{Domain}\*` |
| Organisasi | Flat, berdasarkan urutan penambahan | Grouped by domain/function |
| Import | Mixed inline & top | All imports at top, organized |
| Route Groups | Ad-hoc grouping | Consistent prefix-based grouping |

### Contoh Perubahan Namespace:

```php
// BEFORE (api.php)
use App\Http\Controllers\Api\HomeController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\FobiUserController;

// AFTER (api-refactored.php)
use App\Http\Controllers\Api\General\HomeController;
use App\Http\Controllers\Api\Users\ProfileController;
use App\Http\Controllers\Api\Users\FobiUserController;
```

### Route Groups dalam api-refactored.php:

```php
// Authentication
Route::prefix('auth')->group(function () { ... });

// User Management
Route::prefix('users')->group(function () { ... });

// Profile
Route::prefix('profile')->group(function () { ... });

// Observations
Route::prefix('observations')->group(function () { ... });

// Taxa
Route::prefix('taxa')->group(function () { ... });

// Gallery
Route::prefix('gallery')->group(function () { ... });

// Map & Grid
Route::prefix('map')->group(function () { ... });

// Notifications
Route::prefix('notifications')->group(function () { ... });

// Messages
Route::prefix('messages')->group(function () { ... });

// Badges
Route::prefix('badges')->group(function () { ... });
```

---

> **⚠️ PENTING**: Ini adalah struktur refactored untuk showcase/dokumentasi.
> - `app/Http/Controllers/Api/` → Production (aktif)
> - `app/Http/Controllers/Api-Refactored/` → Showcase only
> - `routes/api.php` → Production (aktif)
> - `routes/api-refactored.php` → Showcase only

---

*Refactored structure for FOBI (Flora & Fauna Observation of Biodiversity Indonesia)*
