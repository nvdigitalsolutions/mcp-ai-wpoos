<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Policies\AdminAccessPolicy;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements FilamentUser, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * `auth0_subject` is deliberately absent: it is an authentication link, and
     * mass-assignable authentication links are how an account gets taken over by
     * a payload. `nvoos:auth0-link` sets it with `forceFill()`.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
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

    /**
     * Estates this user may enter, with the membership role on the pivot.
     *
     * Separate from teams(): a team is a working group inside one estate,
     * while membership is the estate boundary itself (Proposal 149 T-0.3).
     */
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Whether this user belongs to the vendor's operator estate (Q-1).
     *
     * Reads the TENANT flag, never the site role. A customer's estate
     * administrator is an administrator and must not qualify — see
     * AdminAccessPolicy and the regression test that pins it.
     */
    public function isOperatorMember(): bool
    {
        return $this->tenants()->where('tenants.is_operator', true)->exists();
    }

    /**
     * Whether this user may enter the customer panel.
     *
     * `viewer` is read-only, so it is refused the panel entirely rather than
     * being handed a nav with every action hidden — the policy is the
     * enforcement point, not the UI.
     */
    public function hasNonViewerMembership(): bool
    {
        return $this->tenants()->wherePivot('role', '!=', 'viewer')->exists();
    }

    /**
     * Filament panel access (Proposal 149 T-0.4).
     *
     * Delegates to AdminAccessPolicy so there is exactly one definition of who
     * may enter which panel. Do not re-implement the predicates here: the
     * regression test that keeps a customer estate administrator out of `/ops`
     * reads the policy, and a second copy would silently disagree with it.
     *
     * The panel id is the dispatch key, so a panel added later without a policy
     * arm fails closed.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return app(AdminAccessPolicy::class)->accessPanel($this, $panel->getId());
    }

    /**
     * Estates offered by the customer panel's tenant switcher
     * (Proposal 149 T-0.7).
     *
     * Viewer memberships are excluded so the switcher cannot offer an estate
     * the user could not act in
     * (`canAccessPanel()` refuses viewers entirely).
     *
     * @return Collection<int, Tenant>
     */
    public function getTenants(Panel $panel): Collection
    {
        return $this->tenants()
            ->wherePivot('role', '!=', 'viewer')
            ->orderBy('tenants.name')
            ->get();
    }

    /**
     * Whether this user may activate the given estate.
     *
     * Fails closed on `viewer` for the same reason `getTenants()` does: there is
     * no read-only enforcement inside Filament resources yet, so admitting a
     * viewer would hand them write actions. Relax this to plain membership in
     * the same change that adds per-action ability checks to the resources.
     */
    public function canAccessTenant(Model $tenant): bool
    {
        return $this->tenants()
            ->whereKey($tenant->getKey())
            ->wherePivot('role', '!=', 'viewer')
            ->exists();
    }
}
