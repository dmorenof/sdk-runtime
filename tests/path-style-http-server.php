<?php
declare(strict_types=1);
$socket=stream_socket_server('tcp://127.0.0.1:0',$errorCode,$errorText);
if($socket===false)throw new RuntimeException($errorText);
$address=stream_socket_get_name($socket,false);
if($address===false)throw new RuntimeException('No local address.');
echo substr($address,strrpos($address,':')+1),PHP_EOL;flush();
for($attempt=0;$attempt<2;++$attempt){
 $connection=stream_socket_accept($socket,10);
 if($connection===false)throw new RuntimeException('No HTTP request received.');
 stream_set_timeout($connection,10);$line=fgets($connection);
 if($line===false)throw new RuntimeException('Missing request line.');
 $target=explode(' ',trim($line))[1];
 while(($header=fgets($connection))!==false && trim($header)!==''){}
 $body=json_encode($target,JSON_THROW_ON_ERROR);
 fwrite($connection,"HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n\r\n".$body);
 fclose($connection);
}
fclose($socket);
