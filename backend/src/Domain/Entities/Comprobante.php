<?php

declare(strict_types=1);

namespace App\Domain\Entities;

use App\Domain\Enums\EstadoComprobante;
use App\Domain\Enums\MotivoNotaCredito;
use App\Domain\Enums\TipoComprobante;
use App\Domain\Exceptions\EstadoComprobanteInvalidoException;
use App\Domain\Exceptions\ReglaTributariaException;
use App\Domain\ValueObjects\Moneda;
use App\Domain\ValueObjects\SerieCorrelativo;
use DateTimeImmutable;

/**
 * Agregado Raíz: Comprobante de Pago Electrónico (Factura, Boleta, Nota de Crédito, Nota de Débito)
 * Implementa las reglas tributarias de SUNAT y la máquina de estados del CPE.
 */
final class Comprobante
{
    public const MONTO_MINIMO_IDENTIFICACION_BOLETA_PEN = 700.00;
    public const PLAZO_MAXIMO_DIAS_BAJA = 7;

    private EstadoComprobante $estado;
    private ?string $hashCpe = null;
    private ?string $xmlFirmado = null;
    private ?string $codigoRespuestaSunat = null;
    private ?string $mensajeRespuestaSunat = null;
    private ?string $comprobanteReferencia = null;
    private ?MotivoNotaCredito $motivoNotaCredito = null;
    private ?string $descripcionMotivoNota = null;
    private float $saldoDisponibleParaNotasCredito;

    /**
     * @param ItemComprobante[] $items
     */
    public function __construct(
        private SerieCorrelativo $serieCorrelativo,
        private TipoComprobante $tipo,
        private Cliente $cliente,
        private array $items,
        private ResumenImpuestos $resumenImpuestos,
        private Moneda $moneda = new Moneda(Moneda::PEN),
        private DateTimeImmutable $fechaEmision = new DateTimeImmutable()
    ) {
        if (empty($items)) {
            throw new ReglaTributariaException("El comprobante debe contener al menos un ítem.");
        }

        $this->validarReglasTributariasDeEmision();

        $this->estado = EstadoComprobante::BORRADOR;
        $this->saldoDisponibleParaNotasCredito = $resumenImpuestos->importeTotal;
    }

    private function validarReglasTributariasDeEmision(): void
    {
        // Regla 1: Una Factura (01) solo puede emitirse a un cliente con RUC válido (Catálogo 06 = '6') unless Exportación
        if ($this->tipo === TipoComprobante::FACTURA && !$this->cliente->tieneRuc()) {
            throw new ReglaTributariaException(
                "Regla SUNAT (Error 2017): Una Factura Electrónica solo puede emitirse a un cliente con RUC válido."
            );
        }

        // Regla 2: En Boleta de Venta (03), si el importe total supera S/ 700.00 (o su equivalente en USD), es obligatorio identificar al adquirente con DNI o RUC
        $importeEnSoles = $this->moneda->convertirASoles($this->resumenImpuestos->importeTotal);
        if (
            $this->tipo === TipoComprobante::BOLETA
            && $importeEnSoles > self::MONTO_MINIMO_IDENTIFICACION_BOLETA_PEN
            && $this->cliente->esClienteVarios()
        ) {
            throw new ReglaTributariaException(
                "Regla SUNAT (Error 2511): En Boletas de Venta con importe mayor a S/ 700.00 (actual: S/ {$importeEnSoles}), " .
                "es obligatorio identificar al cliente con DNI o RUC."
            );
        }
    }

    /**
     * Fábrica para crear una Nota de Crédito vinculada a un comprobante origen
     *
     * @param ItemComprobante[] $itemsDevolucion
     */
    public static function crearNotaCredito(
        SerieCorrelativo $serieNota,
        Comprobante $comprobanteOrigen,
        MotivoNotaCredito $motivo,
        string $sustento,
        array $itemsDevolucion,
        ResumenImpuestos $resumenNota,
        DateTimeImmutable $fechaEmision = new DateTimeImmutable()
    ): self {
        if (trim($sustento) === '' || mb_strlen(trim($sustento)) < 5) {
            throw new ReglaTributariaException("Toda Nota de Crédito requiere un motivo o sustento de al menos 5 caracteres.");
        }

        // Regla 1: Solo se puede aplicar Nota de Crédito a un comprobante ACEPTADO o OBSERVADO
        if (!in_array($comprobanteOrigen->estado(), [EstadoComprobante::ACEPTADO, EstadoComprobante::OBSERVADO], true)) {
            throw new EstadoComprobanteInvalidoException(
                "No se puede emitir una Nota de Crédito sobre el comprobante {$comprobanteOrigen->numeroCompleto()} " .
                "porque su estado actual es {$comprobanteOrigen->estado()->value}."
            );
        }

        // Regla 2: Coherencia de Serie (Factura -> FC.., Boleta -> BC..)
        $prefijoOrigen = substr($comprobanteOrigen->serieCorrelativo()->serie(), 0, 1);
        $prefijoNota = substr($serieNota->serie(), 0, 1);
        if ($prefijoOrigen !== $prefijoNota) {
            throw new ReglaTributariaException(
                "Incoherencia de serie en Nota de Crédito: un comprobante serie '{$prefijoOrigen}' " .
                "no puede modificarse con una Nota de Crédito serie '{$serieNota->serie()}'."
            );
        }

        // Regla 3: El monto de la Nota de Crédito no puede superar el saldo disponible del comprobante original
        if ($resumenNota->importeTotal > $comprobanteOrigen->saldoDisponibleParaNotasCredito() + 0.001) {
            throw new ReglaTributariaException(
                "El importe de la Nota de Crédito ({$resumenNota->importeTotal}) excede el saldo disponible " .
                "del comprobante origen ({$comprobanteOrigen->saldoDisponibleParaNotasCredito()})."
            );
        }

        $nota = new self(
            serieCorrelativo: $serieNota,
            tipo: TipoComprobante::NOTA_CREDITO,
            cliente: $comprobanteOrigen->cliente(),
            items: $itemsDevolucion,
            resumenImpuestos: $resumenNota,
            moneda: $comprobanteOrigen->moneda(),
            fechaEmision: $fechaEmision
        );

        $nota->comprobanteReferencia = $comprobanteOrigen->numeroCompleto();
        $nota->motivoNotaCredito = $motivo;
        $nota->descripcionMotivoNota = trim($sustento);

        return $nota;
    }

    public function aplicarNotaCreditoAceptada(float $montoNota, MotivoNotaCredito $motivo): void
    {
        $this->saldoDisponibleParaNotasCredito = max(0.0, round($this->saldoDisponibleParaNotasCredito - $montoNota, 2));
        if ($motivo->anulaComprobanteTotalmente() || $this->saldoDisponibleParaNotasCredito <= 0.0) {
            $this->estado = EstadoComprobante::ANULADO_NC;
        }
    }

    /**
     * Registra la firma digital y el XML UBL 2.1 generado
     */
    public function registrarFirmaDigital(string $xmlFirmado, string $hashCpe): void
    {
        if (trim($xmlFirmado) === '' || trim($hashCpe) === '') {
            throw new ReglaTributariaException("El XML firmado y el Hash CPE (DigestValue) no pueden estar vacíos.");
        }
        $this->xmlFirmado = $xmlFirmado;
        $this->hashCpe = $hashCpe;
    }

    /**
     * Procesa la respuesta del CDR de SUNAT / OSE
     */
    public function procesarRespuestaSunat(string $codigo, string $mensaje, bool $hayObservaciones = false): void
    {
        $this->codigoRespuestaSunat = $codigo;
        $this->mensajeRespuestaSunat = $mensaje;

        if ($codigo === '0') {
            $this->estado = $hayObservaciones ? EstadoComprobante::OBSERVADO : EstadoComprobante::ACEPTADO;
            return;
        }

        $codigoNumerico = (int) $codigo;
        if ($codigoNumerico >= 100 && $codigoNumerico <= 1999) {
            // Excepción temporal o caída del servicio -> pasa a CONTINGENCIA para reenvío posterior
            $this->estado = EstadoComprobante::CONTINGENCIA;
            return;
        }

        // Códigos 2000 al 3999 son Rechazos definitivos por reglas de SUNAT
        $this->estado = EstadoComprobante::RECHAZADO;
    }

    public function marcarEnContingenciaPorFalloRed(string $detalleError): void
    {
        $this->estado = EstadoComprobante::CONTINGENCIA;
        $this->codigoRespuestaSunat = 'NETWORK_TIMEOUT';
        $this->mensajeRespuestaSunat = $detalleError;
    }

    /**
     * Anulación por Comunicación de Baja (RA).
     * Regla SUNAT: Solo comprobantes ACEPTADOS/OBSERVADOS, sin Notas de Crédito previas, y dentro de los 7 días calendario.
     */
    public function anularPorComunicacionBaja(string $motivoBaja, DateTimeImmutable $fechaBaja = new DateTimeImmutable()): void
    {
        if (trim($motivoBaja) === '') {
            throw new ReglaTributariaException("Debe indicar el motivo de la Comunicación de Baja.");
        }

        if (!in_array($this->estado, [EstadoComprobante::ACEPTADO, EstadoComprobante::OBSERVADO], true)) {
            throw new EstadoComprobanteInvalidoException(
                "Solo se puede dar de baja un comprobante ACEPTADO u OBSERVADO (estado actual: {$this->estado->value})."
            );
        }

        if ($this->saldoDisponibleParaNotasCredito < $this->resumenImpuestos->importeTotal) {
            throw new EstadoComprobanteInvalidoException(
                "No se puede dar de baja por Comunicación de Baja un comprobante que ya tiene Notas de Crédito aplicadas."
            );
        }

        $diasTranscurridos = (int) $this->fechaEmision->diff($fechaBaja)->format('%r%a');
        if ($diasTranscurridos < 0) {
            throw new ReglaTributariaException("La fecha de baja no puede ser anterior a la fecha de emisión.");
        }

        if ($diasTranscurridos > self::PLAZO_MAXIMO_DIAS_BAJA) {
            throw new ReglaTributariaException(
                "Regla SUNAT: El plazo máximo para enviar una Comunicación de Baja es de 7 días calendario " .
                "(han transcurrido {$diasTranscurridos} días). Debe emitir una Nota de Crédito."
            );
        }

        $this->estado = EstadoComprobante::ANULADO_BAJA;
        $this->codigoRespuestaSunat = '0';
        $this->mensajeRespuestaSunat = "Baja comunicada: " . trim($motivoBaja);
    }

    public function registrarTicketBaja(string $ticketSunat, string $motivoBaja): void
    {
        $this->codigoRespuestaSunat = '0';
        $this->mensajeRespuestaSunat = "Baja aceptada con Ticket SUNAT N° {$ticketSunat} ({$motivoBaja})";
    }

    public function serieCorrelativo(): SerieCorrelativo
    {
        return $this->serieCorrelativo;
    }

    public function numeroCompleto(): string
    {
        return $this->serieCorrelativo->formatoCompleto();
    }

    public function tipo(): TipoComprobante
    {
        return $this->tipo;
    }

    public function cliente(): Cliente
    {
        return $this->cliente;
    }

    /**
     * @return ItemComprobante[]
     */
    public function items(): array
    {
        return $this->items;
    }

    public function resumenImpuestos(): ResumenImpuestos
    {
        return $this->resumenImpuestos;
    }

    public function moneda(): Moneda
    {
        return $this->moneda;
    }

    public function fechaEmision(): DateTimeImmutable
    {
        return $this->fechaEmision;
    }

    public function estado(): EstadoComprobante
    {
        return $this->estado;
    }

    public function hashCpe(): ?string
    {
        return $this->hashCpe;
    }

    public function xmlFirmado(): ?string
    {
        return $this->xmlFirmado;
    }

    public function codigoRespuestaSunat(): ?string
    {
        return $this->codigoRespuestaSunat;
    }

    public function mensajeRespuestaSunat(): ?string
    {
        return $this->mensajeRespuestaSunat;
    }

    public function comprobanteReferencia(): ?string
    {
        return $this->comprobanteReferencia;
    }

    public function motivoNotaCredito(): ?MotivoNotaCredito
    {
        return $this->motivoNotaCredito;
    }

    public function descripcionMotivoNota(): ?string
    {
        return $this->descripcionMotivoNota;
    }

    public function saldoDisponibleParaNotasCredito(): float
    {
        return $this->saldoDisponibleParaNotasCredito;
    }
}
