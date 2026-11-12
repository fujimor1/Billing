<?php

declare(strict_types=1);

namespace App\Domain\Services;

use App\Domain\Entities\ItemComprobante;
use App\Domain\Entities\ResumenImpuestos;
use App\Domain\Exceptions\ReglaTributariaException;
use App\Domain\ValueObjects\Moneda;

/**
 * Motor de Cálculo Tributario UBL 2.1 (Perú - SUNAT)
 * Calcula Operaciones Gravadas, Exoneradas, Inafectas, Gratuitas (Retiro/Bonificación),
 * Descuentos por ítem y globales, IGV (18%), ICBPER (S/ 0.50 por bolsa) y Detracciones SPOT.
 */
final class CalculadoraTributaria
{
    public const TASA_IGV_DEFAULT = 0.18;
    public const TARIFA_ICBPER_PEN = 0.50; // S/ 0.50 por bolsa plástica
    public const TOPE_MINIMO_DETRACCION_PEN = 700.00; // S/ 700.00 para servicios sujetos a SPOT

    private float $tasaIgv;

    public function __construct(float $tasaIgv = self::TASA_IGV_DEFAULT)
    {
        if ($tasaIgv <= 0 || $tasaIgv >= 1.0) {
            throw new ReglaTributariaException("La tasa de IGV debe estar entre 0 y 1 (ej. 0.18).");
        }
        $this->tasaIgv = $tasaIgv;
    }

    /**
     * @param ItemComprobante[] $items
     */
    public function calcular(
        array $items,
        Moneda $moneda = new Moneda(Moneda::PEN),
        float $descuentoGlobalPorcentaje = 0.0,
        float $porcentajeDetraccion = 0.0
    ): ResumenImpuestos {
        if (empty($items)) {
            throw new ReglaTributariaException("No se puede calcular un comprobante sin ítems.");
        }

        if ($descuentoGlobalPorcentaje < 0 || $descuentoGlobalPorcentaje >= 100) {
            throw new ReglaTributariaException("El descuento global debe estar entre 0% y menos de 100%.");
        }

        if ($porcentajeDetraccion < 0 || $porcentajeDetraccion > 30) {
            throw new ReglaTributariaException("El porcentaje de detracción SPOT debe estar entre 0% y 30%.");
        }

        $sumaGravadas = 0.0;
        $sumaExoneradas = 0.0;
        $sumaInafectas = 0.0;
        $sumaGratuitas = 0.0;
        $sumaDescuentosItems = 0.0;
        $sumaIgvGratuitas = 0.0;
        $cantidadBolsasIcbper = 0;

        foreach ($items as $item) {
            if (!$item instanceof ItemComprobante) {
                throw new ReglaTributariaException("Todos los elementos deben ser instancias de ItemComprobante.");
            }

            $valorVentaItem = $item->valorVentaItem();
            $sumaDescuentosItems += $item->montoDescuento();
            $afectacion = $item->tipoAfectacion();

            if ($afectacion->esGratuito()) {
                $sumaGratuitas += $valorVentaItem;
                if ($afectacion->llevaIgv()) {
                    // Retiro gravado (Premio/Publicidad): genera IGV referencial que asume el emisor, no suma al importe a pagar del cliente
                    $sumaIgvGratuitas += round($valorVentaItem * $this->tasaIgv, 2);
                }
            } elseif ($afectacion->llevaIgv()) {
                $sumaGravadas += $valorVentaItem;
            } elseif ($afectacion->esExonerado()) {
                $sumaExoneradas += $valorVentaItem;
            } elseif ($afectacion->esInafecto()) {
                $sumaInafectas += $valorVentaItem;
            }

            if ($item->aplicaIcbper()) {
                $cantidadBolsasIcbper += (int) ceil($item->cantidad());
            }
        }

        // Aplicar descuento global proporcional a las bases onerosas (Gravadas, Exoneradas, Inafectas)
        $factorDescuento = $descuentoGlobalPorcentaje / 100.0;
        $descuentoGravadas = round($sumaGravadas * $factorDescuento, 2);
        $descuentoExoneradas = round($sumaExoneradas * $factorDescuento, 2);
        $descuentoInafectas = round($sumaInafectas * $factorDescuento, 2);
        $descuentoGlobalTotal = round($descuentoGravadas + $descuentoExoneradas + $descuentoInafectas, 2);

        $totalGravadas = round($sumaGravadas - $descuentoGravadas, 2);
        $totalExoneradas = round($sumaExoneradas - $descuentoExoneradas, 2);
        $totalInafectas = round($sumaInafectas - $descuentoInafectas, 2);
        $totalGratuitas = round($sumaGratuitas, 2);

        // Cálculo de IGV (18% sobre la base gravada neta)
        $totalIgv = round($totalGravadas * $this->tasaIgv, 2);

        // Cálculo de ICBPER (en PEN es 0.50 por bolsa; si es USD se convierte con el tipo de cambio)
        $tarifaIcbperMoneda = $moneda->esExtranjera()
            ? round(self::TARIFA_ICBPER_PEN / $moneda->tipoCambio(), 4)
            : self::TARIFA_ICBPER_PEN;
        $totalIcbper = round($cantidadBolsasIcbper * $tarifaIcbperMoneda, 2);

        // Total Impuestos Onerosos (IGV + ICBPER)
        $totalImpuestos = round($totalIgv + $totalIcbper, 2);

        // Valor de Venta Neto (sin impuestos) e Importe Total a Pagar
        $valorVentaNeto = round($totalGravadas + $totalExoneradas + $totalInafectas, 2);
        $importeTotal = round($valorVentaNeto + $totalImpuestos, 2);

        // Cálculo de Detracción SPOT (aplica solo si el equivalente en Soles supera S/ 700.00)
        $montoDetraccion = 0.0;
        $importeEnSoles = $moneda->convertirASoles($importeTotal);

        if ($porcentajeDetraccion > 0 && $importeEnSoles > self::TOPE_MINIMO_DETRACCION_PEN) {
            $montoDetraccion = round($importeTotal * ($porcentajeDetraccion / 100.0), 2);
        }

        $montoNetoPendientePago = round($importeTotal - $montoDetraccion, 2);

        return new ResumenImpuestos(
            totalGravadas: $totalGravadas,
            totalExoneradas: $totalExoneradas,
            totalInafectas: $totalInafectas,
            totalGratuitas: $totalGratuitas,
            totalDescuentosItems: round($sumaDescuentosItems, 2),
            descuentoGlobal: $descuentoGlobalTotal,
            totalIgv: $totalIgv,
            totalIgvGratuitas: round($sumaIgvGratuitas, 2),
            totalIcbper: $totalIcbper,
            totalImpuestos: $totalImpuestos,
            valorVentaNeto: $valorVentaNeto,
            importeTotal: $importeTotal,
            montoDetraccion: $montoDetraccion,
            montoNetoPendientePago: $montoNetoPendientePago
        );
    }
}
