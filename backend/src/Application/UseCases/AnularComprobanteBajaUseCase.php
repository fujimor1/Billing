<?php

declare(strict_types=1);

namespace App\Application\UseCases;

use App\Domain\Entities\Comprobante;
use App\Domain\Exceptions\ReglaTributariaException;
use App\Domain\Ports\ComprobanteRepositoryInterface;
use App\Domain\Ports\ProductoRepositoryInterface;
use App\Domain\Ports\SunatGatewayInterface;
use DateTimeImmutable;

/**
 * Caso de Uso: Comunicación de Baja (Anulación directa dentro de los 7 días)
 * Comunica la baja a SUNAT, obtiene el Ticket, cambia el estado a ANULADO_BAJA y repone el stock al Kardex.
 */
final class AnularComprobanteBajaUseCase
{
    public function __construct(
        private ComprobanteRepositoryInterface $comprobanteRepository,
        private ProductoRepositoryInterface $productoRepository,
        private SunatGatewayInterface $sunatGateway
    ) {
    }

    public function ejecutar(
        string $numeroComprobante,
        string $motivoBaja,
        DateTimeImmutable $fechaBaja = new DateTimeImmutable()
    ): Comprobante {
        $comprobante = $this->comprobanteRepository->buscarPorNumero($numeroComprobante);
        if ($comprobante === null) {
            throw new ReglaTributariaException("No se encontró el comprobante '{$numeroComprobante}' para dar de baja.");
        }

        // Valida estado, que no tenga NC previas y el plazo máximo de 7 días calendario
        $comprobante->anularPorComunicacionBaja($motivoBaja, $fechaBaja);

        // Comunicar baja a SUNAT (Genera Ticket RA)
        $ticketSunat = $this->sunatGateway->comunicarBaja($comprobante, $motivoBaja);
        $comprobante->registrarTicketBaja($ticketSunat, $motivoBaja);

        // Reponer el stock de todos los productos del comprobante anulado
        foreach ($comprobante->items() as $item) {
            $producto = $this->productoRepository->buscarPorCodigo($item->codigoProducto());
            if ($producto !== null) {
                $producto->reponerStock((int) ceil($item->cantidad()));
                $this->productoRepository->guardar($producto);
            }
        }

        $this->comprobanteRepository->guardar($comprobante);

        return $comprobante;
    }
}
