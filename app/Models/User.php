<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'branch_id',
        'phone',
        'status',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
        ];
    }

    // =========================================================
    // Filament Access Control
    // =========================================================

    /**
     * Allow active users to access the Filament panel.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->status === 'active';
    }

    // =========================================================
    // Role Helpers
    // =========================================================

    /** @return bool Level 1 — Full access to all branches */
    public function isOwnerPusat(): bool
    {
        return $this->role === 'owner_pusat';
    }

    /** @return bool Level 2 — Access to regional branches */
    public function isRegionalLeader(): bool
    {
        return $this->role === 'regional_leader';
    }

    /** @return bool Level 3 — Access to own branch */
    public function isOwnerCabang(): bool
    {
        return $this->role === 'owner_cabang';
    }

    /** @return bool Level 4 — Operational input only, no P&L */
    public function isStaffGudang(): bool
    {
        return $this->role === 'staff_gudang';
    }

    /**
     * Check if user can see financial/P&L data.
     */
    public function canViewFinance(): bool
    {
        return in_array($this->role, ['owner_pusat', 'regional_leader', 'owner_cabang']);
    }

    /**
     * Check if user can approve Delivery Orders.
     */
    public function canApproveOrders(): bool
    {
        return in_array($this->role, ['owner_pusat', 'regional_leader']);
    }

    // =========================================================
    // Relationships
    // =========================================================

    /**
     * The branch this user belongs to.
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
