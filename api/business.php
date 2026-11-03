<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/../app/business.php';
header('X-Content-Type-Options: nosniff');
require_admin();
$method=$_SERVER['REQUEST_METHOD']??'GET';$action=(string)($_GET['action']??'');
try {
 // Permissão por operação, validada no servidor, inclusive para chamadas diretas.
 if (in_array($action,['commissions','commission_pay','commission_reverse','barber_commission_report','barber_commission_csv','barber_commission_print'],true) && !mt_feature_enabled('comissoes')) fail('Comissões desativadas nas configurações.',403);
 $reportActions=['report'];
 $inventoryActions=['products','product_save','stock_adjust'];
 if ($action==='status') mt_require_any(['financeiro','estoque','relatorios']);
 elseif (in_array($action,['barber_commission_report','barber_commission_csv','barber_commission_print'],true)) mt_require('financeiro');
 elseif ($action==='install') mt_require_owner();
 elseif ($action==='catalog') mt_require_any(['financeiro','estoque','relatorios']);
 elseif (in_array($action,$reportActions,true)) mt_require('relatorios');
 elseif (in_array($action,$inventoryActions,true)) mt_require('estoque');
 else mt_require('financeiro');
 if(!in_array($method,['GET','POST'],true))fail('Método não permitido.',405);
 if($method==='POST')check_csrf();
 if($action==='status' && $method==='GET')json_out(['ok'=>true,'ready'=>biz_ready()]);
 if($action==='install' && $method==='POST'){
  $d=input();$hash=biz_query('SELECT password_hash FROM mt_admins WHERE id=?',[biz_actor()])->fetchColumn();
  if(!$hash||!password_verify((string)($d['password']??''),$hash))fail('Senha atual incorreta.',403);
  biz_install();json_out(['ok'=>true,'ready'=>biz_ready()]);
 }
 if(!biz_ready())fail('Prepare os módulos administrativos antes de continuar.',409);
 if($action==='barber_commission_csv' && $method==='GET')biz_export_barber_commissions($_GET);
 if($action==='barber_commission_print' && $method==='GET')biz_print_barber_commissions($_GET);
 if($method==='GET')json_out(['ok'=>true]+biz_read($action,$_GET));
 json_out(['ok'=>true]+biz_mutate($action,input()));
} catch(BusinessError $e){fail($e->getMessage(),409);}
catch(Throwable $e){error_log('Martelli business: '.$e->getMessage());fail('Não foi possível concluir. Confira a conexão e os logs do servidor.',503);}
