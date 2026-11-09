<?php
declare(strict_types=1);
namespace Dmorenof\SdkRuntime\Tests;
use Dmorenof\SdkRuntime\QuerySerializer;
use PHPUnit\Framework\TestCase;
final class PathStyleTest extends TestCase {
 /** Examples from https://github.com/uri-templates/uritemplate-test (RFC 6570 sections 3.2.5/3.2.7). */
 public function testRfc6570LabelAndMatrixExamples(): void {
  $spec=json_decode(file_get_contents(__DIR__.'/fixtures/path-style-rfc6570.json'), false, 512, JSON_THROW_ON_ERROR);
  foreach($spec as $section) {
   foreach($section->testcases as [$template,$expected]) {
    if(preg_match('/^(X)?\{([.;])([A-Za-z_]+)(\*)?\}$/D',$template,$m)!==1)continue;
    $value=$section->variables->{$m[3]}??null; if($value===null)continue;
    $kind=is_array($value)?'list':($value instanceof \stdClass?'object':'scalar');
    $format=($m[2]==='.'?'label':'matrix').($kind==='scalar'?'':'-'.$kind.(isset($m[4])?'-explode':''));
    $actual=($m[1]??'').QuerySerializer::path($value,$m[3],$format);
    if(is_array($expected))self::assertContains($actual,$expected,$template);else self::assertSame($expected,$actual,$template);
   }
  }
 }
 public function testEmptyBooleanNumericAndEncodedDataRemainDistinct(): void {
  foreach([
   ['', 'id','label','.'],['','id','matrix',';id'],[false,'id','label','.false'],[0,'id','matrix',';id=0'],
   [[], 'id','label-list',''],[[], 'id','matrix-list-explode',''],[[''],'id','label-list','.'],
   [['','x'],'id','matrix-list-explode',';id;id=x'],
   [(object)['0'=>'value'],'0','matrix-object-explode',';0=value'],
   [(object)['a,b'=>'x/y','flag'=>false],'id','label-object-explode','.a%2Cb=x%2Fy.flag=false'],
   [(object)['a'=>''],'id','matrix-object-explode',';a'],
   [['a,b','%2F','ñ'],'id','label-list','.a%2Cb,%252F,%C3%B1'],
  ] as [$value,$name,$format,$expected]) self::assertSame($expected,QuerySerializer::path($value,$name,$format));
 }
 public function testDefaultTransportPreservesAnEmptyLabelOnTheActualWire(): void {
  $process=proc_open([PHP_BINARY,__DIR__.'/path-style-http-server.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
  if(!is_resource($process))self::fail('Cannot start local HTTP probe.');
  try {
   stream_set_timeout($pipes[1],10);$port=trim((string)fgets($pipes[1]));
   self::assertMatchesRegularExpression('/^[0-9]+$/',$port);
   $client=new class('http://127.0.0.1:'.$port) extends \Dmorenof\SdkRuntime\ApiClient {
    public function target(string $format): string {
     return $this->executeOperation(['method'=>'GET','path'=>'/items/{id}','responseType'=>'string','pathSerialization'=>['id'=>$format]],pathParameters:['id'=>'']);
    }
   };
   self::assertSame('/items/.',$client->target('label'));
   self::assertSame('/items/;id',$client->target('matrix'));
  } finally {foreach($pipes as $pipe)fclose($pipe);proc_terminate($process);proc_close($process);}
 }
 public function testUndeclaredFormatsAndUnsupportedValuesFail(): void {
  foreach([['x','bogus'],[[null],'matrix-list'],[(object)['nested'=>[]],'label-object'],[INF,'matrix']] as [$value,$format]) {
   try{QuerySerializer::path($value,'id',$format);self::fail('Invalid value accepted.');}
   catch(\UnexpectedValueException|\InvalidArgumentException){self::assertTrue(true);}
  }
 }
}
