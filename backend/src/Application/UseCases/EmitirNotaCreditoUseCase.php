<?php

declare(strict_types=1);

namespace App\Application\UseCases;

use App\Domain\Entities\Comprobante;
use App\Domain\Entities\ItemComprobante;
use App\Domain\Enums\EstadoComprobante;
use App\Domain\Enums\MotivoNotaCredito;
use App\Domain\Enums\TipoComprobante;
use App\Domain\Exceptions\ReglaTributariaException;
use App\Domain\Ports\ComprobanteRepositoryInterface;
use App\Domain\Ports\DigitalSignerInterface;
use App\Domain\Ports\ProductoRepositoryInterface;
use App\Domain\Ports\SunatGatewayInterface;
use App\Domain\Ports\XmlGeneratorInterface;
use App\Domain\Services\CalculadoraTributaria;
use App\Domain\ValueObjects\SerieCorrelativo;
use DateTimeImmutable;

/**
 * Caso de Uso: Emisión de Nota de Crédito Electrónica (07)
 * Verifica el comprobante origen, emite la NC, actualiza el saldo del comprobante origen y restaura el stock al Kardex si corresponde.
 */
final class EmitirNotaCreditoUseCase
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
     * @param ItemComprobante[] $itemsDevolucion
     */
    public function ejecutar(
        string $serieNota,
        string $numeroComprobanteOrigen,
        MotivoNotaCredito $motivo,
        string $sustento,
        array $itemsDevolucion,
        DateTimeImmutable $fechaEmision = new DateTimeImmutable()
    ): Comprobante {
        $comprobanteOrigen = $this->comprobanteRepository->buscarPorNumero($numeroComprobanteOrigen);
        if ($comprobanteOrigen === null) {
            throw new ReglaTributariaException(
                "No existe el comprobante de referencia '{$numeroComprobanteOrigen}' para emitir la Nota de Crédito."
            );
        }

        $resumenNota = $this->calculadora->calcular($itemsDevolucion, $comprobanteOrigen->moneda());
        $correlativo = $this->comprobanteRepository->siguienteCorrelativo($serieNota);
        $serieCorrelativo = new SerieCorrelativo($serieNota, $correlativo, TipoComprobante::NOTA_CREDITO);

        $notaCredito = Comprobante::crearNotaCredito(
            serieNota: $serieCorrelativo,
            comprobanteOrigen: $comprobanteOrigen,
            motivo: $motivo,
            sustento: $sustento,
            itemsDevolucion: $itemsDevolucion,
            resumenNota: $resumenNota,
            fechaEmision: $fechaEmision
        );

        $xmlSinFirmar = $this->xmlGenerator->generarXmlUbl21($notaCredito);
        $firma = $this->digitalSigner->firmarXml($xmlSinFirmar);
        $notaCredito->registrarFirmaDigital($firma->xmlFirmado, $firma->hashCpe);

        $cdr = $this->sunatGateway->enviarComprobante($notaCredito, $firma->xmlFirmado);
        $notaCredito->procesarRespuestaSunat($cdr->codigo, $cdr->mensaje, $cdr->tieneObservaciones());

        if (in_array($notaCredito->estado(), [EstadoComprobante::ACEPTADO, EstadoComprobante::OBSERVADO], true)) {
            // Actualizar el saldo o estado (ANULADO_NC) del comprobante original
            $comprobanteOrigen->aplicarNotaCreditoAceptada($resumenNota->importeTotal, $motivo);
            $this->comprobanteRepository->guardar($comprobanteOrigen);

            // Si el motivo implica devolución física o anulación de la venta, restaurar el stock en el Kardex
            if ($motivo->restauraStock()) {
                foreach ($itemsDevolucion as $item) {
                    $producto = $this->productoRepository->buscarPorCodigo($item->codigoProducto());
                    if ($producto !== null) {
                        $producto->reponerStock((int) ceil($item->cantidad()));
                        $this->productoRepository->guardar($producto);
                    }
                }
            }
        }

        $this->comprobanteRepository->guardar($notaCredito);

        return $notaCredito;
    }
}
