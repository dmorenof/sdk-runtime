<?php
require getenv('AUDIT_AUTOLOAD');
$base=getenv('AUDIT_OUT');
spl_autoload_register(function($c) use($base){
  foreach(['Oag\\'=>"$base/oag-accounts-openapi30/lib/",'Ours\\'=>"$base/ours-accounts-openapi30/src/"] as $p=>$d)
    if(str_starts_with($c,$p)){ $f=$d.str_replace('\\','/',substr($c,strlen($p))).'.php'; if(is_file($f)) require $f; }
});
use GuzzleHttp\{Client,HandlerStack}; use GuzzleHttp\Psr7\Response;
$tool=$argv[1]; $n=(int)($argv[2]??20000);
$body='{"id":7,"name":"Ada","role":"admin","labels":["a","b"],"profile":{"city":"Madrid"}}';
$handler=function($req,$opts) use($body){ return \GuzzleHttp\Promise\Create::promiseFor(new Response(200,['Content-Type'=>'application/json'],$body)); };
$http=new Client(['handler'=>HandlerStack::create($handler)]);
$t0=hrtime(true);
if($tool==='oag'){ $api=new Oag\Api\DefaultApi($http,(new Oag\Configuration())->setHost('https://api.example.test/v1')); }
else { $c=new Dmorenof\SdkRuntime\Configuration(); $c->baseUrl='https://api.example.test/v1'; $c->httpClient=$http; $api=Ours\Client::connect($c)->resource(Ours\Resources\Users::class); }
$init=(hrtime(true)-$t0)/1e6;
$call=$tool==='oag' ? fn()=>$api->getUser(7)->getName() : fn()=>$api->getById(7)->name;
for($i=0;$i<500;$i++) $call();
gc_collect_cycles(); $m0=memory_get_usage(); $t=hrtime(true);
for($i=0;$i<$n;$i++) $call();
$us=(hrtime(true)-$t)/1e3/$n;
printf("%s: %.1f us/call, init %.2f ms, peak %.1f MiB\n",$tool,$us,$init,memory_get_peak_usage(true)/1048576);
