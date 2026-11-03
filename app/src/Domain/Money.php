<?php
declare(strict_types=1);
namespace Martelli\Domain;

use InvalidArgumentException;

/** Conversão financeira exata para centavos, sem cálculos em ponto flutuante. */
final class Money
{
    /** Interpreta valores com zero a duas casas decimais. */
    public static function toCents(mixed $value): int
    {
        $string = str_replace(',', '.', trim((string)$value));
        if (!preg_match('/^\d{1,7}(?:\.\d{1,2})?$/D', $string)) {
            throw new InvalidArgumentException('Valor inválido. Use até 9.999.999,99 sem separador de milhar.');
        }
        [$whole, $fraction] = array_pad(explode('.', $string), 2, '');
        return (int)$whole * 100 + (int)str_pad($fraction, 2, '0');
    }
}
