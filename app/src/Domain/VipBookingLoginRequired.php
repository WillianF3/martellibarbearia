<?php
declare(strict_types=1);
namespace Martelli\Domain;

/** Solicita login para CPF com ciclo VIP vigente, sem expor agendamentos. */
final class VipBookingLoginRequired extends \DomainException
{
    public function __construct(
        public readonly bool $hasBookedCuts,
        public readonly bool $contactMatches
    ) {
        parent::__construct('Confirme seu acesso VIP para consultar a agenda.');
    }
}
