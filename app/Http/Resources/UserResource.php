<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource untuk transformasi data user
 */
class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'username' => $this->uname,
            'name' => trim(($this->fname ?? '') . ' ' . ($this->lname ?? '')),
            'first_name' => $this->fname,
            'last_name' => $this->lname,
            'email' => $this->when($this->shouldShowEmail($request), $this->email),
            'bio' => $this->bio,
            'profile_picture' => $this->profile_picture,
            'level' => $this->level,
            'is_verified' => (bool) $this->is_verified,
            
            // Stats
            'stats' => [
                'observations' => $this->observations_count ?? 0,
                'species' => $this->species_count ?? 0,
                'identifications' => $this->identifications_count ?? 0,
            ],
            
            // Social
            'followers_count' => $this->followers_count ?? 0,
            'following_count' => $this->following_count ?? 0,
            
            // Licenses
            'licenses' => [
                'observation' => $this->license_observation ?? 'CC-BY-NC',
                'photo' => $this->license_photo ?? 'CC-BY-NC',
                'audio' => $this->license_audio ?? 'CC-BY-NC',
            ],
            
            // Timestamps
            'created_at' => $this->created_at,
        ];
    }

    /**
     * Determine if email should be shown
     */
    private function shouldShowEmail(Request $request): bool
    {
        // Show email only to the user themselves
        return $request->user()?->id === $this->id;
    }
}
