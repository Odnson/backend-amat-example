<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

/**
 * Seeder untuk data demo versi public/lite
 * Menambahkan data dummy untuk testing fitur login, upload, dan get data
 */
class PublicDemoSeeder extends Seeder
{
    public function run(): void
    {
        // Seed Users
        $this->seedUsers();
        
        // Seed Taxa
        $this->seedTaxa();
        
        // Seed Checklists (Observations)
        $this->seedChecklists();
        
        // Seed Media
        $this->seedMedia();
        
        // Seed Identifications
        $this->seedIdentifications();
        
        // Seed Comments
        $this->seedComments();
        
        // Seed Badges
        $this->seedBadges();
        
        $this->command->info('Public demo data seeded successfully!');
    }

    private function seedUsers(): void
    {
        $users = [
            [
                'id' => 1,
                'uname' => 'ahmad_naturalist',
                'fname' => 'Ahmad',
                'lname' => 'Naturalis',
                'email' => 'ahmad@example.com',
                'password' => Hash::make('password123'),
                'bio' => 'Pengamat burung dan kupu-kupu dari Jakarta. Aktif berkontribusi dalam citizen science.',
                'profile_picture' => 'https://api.dicebear.com/7.x/avataaars/svg?seed=ahmad',
                'level' => 2,
                'is_verified' => true,
                'is_approved' => true,
                'email_verified_at' => now(),
                'license_observation' => 'CC-BY-NC',
                'license_photo' => 'CC-BY-NC',
                'license_audio' => 'CC-BY-NC',
                'created_at' => now()->subMonths(6),
                'updated_at' => now(),
            ],
            [
                'id' => 2,
                'uname' => 'siti_biodiv',
                'fname' => 'Siti',
                'lname' => 'Biodiversitas',
                'email' => 'siti@example.com',
                'password' => Hash::make('password123'),
                'bio' => 'Peneliti biodiversitas dari Bogor. Fokus pada konservasi satwa liar.',
                'profile_picture' => 'https://api.dicebear.com/7.x/avataaars/svg?seed=siti',
                'level' => 3,
                'is_verified' => true,
                'is_approved' => true,
                'email_verified_at' => now(),
                'license_observation' => 'CC-BY',
                'license_photo' => 'CC-BY',
                'license_audio' => 'CC-BY',
                'created_at' => now()->subMonths(12),
                'updated_at' => now(),
            ],
            [
                'id' => 3,
                'uname' => 'budi_explorer',
                'fname' => 'Budi',
                'lname' => 'Explorer',
                'email' => 'budi@example.com',
                'password' => Hash::make('password123'),
                'bio' => 'Pecinta alam dan fotografer wildlife dari Bandung.',
                'profile_picture' => 'https://api.dicebear.com/7.x/avataaars/svg?seed=budi',
                'level' => 1,
                'is_verified' => true,
                'is_approved' => true,
                'email_verified_at' => now(),
                'license_observation' => 'CC-BY-NC',
                'license_photo' => 'CC-BY-NC',
                'license_audio' => 'CC-BY-NC',
                'created_at' => now()->subMonths(3),
                'updated_at' => now(),
            ],
        ];

        DB::table('fobi_users')->insert($users);
        $this->command->info('Users seeded: ' . count($users));
    }

    private function seedTaxa(): void
    {
        $taxa = [
            // Birds
            [
                'id' => 1,
                'scientific_name' => 'Passer montanus',
                'rank' => 'species',
                'taxonomic_status' => 'ACCEPTED',
                'kingdom' => 'Animalia',
                'phylum' => 'Chordata',
                'class' => 'Aves',
                'order' => 'Passeriformes',
                'family' => 'Passeridae',
                'genus' => 'Passer',
                'species' => 'montanus',
                'cname_species' => 'Burung Gereja Erasia',
                'author' => '(Linnaeus, 1758)',
                'iucn_red_list_category' => 'LC',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 2,
                'scientific_name' => 'Geopelia striata',
                'rank' => 'species',
                'taxonomic_status' => 'ACCEPTED',
                'kingdom' => 'Animalia',
                'phylum' => 'Chordata',
                'class' => 'Aves',
                'order' => 'Columbiformes',
                'family' => 'Columbidae',
                'genus' => 'Geopelia',
                'species' => 'striata',
                'cname_species' => 'Perkutut Jawa',
                'author' => '(Linnaeus, 1766)',
                'iucn_red_list_category' => 'LC',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 3,
                'scientific_name' => 'Halcyon cyanoventris',
                'rank' => 'species',
                'taxonomic_status' => 'ACCEPTED',
                'kingdom' => 'Animalia',
                'phylum' => 'Chordata',
                'class' => 'Aves',
                'order' => 'Coraciiformes',
                'family' => 'Alcedinidae',
                'genus' => 'Halcyon',
                'species' => 'cyanoventris',
                'cname_species' => 'Cekakak Jawa',
                'author' => 'Vieillot, 1818',
                'iucn_red_list_category' => 'LC',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            // Butterflies
            [
                'id' => 4,
                'scientific_name' => 'Papilio memnon',
                'rank' => 'species',
                'taxonomic_status' => 'ACCEPTED',
                'kingdom' => 'Animalia',
                'phylum' => 'Arthropoda',
                'class' => 'Insecta',
                'order' => 'Lepidoptera',
                'family' => 'Papilionidae',
                'genus' => 'Papilio',
                'species' => 'memnon',
                'cname_species' => 'Kupu-kupu Raja',
                'author' => 'Linnaeus, 1758',
                'iucn_red_list_category' => 'LC',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 5,
                'scientific_name' => 'Graphium sarpedon',
                'rank' => 'species',
                'taxonomic_status' => 'ACCEPTED',
                'kingdom' => 'Animalia',
                'phylum' => 'Arthropoda',
                'class' => 'Insecta',
                'order' => 'Lepidoptera',
                'family' => 'Papilionidae',
                'genus' => 'Graphium',
                'species' => 'sarpedon',
                'cname_species' => 'Kupu-kupu Hijau',
                'author' => '(Linnaeus, 1758)',
                'iucn_red_list_category' => 'LC',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            // Plants
            [
                'id' => 6,
                'scientific_name' => 'Ficus benjamina',
                'rank' => 'species',
                'taxonomic_status' => 'ACCEPTED',
                'kingdom' => 'Plantae',
                'phylum' => 'Tracheophyta',
                'class' => 'Magnoliopsida',
                'order' => 'Rosales',
                'family' => 'Moraceae',
                'genus' => 'Ficus',
                'species' => 'benjamina',
                'cname_species' => 'Beringin',
                'author' => 'L.',
                'iucn_red_list_category' => 'LC',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            // Mammals
            [
                'id' => 7,
                'scientific_name' => 'Macaca fascicularis',
                'rank' => 'species',
                'taxonomic_status' => 'ACCEPTED',
                'kingdom' => 'Animalia',
                'phylum' => 'Chordata',
                'class' => 'Mammalia',
                'order' => 'Primates',
                'family' => 'Cercopithecidae',
                'genus' => 'Macaca',
                'species' => 'fascicularis',
                'cname_species' => 'Monyet Ekor Panjang',
                'author' => '(Raffles, 1821)',
                'iucn_red_list_category' => 'VU',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            // Reptiles
            [
                'id' => 8,
                'scientific_name' => 'Varanus salvator',
                'rank' => 'species',
                'taxonomic_status' => 'ACCEPTED',
                'kingdom' => 'Animalia',
                'phylum' => 'Chordata',
                'class' => 'Reptilia',
                'order' => 'Squamata',
                'family' => 'Varanidae',
                'genus' => 'Varanus',
                'species' => 'salvator',
                'cname_species' => 'Biawak Air',
                'author' => '(Laurenti, 1768)',
                'iucn_red_list_category' => 'LC',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        DB::table('taxa')->insert($taxa);
        $this->command->info('Taxa seeded: ' . count($taxa));
    }

    private function seedChecklists(): void
    {
        $locations = [
            ['name' => 'Taman Nasional Gunung Gede Pangrango', 'lat' => -6.7833, 'lng' => 106.9833],
            ['name' => 'Kebun Raya Bogor', 'lat' => -6.5971, 'lng' => 106.7989],
            ['name' => 'Taman Nasional Ujung Kulon', 'lat' => -6.7500, 'lng' => 105.3333],
            ['name' => 'Cagar Alam Gunung Tangkuban Parahu', 'lat' => -6.7667, 'lng' => 107.6000],
            ['name' => 'Taman Nasional Baluran', 'lat' => -7.8500, 'lng' => 114.3667],
        ];

        $checklists = [];
        $id = 1;

        foreach ([1, 2, 3] as $userId) {
            for ($i = 0; $i < 5; $i++) {
                $location = $locations[array_rand($locations)];
                $taxaId = rand(1, 8);
                $grades = ['research grade', 'confirmed id', 'needs id', 'casual'];
                
                $checklists[] = [
                    'id' => $id,
                    'user_id' => $userId,
                    'taxa_id' => $taxaId,
                    'latitude' => $location['lat'] + (rand(-100, 100) / 10000),
                    'longitude' => $location['lng'] + (rand(-100, 100) / 10000),
                    'location_name' => $location['name'],
                    'observation_date' => Carbon::now()->subDays(rand(1, 180)),
                    'observation_time' => sprintf('%02d:%02d:00', rand(6, 18), rand(0, 59)),
                    'notes' => 'Observasi di ' . $location['name'] . '. Cuaca cerah.',
                    'count' => rand(1, 5),
                    'quality_grade' => $grades[array_rand($grades)],
                    'is_wild' => true,
                    'is_public' => true,
                    'obscured' => false,
                    'source' => 'fobi',
                    'created_at' => now()->subDays(rand(1, 180)),
                    'updated_at' => now(),
                ];
                $id++;
            }
        }

        DB::table('fobi_checklists')->insert($checklists);
        $this->command->info('Checklists seeded: ' . count($checklists));
    }

    private function seedMedia(): void
    {
        $placeholderImages = [
            'https://images.unsplash.com/photo-1444464666168-49d633b86797?w=800',
            'https://images.unsplash.com/photo-1452570053594-1b985d6ea890?w=800',
            'https://images.unsplash.com/photo-1470114716159-e389f8712fda?w=800',
            'https://images.unsplash.com/photo-1549608276-5786777e6587?w=800',
            'https://images.unsplash.com/photo-1518709268805-4e9042af9f23?w=800',
        ];

        $media = [];
        $checklistCount = DB::table('fobi_checklists')->count();

        for ($checklistId = 1; $checklistId <= $checklistCount; $checklistId++) {
            $mediaCount = rand(1, 3);
            for ($i = 0; $i < $mediaCount; $i++) {
                $media[] = [
                    'checklist_id' => $checklistId,
                    'file_path' => $placeholderImages[array_rand($placeholderImages)],
                    'file_name' => 'observation_' . $checklistId . '_' . $i . '.jpg',
                    'media_type' => 'image',
                    'mime_type' => 'image/jpeg',
                    'storage_type' => 'external',
                    'license' => 'CC-BY-NC',
                    'sort_order' => $i,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        DB::table('fobi_checklist_media')->insert($media);
        $this->command->info('Media seeded: ' . count($media));
    }

    private function seedIdentifications(): void
    {
        $identifications = [];
        $checklistCount = DB::table('fobi_checklists')->count();

        for ($checklistId = 1; $checklistId <= $checklistCount; $checklistId++) {
            $checklist = DB::table('fobi_checklists')->find($checklistId);
            
            // Owner identification
            $identifications[] = [
                'checklist_id' => $checklistId,
                'user_id' => $checklist->user_id,
                'taxa_id' => $checklist->taxa_id,
                'body' => 'Identifikasi awal berdasarkan pengamatan langsung.',
                'is_current' => true,
                'is_withdrawn' => false,
                'agrees_with_observation' => true,
                'created_at' => $checklist->created_at,
                'updated_at' => now(),
            ];

            // Random community identifications
            if (rand(0, 1)) {
                $otherUsers = [1, 2, 3];
                $otherUsers = array_diff($otherUsers, [$checklist->user_id]);
                $randomUser = $otherUsers[array_rand($otherUsers)];

                $identifications[] = [
                    'checklist_id' => $checklistId,
                    'user_id' => $randomUser,
                    'taxa_id' => $checklist->taxa_id,
                    'body' => 'Setuju dengan identifikasi ini.',
                    'is_current' => true,
                    'is_withdrawn' => false,
                    'agrees_with_observation' => true,
                    'created_at' => Carbon::parse($checklist->created_at)->addHours(rand(1, 48)),
                    'updated_at' => now(),
                ];
            }
        }

        DB::table('community_identifications')->insert($identifications);
        $this->command->info('Identifications seeded: ' . count($identifications));
    }

    private function seedComments(): void
    {
        $comments = [];
        $sampleComments = [
            'Foto yang bagus! Terima kasih sudah berbagi.',
            'Apakah ini diambil di pagi hari?',
            'Saya juga pernah melihat spesies ini di lokasi yang sama.',
            'Habitatnya terlihat masih alami.',
            'Observasi yang menarik!',
        ];

        $checklistCount = DB::table('fobi_checklists')->count();

        for ($checklistId = 1; $checklistId <= $checklistCount; $checklistId++) {
            if (rand(0, 1)) {
                $checklist = DB::table('fobi_checklists')->find($checklistId);
                $otherUsers = [1, 2, 3];
                $otherUsers = array_diff($otherUsers, [$checklist->user_id]);
                $randomUser = $otherUsers[array_rand($otherUsers)];

                $comments[] = [
                    'checklist_id' => $checklistId,
                    'user_id' => $randomUser,
                    'body' => $sampleComments[array_rand($sampleComments)],
                    'created_at' => Carbon::parse($checklist->created_at)->addHours(rand(1, 72)),
                    'updated_at' => now(),
                ];
            }
        }

        if (!empty($comments)) {
            DB::table('fobi_comments')->insert($comments);
        }
        $this->command->info('Comments seeded: ' . count($comments));
    }

    private function seedBadges(): void
    {
        $badges = [
            [
                'name' => 'Pengamat Pemula',
                'slug' => 'observer-beginner',
                'description' => 'Membuat 10 observasi pertama',
                'icon' => 'fa-binoculars',
                'type' => 'observation',
                'threshold' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Pengamat Aktif',
                'slug' => 'observer-active',
                'description' => 'Membuat 50 observasi',
                'icon' => 'fa-eye',
                'type' => 'observation',
                'threshold' => 50,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Identifier Pemula',
                'slug' => 'identifier-beginner',
                'description' => 'Memberikan 10 identifikasi',
                'icon' => 'fa-search',
                'type' => 'identification',
                'threshold' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Kontributor Komunitas',
                'slug' => 'community-contributor',
                'description' => 'Aktif membantu komunitas',
                'icon' => 'fa-users',
                'type' => 'community',
                'threshold' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        DB::table('badges')->insert($badges);
        $this->command->info('Badges seeded: ' . count($badges));

        // Assign badges to users
        $userBadges = [
            ['user_id' => 1, 'badge_id' => 1, 'earned_at' => now()->subMonths(5), 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => 2, 'badge_id' => 1, 'earned_at' => now()->subMonths(10), 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => 2, 'badge_id' => 2, 'earned_at' => now()->subMonths(6), 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => 2, 'badge_id' => 3, 'earned_at' => now()->subMonths(8), 'created_at' => now(), 'updated_at' => now()],
        ];

        DB::table('user_badges')->insert($userBadges);
        $this->command->info('User badges assigned: ' . count($userBadges));
    }
}
