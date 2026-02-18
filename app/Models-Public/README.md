# Models Public (Versi Lite)

Folder ini berisi model untuk versi public/lite dari aplikasi FOBi.

## Cara Penggunaan

Untuk menggunakan model ini di environment development:

1. **Copy models ke folder utama:**
   ```bash
   cp app/Models-Public/*.php app/Models/
   ```

2. **Atau gunakan symlink (Linux/Mac):**
   ```bash
   # Backup existing models
   mv app/Models app/Models-Backup
   
   # Create symlink
   ln -s app/Models-Public app/Models
   ```

## Model yang Tersedia

| Model | Tabel | Deskripsi |
|-------|-------|-----------|
| `FobiUser` | fobi_users | User aplikasi dengan JWT auth |
| `Taxa` | taxa | Data taksonomi |
| `FobiChecklist` | fobi_checklists | Observasi/checklist |
| `FobiChecklistMedia` | fobi_checklist_media | Media observasi |
| `CommunityIdentification` | community_identifications | Identifikasi komunitas |
| `FobiComment` | fobi_comments | Komentar |
| `Badge` | badges | Badge/lencana |

## Relasi Antar Model

```
FobiUser
├── hasMany: FobiChecklist (checklists)
├── hasMany: CommunityIdentification (identifications)
├── hasMany: FobiComment (comments)
├── belongsToMany: FobiUser (followers, following)
└── belongsToMany: Badge (badges)

FobiChecklist
├── belongsTo: FobiUser (user)
├── belongsTo: Taxa (taxa)
├── hasMany: FobiChecklistMedia (media)
├── hasMany: CommunityIdentification (identifications)
└── hasMany: FobiComment (comments)

Taxa
├── hasMany: FobiChecklist (checklists)
├── hasMany: CommunityIdentification (identifications)
├── belongsTo: Taxa (acceptedName) - untuk sinonim
└── hasMany: Taxa (synonyms)
```

## Catatan

- Model ini adalah versi **simplified** untuk development
- Tidak termasuk semua method dan relasi dari versi production
- Cocok untuk kontributor yang ingin mengembangkan fitur frontend
