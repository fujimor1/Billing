<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use App\Domain\Enums\TipoComprobante;
use App\Domain\Exceptions\ReglaTributariaException;

/**
 * Representa la numeración oficial SUNAT (ej. F001-00000001, B001-00000125, FC01-00000001)
 */
final class SerieCorrelativo
{
    private string $serie;
    private int $correlativo;

    public function __construct(string $serie, int $correlativo, ?TipoComprobante $tipo = null)
    {
        $serieLimpia = strtoupper(trim($serie));

        if (!preg_match('/^[FB][A-Z0-9]{3}$/', $serieLimpia)) {
            throw new ReglaTributariaException(
                "La serie '{$serie}' no cumple el formato alfanumérico de 4 caracteres de SUNAT (debe iniciar con F o B)."
            );
        }

        if ($correlativo < 1 || $correlativo > 99999999) {
            throw new ReglaTributariaException(
                "El correlativo '{$correlativo}' debe estar entre 1 y 99,999,999."
            );
        }

        if ($tipo !== null) {
            $this->validarCoherenciaConTipo($serieLimpia, $tipo);
        }

        $this->serie = $serieLimpia;
        $this->correlativo = $correlativo;
    }

    private function validarCoherenciaConTipo(string $serie, TipoComprobante $tipo): void
    {
        if ($tipo === TipoComprobante::FACTURA && !str_starts_with($serie, 'F')) {
            throw new ReglaTributariaException(
                "Una Factura Electrónica (01) debe tener una serie que inicie con 'F' (recibido: {$serie})."
            );
        }

        if ($tipo === TipoComprobante::BOLETA && !str_starts_with($serie, 'B')) {
            throw new ReglaTributariaException(
                "Una Boleta de Venta (03) debe tener una serie que inicie con 'B' (recibido: {$serie})."
            );
        }
    }

    public function serie(): string
    {
        return $this->serie;
    }

    public function correlativo(): int
    {
        return $this->correlativo;
    }

    public function correlativoFormateado(): string
    {
        return str_pad((string) $this->correlativo, 8, '0', STR_PAD_LEFT);
    }

    public function formatoCompleto(): string
    {
        return "{$this->serie}-{$this->correlativoFormateado()}";
    }

    public function __toString(): string
    {
        return $this->formatoCompleto();
    }
}
