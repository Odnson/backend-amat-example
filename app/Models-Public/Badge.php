<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Model Badge (versi public/lite)
 * Model untuk badge/lencana user
 */
class Badge extends Model
{
    use HasFactory;

    protected $table = 'badges';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'icon',
        'type',
        'threshold',
    ];

    // Relationships
    public function users()
    {
        return $this->belongsToMany(FobiUser::class, 'user_badges')
            ->withPivot('earned_at')
            ->withTimestamps();
    }
}
