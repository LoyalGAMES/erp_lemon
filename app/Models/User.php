<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasFactory;

    public const ROLE_ADMINISTRATOR = 'administrator';

    public const ROLE_OPERATOR = 'operator';

    public const ROLE_PACKER = 'packer';

    public const ROLE_ACCOUNTING = 'accounting';

    /**
     * @var array<string, list<string>>
     */
    private const AREA_ROLES = [
        'dashboard' => [
            self::ROLE_ADMINISTRATOR,
            self::ROLE_OPERATOR,
            self::ROLE_PACKER,
            self::ROLE_ACCOUNTING,
        ],
        'products' => [self::ROLE_ADMINISTRATOR, self::ROLE_OPERATOR],
        'warehouses' => [self::ROLE_ADMINISTRATOR, self::ROLE_OPERATOR],
        'documents' => [self::ROLE_ADMINISTRATOR, self::ROLE_OPERATOR],
        'orders' => [self::ROLE_ADMINISTRATOR, self::ROLE_OPERATOR, self::ROLE_ACCOUNTING],
        'order_editing' => [
            self::ROLE_ADMINISTRATOR,
            self::ROLE_OPERATOR,
            self::ROLE_PACKER,
            self::ROLE_ACCOUNTING,
        ],
        'customers' => [self::ROLE_ADMINISTRATOR, self::ROLE_OPERATOR, self::ROLE_ACCOUNTING],
        'returns' => [self::ROLE_ADMINISTRATOR, self::ROLE_OPERATOR, self::ROLE_PACKER],
        'packing' => [self::ROLE_ADMINISTRATOR, self::ROLE_OPERATOR, self::ROLE_PACKER],
        'invoices' => [self::ROLE_ADMINISTRATOR, self::ROLE_ACCOUNTING],
        'ksef' => [self::ROLE_ADMINISTRATOR, self::ROLE_ACCOUNTING],
        'integrations' => [self::ROLE_ADMINISTRATOR],
        'settings' => [self::ROLE_ADMINISTRATOR],
        'users' => [self::ROLE_ADMINISTRATOR],
        'sync' => [self::ROLE_ADMINISTRATOR, self::ROLE_OPERATOR],
        'ledger' => [self::ROLE_ADMINISTRATOR, self::ROLE_OPERATOR, self::ROLE_ACCOUNTING],
        'audit' => [self::ROLE_ADMINISTRATOR],
    ];

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_login_at' => 'datetime',
        'password' => 'hashed',
    ];

    /**
     * @return array<string, string>
     */
    public static function roleLabels(): array
    {
        return [
            self::ROLE_ADMINISTRATOR => 'Administrator',
            self::ROLE_OPERATOR => 'Operator',
            self::ROLE_PACKER => 'Pakowanie',
            self::ROLE_ACCOUNTING => 'Księgowość',
        ];
    }

    public function roleLabel(): string
    {
        return self::roleLabels()[$this->role] ?? $this->role;
    }

    public function isAdministrator(): bool
    {
        return $this->role === self::ROLE_ADMINISTRATOR;
    }

    public function canAccessArea(string $area): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->isAdministrator()) {
            return true;
        }

        return in_array($this->role, self::AREA_ROLES[$area] ?? [], true);
    }
}
