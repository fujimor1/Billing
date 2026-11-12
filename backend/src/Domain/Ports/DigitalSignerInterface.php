<?php

declare(strict_types=1);

namespace App\Domain\Ports;

interface DigitalSignerInterface
{
    public function firmarXml(string $xmlSinFirmar): ResultadoFirmaDigital;

    public function verificarIntegridadXml(string $xmlFirmado): bool;
}
