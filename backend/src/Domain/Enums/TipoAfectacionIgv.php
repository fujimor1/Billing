<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Catálogo N° 07 SUNAT: Códigos de Tipo de Afectación del IGV
 */
enum TipoAfectacionIgv: string
{
    case GRAVADO_OPERACION_ONEROSA = '10';
    case GRAVADO_RETIRO_PREMIO = '11';
    case EXONERADO_OPERACION_ONEROSA = '20';
    case EXONERADO_TRANSFERENCIA_GRATUITA = '21';
    case INAFECTO_OPERACION_ONEROSA = '30';
    case INAFECTO_BONIFICACION = '31';
    case EXPORTACION = '40';

    public function llevaIgv(): bool
    {
        return in_array($this, [
            self::GRAVADO_OPERACION_ONEROSA,
            self::GRAVADO_RETIRO_PREMIO,
        ], true);
    }

    public function esGratuito(): bool
    {
        return in_array($this, [
            self::GRAVADO_RETIRO_PREMIO,
            self::EXONERADO_TRANSFERENCIA_GRATUITA,
            self::INAFECTO_BONIFICACION,
        ], true);
    }

    public function esExonerado(): bool
    {
        return $this === self::EXONERADO_OPERACION_ONEROSA;
    }

    public function esInafecto(): bool
    {
        return $this === self::INAFECTO_OPERACION_ONEROSA || $this === self::EXPORTACION;
    }

    public function codigoTributoSunat(): string
    {
        if ($this->esGratuito()) {
            return '9996'; // GRA - Gratuito
        }
        return match ($this) {
            self::GRAVADO_OPERACION_ONEROSA => '1000', // IGV
            self::EXONERADO_OPERACION_ONEROSA => '9997', // EXO
            self::INAFECTO_OPERACION_ONEROSA => '9998', // INA
            self::EXPORTACION => '9995', // EXP
            default => '1000',
        };
    }
}
