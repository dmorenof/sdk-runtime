<?php
// Same user scenarios against both SDKs generated from accounts-openapi30. Prints one row per scenario and tool.
require getenv('AUDIT_AUTOLOAD');
$base=getenv('AUDIT_OUT');
spl_autoload_register(function($c) use($base){
  foreach(['Oag\\'=>"$base/oag-accounts-openapi30/lib/",'Ours\\'=>"$base/ours-accounts-openapi30/src/"] as $p=>$d)
    if(str_starts_with($c,$p)){ $f=$d.str_replace('\\','/',substr($c,strlen($p))).'.php'; if(is_file($f)) require $f; }
});
use GuzzleHttp\{Client,HandlerStack,Middleware}; use GuzzleHttp\Handler\MockHandler; use GuzzleHttp\Psr7\Response;
function guzzle(array $responses,&$history){ $history=[]; $stack=HandlerStack::create(new MockHandler($responses)); $stack->push(Middleware::history($history)); return new Client(['handler'=>$stack,'http_errors'=>false]); }
function oag($http){ $cfg=(new Oag\Configuration())->setHost('https://api.example.test/v1'); return new Oag\Api\DefaultApi($http,$cfg); }
function ours($http){ $c=new Dmorenof\SdkRuntime\Configuration(); $c->baseUrl='https://api.example.test/v1'; $c->httpClient=$http; return Ours\Client::connect($c)->resource(Ours\Resources\Users::class); }
$json=['Content-Type'=>'application/json'];
$user='{"id":7,"name":"Ada","role":"admin"}';
$scenarios=[
 'get: typed result' => [
   fn($h)=>oag($h)->getUser(7)->getName(),
   fn($h)=>ours($h)->getById(7)->name, [new Response(200,$json,$user)] ],
 'post: create from a model' => [
   function($h){ $m=new Oag\Model\NewUser(); $m->setName('Ada'); return oag($h)->createUser($m)->getId(); },
   function($h){ $m=new Ours\DTOs\NewUser(); $m->name='Ada'; return ours($h)->post($m)->id; }, [new Response(201,$json,$user)] ],
 'bad input: required field missing (HTTP calls made)' => [
   function($h){ return oag($h)->createUser(new Oag\Model\NewUser())->getId(); },
   function($h){ return ours($h)->post(new Ours\DTOs\NewUser())->id; }, [new Response(201,$json,$user)] ],
 'bad input: wrong type for a path id' => [
   fn($h)=>oag($h)->getUser('abc')->getName(),
   fn($h)=>ours($h)->getById('abc')->name, [new Response(200,$json,$user)] ],
 'bad input: value outside an enum' => [
   function($h){ $m=new Oag\Model\NewUser(); $m->setName('A'); $m->setRole('root'); return oag($h)->createUser($m)->getId(); },
   function($h){ $m=new Ours\DTOs\NewUser(); $m->name='A'; $m->role='root'; return ours($h)->post($m)->id; }, [new Response(201,$json,$user)] ],
 'error: HTTP 404 with a JSON body' => [
   fn($h)=>oag($h)->getUser(9)->getName(),
   fn($h)=>ours($h)->getById(9)->name, [new Response(404,$json,'{"message":"nope"}')] ],
 'response: server adds an unknown field' => [
   fn($h)=>oag($h)->getUser(7)->getName(),
   fn($h)=>ours($h)->getById(7)->name, [new Response(200,$json,'{"id":7,"name":"Ada","extra":true}')] ],
 'response: wrong type in a field (id is "x")' => [
   fn($h)=>var_export(oag($h)->getUser(7)->getId(),true),
   fn($h)=>var_export(ours($h)->getById(7)->id,true), [new Response(200,$json,'{"id":"x","name":"Ada"}')] ],
 'response: empty body on a 200' => [
   fn($h)=>var_export(oag($h)->getUser(7)->getName(),true),
   fn($h)=>var_export(ours($h)->getById(7)->name,true), [new Response(200,$json,'')] ],
];
foreach($scenarios as $name=>[$o,$u,$resp]){
  foreach(['OpenAPI Generator'=>$o,'ours'=>$u] as $tool=>$fn){
    $h=guzzle($resp,$hist); $calls0=0;
    try { $r=$fn($h); $res='returned '.var_export($r,true); }
    catch(Throwable $e){ $res=(new ReflectionClass($e))->getShortName().': '.substr($e->getMessage(),0,70); }
    printf("%-52s | %-17s | http=%d | %s\n",$name,$tool,count($hist),$res);
  }
}
