<?php

declare(strict_types=1);

namespace App\Application\UseCases;

use App\Domain\Entities\Cliente;
use App\Domain\Entities\Comprobante;
use App\Domain\Entities\ItemComprobante;
use App\Domain\Enums\EstadoComprobante;
use App\Domain\Enums\TipoComprobante;
use App\Domain\Exceptions\ReglaTributariaException;
use App\Domain\Ports\ComprobanteRepositoryInterface;
use App\Domain\Ports\DigitalSignerInterface;
use App\Domain\Ports\ProductoRepositoryInterface;
use App\Domain\Ports\SunatGatewayInterface;
use App\Domain\Ports\XmlGeneratorInterface;
use App\Domain\Services\CalculadoraTributaria;
use App\Domain\ValueObjects\Moneda;
use App\Domain\ValueObjects\SerieCorrelativo;
use DateTimeImmutable;
use RuntimeException;

/**
 * Caso de Uso Principal: Emisión de Factura o Boleta Electrónica
 * Orquesta: Kardex -> Correlativo -> Cálculo -> XML UBL 2.1 -> Firma Digital -> Envío OSE/SUNAT -> Persistencia / Rollback Stock
 */
final class EmitirComprobanteUseCase
{
    public function __construct(
        private ComprobanteRepositoryInterface $comprobanteRepository,
        private ProductoRepositoryInterface $productoRepository,
        private CalculadoraTributaria $calculadora,
        private XmlGeneratorInterface $xmlGenerator,
        private DigitalSignerInterface $digitalSigner,
        private SunatGatewayInterface $sunatGateway
    ) {
    }

    /**
     * @param ItemComprobante[] $items
     */
    public function ejecutar(
        string $serie,
        TipoComprobante $tipo,
        Cliente $cliente,
        array $items,
        Moneda $moneda = new Moneda(Moneda::PEN),
        float $descuentoGlobalPorcentaje = 0.0,
        float $porcentajeDetraccion = 0.0,
        DateTimeImmutable $fechaEmision = new DateTimeImmutable()
    ): Comprobante {
        if (!in_array($tipo, [TipoComprobante::FACTURA, TipoComprobante::BOLETA], true)) {
            throw new ReglaTributariaException("Este caso de uso solo emite Facturas (01) o Boletas (03).");
        }

        // 1. Verificar existencia y disponibilidad de Stock (Kardex) antes de emitir
        $productosParaDescontar = [];
        foreach ($items as $item) {
            $producto = $this->productoRepository->buscarPorCodigo($item->codigoProducto());
            if ($producto !== null) {
                $producto->descontarStock((int) ceil($item->cantidad()));
                $productosParaDescontar[] = [
                    'producto' => $producto,
                    'cantidad' => (int) ceil($item->cantidad()),
                ];
            }
        }

        // 2. Calcular impuestos, descuentos, ICBPER y detracciones
        $resumenImpuestos = $this->calculadora->calcular(
            items: $items,
            moneda: $moneda,
            descuentoGlobalPorcentaje: $descuentoGlobalPorcentaje,
            porcentajeDetraccion: $porcentajeDetraccion
        );

        // 3. Obtener correlativo secuencial y construir la entidad Comprobante
        $correlativo = $this->comprobanteRepository->siguienteCorrelativo($serie);
        $serieCorrelativo = new SerieCorrelativo($serie, $correlativo, $tipo);

        $comprobante = new Comprobante(
            serieCorrelativo: $serieCorrelativo,
            tipo: $tipo,
            cliente: $cliente,
            items: $items,
            resumenImpuestos: $resumenImpuestos,
            moneda: $moneda,
            fechaEmision: $fechaEmision
        );

        // 4. Generar XML UBL 2.1 y aplicar Firma Criptográfica
        $xmlSinFirmar = $this->xmlGenerator->generarXmlUbl21($comprobante);
        $resultadoFirma = $this->digitalSigner->firmarXml($xmlSinFirmar);
        $comprobante->registrarFirmaDigital($resultadoFirma->xmlFirmado, $resultadoFirma->hashCpe);

        // 5. Enviar al Gateway de SUNAT / OSE manejando fallos de red (Contingencia) y Rechazos (Rollback de Stock)
        try {
            $cdr = $this->sunatGateway->enviarComprobante($comprobante, $resultadoFirma->xmlFirmado);
            $comprobante->procesarRespuestaSunat(
                $cdr->codigo,
                $cdr->mensaje,
                $cdr->tieneObservaciones()
            );
        } catch (RuntimeException $e) {
            // Si hay caída de red o Timeout de SUNAT, el comprobante pasa a CONTINGENCIA (no se pierde la venta)
            $comprobante->marcarEnContingenciaPorFalloRed($e->getMessage());
        }

        // 6. Si SUNAT rechazó definitivamente el comprobante (ej. error fiscal 2000-3999), revertimos el stock descontado
        if ($comprobante->estado() === EstadoComprobante::RECHAZADO) {
            foreach ($productosParaDescontar as $registro) {
                $registro['producto']->reponerStock($registro['cantidad']);
            }
        } else {
            // Persistimos el nuevo stock descontado en el Kardex
            foreach ($productosParaDescontar as $registro) {
                $this->productoRepository->guardar($registro['producto']);
            }
        }

        // 7. Guardar el comprobante con su estado final, XML y CDR
        $this->comprobanteRepository->guardar($comprobante);

        return $comprobante;
    }
}
