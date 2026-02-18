<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;

/**
 * Model FobiUser (versi public/lite)
 * Model untuk user aplikasi FOBi
 */
class FobiUser extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable;

    protected $table = 'fobi_users';

    protected $fillable = [
        'uname',
        'fname',
        'lname',
        'email',
        'password',
        'phone',
        'organization',
        'bio',
        'profile_picture',
        'profile_picture_storage_type',
        'level',
        'is_verified',
        'is_approved',
        'email_verified_at',
        'verification_token',
        'license_observation',
        'license_photo',
        'license_audio',
        'burungnesia_user_id',
        'kupunesia_user_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'verification_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'is_verified' => 'boolean',
        'is_approved' => 'boolean',
    ];

    // JWT Methods
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [];
    }

    // Relationships
    public function checklists()
    {
        return $this->hasMany(FobiChecklist::class, 'user_id');
    }

    public function identifications()
    {
        return $this->hasMany(CommunityIdentification::class, 'user_id');
    }

    public function comments()
    {
        return $this->hasMany(FobiComment::class, 'user_id');
    }

    public function followers()
    {
        return $this->belongsToMany(FobiUser::class, 'user_followers', 'following_id', 'follower_id')
            ->withTimestamps();
    }

    public function following()
    {
        return $this->belongsToMany(FobiUser::class, 'user_followers', 'follower_id', 'following_id')
            ->withTimestamps();
    }

    public function badges()
    {
        return $this->belongsToMany(Badge::class, 'user_badges')
            ->withPivot('earned_at')
            ->withTimestamps();
    }

    // Accessors
    public function getFullNameAttribute()
    {
        return trim("{$this->fname} {$this->lname}");
    }

    public function getObservationsCountAttribute()
    {
        return $this->checklists()->count();
    }

    public function getIdentificationsCountAttribute()
    {
        return $this->identifications()->count();
    }
}
