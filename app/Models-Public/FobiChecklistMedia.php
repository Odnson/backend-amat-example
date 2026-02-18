<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Model FobiChecklistMedia (versi public/lite)
 * Model untuk media observasi (foto, audio)
 */
class FobiChecklistMedia extends Model
{
    use HasFactory;

    protected $table = 'fobi_checklist_media';

    protected $fillable = [
        'checklist_id',
        'file_path',
        'file_name',
        'media_type',
        'mime_type',
        'file_size',
        'storage_type',
        'spectrogram_path',
        'license',
        'photographer_name',
        'caption',
        'sort_order',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'sort_order' => 'integer',
    ];

    // Relationships
    public function checklist()
    {
        return $this->belongsTo(FobiChecklist::class, 'checklist_id');
    }

    // Accessors
    public function getUrlAttribute()
    {
        if ($this->storage_type === 'external') {
            return $this->file_path;
        }
        
        return asset('storage/' . $this->file_path);
    }

    public function getIsImageAttribute()
    {
        return $this->media_type === 'image';
    }

    public function getIsAudioAttribute()
    {
        return $this->media_type === 'audio';
    }

    public function getSpectrogramUrlAttribute()
    {
        if (!$this->spectrogram_path) {
            return null;
        }
        
        if ($this->storage_type === 'external') {
            return $this->spectrogram_path;
        }
        
        return asset('storage/' . $this->spectrogram_path);
    }
}
