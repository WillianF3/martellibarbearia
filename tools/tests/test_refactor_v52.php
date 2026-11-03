<?php
declare(strict_types=1);

/** Testes isolados do domínio. Execute: php tools/tests/test_refactor_v52.php */
require __DIR__ . '/../../app/autoload.php';

use Martelli\Domain\BrazilianPhone;
use Martelli\Domain\CpfValidator;
use Martelli\Domain\VipCycle;
use Martelli\Domain\Money;
use Martelli\Infrastructure\ConfigurationLoader;

$cases = 0;
function verify(bool $condition, string $message): void {
    global $cases;
    $cases++;
    if (!$condition) throw new RuntimeException('Falhou: ' . $message);
}

// CPF com dígitos verificadores válidos, exemplos públicos de teste.
verify(CpfValidator::normalize('529.982.247-25') === '52998224725', 'CPF com máscara');
verify(CpfValidator::normalize('52998224726') === null, 'CPF com dígito incorreto');
verify(CpfValidator::normalize('11111111111') === null, 'CPF repetido inválido');
verify(CpfValidator::normalize('123') === null, 'CPF incompleto');
verify(CpfValidator::invalidReason('123') === 'Informe um CPF válido com 11 dígitos.', 'Mensagem de CPF incompleto');

verify(BrazilianPhone::toLocal('+55 (49) 99999-9999') === '49999999999', 'DDI opcional');
verify(BrazilianPhone::toLocal('(49) 99999-9999') === '49999999999', 'DDD sem DDI');
verify(BrazilianPhone::forWhatsApp('(49) 99999-9999') === '5549999999999', 'Formato WhatsApp');
verify(BrazilianPhone::toLocal('x') === '', 'Telefone inválido');

verify(VipCycle::lastDay('2026-10-28') === '2026-11-26', 'Ciclo de 30 dias');
verify(VipCycle::lastDay('2028-02-01') === '2028-03-01', 'Ano bissexto');
verify(VipCycle::lastDay('2026-12-31') === '2027-01-29', 'Virada do ano');

verify(Money::toCents('120,00') === 12000, 'Vírgula decimal');
verify(Money::toCents('0') === 0, 'Zero');
verify(Money::toCents('45.5') === 4550, 'Uma casa decimal');
try { Money::toCents('1.000,00'); verify(false,'Separador de milhar inválido'); }
catch (InvalidArgumentException $e) { verify(true, 'Separador de milhar rejeitado'); }

// Configuração: compatibilidade com nomes antigos sem necessitar banco.
$tmp = sys_get_temp_dir() . '/martelli-v52-' . bin2hex(random_bytes(5));
mkdir($tmp);
file_put_contents($tmp.'/config.local.php', '<?php return '.var_export([
    'host'=>'localhost','port'=>3306,'database'=>'martelli_test','username'=>'demo','password'=>'demo'
],true).';');
try {
    $config=ConfigurationLoader::load($tmp);
    verify($config['db_name']==='martelli_test', 'Compatibilidade de config antiga');
    verify($config['db_user']==='demo', 'Usuário legado');
} finally {
    unlink($tmp.'/config.local.php');
    rmdir($tmp);
}

echo "OK: $cases verificações do domínio Martelli V52.\n";
