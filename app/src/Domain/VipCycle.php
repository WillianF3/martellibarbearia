<?php
declare(strict_types=1);
namespace Martelli\Domain;

use DateTimeImmutable;
use DateTimeZone;

/** Calcula a vigência VIP em dias corridos, incluindo o primeiro dia. */
final class VipCycle
{
    public const DURATION_DAYS = 30;

    /** Data final de um ciclo, sem mudar o horário do fuso de São Paulo. */
    public static function lastDay(string $start): string
    {
        return (new DateTimeImmutable($start, new DateTimeZone('America/Sao_Paulo')))
            ->modify('+' . (self::DURATION_DAYS - 1) . ' days')
            ->format('Y-m-d');
    }
}
