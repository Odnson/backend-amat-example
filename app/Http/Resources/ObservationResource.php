<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource untuk transformasi data observasi
 */
class ObservationResource extends JsonResource
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
            'type' => 'observation',
            'source' => $this->source ?? 'fobi',
            
            // Location
            'latitude' => (float) $this->latitude,
            'longitude' => (float) $this->longitude,
            'location_name' => $this->location_name,
            
            // Date & Time
            'observation_date' => $this->observation_date,
            'observation_time' => $this->observation_time,
            'created_at' => $this->created_at,
            
            // Taxa
            'taxa' => [
                'id' => $this->taxa_id,
                'scientific_name' => $this->scientific_name ?? null,
                'common_name' => $this->common_name ?? $this->cname_species ?? null,
                'family' => $this->family ?? null,
                'order' => $this->order ?? null,
                'class' => $this->class ?? null,
            ],
            
            // User
            'user' => [
                'id' => $this->user_id,
                'username' => $this->username ?? $this->uname ?? null,
                'name' => trim(($this->fname ?? '') . ' ' . ($this->lname ?? '')),
                'profile_picture' => $this->profile_picture,
            ],
            
            // Quality
            'quality_grade' => $this->quality_grade,
            'is_wild' => (bool) $this->is_wild,
            'is_public' => (bool) $this->is_public,
            
            // Counts
            'count' => $this->count ?? 1,
            'identifications_count' => $this->identifications_count ?? 0,
            'comments_count' => $this->comments_count ?? 0,
            
            // Notes
            'notes' => $this->notes,
            
            // Media (akan di-load terpisah jika diperlukan)
            'media' => $this->whenLoaded('media', function () {
                return $this->media->map(fn($m) => [
                    'id' => $m->id,
                    'type' => $m->media_type,
                    'url' => $m->url,
                    'thumbnail' => $m->thumbnail_url ?? $m->url,
                ]);
            }),
        ];
    }
}
