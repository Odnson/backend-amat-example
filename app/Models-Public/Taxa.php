<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Model Taxa (versi public/lite)
 * Model untuk data taksonomi
 */
class Taxa extends Model
{
    use HasFactory;

    protected $table = 'taxa';

    protected $fillable = [
        'scientific_name',
        'rank',
        'taxonomic_status',
        'kingdom',
        'phylum',
        'class',
        'order',
        'family',
        'genus',
        'species',
        'subspecies',
        'cname_species',
        'cname_genus',
        'cname_family',
        'cname_order',
        'author',
        'iucn_red_list_category',
        'cites_status',
        'accepted_scientific_name',
        'accepted_name_id',
        'default_image',
    ];

    // Relationships
    public function checklists()
    {
        return $this->hasMany(FobiChecklist::class, 'taxa_id');
    }

    public function identifications()
    {
        return $this->hasMany(CommunityIdentification::class, 'taxa_id');
    }

    public function acceptedName()
    {
        return $this->belongsTo(Taxa::class, 'accepted_name_id');
    }

    public function synonyms()
    {
        return $this->hasMany(Taxa::class, 'accepted_name_id');
    }

    // Scopes
    public function scopeAccepted($query)
    {
        return $query->where('taxonomic_status', 'ACCEPTED');
    }

    public function scopeSpecies($query)
    {
        return $query->where('rank', 'species');
    }

    public function scopeSearch($query, $term)
    {
        return $query->where(function ($q) use ($term) {
            $q->where('scientific_name', 'LIKE', "%{$term}%")
              ->orWhere('cname_species', 'LIKE', "%{$term}%")
              ->orWhere('genus', 'LIKE', "%{$term}%")
              ->orWhere('family', 'LIKE', "%{$term}%");
        });
    }

    // Accessors
    public function getDisplayNameAttribute()
    {
        return $this->cname_species ?: $this->scientific_name;
    }

    public function getFullScientificNameAttribute()
    {
        $name = $this->scientific_name;
        if ($this->author) {
            $name .= ' ' . $this->author;
        }
        return $name;
    }
}
