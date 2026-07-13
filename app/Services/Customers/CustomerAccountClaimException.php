<?php

declare(strict_types=1);

namespace App\Services\Customers;

use RuntimeException;

final class CustomerAccountClaimException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $publicMessage,
        public readonly int $httpStatus = 409,
        public readonly ?string $field = null,
    ) {
        parent::__construct($message);
    }

    public static function expired(): self
    {
        return new self(
            'Customer account claim has expired.',
            'Ten link wygasł. Jeśli nadal chcesz założyć konto i przypisać zamówienie, skontaktuj się z obsługą sklepu.',
            410,
        );
    }

    public static function unavailable(string $reason = 'Customer account claim is unavailable.'): self
    {
        return new self(
            $reason,
            'Nie możemy już użyć tego linku do przypisania zamówienia. Zamówienie mogło zostać wcześniej przypisane do konta. W razie pytań skontaktuj się z obsługą sklepu.',
            409,
        );
    }

    public static function passwordRequired(): self
    {
        return new self(
            'A password is required to create a WooCommerce customer.',
            'Ustaw hasło, aby utworzyć nowe konto.',
            422,
            'password',
        );
    }
}
