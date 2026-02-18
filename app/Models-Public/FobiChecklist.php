<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Model FobiChecklist (versi public/lite)
 * Model untuk observasi/checklist
 */
class FobiChecklist extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'fobi_checklists';

    protected $fillable = [
        'user_id',
        'taxa_id',
        'latitude',
        'longitude',
        'location_name',
        'administrative_area',
        'observation_date',
        'observation_time',
        'notes',
        'count',
        'quality_grade',
        'is_wild',
        'is_public',
        'obscured',
        'source',
    ];

    protected $casts = [
        'observation_date' => 'date',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'is_wild' => 'boolean',
        'is_public' => 'boolean',
        'obscured' => 'boolean',
    ];

    // Relationships
    public function user()
    {
        return $this->belongsTo(FobiUser::class, 'user_id');
    }

    public function taxa()
    {
        return $this->belongsTo(Taxa::class, 'taxa_id');
    }

    public function media()
    {
        return $this->hasMany(FobiChecklistMedia::class, 'checklist_id')->orderBy('sort_order');
    }

    public function identifications()
    {
        return $this->hasMany(CommunityIdentification::class, 'checklist_id');
    }

    public function currentIdentifications()
    {
        return $this->identifications()->where('is_current', true)->where('is_withdrawn', false);
    }

    public function comments()
    {
        return $this->hasMany(FobiComment::class, 'checklist_id');
    }

    // Scopes
    public function scopePublic($query)
    {
        return $query->where('is_public', true);
    }

    public function scopeResearchGrade($query)
    {
        return $query->where('quality_grade', 'research grade');
    }

    public function scopeNeedsId($query)
    {
        return $query->where('quality_grade', 'needs id');
    }

    // Accessors
    public function getFirstImageAttribute()
    {
        $media = $this->media()->where('media_type', 'image')->first();
        return $media ? $media->file_path : null;
    }

    public function getImagesAttribute()
    {
        return $this->media()->where('media_type', 'image')->pluck('file_path')->toArray();
    }

    public function getIdentificationsCountAttribute()
    {
        return $this->currentIdentifications()->count();
    }

    public function getCommentsCountAttribute()
    {
        return $this->comments()->count();
    }
}
