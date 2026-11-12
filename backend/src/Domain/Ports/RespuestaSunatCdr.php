<?php

declare(strict_types=1);

namespace App\Domain\Ports;

final class RespuestaSunatCdr
{
    /**
     * @param string[] $observaciones
     */
    public function __construct(
        public readonly string $codigo,
        public readonly string $mensaje,
        public readonly ?string $xmlCdr = null,
        public readonly array $observaciones = []
    ) {
    }

    public function tieneObservaciones(): bool
    {
        return !empty($this->observaciones);
    }
}
