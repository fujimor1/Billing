<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Catálogo N° 01 SUNAT: Código de Tipo de Documento
 */
enum TipoComprobante: string
{
    case FACTURA = '01';
    case BOLETA = '03';
    case NOTA_CREDITO = '07';
    case NOTA_DEBITO = '08';

    public function prefijoEsperado(): string
    {
        return match ($this) {
            self::FACTURA => 'F',
            self::BOLETA => 'B',
            self::NOTA_CREDITO, self::NOTA_DEBITO => '', // Depende del documento que modifica (FC01, BC01, FD01, BD01)
        };
    }

    public function nombre(): string
    {
        return match ($this) {
            self::FACTURA => 'FACTURA ELECTRÓNICA',
            self::BOLETA => 'BOLETA DE VENTA ELECTRÓNICA',
            self::NOTA_CREDITO => 'NOTA DE CRÉDITO ELECTRÓNICA',
            self::NOTA_DEBITO => 'NOTA DE DÉBITO ELECTRÓNICA',
        };
    }
}
