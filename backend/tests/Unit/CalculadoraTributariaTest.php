<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Entities\ItemComprobante;
use App\Domain\Enums\TipoAfectacionIgv;
use App\Domain\Exceptions\ReglaTributariaException;
use App\Domain\Services\CalculadoraTributaria;
use App\Domain\ValueObjects\Moneda;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[TestDox('Suite Unitaria 2: Motor de Cálculo Tributario (IGV 18%, Exonerados, Gratuitos, ICBPER, Descuentos y Detracciones)')]
final class CalculadoraTributariaTest extends TestCase
{
    private CalculadoraTributaria $calculadora;

    protected function setUp(): void
    {
        $this->calculadora = new CalculadoraTributaria(0.18);
    }

    #[TestDox('Calcula exactamente una operación gravada estándar con IGV 18%')]
    public function testCalculaOperacionGravadaEstandar(): void
    {
        $items = [
            new ItemComprobante('LAP-01', 'Laptop Empresarial Core i7', 2, 2500.00, TipoAfectacionIgv::GRAVADO_OPERACION_ONEROSA),
            new ItemComprobante('MOU-01', 'Mouse Inalámbrico', 5, 50.00, TipoAfectacionIgv::GRAVADO_OPERACION_ONEROSA),
        ];

        $resumen = $this->calculadora->calcular($items);

        // 2 * 2500 + 5 * 50 = 5250.00
        $this->assertSame(5250.00, $resumen->totalGravadas);
        $this->assertSame(945.00, $resumen->totalIgv); // 18% de 5250
        $this->assertSame(6195.00, $resumen->importeTotal);
        $this->assertSame(0.0, $resumen->totalExoneradas);
        $this->assertSame(0.0, $resumen->totalInafectas);
    }

    #[TestDox('Calcula factura mixta: Gravados + Exonerados (Amazonía/Libros) + Inafectos + Bonificación Gratuita + Bolsas ICBPER')]
    public function testCalculaComprobanteMixtoComplejo(): void
    {
        $items = [
            // 1. Gravado con 10% de descuento por ítem: 10 * 100 = 1000 - 10% = 900.00
            new ItemComprobante(
                codigoProducto: 'PROD-GRAV',
                descripcion: 'Monitor 27 pulgadas',
                cantidad: 10,
                valorUnitarioSinIgv: 100.00,
                tipoAfectacion: TipoAfectacionIgv::GRAVADO_OPERACION_ONEROSA,
                descuentoPorcentaje: 10.0
            ),
            // 2. Exonerado de IGV (ej. Libro o producto agrario): 2 * 150 = 300.00
            new ItemComprobante(
                codigoProducto: 'LIB-EXO',
                descripcion: 'Libro Ingeniería de Software y Testing',
                cantidad: 2,
                valorUnitarioSinIgv: 150.00,
                tipoAfectacion: TipoAfectacionIgv::EXONERADO_OPERACION_ONEROSA
            ),
            // 3. Transferencia Gratuita (Premio/Promoción afecta a retiro IGV): 1 * 200 = 200.00 (No suma al total a pagar)
            new ItemComprobante(
                codigoProducto: 'PREMIO-01',
                descripcion: 'Teclado Mecánico (Bonificación Promocional)',
                cantidad: 1,
                valorUnitarioSinIgv: 200.00,
                tipoAfectacion: TipoAfectacionIgv::GRAVADO_RETIRO_PREMIO
            ),
            // 4. Bolsas plásticas afectas a ICBPER: 4 bolsas * S/ 0.10 + S/ 0.50 ICBPER c/u
            new ItemComprobante(
                codigoProducto: 'BOLSA-BIO',
                descripcion: 'Bolsa Plástica Grande',
                cantidad: 4,
                valorUnitarioSinIgv: 0.10,
                tipoAfectacion: TipoAfectacionIgv::GRAVADO_OPERACION_ONEROSA,
                aplicaIcbper: true
            ),
        ];

        $resumen = $this->calculadora->calcular($items);

        // Gravadas: 900.00 + 0.40 = 900.40
        $this->assertSame(900.40, $resumen->totalGravadas);
        // Exoneradas: 300.00
        $this->assertSame(300.00, $resumen->totalExoneradas);
        // Gratuitas: 200.00 (y su IGV referencial es 36.00, que no lo paga el cliente)
        $this->assertSame(200.00, $resumen->totalGratuitas);
        $this->assertSame(36.00, $resumen->totalIgvGratuitas);
        // Descuento por ítem: 100.00
        $this->assertSame(100.00, $resumen->totalDescuentosItems);
        // IGV Oneroso: round(900.40 * 0.18, 2) = 162.07
        $this->assertSame(162.07, $resumen->totalIgv);
        // ICBPER: 4 bolsas * 0.50 = 2.00
        $this->assertSame(2.00, $resumen->totalIcbper);
        // Total Impuestos = 162.07 + 2.00 = 164.07
        $this->assertSame(164.07, $resumen->totalImpuestos);
        // Valor Venta Neto = 900.40 + 300.00 = 1200.40
        $this->assertSame(1200.40, $resumen->valorVentaNeto);
        // Importe Total a Pagar = 1200.40 + 164.07 = 1364.47 (Las gratuitas no se cobran al cliente)
        $this->assertSame(1364.47, $resumen->importeTotal);
    }

    #[TestDox('Aplica Detracción SPOT (12%) automáticamente cuando el servicio supera S/ 700.00 o equivalente en USD')]
    public function testCalculaDetraccionSpotEnDolaresCuandoSuperaTopeEnSoles(): void
    {
        // Servicio de consultoría en Dólares: USD 300 + IGV = USD 354.00
        // Al tipo de cambio 3.80 -> S/ 1,345.20 (Supera los S/ 700.00 de ley)
        $items = [
            new ItemComprobante('SERV-TI', 'Auditoría QA y Pruebas de Software', 1, 300.00, TipoAfectacionIgv::GRAVADO_OPERACION_ONEROSA, unidadMedida: 'ZZ'),
        ];

        $monedaUsd = new Moneda(Moneda::USD, 3.80);
        $resumen = $this->calculadora->calcular($items, $monedaUsd, descuentoGlobalPorcentaje: 0.0, porcentajeDetraccion: 12.0);

        $this->assertSame(354.00, $resumen->importeTotal);
        // Detracción 12% de 354.00 = 42.48 USD
        $this->assertSame(42.48, $resumen->montoDetraccion);
        // Neto pendiente a pagar al proveedor = 354.00 - 42.48 = 311.52 USD
        $this->assertSame(311.52, $resumen->montoNetoPendientePago);
    }

    #[TestDox('No aplica Detracción SPOT si el importe total en Soles no supera los S/ 700.00')]
    public function testNoAplicaDetraccionSiMontoNoSuperaSetecientosSoles(): void
    {
        // S/ 500 + IGV = S/ 590.00 (Menor a S/ 700.00)
        $items = [
            new ItemComprobante('SERV-MANT', 'Soporte Técnico Básico', 1, 500.00, TipoAfectacionIgv::GRAVADO_OPERACION_ONEROSA, unidadMedida: 'ZZ'),
        ];

        $resumen = $this->calculadora->calcular($items, new Moneda(Moneda::PEN), 0.0, 12.0);

        $this->assertSame(590.00, $resumen->importeTotal);
        $this->assertSame(0.0, $resumen->montoDetraccion);
        $this->assertSame(590.00, $resumen->montoNetoPendientePago);
    }

    #[TestDox('Lanza excepción si se intenta aplicar descuento comercial a una bonificación gratuita')]
    public function testImpideDescuentoEnItemGratuito(): void
    {
        $this->expectException(ReglaTributariaException::class);
        $this->expectExceptionMessage('No se puede aplicar descuento comercial a una transferencia gratuita');

        new ItemComprobante(
            codigoProducto: 'BONO-01',
            descripcion: 'Muestra Médica Gratuita',
            cantidad: 1,
            valorUnitarioSinIgv: 50.00,
            tipoAfectacion: TipoAfectacionIgv::INAFECTO_BONIFICACION,
            descuentoPorcentaje: 15.0
        );
    }

    #[TestDox('Calcula exactamente una operación inafecta al IGV (30) con base imponible y 0% de IGV')]
    public function testCalculaOperacionInafectaEstricta(): void
    {
        $items = [
            new ItemComprobante(
                codigoProducto: 'CURS-EDU-INAF',
                descripcion: 'Diplomado Superior en Ingeniería de Software (Inafecto)',
                cantidad: 1,
                valorUnitarioSinIgv: 1500.00,
                tipoAfectacion: TipoAfectacionIgv::INAFECTO_OPERACION_ONEROSA,
                unidadMedida: 'ZZ'
            ),
        ];

        $resumen = $this->calculadora->calcular($items);

        $this->assertSame(1500.00, $resumen->totalInafectas);
        $this->assertSame(0.0, $resumen->totalGravadas);
        $this->assertSame(0.0, $resumen->totalExoneradas);
        $this->assertSame(0.0, $resumen->totalIgv);
        $this->assertSame(1500.00, $resumen->importeTotal);
        $this->assertSame(1500.00, $resumen->valorVentaNeto);
    }
}
