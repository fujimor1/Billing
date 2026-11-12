<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Entities\Cliente;
use App\Domain\Entities\Comprobante;
use App\Domain\Entities\ItemComprobante;
use App\Domain\Entities\Producto;
use App\Domain\Enums\EstadoComprobante;
use App\Domain\Enums\TipoComprobante;
use App\Domain\Services\CalculadoraTributaria;
use App\Domain\ValueObjects\SerieCorrelativo;
use App\Infrastructure\Persistence\SqliteComprobanteRepository;
use App\Infrastructure\Persistence\SqliteConnection;
use App\Infrastructure\Persistence\SqliteProductoRepository;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[TestDox('Suite de Integración 2: Persistencia Relacional SQLite (:memory:), Correlativos Secuenciales y Kardex')]
final class SqlitePersistenceIntegrationTest extends TestCase
{
    private SqliteComprobanteRepository $comprobanteRepo;
    private SqliteProductoRepository $productoRepo;
    private CalculadoraTributaria $calculadora;

    protected function setUp(): void
    {
        $connection = new SqliteConnection(':memory:');
        $connection->sembrarCatalogoInicialSiVacio();

        $this->comprobanteRepo = new SqliteComprobanteRepository($connection->pdo());
        $this->productoRepo = new SqliteProductoRepository($connection->pdo());
        $this->calculadora = new CalculadoraTributaria();
    }

    #[TestDox('Sembrado inicial carga el catálogo de productos y permite actualizar el stock en SQLite')]
    public function testCatalogoInicialYActualizacionDeStockEnSqlite(): void
    {
        $productos = $this->productoRepo->listarTodos();
        $this->assertCount(6, $productos);

        $laptop = $this->productoRepo->buscarPorCodigo('LAP-PRO-16');
        $this->assertNotNull($laptop);
        $this->assertSame(15, $laptop->stockActual());

        $laptop->descontarStock(3);
        $this->productoRepo->guardar($laptop);

        $laptopActualizada = $this->productoRepo->buscarPorCodigo('LAP-PRO-16');
        $this->assertSame(12, $laptopActualizada?->stockActual());
    }

    #[TestDox('Persiste y recupera un Comprobante completo manteniendo su correlativo secuencial por serie')]
    public function testPersistenciaYCorrelativoSecuencialPorSerie(): void
    {
        $this->assertSame(1, $this->comprobanteRepo->siguienteCorrelativo('F001'));
        $this->assertSame(1, $this->comprobanteRepo->siguienteCorrelativo('B001'));

        $items = [new ItemComprobante('LAP-PRO-16', 'Laptop Workstation', 1, 3500.00)];
        $factura1 = new Comprobante(
            new SerieCorrelativo('F001', 1, TipoComprobante::FACTURA),
            TipoComprobante::FACTURA,
            Cliente::conRuc('20131312955', 'SUNAT'),
            $items,
            $this->calculadora->calcular($items)
        );
        $factura1->registrarFirmaDigital('<xml>firmado</xml>', 'HASH123456=');
        $factura1->procesarRespuestaSunat('0', 'Aceptada');

        $this->comprobanteRepo->guardar($factura1);

        // El siguiente correlativo para F001 debe ser 2, mientras que para B001 sigue siendo 1
        $this->assertSame(2, $this->comprobanteRepo->siguienteCorrelativo('F001'));
        $this->assertSame(1, $this->comprobanteRepo->siguienteCorrelativo('B001'));

        $recuperada = $this->comprobanteRepo->buscarPorNumero('F001-00000001');
        $this->assertNotNull($recuperada);
        $this->assertSame(EstadoComprobante::ACEPTADO, $recuperada->estado());
        $this->assertSame('HASH123456=', $recuperada->hashCpe());
        $this->assertSame(4130.00, $recuperada->resumenImpuestos()->importeTotal);
    }
}
