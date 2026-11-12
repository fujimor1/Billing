<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Entities\Cliente;
use App\Domain\Entities\Comprobante;
use App\Domain\Entities\ItemComprobante;
use App\Domain\Enums\EstadoComprobante;
use App\Domain\Enums\MotivoNotaCredito;
use App\Domain\Enums\TipoComprobante;
use App\Domain\Exceptions\EstadoComprobanteInvalidoException;
use App\Domain\Exceptions\ReglaTributariaException;
use App\Domain\Services\CalculadoraTributaria;
use App\Domain\ValueObjects\Moneda;
use App\Domain\ValueObjects\SerieCorrelativo;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[TestDox('Suite Unitaria 3: Reglas de Negocio del Comprobante, Notas de Crédito y Comunicaciones de Baja')]
final class ComprobanteDomainRulesTest extends TestCase
{
    private CalculadoraTributaria $calculadora;

    protected function setUp(): void
    {
        $this->calculadora = new CalculadoraTributaria();
    }

    #[TestDox('Regla SUNAT 2017: Impide emitir una Factura Electrónica (01) a un cliente con DNI')]
    public function testImpideEmitirFacturaAClienteConDni(): void
    {
        $clienteDni = Cliente::conDni('72345678', 'Juan Pérez');
        $items = [new ItemComprobante('P01', 'Teclado', 1, 100.00)];
        $resumen = $this->calculadora->calcular($items);

        $this->expectException(ReglaTributariaException::class);
        $this->expectExceptionMessage('Error 2017');

        new Comprobante(
            new SerieCorrelativo('F001', 1, TipoComprobante::FACTURA),
            TipoComprobante::FACTURA,
            $clienteDni,
            $items,
            $resumen
        );
    }

    #[TestDox('Regla SUNAT 2511: Impide emitir Boleta mayor a S/ 700.00 a Clientes Varios (Sin DNI)')]
    public function testImpideBoletaMayorASetecientosSolesSinIdentificarCliente(): void
    {
        // 1 unidad de S/ 650 + IGV (18%) = S/ 767.00 (> S/ 700.00)
        $items = [new ItemComprobante('TV-01', 'Smart TV 50 pulgadas', 1, 650.00)];
        $resumen = $this->calculadora->calcular($items);

        $this->expectException(ReglaTributariaException::class);
        $this->expectExceptionMessage('Error 2511');

        new Comprobante(
            new SerieCorrelativo('B001', 1, TipoComprobante::BOLETA),
            TipoComprobante::BOLETA,
            Cliente::clienteVarios(),
            $items,
            $resumen
        );
    }

    #[TestDox('Permite emitir Boleta menor o igual a S/ 700.00 a Clientes Varios')]
    public function testPermiteBoletaMenorASetecientosSolesAClientesVarios(): void
    {
        $items = [new ItemComprobante('AUD-01', 'Audífonos Bluetooth', 1, 100.00)];
        $resumen = $this->calculadora->calcular($items); // S/ 118.00

        $boleta = new Comprobante(
            new SerieCorrelativo('B001', 10, TipoComprobante::BOLETA),
            TipoComprobante::BOLETA,
            Cliente::clienteVarios(),
            $items,
            $resumen
        );

        $this->assertSame('B001-00000010', $boleta->numeroCompleto());
        $this->assertSame(EstadoComprobante::BORRADOR, $boleta->estado());
    }

    #[TestDox('Controla el saldo de una Factura ante Notas de Crédito parciales e impide devolver más del total')]
    public function testControlaSaldoDeFacturaConNotasDeCreditoParciales(): void
    {
        $cliente = Cliente::conRuc('20131312955', 'EMPRESA DEMO SAC');
        $itemsFactura = [new ItemComprobante('LAP-01', 'Laptop', 2, 1000.00)];
        $resumenFactura = $this->calculadora->calcular($itemsFactura); // Total: S/ 2,360.00

        $factura = new Comprobante(
            new SerieCorrelativo('F001', 100, TipoComprobante::FACTURA),
            TipoComprobante::FACTURA,
            $cliente,
            $itemsFactura,
            $resumenFactura
        );
        $factura->procesarRespuestaSunat('0', 'La Factura F001-00000100 ha sido aceptada');

        // 1ra Nota de Crédito parcial por devolución de 1 Laptop (S/ 1,180.00)
        $itemsNc1 = [new ItemComprobante('LAP-01', 'Laptop', 1, 1000.00)];
        $resumenNc1 = $this->calculadora->calcular($itemsNc1);

        $nc1 = Comprobante::crearNotaCredito(
            new SerieCorrelativo('FC01', 1, TipoComprobante::NOTA_CREDITO),
            $factura,
            MotivoNotaCredito::DEVOLUCION_POR_ITEM,
            'Devolución de 1 equipo por falla en pantalla',
            $itemsNc1,
            $resumenNc1
        );

        $factura->aplicarNotaCreditoAceptada($nc1->resumenImpuestos()->importeTotal, MotivoNotaCredito::DEVOLUCION_POR_ITEM);

        // El saldo disponible de la factura baja a S/ 1,180.00 y sigue ACEPTADA
        $this->assertSame(1180.00, $factura->saldoDisponibleParaNotasCredito());
        $this->assertSame(EstadoComprobante::ACEPTADO, $factura->estado());

        // Intentar emitir una 2da Nota de Crédito por 2 Laptops (S/ 2,360.00 > S/ 1,180.00 disponible) debe fallar
        $this->expectException(ReglaTributariaException::class);
        $this->expectExceptionMessage('excede el saldo disponible');

        Comprobante::crearNotaCredito(
            new SerieCorrelativo('FC01', 2, TipoComprobante::NOTA_CREDITO),
            $factura,
            MotivoNotaCredito::DEVOLUCION_POR_ITEM,
            'Intento de devolver más de lo comprado',
            $itemsFactura,
            $resumenFactura
        );
    }

    #[TestDox('Impide anular por Comunicación de Baja una Factura emitida hace más de 7 días calendario')]
    public function testImpideComunicacionDeBajaPasadosSieteDias(): void
    {
        $fechaEmision = new DateTimeImmutable('2026-11-01 10:00:00');
        $fechaBajaExtemporanea = new DateTimeImmutable('2026-11-10 10:00:00'); // 9 días después

        $factura = new Comprobante(
            new SerieCorrelativo('F001', 50, TipoComprobante::FACTURA),
            TipoComprobante::FACTURA,
            Cliente::conRuc('20100047218', 'BANCO DE CREDITO'),
            [new ItemComprobante('SERV-01', 'Consultoría', 1, 1000.00)],
            $this->calculadora->calcular([new ItemComprobante('SERV-01', 'Consultoría', 1, 1000.00)]),
            new Moneda(Moneda::PEN),
            $fechaEmision
        );
        $factura->procesarRespuestaSunat('0', 'Aceptado');

        $this->expectException(ReglaTributariaException::class);
        $this->expectExceptionMessage('El plazo máximo para enviar una Comunicación de Baja es de 7 días');

        $factura->anularPorComunicacionBaja('Error en emisión', $fechaBajaExtemporanea);
    }

    #[TestDox('Permite Comunicación de Baja dentro de los 7 días y cambia estado a ANULADO_BAJA')]
    public function testPermiteComunicacionDeBajaDentroDelPlazo(): void
    {
        $fechaEmision = new DateTimeImmutable('2026-11-01 10:00:00');
        $fechaBajaValida = new DateTimeImmutable('2026-11-05 15:00:00'); // 4 días después

        $factura = new Comprobante(
            new SerieCorrelativo('F001', 51, TipoComprobante::FACTURA),
            TipoComprobante::FACTURA,
            Cliente::conRuc('20100047218', 'BANCO DE CREDITO'),
            [new ItemComprobante('SERV-01', 'Consultoría', 1, 1000.00)],
            $this->calculadora->calcular([new ItemComprobante('SERV-01', 'Consultoría', 1, 1000.00)]),
            new Moneda(Moneda::PEN),
            $fechaEmision
        );
        $factura->procesarRespuestaSunat('0', 'Aceptado');

        $factura->anularPorComunicacionBaja('Cliente desistió de la compra', $fechaBajaValida);

        $this->assertSame(EstadoComprobante::ANULADO_BAJA, $factura->estado());
    }
}
