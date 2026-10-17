<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
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

    public function houses(): HasMany
    {
        return $this->hasMany(House::class, 'owner_id');
    }

    public function inspectionReportsAsWorker(): HasMany
    {
        return $this->hasMany(InspectionReport::class, 'worker_id');
    }

    public function maintenanceRequestsAssigned(): HasMany
    {
        return $this->hasMany(MaintenanceRequest::class, 'assigned_worker_id');
    }

    public function rentalRequestsAsStudent(): HasMany
    {
        return $this->hasMany(RentalRequest::class, 'student_id');
    }

    public function maintenanceRequestsReported(): HasMany
    {
        return $this->hasMany(MaintenanceRequest::class, 'reporter_id');
    }

    public function maintenanceAssignments(): HasMany
    {
        return $this->hasMany(MaintenanceRequest::class, 'assigned_worker_id');
    }

    public function maintenanceNotifications(): HasMany
    {
        return $this->hasMany(MaintenanceNotification::class, 'recipient_id');
    }

    public function ownerSubscriptions(): HasMany
    {
        return $this->hasMany(OwnerSubscription::class, 'owner_id');
    }
}
