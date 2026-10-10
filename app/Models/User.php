<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\UserInvited;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use NotificationChannels\WebPush\HasPushSubscriptions;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasPushSubscriptions, LogsActivity, Notifiable;

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

    public function reconciliations(): HasMany
    {
        return $this->hasMany(Reconciliation::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }

    public function sendInvite(): void
    {
        $token = $this->use_microsoft ? null : Password::broker('invites')->createToken($this);

        $this->notify(new UserInvited($token));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('users')
            ->logOnly(['name', 'email', 'is_admin', 'is_cardholder', 'use_microsoft', 'active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * Replace this user's card identifiers with the given rows.
     * Rows with an id update that identifier (only if it belongs to this user); rows without one are created.
     *
     * @param  array<int, array{id?: ?int, last4?: ?string, cardholder_name?: ?string}>  $rows
     */
    public function syncCardIdentifiers(array $rows): void
    {
        $keep = [];

        foreach ($rows as $row) {
            $attributes = [
                'last4' => $row['last4'] ?? null,
                'cardholder_name' => $row['cardholder_name'] ?? null,
            ];

            $identifier = isset($row['id']) ? $this->cardIdentifiers()->find($row['id']) : null;

            if ($identifier) {
                $identifier->update($attributes);
            } else {
                $identifier = $this->cardIdentifiers()->create($attributes);
            }

            $keep[] = $identifier->id;
        }

        // Delete one by one so each removal is written to the activity log.
        $this->cardIdentifiers()->whereNotIn('id', $keep)->get()->each->delete();
    }
}
