<?php

namespace App\Enums;

enum DeploymentMode: string
{
    case DOMAIN   = 'domain';    // acesso público via domínio (ex.: https://restaurante.com)
    case INTRANET = 'intranet';  // acesso local via IP:porta (ex.: http://192.168.0.50:8080)

    public function label(): string
    {
        return match ($this) {
            self::DOMAIN   => 'Domínio público',
            self::INTRANET => 'Intranet (rede local)',
        };
    }
}
