<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\UseCases\AnularComprobanteBajaUseCase;
use App\Application\UseCases\EmitirComprobanteUseCase;
use App\Application\UseCases\EmitirNotaCreditoUseCase;
use App\Domain\Entities\Cliente;
use App\Domain\Entities\ItemComprobante;
use App\Domain\Enums\EstadoComprobante;
use App\Domain\Enums\MotivoNotaCredito;
use App\Domain\Enums\TipoComprobante;
use App\Domain\Exceptions\StockInsuficienteException;
use App\Domain\Ports\RespuestaSunatCdr;
use App\Domain\Ports\SunatGatewayInterface;
use App\Domain\Services\CalculadoraTributaria;
use App\Infrastructure\Crypto\XmlDigitalSigner;
use App\Infrastructure\Persistence\SqliteComprobanteRepository;
use App\Infrastructure\Persistence\SqliteConnection;
use App\Infrastructure\Persistence\SqliteProductoRepository;
use App\Infrastructure\Xml\Ubl21XmlGenerator;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[TestDox('Suite Feature (Casos de Uso con Mocks y Spies): Simulación de SUNAT, Rollback de Kardex y Ciclo de Vida')]
final class FacturacionElectronicaMocksFeatureTest extends TestCase
{
    private SqliteComprobanteRepository $comprobanteRepo;
    private SqliteProductoRepository $productoRepo;
    private CalculadoraTributaria $calculadora;
    private Ubl21XmlGenerator $xmlGenerator;
    private XmlDigitalSigner $digitalSigner;

    protected function setUp(): void
    {
        $connection = new SqliteConnection(':memory:');
        $connection->sembrarCatalogoInicialSiVacio();

        $this->comprobanteRepo = new SqliteComprobanteRepository($connection->pdo());
        $this->productoRepo = new SqliteProductoRepository($connection->pdo());
        $this->calculadora = new CalculadoraTributaria();
        $this->xmlGenerator = new Ubl21XmlGenerator();
        $this->digitalSigner = new XmlDigitalSigner();
    }

    #[TestDox('Caso 1 (Mock Aceptado): Emite Factura, SUNAT devuelve CDR código 0 y se descuenta el stock en el Kardex')]
    public function testEmiteFacturaAceptadaPorSunatYDescuentaStockEnKardex(): void
    {
        // Creamos un Mock del servicio de SUNAT que espera exactamente 1 llamada y retorna Código 0 (Aceptado)
        $sunatMock = $this->createMock(SunatGatewayInterface::class);
        $sunatMock->expects($this->once())
            ->method('enviarComprobante')
            ->willReturn(new RespuestaSunatCdr('0', 'La Factura F001-00000001 ha sido aceptada', '<cdr/>'));

        $useCase = new EmitirComprobanteUseCase(
            $this->comprobanteRepo,
            $this->productoRepo,
            $this->calculadora,
            $this->xmlGenerator,
            $this->digitalSigner,
            $sunatMock
        );

        // El producto LAP-PRO-16 inicia con stock 15
        $comprobante = $useCase->ejecutar(
            serie: 'F001',
            tipo: TipoComprobante::FACTURA,
            cliente: Cliente::conRuc('20100047218', 'BANCO DE CREDITO DEL PERU'),
            items: [new ItemComprobante('LAP-PRO-16', 'Laptop Workstation Pro 16"', 5, 3500.00)]
        );

        $this->assertSame('F001-00000001', $comprobante->numeroCompleto());
        $this->assertSame(EstadoComprobante::ACEPTADO, $comprobante->estado());
        $this->assertNotNull($comprobante->hashCpe());

        // Verificamos que el stock en base de datos bajó de 15 a 10
        $productoActualizado = $this->productoRepo->buscarPorCodigo('LAP-PRO-16');
        $this->assertSame(10, $productoActualizado?->stockActual());
    }

    #[TestDox('Caso 2 (Mock Caída de Red / Timeout): Si SUNAT falla por red, la factura pasa a CONTINGENCIA sin perder la venta')]
    public function testPasaAContingenciaCuandoElServidorDeSunatSufreCaidaDeRed(): void
    {
        $sunatMock = $this->createMock(SunatGatewayInterface::class);
        $sunatMock->expects($this->once())
            ->method('enviarComprobante')
            ->willThrowException(new RuntimeException('SOAP Fault: Connection timed out after 30000ms'));

        $useCase = new EmitirComprobanteUseCase(
            $this->comprobanteRepo,
            $this->productoRepo,
            $this->calculadora,
            $this->xmlGenerator,
            $this->digitalSigner,
            $sunatMock
        );

        $comprobante = $useCase->ejecutar(
            serie: 'F001',
            tipo: TipoComprobante::FACTURA,
            cliente: Cliente::conRuc('20100055237', 'ALICORP SAA'),
            items: [new ItemComprobante('MON-4K-27', 'Monitor IPS 27"', 2, 1100.00)]
        );

        $this->assertSame(EstadoComprobante::CONTINGENCIA, $comprobante->estado());
        $this->assertSame('NETWORK_TIMEOUT', $comprobante->codigoRespuestaSunat());
        // El stock de MON-4K-27 (25) se reserva (queda en 23) porque la factura está en cola de contingencia
        $this->assertSame(23, $this->productoRepo->buscarPorCodigo('MON-4K-27')?->stockActual());
    }

    #[TestDox('Caso 3 (Mock Rechazo Fiscal SUNAT): Si SUNAT rechaza la factura (código 2800), hace Rollback automático del Stock')]
    public function testRevierteElStockAutomaticamenteCuandoSunatRechazaElComprobante(): void
    {
        $sunatMock = $this->createMock(SunatGatewayInterface::class);
        $sunatMock->expects($this->once())
            ->method('enviarComprobante')
            ->willReturn(new RespuestaSunatCdr('2800', 'Rechazo: Contribuyente en Baja de Oficio'));

        $useCase = new EmitirComprobanteUseCase(
            $this->comprobanteRepo,
            $this->productoRepo,
            $this->calculadora,
            $this->xmlGenerator,
            $this->digitalSigner,
            $sunatMock
        );

        // LAP-PRO-16 inicia con 15 unidades. Intentamos vender 10 unidades.
        $comprobante = $useCase->ejecutar(
            serie: 'F001',
            tipo: TipoComprobante::FACTURA,
            cliente: Cliente::conRuc('20131312955', 'EMPRESA CON BAJA DE OFICIO SAC'),
            items: [new ItemComprobante('LAP-PRO-16', 'Laptop Workstation Pro 16"', 10, 3500.00)]
        );

        $this->assertSame(EstadoComprobante::RECHAZADO, $comprobante->estado());
        // ¡El stock en el Kardex debe seguir intacto en 15 unidades gracias al Rollback!
        $this->assertSame(15, $this->productoRepo->buscarPorCodigo('LAP-PRO-16')?->stockActual());
    }

    #[TestDox('Caso 4 (Flujo Completo): Emite Factura -> Emite Nota de Crédito por devolución -> Restaura Stock y actualiza saldo')]
    public function testFlujoCompletoFacturaYNotaDeCreditoRestauraStockEnKardex(): void
    {
        $sunatMock = $this->createMock(SunatGatewayInterface::class);
        $sunatMock->expects($this->exactly(2))
            ->method('enviarComprobante')
            ->willReturn(new RespuestaSunatCdr('0', 'Documento aceptado por SUNAT'));

        $emitirFactura = new EmitirComprobanteUseCase(
            $this->comprobanteRepo,
            $this->productoRepo,
            $this->calculadora,
            $this->xmlGenerator,
            $this->digitalSigner,
            $sunatMock
        );

        $emitirNotaCredito = new EmitirNotaCreditoUseCase(
            $this->comprobanteRepo,
            $this->productoRepo,
            $this->calculadora,
            $this->xmlGenerator,
            $this->digitalSigner,
            $sunatMock
        );

        // 1. Emitimos Factura por 4 Laptops (Stock baja de 15 a 11)
        $factura = $emitirFactura->ejecutar(
            serie: 'F001',
            tipo: TipoComprobante::FACTURA,
            cliente: Cliente::conRuc('20100047218', 'BANCO DE CREDITO'),
            items: [new ItemComprobante('LAP-PRO-16', 'Laptop Workstation Pro 16"', 4, 3500.00)]
        );
        $this->assertSame(11, $this->productoRepo->buscarPorCodigo('LAP-PRO-16')?->stockActual());

        // 2. El cliente devuelve 1 Laptop mediante Nota de Crédito (Motivo 07: Devolución por ítem)
        $notaCredito = $emitirNotaCredito->ejecutar(
            serieNota: 'FC01',
            numeroComprobanteOrigen: $factura->numeroCompleto(),
            motivo: MotivoNotaCredito::DEVOLUCION_POR_ITEM,
            sustento: 'Devolución de 1 unidad por cambio de modelo',
            itemsDevolucion: [new ItemComprobante('LAP-PRO-16', 'Laptop Workstation Pro 16"', 1, 3500.00)]
        );

        $this->assertSame('FC01-00000001', $notaCredito->numeroCompleto());
        $this->assertSame(EstadoComprobante::ACEPTADO, $notaCredito->estado());

        // 3. El stock en el Kardex sube automáticamente de 11 a 12 unidades
        $this->assertSame(12, $this->productoRepo->buscarPorCodigo('LAP-PRO-16')?->stockActual());

        // 4. El saldo disponible de la factura original disminuyó en el valor de 1 Laptop (S/ 4,130.00)
        $facturaActualizada = $this->comprobanteRepo->buscarPorNumero('F001-00000001');
        $this->assertSame(12390.00, $facturaActualizada?->saldoDisponibleParaNotasCredito());
    }

    #[TestDox('Caso 5 (Comunicación de Baja): Anula una Factura dentro del plazo legal, genera Ticket SUNAT y repone el Stock')]
    public function testComunicacionDeBajaAnulaFacturaYReponeStock(): void
    {
        $sunatMock = $this->createMock(SunatGatewayInterface::class);
        $sunatMock->method('enviarComprobante')
            ->willReturn(new RespuestaSunatCdr('0', 'Aceptado'));
        $sunatMock->expects($this->once())
            ->method('comunicarBaja')
            ->willReturn('2026009876543');

        $emitirFactura = new EmitirComprobanteUseCase(
            $this->comprobanteRepo,
            $this->productoRepo,
            $this->calculadora,
            $this->xmlGenerator,
            $this->digitalSigner,
            $sunatMock
        );

        $anularBaja = new AnularComprobanteBajaUseCase(
            $this->comprobanteRepo,
            $this->productoRepo,
            $sunatMock
        );

        // Emitimos factura por 5 Teclados (Stock inicial 40 -> baja a 35)
        $fechaEmision = new DateTimeImmutable('2026-11-10 09:00:00');
        $factura = $emitirFactura->ejecutar(
            serie: 'F001',
            tipo: TipoComprobante::FACTURA,
            cliente: Cliente::conRuc('20100047218', 'BANCO DE CREDITO'),
            items: [new ItemComprobante('TEC-MEC-RGB', 'Teclado Mecánico', 5, 250.00)],
            fechaEmision: $fechaEmision
        );
        $this->assertSame(35, $this->productoRepo->buscarPorCodigo('TEC-MEC-RGB')?->stockActual());

        // Damos de baja al día siguiente
        $comprobanteAnulado = $anularBaja->ejecutar(
            numeroComprobante: $factura->numeroCompleto(),
            motivoBaja: 'Duplicidad en orden de compra del cliente',
            fechaBaja: new DateTimeImmutable('2026-11-11 11:00:00')
        );

        $this->assertSame(EstadoComprobante::ANULADO_BAJA, $comprobanteAnulado->estado());
        $this->assertStringContainsString('2026009876543', (string) $comprobanteAnulado->mensajeRespuestaSunat());
        // El stock regresó a sus 40 unidades originales
        $this->assertSame(40, $this->productoRepo->buscarPorCodigo('TEC-MEC-RGB')?->stockActual());
    }

    #[TestDox('Caso 6 (Protección de Inventario): Impide emitir un comprobante y no llama a SUNAT si no hay stock suficiente')]
    public function testImpideEmitirComprobanteYNoLlamaASunatSiNoHayStock(): void
    {
        $sunatMock = $this->createMock(SunatGatewayInterface::class);
        // Si no hay stock, NUNCA debe enviarse nada a SUNAT
        $sunatMock->expects($this->never())->method('enviarComprobante');

        $useCase = new EmitirComprobanteUseCase(
            $this->comprobanteRepo,
            $this->productoRepo,
            $this->calculadora,
            $this->xmlGenerator,
            $this->digitalSigner,
            $sunatMock
        );

        $this->expectException(StockInsuficienteException::class);

        // LAP-PRO-16 solo tiene 15 unidades en stock; intentamos facturar 50
        $useCase->ejecutar(
            serie: 'F001',
            tipo: TipoComprobante::FACTURA,
            cliente: Cliente::conRuc('20100047218', 'BANCO DE CREDITO'),
            items: [new ItemComprobante('LAP-PRO-16', 'Laptop Workstation Pro 16"', 50, 3500.00)]
        );
    }
}
