<?php
declare(strict_types=1);
/** Valida CPF e remove a máscara; CPF não comprova identidade. */
function mt_cpf(string $raw): string {
    $cpf = \Martelli\Domain\CpfValidator::normalize($raw);
    if ($cpf === null) {
        fail(\Martelli\Domain\CpfValidator::invalidReason($raw) ?? 'CPF inválido.', 422);
    }
    return $cpf;
}
/** Require migration before allowing bookings and VIP requests; fail closed. */
function mt_cpf_schema():void {
    static $ready=false;
    if ($ready) return;
    try {
        $c=db()->query("SHOW COLUMNS FROM mt_clients LIKE 'cpf'")->fetchColumn();
    } catch(Throwable $e) {$c=false;}
    if (!$c) fail('Cadastro CPF indisponível. A barbearia precisa importar a migração V42 no banco.',503);
    $ready=true;
}
/** Interface legada para criação/reutilização de cliente.
 * Exceções chegam à API depois do rollback e liberação dos locks MySQL.
 */
function mt_cpf_client(PDO $pdo, string $name, string $phone, string $cpf, int $verifiedClientId=0, bool $enforceVipLogin=true): int {
    return (new \Martelli\Domain\ClientRegistry($pdo))->findOrCreate(
        $name, $phone, $cpf, $verifiedClientId, $enforceVipLogin
    );
}
