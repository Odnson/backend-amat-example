<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Model CommunityIdentification (versi public/lite)
 * Model untuk identifikasi komunitas
 */
class CommunityIdentification extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'community_identifications';

    protected $fillable = [
        'checklist_id',
        'user_id',
        'taxa_id',
        'body',
        'is_current',
        'is_withdrawn',
        'agrees_with_observation',
        'certainty',
    ];

    protected $casts = [
        'is_current' => 'boolean',
        'is_withdrawn' => 'boolean',
        'agrees_with_observation' => 'boolean',
    ];

    // Relationships
    public function checklist()
    {
        return $this->belongsTo(FobiChecklist::class, 'checklist_id');
    }

    public function user()
    {
        return $this->belongsTo(FobiUser::class, 'user_id');
    }

    public function taxa()
    {
        return $this->belongsTo(Taxa::class, 'taxa_id');
    }

    // Scopes
    public function scopeCurrent($query)
    {
        return $query->where('is_current', true)->where('is_withdrawn', false);
    }

    public function scopeAgreeing($query)
    {
        return $query->where('agrees_with_observation', true);
    }
}
