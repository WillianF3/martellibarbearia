<?php
declare(strict_types=1);
namespace Martelli\Domain;

/** Erro de cadastro duplicado, convertido em HTTP 409 na API pública. */
final class ClientConflict extends \DomainException {}
