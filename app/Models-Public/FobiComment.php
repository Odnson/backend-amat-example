<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Model FobiComment (versi public/lite)
 * Model untuk komentar pada observasi
 */
class FobiComment extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'fobi_comments';

    protected $fillable = [
        'checklist_id',
        'user_id',
        'parent_id',
        'body',
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

    public function parent()
    {
        return $this->belongsTo(FobiComment::class, 'parent_id');
    }

    public function replies()
    {
        return $this->hasMany(FobiComment::class, 'parent_id');
    }
}
