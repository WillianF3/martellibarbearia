<?php
declare(strict_types=1);
namespace Martelli\Domain;

/** Padroniza telefones brasileiros no formato legado DDD+número. */
final class BrazilianPhone
{
    /** Remove pontuação; não verifica a validade do número. */
    public static function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    /** Retorna DDD+número (10/11 dígitos) ou vazio para entradas inválidas. */
    public static function toLocal(string $value): string
    {
        $number = self::digits($value);
        if ((strlen($number) === 12 || strlen($number) === 13) && str_starts_with($number, '55')) {
            $number = substr($number, 2);
        }
        return preg_match('/^[1-9][0-9][0-9]{8,9}$/D', $number) ? $number : '';
    }

    /** Acrescenta DDI 55 somente a números válidos para links WhatsApp. */
    public static function forWhatsApp(string $value): string
    {
        $local = self::toLocal($value);
        return $local === '' ? '' : '55' . $local;
    }
}
