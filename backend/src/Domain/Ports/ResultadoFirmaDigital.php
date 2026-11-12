<?php

declare(strict_types=1);

namespace App\Domain\Ports;

final class ResultadoFirmaDigital
{
    public function __construct(
        public readonly string $xmlFirmado,
        public readonly string $hashCpe,
        public readonly string $signatureValue
    ) {
    }
}
