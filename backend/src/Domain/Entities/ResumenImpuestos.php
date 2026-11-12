<?php

declare(strict_types=1);

namespace App\Domain\Entities;

/**
 * Contiene el desglose tributario completo según estándar UBL 2.1 de SUNAT
 */
final class ResumenImpuestos
{
    public function __construct(
        public readonly float $totalGravadas,
        public readonly float $totalExoneradas,
        public readonly float $totalInafectas,
        public readonly float $totalGratuitas,
        public readonly float $totalDescuentosItems,
        public readonly float $descuentoGlobal,
        public readonly float $totalIgv,
        public readonly float $totalIgvGratuitas,
        public readonly float $totalIcbper,
        public readonly float $totalImpuestos,
        public readonly float $valorVentaNeto,
        public readonly float $importeTotal,
        public readonly float $montoDetraccion = 0.0,
        public readonly float $montoNetoPendientePago = 0.0
    ) {
    }

    public function toArray(): array
    {
        return [
            'total_gravadas' => $this->totalGravadas,
            'total_exoneradas' => $this->totalExoneradas,
            'total_inafectas' => $this->totalInafectas,
            'total_gratuitas' => $this->totalGratuitas,
            'total_descuentos_items' => $this->totalDescuentosItems,
            'descuento_global' => $this->descuentoGlobal,
            'total_igv' => $this->totalIgv,
            'total_igv_gratuitas' => $this->totalIgvGratuitas,
            'total_icbper' => $this->totalIcbper,
            'total_impuestos' => $this->totalImpuestos,
            'valor_venta_neto' => $this->valorVentaNeto,
            'importe_total' => $this->importeTotal,
            'monto_detraccion' => $this->montoDetraccion,
            'monto_neto_pendiente_pago' => $this->montoNetoPendientePago,
        ];
    }
}
