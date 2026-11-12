<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Entities\Cliente;
use App\Domain\Entities\Comprobante;
use App\Domain\Entities\ItemComprobante;
use App\Domain\Entities\ResumenImpuestos;
use App\Domain\Enums\EstadoComprobante;
use App\Domain\Enums\MotivoNotaCredito;
use App\Domain\Enums\TipoAfectacionIgv;
use App\Domain\Enums\TipoComprobante;
use App\Domain\Ports\ComprobanteRepositoryInterface;
use App\Domain\ValueObjects\Moneda;
use App\Domain\ValueObjects\SerieCorrelativo;
use DateTimeImmutable;
use PDO;
use ReflectionClass;

final class SqliteComprobanteRepository implements ComprobanteRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    public function guardar(Comprobante $comprobante): void
    {
        $itemsArray = array_map(fn (ItemComprobante $item) => [
            'codigoProducto' => $item->codigoProducto(),
            'descripcion' => $item->descripcion(),
            'cantidad' => $item->cantidad(),
            'valorUnitarioSinIgv' => $item->valorUnitarioSinIgv(),
            'tipoAfectacion' => $item->tipoAfectacion()->value,
            'descuentoPorcentaje' => $item->descuentoPorcentaje(),
            'aplicaIcbper' => $item->aplicaIcbper(),
            'unidadMedida' => $item->unidadMedida(),
        ], $comprobante->items());

        $stmt = $this->pdo->prepare("
            INSERT INTO comprobantes (
                numero_completo, serie, correlativo, tipo_comprobante,
                cliente_tipo_doc, cliente_num_doc, cliente_razon_social, cliente_direccion,
                moneda_codigo, moneda_tipo_cambio, fecha_emision, estado,
                hash_cpe, xml_firmado, codigo_sunat, mensaje_sunat,
                comprobante_referencia, motivo_nc, descripcion_motivo_nc,
                saldo_disponible_nc, resumen_json, items_json
            ) VALUES (
                :numero_completo, :serie, :correlativo, :tipo_comprobante,
                :cliente_tipo_doc, :cliente_num_doc, :cliente_razon_social, :cliente_direccion,
                :moneda_codigo, :moneda_tipo_cambio, :fecha_emision, :estado,
                :hash_cpe, :xml_firmado, :codigo_sunat, :mensaje_sunat,
                :comprobante_referencia, :motivo_nc, :descripcion_motivo_nc,
                :saldo_disponible_nc, :resumen_json, :items_json
            )
            ON CONFLICT(numero_completo) DO UPDATE SET
                estado = excluded.estado,
                hash_cpe = excluded.hash_cpe,
                xml_firmado = excluded.xml_firmado,
                codigo_sunat = excluded.codigo_sunat,
                mensaje_sunat = excluded.mensaje_sunat,
                saldo_disponible_nc = excluded.saldo_disponible_nc
        ");

        $stmt->execute([
            ':numero_completo' => $comprobante->numeroCompleto(),
            ':serie' => $comprobante->serieCorrelativo()->serie(),
            ':correlativo' => $comprobante->serieCorrelativo()->correlativo(),
            ':tipo_comprobante' => $comprobante->tipo()->value,
            ':cliente_tipo_doc' => $comprobante->cliente()->tipoDocumento(),
            ':cliente_num_doc' => $comprobante->cliente()->numeroDocumento(),
            ':cliente_razon_social' => $comprobante->cliente()->razonSocial(),
            ':cliente_direccion' => $comprobante->cliente()->direccion(),
            ':moneda_codigo' => $comprobante->moneda()->codigo(),
            ':moneda_tipo_cambio' => $comprobante->moneda()->tipoCambio(),
            ':fecha_emision' => $comprobante->fechaEmision()->format('Y-m-d H:i:s'),
            ':estado' => $comprobante->estado()->value,
            ':hash_cpe' => $comprobante->hashCpe(),
            ':xml_firmado' => $comprobante->xmlFirmado(),
            ':codigo_sunat' => $comprobante->codigoRespuestaSunat(),
            ':mensaje_sunat' => $comprobante->mensajeRespuestaSunat(),
            ':comprobante_referencia' => $comprobante->comprobanteReferencia(),
            ':motivo_nc' => $comprobante->motivoNotaCredito()?->value,
            ':descripcion_motivo_nc' => $comprobante->descripcionMotivoNota(),
            ':saldo_disponible_nc' => $comprobante->saldoDisponibleParaNotasCredito(),
            ':resumen_json' => json_encode($comprobante->resumenImpuestos()->toArray(), JSON_THROW_ON_ERROR),
            ':items_json' => json_encode($itemsArray, JSON_THROW_ON_ERROR),
        ]);
    }

    public function buscarPorNumero(string $serieCorrelativo): ?Comprobante
    {
        $stmt = $this->pdo->prepare("SELECT * FROM comprobantes WHERE numero_completo = :num LIMIT 1");
        $stmt->execute([':num' => strtoupper(trim($serieCorrelativo))]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        return $this->hidratar($row);
    }

    public function listarTodos(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM comprobantes ORDER BY fecha_emision DESC, correlativo DESC");
        $rows = $stmt->fetchAll();

        return array_map(fn (array $row) => $this->hidratar($row), $rows);
    }

    public function siguienteCorrelativo(string $serie): int
    {
        $stmt = $this->pdo->prepare("SELECT MAX(correlativo) FROM comprobantes WHERE serie = :serie");
        $stmt->execute([':serie' => strtoupper(trim($serie))]);
        $max = $stmt->fetchColumn();

        return ((int) $max) + 1;
    }

    private function hidratar(array $row): Comprobante
    {
        $tipo = TipoComprobante::from((string) $row['tipo_comprobante']);
        $serieCorrelativo = new SerieCorrelativo((string) $row['serie'], (int) $row['correlativo'], $tipo);

        $cliente = match ((string) $row['cliente_tipo_doc']) {
            Cliente::TIPO_DOC_RUC => Cliente::conRuc((string) $row['cliente_num_doc'], (string) $row['cliente_razon_social'], (string) $row['cliente_direccion']),
            Cliente::TIPO_DOC_DNI => Cliente::conDni((string) $row['cliente_num_doc'], (string) $row['cliente_razon_social'], (string) $row['cliente_direccion']),
            default => Cliente::clienteVarios(),
        };

        $itemsData = json_decode((string) $row['items_json'], true, 512, JSON_THROW_ON_ERROR);
        $items = array_map(fn (array $i) => new ItemComprobante(
            codigoProducto: (string) $i['codigoProducto'],
            descripcion: (string) $i['descripcion'],
            cantidad: (float) $i['cantidad'],
            valorUnitarioSinIgv: (float) $i['valorUnitarioSinIgv'],
            tipoAfectacion: TipoAfectacionIgv::from((string) $i['tipoAfectacion']),
            descuentoPorcentaje: (float) $i['descuentoPorcentaje'],
            aplicaIcbper: (bool) $i['aplicaIcbper'],
            unidadMedida: (string) $i['unidadMedida']
        ), $itemsData);

        $r = json_decode((string) $row['resumen_json'], true, 512, JSON_THROW_ON_ERROR);
        $resumen = new ResumenImpuestos(
            totalGravadas: (float) $r['total_gravadas'],
            totalExoneradas: (float) $r['total_exoneradas'],
            totalInafectas: (float) $r['total_inafectas'],
            totalGratuitas: (float) $r['total_gratuitas'],
            totalDescuentosItems: (float) $r['total_descuentos_items'],
            descuentoGlobal: (float) $r['descuento_global'],
            totalIgv: (float) $r['total_igv'],
            totalIgvGratuitas: (float) $r['total_igv_gratuitas'],
            totalIcbper: (float) $r['total_icbper'],
            totalImpuestos: (float) $r['total_impuestos'],
            valorVentaNeto: (float) $r['valor_venta_neto'],
            importeTotal: (float) $r['importe_total'],
            montoDetraccion: (float) $r['monto_detraccion'],
            montoNetoPendientePago: (float) $r['monto_neto_pendiente_pago']
        );

        $moneda = new Moneda((string) $row['moneda_codigo'], (float) $row['moneda_tipo_cambio']);
        $fecha = new DateTimeImmutable((string) $row['fecha_emision']);

        $comprobante = new Comprobante($serieCorrelativo, $tipo, $cliente, $items, $resumen, $moneda, $fecha);

        // Restaurar estado interno persistido mediante Reflection para respetar encapsulamiento de dominio
        $ref = new ReflectionClass(Comprobante::class);
        $propiedades = [
            'estado' => EstadoComprobante::from((string) $row['estado']),
            'hashCpe' => $row['hash_cpe'],
            'xmlFirmado' => $row['xml_firmado'],
            'codigoRespuestaSunat' => $row['codigo_sunat'],
            'mensajeRespuestaSunat' => $row['mensaje_sunat'],
            'comprobanteReferencia' => $row['comprobante_referencia'],
            'motivoNotaCredito' => $row['motivo_nc'] ? MotivoNotaCredito::from((string) $row['motivo_nc']) : null,
            'descripcionMotivoNota' => $row['descripcion_motivo_nc'],
            'saldoDisponibleParaNotasCredito' => (float) $row['saldo_disponible_nc'],
        ];

        foreach ($propiedades as $nombreProp => $valor) {
            $prop = $ref->getProperty($nombreProp);
            $prop->setValue($comprobante, $valor);
        }

        return $comprobante;
    }
}
