# Amaturalist Beta - FOBi Public Version

Versi public dari aplikasi FOBi (Flora & Fauna Observation of Biodiversity Indonesia) yang sudah di-refactor dengan clean architecture.

## 📁 Struktur Direktori

```
amaturalist-beta/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   └── Api/                    # Unified Controllers (domain-based)
│   │   │       ├── Auth/               # Authentication
│   │   │       ├── Observations/       # Observation management
│   │   │       ├── Checklists/         # Checklist management
│   │   │       ├── Quality/            # Quality assessment
│   │   │       ├── Taxa/               # Taxonomy
│   │   │       ├── Users/              # User profiles
│   │   │       ├── Media/              # Media handling
│   │   │       ├── Gallery/            # Gallery views
│   │   │       ├── Grid/               # Map grid
│   │   │       ├── General/            # General endpoints
│   │   │       ├── ObservationController.php   # Clean architecture
│   │   │       ├── IdentificationController.php
│   │   │       └── QualityAssessmentController.php
│   │   ├── Middleware-Example/         # Contoh middleware
│   │   ├── Requests/                   # Form request validation
│   │   │   ├── StoreObservationRequest.php
│   │   │   └── UpdateObservationRequest.php
│   │   └── Resources/                  # API Resources
│   │       ├── ObservationResource.php
│   │       ├── ObservationCollection.php
│   │       └── UserResource.php
│   ├── Mail-Example/                   # Contoh mailable
│   ├── Models-Public/                  # Models untuk versi public
│   ├── Providers/
│   │   └── AppServiceProvider.php      # Service registration
│   └── Services/                       # Business logic services
│       ├── LocationService.php         # Reverse geocoding
│       ├── MediaService.php            # Image/audio processing
│       ├── ObservationService.php      # CRUD observations
│       └── QualityAssessmentService.php # Quality grading
├── database/
│   ├── migrations-public/              # Migrations untuk versi public
│   └── seeders/
│       └── PublicDemoSeeder.php        # Seeder data demo
└── routes/
    └── api.php                         # Unified API routes
```

## 🏗️ Arsitektur

### Clean Architecture

Kode sudah dipisahkan menjadi beberapa layer:

1. **Controllers** - Handle HTTP request/response
2. **Services** - Business logic
3. **Resources** - Data transformation
4. **Requests** - Validation

### Services

| Service | Deskripsi |
|---------|-----------|
| `LocationService` | Reverse geocoding dengan Nominatim |
| `MediaService` | Upload dan processing gambar/audio |
| `ObservationService` | CRUD operasi observasi |
| `QualityAssessmentService` | Penilaian kualitas data |

### API Endpoints

#### V1 - New Clean API (dengan Services)

**Public (tanpa auth)**
```
GET  /api/v1/observations                           # List observasi
GET  /api/v1/observations/{id}                      # Detail observasi
GET  /api/v1/observations/{id}/identifications      # List identifikasi
GET  /api/v1/observations/{id}/quality              # Quality assessment
GET  /api/v1/observations/{id}/confidence           # Confidence data
```

**Protected (dengan auth)**
```
POST   /api/v1/observations                         # Buat observasi
PUT    /api/v1/observations/{id}                    # Update observasi
DELETE /api/v1/observations/{id}                    # Hapus observasi
POST   /api/v1/observations/{id}/identifications    # Tambah identifikasi
POST   /api/v1/observations/{id}/identifications/{id}/withdraw  # Tarik identifikasi
POST   /api/v1/observations/{id}/identifications/{id}/agree     # Setuju identifikasi
POST   /api/v1/observations/{id}/quality/update     # Update quality
```

#### Legacy Compatibility Routes

**Profile & Users**
```
GET  /api/profile/home/{id}                         # Profile data
GET  /api/profile/stats/{id}                        # User statistics
GET  /api/profile/activities/{id}                   # Activity chart data
GET  /api/profile/top-taxa/{id}                     # Top taxa
GET  /api/profile/observations/{id}                 # User observations
GET  /api/profile/identifications/{id}              # User identifications
GET  /api/profile/life-list/{id}                    # Taxonomy tree
GET  /api/profile/favorite-taxas/{id}               # Favorite taxa
GET  /api/fobi-users/{id}                           # User detail
```

**Map & Markers**
```
GET  /api/markers                                   # All markers
GET  /api/fobi-markers                              # FOBi markers only
GET  /api/map/markers                               # Map markers
```

**Observations**
```
GET  /api/general-observations                      # General observations
GET  /api/unified-observations                      # Multi-source observations
```

**Taxa**
```
GET  /api/taksa/search                              # Search taxa
GET  /api/taxa/{rank}/{id}                          # Taxa detail
```

## 🚀 Setup

### 1. Copy ke project utama

```bash
# Copy services
cp -r amaturalist-beta/app/Services/* app/Services/

# Copy controllers
cp -r amaturalist-beta/app/Http/Controllers/Api/* app/Http/Controllers/Api/

# Copy resources
cp -r amaturalist-beta/app/Http/Resources/* app/Http/Resources/

# Copy requests
cp -r amaturalist-beta/app/Http/Requests/* app/Http/Requests/

# Copy models
cp -r amaturalist-beta/app/Models-Public/* app/Models/

# Copy migrations
cp amaturalist-beta/database/migrations-public/* database/migrations/

# Copy seeder
cp amaturalist-beta/database/seeders/PublicDemoSeeder.php database/seeders/
```

### 2. Register Services

Tambahkan ke `app/Providers/AppServiceProvider.php`:

```php
use App\Services\LocationService;
use App\Services\MediaService;
use App\Services\ObservationService;
use App\Services\QualityAssessmentService;

public function register(): void
{
    $this->app->singleton(LocationService::class);
    $this->app->singleton(MediaService::class);
    $this->app->singleton(QualityAssessmentService::class);
    
    $this->app->singleton(ObservationService::class, function ($app) {
        return new ObservationService(
            $app->make(LocationService::class),
            $app->make(MediaService::class)
        );
    });
}
```

### 3. Jalankan migrations

```bash
php artisan migrate
php artisan db:seed --class=PublicDemoSeeder
```

## 📝 Perbedaan dengan Versi Production

| Aspek | Production | Public |
|-------|------------|--------|
| Controllers | Monolithic, banyak method | Clean, terpisah ke services |
| Business Logic | Di controller | Di services |
| Validation | Manual | Form Requests |
| Response | Manual array | API Resources |
| Multi-source | Burungnesia, Kupunesia, FOBi | FOBi only |
| Spectrogram | Python script + S3 | Local storage only |

## 🔒 Keamanan

- Tidak ada kredensial atau API keys
- Tidak ada logic sensitif
- Cocok untuk public repository

## 📄 License

MIT License
