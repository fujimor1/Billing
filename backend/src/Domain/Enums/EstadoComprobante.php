<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Estados del ciclo de vida de un Comprobante de Pago Electrónico (CPE)
 */
enum EstadoComprobante: string
{
    case BORRADOR = 'BORRADOR';
    case ACEPTADO = 'ACEPTADO';
    case OBSERVADO = 'OBSERVADO';
    case RECHAZADO = 'RECHAZADO';
    case CONTINGENCIA = 'CONTINGENCIA';
    case ANULADO_BAJA = 'ANULADO_BAJA';
    case ANULADO_NC = 'ANULADO_NC';
}
