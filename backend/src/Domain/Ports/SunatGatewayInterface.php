<?php

declare(strict_types=1);

namespace App\Domain\Ports;

use App\Domain\Entities\Comprobante;

interface SunatGatewayInterface
{
    public function enviarComprobante(Comprobante $comprobante, string $xmlFirmado): RespuestaSunatCdr;

    public function comunicarBaja(Comprobante $comprobante, string $motivoBaja): string;
}
