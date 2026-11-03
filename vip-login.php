<?php
/** Compatibilidade com links antigos de login/ativação/troca de senha. */
declare(strict_types=1);
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
$token=strtolower(trim((string)($_GET['chave']??'')));
if(preg_match('/^[a-f0-9]{64}$/D',$token))$target='/vip-ativar.php?chave='.$token;
elseif((string)($_GET['alterar']??'')==='1')$target='/vip-senha.php';
else {
  $target='/vip-entrar.php';
  $google=(string)($_GET['google']??'');
  $apple=(string)($_GET['apple']??'');
  $params=[];
  if(preg_match('/^[a-z0-9-]{1,45}$/D',$google))$params['google']=$google;
  if(preg_match('/^[a-z0-9-]{1,45}$/D',$apple))$params['apple']=$apple;
  if($params)$target.='?'.http_build_query($params,'','&',PHP_QUERY_RFC3986);
}
header('Location: '.$target,true,302);exit;
