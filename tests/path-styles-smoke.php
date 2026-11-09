<?php
declare(strict_types=1);
require $argv[1]??throw new RuntimeException('Missing autoload path.');
$process=proc_open([PHP_BINARY,__DIR__.'/path-style-http-server.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
if(!is_resource($process))throw new RuntimeException('Cannot start local HTTP server.');
try{
 stream_set_timeout($pipes[1],10);$port=trim((string)fgets($pipes[1]));
 if(!ctype_digit($port))throw new RuntimeException('Missing server port.');
 $client=new class('http://127.0.0.1:'.$port) extends \Dmorenof\SdkRuntime\ApiClient {
  public function target(string $format):string{return $this->executeOperation(['method'=>'GET','path'=>'/items/{id}','responseType'=>'string','pathSerialization'=>['id'=>$format]],pathParameters:['id'=>'']);}
 };
 if($client->target('label')!=='/items/.'||$client->target('matrix')!=='/items/;id')throw new RuntimeException('Transport changed the path expansion.');
 if(\Dmorenof\SdkRuntime\QuerySerializer::path((object)['0'=>'value'],'0','matrix-object-explode')!==';0=value')throw new RuntimeException('Numeric object key confused with a list.');
}finally{foreach($pipes as $pipe)fclose($pipe);proc_terminate($process);proc_close($process);}
echo 'PATH_STYLE_RUNTIME_HTTP_OK',PHP_EOL;
