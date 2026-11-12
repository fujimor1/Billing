<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Catálogo N° 09 SUNAT: Códigos de Tipo de Nota de Crédito Electrónica
 */
enum MotivoNotaCredito: string
{
    case ANULACION_OPERACION = '01';
    case ANULACION_ERROR_RUC = '02';
    case CORRECCION_DESCRIPCION = '03';
    case DESCUENTO_GLOBAL = '04';
    case DESCUENTO_POR_ITEM = '05';
    case DEVOLUCION_TOTAL = '06';
    case DEVOLUCION_POR_ITEM = '07';

    public function restauraStock(): bool
    {
        return in_array($this, [
            self::ANULACION_OPERACION,
            self::ANULACION_ERROR_RUC,
            self::DEVOLUCION_TOTAL,
            self::DEVOLUCION_POR_ITEM,
        ], true);
    }

    public function anulaComprobanteTotalmente(): bool
    {
        return in_array($this, [
            self::ANULACION_OPERACION,
            self::ANULACION_ERROR_RUC,
            self::DEVOLUCION_TOTAL,
        ], true);
    }
}
