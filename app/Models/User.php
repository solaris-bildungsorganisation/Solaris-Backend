<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * users — هوية المستخدم. الأدوار عبر spatie (HasRoles) وليست عمودًا هنا.
 * id داخلي فقط، والـ API يستخدم uuid.
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, HasUuids, Notifiable, SoftDeletes;

    /**
     * الحقول التي يحق للمستخدم/النموذج تعبئتها جماعيًا.
     * الحقول الأمنية (status, locked_until, failed_login_count, two_factor_*) تُضبط صراحةً من الـ Services فقط.
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'display_name',
        'email',
        'phone',
        'password',
        'avatar_path',
        'birth_date',
        'interface_language_id',
        'country_id',
        'region_id',
        'timezone',
    ];

    protected $hidden = [
        'id',
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'birth_date' => 'date',
            'status_changed_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'must_change_password' => 'boolean',
            'failed_login_count' => 'integer',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /** uuid يُولَّد تلقائيًا، و id يبقى bigint auto-increment. */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    // ───────────── BelongsTo ─────────────

    public function interfaceLanguage(): BelongsTo
    {
        return $this->belongsTo(Language::class, 'interface_language_id');
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ───────────── Identity & security ─────────────

    public function passwordHistories(): HasMany
    {
        return $this->hasMany(PasswordHistory::class);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(AccountStatusHistory::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(UserSession::class);
    }

    public function verificationCodes(): HasMany
    {
        return $this->hasMany(VerificationCode::class);
    }

    public function authEvents(): HasMany
    {
        return $this->hasMany(AuthEvent::class);
    }

    // ───────────── Contexts & account types ─────────────

    public function userContexts(): HasMany
    {
        return $this->hasMany(UserContext::class);
    }

    public function contexts(): BelongsToMany
    {
        return $this->belongsToMany(Context::class, 'user_contexts')
            ->withPivot(['id', 'status', 'is_default', 'activated_at', 'deactivated_at', 'granted_by'])
            ->withTimestamps();
    }

    public function accountTypeRequests(): HasMany
    {
        return $this->hasMany(AccountTypeRequest::class);
    }

    public function regionChanges(): HasMany
    {
        return $this->hasMany(UserRegionChange::class);
    }

    // ───────────── Languages ─────────────

    public function userLanguages(): HasMany
    {
        return $this->hasMany(UserLanguage::class);
    }

    public function languages(): BelongsToMany
    {
        return $this->belongsToMany(Language::class, 'user_languages')
            ->withPivot(['id', 'proficiency', 'is_native', 'source', 'verified_at'])
            ->withTimestamps();
    }

    // ───────────── Organizations ─────────────

    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_memberships')
            ->withPivot(['id', 'membership_type', 'status', 'title', 'joined_at', 'left_at', 'added_by'])
            ->withTimestamps();
    }

    // ───────────── Notifications & reports ─────────────

    public function notificationPreferences(): HasMany
    {
        return $this->hasMany(NotificationPreference::class);
    }

    public function notificationDeliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class);
    }

    public function reportExports(): HasMany
    {
        return $this->hasMany(ReportExport::class);
    }
}
