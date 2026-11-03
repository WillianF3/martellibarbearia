<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../app/bootstrap.php';require __DIR__.'/../app/business.php';
try{biz_install();echo biz_ready()?"Módulos preparados. Nenhum dado anterior foi removido.\n":"Instalação incompleta.\n";}
catch(Throwable $e){fwrite(STDERR,"Não foi possível preparar as tabelas. Confira a configuração e as permissões do banco.\n");exit(1);}
