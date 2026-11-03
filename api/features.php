<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
require_admin();
mt_require_owner();
$method=$_SERVER['REQUEST_METHOD']??'GET';
if ($method==='GET') {
    $states=mt_feature_states();
    $items=[];
    foreach (MT_PANEL_FEATURES as $key=>$info) {
        $items[]=['key'=>$key,'title'=>$info[0],'description'=>$info[1],'enabled'=>$states[$key]];
    }
    json_out(['ok'=>true,'features'=>$items]);
}
if ($method!=='POST') fail('Método não permitido.',405);
check_csrf();
$data=input();
$key=$data['key']??null;
$enabled=$data['enabled']??null;
if (!is_string($key) || !array_key_exists($key,MT_PANEL_FEATURES) || !is_bool($enabled)) {
    fail('Módulo ou estado inválido.',422);
}
try {
    $q=db()->prepare('INSERT INTO mt_settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)');
    $q->execute(['admin_feature_'.$key, $enabled?'1':'0']);
    json_out(['ok'=>true,'key'=>$key,'enabled'=>$enabled,'message'=>$enabled?'Função ativada.':'Função desativada.']);
} catch (Throwable $e) {
    error_log('Martelli features save: '.$e->getMessage());
    fail('Não foi possível salvar. Confira o banco de dados.',503);
}
