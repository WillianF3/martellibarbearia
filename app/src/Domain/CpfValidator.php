<?php
declare(strict_types=1);
namespace Martelli\Domain;

/** Validação matemática de CPF; CPF válido não é comprovação de titularidade. */
final class CpfValidator
{
    /** Remove pontuação e devolve os 11 dígitos, caso o CPF seja válido. */
    public static function normalize(string $value): ?string
    {
        $cpf = preg_replace('/\D+/', '', $value);
        if (!is_string($cpf) || strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
            return null;
        }
        for ($digit = 9; $digit <= 10; $digit++) {
            $sum = 0;
            for ($i = 0; $i < $digit; $i++) {
                $sum += (int)$cpf[$i] * ($digit + 1 - $i);
            }
            $check = ($sum * 10) % 11;
            if ($check === 10) {
                $check = 0;
            }
            if ($check !== (int)$cpf[$digit]) {
                return null;
            }
        }
        return $cpf;
    }

    /** Distingue erros de formato e de dígitos para mensagens públicas compatíveis. */
    public static function invalidReason(string $value): ?string
    {
        if (self::normalize($value) !== null) {
            return null;
        }
        $cpf = preg_replace('/\D+/', '', $value) ?? '';
        return strlen($cpf) !== 11 || (bool)preg_match('/^(\d)\1{10}$/', $cpf)
            ? 'Informe um CPF válido com 11 dígitos.'
            : 'CPF inválido. Confira os dígitos.';
    }
}
