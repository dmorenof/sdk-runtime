<?php
require getenv('AUDIT_AUTOLOAD');
spl_autoload_register(function($c){ if(str_starts_with($c,'Ours\\')){ $f=getenv('AUDIT_OUT')."/ours-accounts-openapi30/src/".str_replace('\\','/',substr($c,5)).'.php'; if(is_file($f)) require $f; } });
$body='{"id":7,"name":"Ada","role":"admin","labels":["a","b"],"profile":{"city":"Madrid"}}';
Dmorenof\SdkRuntime\JsonHydrator::decode($body,'\\Ours\\DTOs\\User',false);
print_r(Dmorenof\SdkRuntime\DTO::$counts);
