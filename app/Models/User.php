<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /** In-memory defaults mirroring the DB column defaults, so new models behave the same before a re-read. */
    protected $attributes = [
        'use_microsoft' => false,
        'is_admin' => false,
        'is_cardholder' => true,
        'active' => true,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'use_microsoft',
        'is_admin',
        'is_cardholder',
        'active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'external_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'use_microsoft' => 'boolean',
            'is_admin' => 'boolean',
            'is_cardholder' => 'boolean',
            'active' => 'boolean',
        ];
    }

    /** Emails are compared case-insensitively everywhere, so store them normalised. */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value) => Str::lower(trim($value)));
    }

    public function cardIdentifiers(): HasMany
    {
        return $this->hasMany(CardIdentifier::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }
}
