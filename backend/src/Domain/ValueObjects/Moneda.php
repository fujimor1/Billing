<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use App\Domain\Exceptions\ReglaTributariaException;

/**
 * Catálogo N° 02 SUNAT: Monedas (ISO 4217) y Tipo de Cambio
 */
final class Moneda
{
    public const PEN = 'PEN';
    public const USD = 'USD';

    private string $codigo;
    private float $tipoCambio;

    public function __construct(string $codigo = self::PEN, float $tipoCambio = 1.0)
    {
        $codigo = strtoupper(trim($codigo));

        if (!in_array($codigo, [self::PEN, self::USD], true)) {
            throw new ReglaTributariaException("Moneda '{$codigo}' no soportada. Use PEN o USD.");
        }

        if ($codigo === self::PEN && abs($tipoCambio - 1.0) > 0.0001) {
            throw new ReglaTributariaException("Para moneda nacional (PEN), el tipo de cambio debe ser 1.00.");
        }

        if ($codigo === self::USD && ($tipoCambio <= 1.0 || $tipoCambio > 10.0)) {
            throw new ReglaTributariaException(
                "Para operaciones en Dólares (USD), se requiere un tipo de cambio válido mayor a 1.00 (recibido: {$tipoCambio})."
            );
        }

        $this->codigo = $codigo;
        $this->tipoCambio = round($tipoCambio, 3);
    }

    public function codigo(): string
    {
        return $this->codigo;
    }

    public function tipoCambio(): float
    {
        return $this->tipoCambio;
    }

    public function esExtranjera(): bool
    {
        return $this->codigo !== self::PEN;
    }

    public function convertirASoles(float $monto): float
    {
        return round($monto * $this->tipoCambio, 2);
    }
}
