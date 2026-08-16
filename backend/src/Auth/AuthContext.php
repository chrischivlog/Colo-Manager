<?php

declare(strict_types=1);

namespace ColoManager\Auth;

/** Vertrauenswürdige Benutzerinformationen aus einem erfolgreich geprüften JWT. */
final readonly class AuthContext
{
    public function __construct(
        public string $userId,
        public string $email,
        public string $role,
        public ?string $customerId,
        public string $sessionId,
    ) {
    }

    public function isPlatformAdmin(): bool
    {
        return $this->role === 'platform_admin';
    }

    /** Mitarbeiter dürfen die gemeinsame Servicequeue bearbeiten, aber nicht automatisch die Plattform konfigurieren. */
    public function isDatacenterStaff(): bool
    {
        return $this->role === 'datacenter_staff';
    }

    public function canManageTickets(): bool
    {
        return $this->isPlatformAdmin() || $this->isDatacenterStaff();
    }

    public function canWriteCustomerData(): bool
    {
        return in_array($this->role, ['platform_admin', 'customer_admin'], true);
    }
}
