<?php

declare(strict_types=1);

namespace App\Domain\Entities;

use App\Domain\Enums\TipoAfectacionIgv;
use App\Domain\Exceptions\ReglaTributariaException;

/**
 * Línea de detalle (InvoiceLine / CreditNoteLine UBL 2.1)
 */
final class ItemComprobante
{
    public function __construct(
        private string $codigoProducto,
        private string $descripcion,
        private float $cantidad,
        private float $valorUnitarioSinIgv,
        private TipoAfectacionIgv $tipoAfectacion = TipoAfectacionIgv::GRAVADO_OPERACION_ONEROSA,
        private float $descuentoPorcentaje = 0.0,
        private bool $aplicaIcbper = false,
        private string $unidadMedida = 'NIU'
    ) {
        if (trim($codigoProducto) === '' || trim($descripcion) === '') {
            throw new ReglaTributariaException("Todo ítem debe tener código y descripción.");
        }

        if ($cantidad <= 0) {
            throw new ReglaTributariaException(
                "La cantidad del ítem '{$codigoProducto}' debe ser mayor a cero (recibido: {$cantidad})."
            );
        }

        if ($valorUnitarioSinIgv <= 0) {
            throw new ReglaTributariaException(
                "El valor unitario o referencial del ítem '{$codigoProducto}' debe ser mayor a cero."
            );
        }

        if ($descuentoPorcentaje < 0 || $descuentoPorcentaje >= 100) {
            throw new ReglaTributariaException(
                "El descuento por ítem debe estar entre 0% y menos de 100% (recibido: {$descuentoPorcentaje}%)."
            );
        }

        if ($tipoAfectacion->esGratuito() && $descuentoPorcentaje > 0) {
            throw new ReglaTributariaException(
                "No se puede aplicar descuento comercial a una transferencia gratuita o bonificación."
            );
        }
    }

    public function codigoProducto(): string
    {
        return $this->codigoProducto;
    }

    public function descripcion(): string
    {
        return $this->descripcion;
    }

    public function cantidad(): float
    {
        return $this->cantidad;
    }

    public function valorUnitarioSinIgv(): float
    {
        return $this->valorUnitarioSinIgv;
    }

    public function tipoAfectacion(): TipoAfectacionIgv
    {
        return $this->tipoAfectacion;
    }

    public function descuentoPorcentaje(): float
    {
        return $this->descuentoPorcentaje;
    }

    public function aplicaIcbper(): bool
    {
        return $this->aplicaIcbper;
    }

    public function unidadMedida(): string
    {
        return $this->unidadMedida;
    }

    public function valorBruto(): float
    {
        return round($this->cantidad * $this->valorUnitarioSinIgv, 2);
    }

    public function montoDescuento(): float
    {
        if ($this->descuentoPorcentaje <= 0) {
            return 0.0;
        }
        return round($this->valorBruto() * ($this->descuentoPorcentaje / 100), 2);
    }

    /**
     * Valor de venta del ítem neto de descuento (Base imponible o valor referencial si es gratuito)
     */
    public function valorVentaItem(): float
    {
        return round($this->valorBruto() - $this->montoDescuento(), 2);
    }
}
