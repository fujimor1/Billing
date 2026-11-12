<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Application\UseCases\AnularComprobanteBajaUseCase;
use App\Application\UseCases\EmitirComprobanteUseCase;
use App\Application\UseCases\EmitirNotaCreditoUseCase;
use App\Domain\Entities\Cliente;
use App\Domain\Entities\Comprobante;
use App\Domain\Entities\ItemComprobante;
use App\Domain\Entities\Producto;
use App\Domain\Enums\MotivoNotaCredito;
use App\Domain\Enums\TipoAfectacionIgv;
use App\Domain\Enums\TipoComprobante;
use App\Domain\Exceptions\FacturacionDomainException;
use App\Domain\Services\CalculadoraTributaria;
use App\Domain\ValueObjects\Moneda;
use App\Infrastructure\Crypto\XmlDigitalSigner;
use App\Infrastructure\Gateways\SunatSoapSimulatorGateway;
use App\Infrastructure\Persistence\SqliteComprobanteRepository;
use App\Infrastructure\Persistence\SqliteConnection;
use App\Infrastructure\Persistence\SqliteProductoRepository;
use App\Infrastructure\Xml\Ubl21XmlGenerator;

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$dbPath = __DIR__ . '/../storage/facturacion.sqlite';
if (!is_dir(dirname($dbPath))) {
    mkdir(dirname($dbPath), 0777, true);
}

$connection = new SqliteConnection($dbPath);
$connection->sembrarCatalogoInicialSiVacio();

$productoRepo = new SqliteProductoRepository($connection->pdo());
$comprobanteRepo = new SqliteComprobanteRepository($connection->pdo());
$calculadora = new CalculadoraTributaria(0.18);
$xmlGenerator = new Ubl21XmlGenerator();
$digitalSigner = new XmlDigitalSigner();
$sunatGateway = new SunatSoapSimulatorGateway($digitalSigner);

function serializarComprobante(Comprobante $c): array
{
    return [
        'numero_completo' => $c->numeroCompleto(),
        'serie' => $c->serieCorrelativo()->serie(),
        'correlativo' => $c->serieCorrelativo()->correlativo(),
        'tipo' => $c->tipo()->value,
        'tipo_nombre' => $c->tipo()->nombre(),
        'cliente' => [
            'tipo_doc' => $c->cliente()->tipoDocumento(),
            'num_doc' => $c->cliente()->numeroDocumento(),
            'razon_social' => $c->cliente()->razonSocial(),
        ],
        'moneda' => $c->moneda()->codigo(),
        'tipo_cambio' => $c->moneda()->tipoCambio(),
        'fecha_emision' => $c->fechaEmision()->format('Y-m-d H:i:s'),
        'estado' => $c->estado()->value,
        'hash_cpe' => $c->hashCpe(),
        'xml_firmado' => $c->xmlFirmado(),
        'codigo_sunat' => $c->codigoRespuestaSunat(),
        'mensaje_sunat' => $c->mensajeRespuestaSunat(),
        'comprobante_referencia' => $c->comprobanteReferencia(),
        'motivo_nc' => $c->motivoNotaCredito()?->value,
        'descripcion_motivo_nc' => $c->descripcionMotivoNota(),
        'saldo_disponible_nc' => $c->saldoDisponibleParaNotasCredito(),
        'resumen' => $c->resumenImpuestos()->toArray(),
        'items' => array_map(fn (ItemComprobante $i) => [
            'codigo' => $i->codigoProducto(),
            'descripcion' => $i->descripcion(),
            'cantidad' => $i->cantidad(),
            'valor_unitario' => $i->valorUnitarioSinIgv(),
            'tipo_afectacion' => $i->tipoAfectacion()->value,
            'descuento_porcentaje' => $i->descuentoPorcentaje(),
            'aplica_icbper' => $i->aplicaIcbper(),
            'valor_venta' => $i->valorVentaItem(),
        ], $c->items()),
    ];
}

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$body = json_decode((string) file_get_contents('php://input'), true) ?? [];

try {
    if ($uri === '/api/estado' && $method === 'GET') {
        $productos = array_map(fn (Producto $p) => [
            'codigo' => $p->codigo(),
            'descripcion' => $p->descripcion(),
            'valor_unitario_sin_igv' => $p->valorUnitarioSinIgv(),
            'stock_actual' => $p->stockActual(),
            'tipo_afectacion' => $p->tipoAfectacion()->value,
            'afecto_icbper' => $p->esAfectoIcbper(),
            'unidad_medida' => $p->unidadMedida(),
        ], $productoRepo->listarTodos());

        $comprobantes = array_map('serializarComprobante', $comprobanteRepo->listarTodos());

        echo json_encode([
            'ok' => true,
            'productos' => $productos,
            'comprobantes' => $comprobantes,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($uri === '/api/emitir' && $method === 'POST') {
        $modoSunat = (string) ($body['modo_simulacion_sunat'] ?? 'NORMAL');
        $sunatGateway->setModoSimulacion($modoSunat);

        $tipo = TipoComprobante::from((string) ($body['tipo_comprobante'] ?? '01'));
        $serie = (string) ($body['serie'] ?? ($tipo === TipoComprobante::FACTURA ? 'F001' : 'B001'));

        $tipoDocCliente = (string) ($body['cliente_tipo_doc'] ?? '6');
        $numDocCliente = (string) ($body['cliente_num_doc'] ?? '');
        $razonSocial = (string) ($body['cliente_razon_social'] ?? '');

        $cliente = match ($tipoDocCliente) {
            '6' => Cliente::conRuc($numDocCliente, $razonSocial),
            '1' => Cliente::conDni($numDocCliente, $razonSocial),
            default => Cliente::clienteVarios(),
        };

        $moneda = new Moneda(
            (string) ($body['moneda'] ?? 'PEN'),
            (float) ($body['tipo_cambio'] ?? 1.0)
        );

        $itemsInput = (array) ($body['items'] ?? []);
        $items = array_map(fn (array $item) => new ItemComprobante(
            codigoProducto: (string) $item['codigo'],
            descripcion: (string) $item['descripcion'],
            cantidad: (float) $item['cantidad'],
            valorUnitarioSinIgv: (float) $item['valor_unitario'],
            tipoAfectacion: TipoAfectacionIgv::from((string) ($item['tipo_afectacion'] ?? '10')),
            descuentoPorcentaje: (float) ($item['descuento_porcentaje'] ?? 0.0),
            aplicaIcbper: (bool) ($item['aplica_icbper'] ?? false),
            unidadMedida: (string) ($item['unidad_medida'] ?? 'NIU')
        ), $itemsInput);

        $useCase = new EmitirComprobanteUseCase(
            $comprobanteRepo,
            $productoRepo,
            $calculadora,
            $xmlGenerator,
            $digitalSigner,
            $sunatGateway
        );

        $comprobante = $useCase->ejecutar(
            serie: $serie,
            tipo: $tipo,
            cliente: $cliente,
            items: $items,
            moneda: $moneda,
            descuentoGlobalPorcentaje: (float) ($body['descuento_global'] ?? 0.0),
            porcentajeDetraccion: (float) ($body['porcentaje_detraccion'] ?? 0.0)
        );

        echo json_encode([
            'ok' => true,
            'comprobante' => serializarComprobante($comprobante),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($uri === '/api/nota-credito' && $method === 'POST') {
        $numeroOrigen = (string) ($body['comprobante_origen'] ?? '');
        $motivo = MotivoNotaCredito::from((string) ($body['motivo'] ?? '07'));
        $sustento = (string) ($body['sustento'] ?? '');

        $origen = $comprobanteRepo->buscarPorNumero($numeroOrigen);
        if ($origen === null) {
            throw new FacturacionDomainException("Comprobante origen '{$numeroOrigen}' no encontrado.");
        }

        $prefijo = substr($origen->serieCorrelativo()->serie(), 0, 1);
        $serieNota = $prefijo . 'C01';

        $useCase = new EmitirNotaCreditoUseCase(
            $comprobanteRepo,
            $productoRepo,
            $calculadora,
            $xmlGenerator,
            $digitalSigner,
            $sunatGateway
        );

        $nota = $useCase->ejecutar(
            serieNota: $serieNota,
            numeroComprobanteOrigen: $numeroOrigen,
            motivo: $motivo,
            sustento: $sustento,
            itemsDevolucion: $origen->items()
        );

        echo json_encode([
            'ok' => true,
            'comprobante' => serializarComprobante($nota),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($uri === '/api/baja' && $method === 'POST') {
        $numero = (string) ($body['numero_comprobante'] ?? '');
        $motivo = (string) ($body['motivo_baja'] ?? '');

        $useCase = new AnularComprobanteBajaUseCase($comprobanteRepo, $productoRepo, $sunatGateway);
        $anulado = $useCase->ejecutar($numero, $motivo);

        echo json_encode([
            'ok' => true,
            'comprobante' => serializarComprobante($anulado),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($uri === '/api/ejecutar-phpunit' && $method === 'POST') {
        $suite = (string) ($body['suite'] ?? '');
        $phpBin = 'C:\\xampp\\php\\php.exe';
        $phpunitBin = realpath(__DIR__ . '/../vendor/bin/phpunit');
        $configXml = realpath(__DIR__ . '/../phpunit.xml');

        $suiteArg = in_array($suite, ['Unit', 'Integration', 'Feature'], true)
            ? "--testsuite {$suite}"
            : '';

        $cmd = "\"{$phpBin}\" -d extension=zip \"{$phpunitBin}\" -c \"{$configXml}\" --testdox --no-color {$suiteArg} 2>&1";
        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);

        echo json_encode([
            'ok' => $exitCode === 0,
            'exit_code' => $exitCode,
            'output' => implode("\n", $output),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => "Ruta '{$uri}' no encontrada."]);
} catch (FacturacionDomainException $e) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'tipo_error' => (new ReflectionClass($e))->getShortName(),
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'tipo_error' => 'ErrorInterno',
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
