<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\PasswordResetMail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * Whether this login may be used at all.
     *
     * Independent of Employee::STATUSES on purpose: employment status is an
     * HR fact about a person, account status is an operational switch on the
     * credential. Disabling a compromised account must not rewrite the HR
     * record, and a resigned employee's record must survive account closure.
     */
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
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
        'status',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
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
        ];
    }

    /**
     * The employee master record backing this login (1:0..1 — a login may be
     * issued before the HR record exists).
     */
    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    /**
     * Send the password-reset mail through this application's queued
     * subclass rather than the broker's stock one.
     *
     * The broker calls this synchronously inside `POST /auth/forgot-password`,
     * which would otherwise put an SMTP round trip between a person tapping
     * a button and their phone telling them the request went through.
     * {@see App\Notifications\PasswordResetMail} is the same notification
     * with a queue on it, and AppServiceProvider is where its URL and copy
     * are wired up.
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new PasswordResetMail($token));
    }

    /**
     * May this credential still be exchanged for a token?
     *
     * Checked *after* the password verifies, so a disabled account can be
     * told why it was refused without revealing account existence to someone
     * who does not know the password.
     */
    public function canLogIn(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
