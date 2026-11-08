<?php
declare(strict_types=1);

// Runs against an installed profile, without booting the application or using a database.
$autoload = realpath($argv[1] ?? dirname(__DIR__) . '/vendor/autoload.php');
if ($autoload === false) throw new RuntimeException('Pass an installed profile vendor/autoload.php');
require $autoload;

$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException($label);
    ++$checks;
};
foreach (['http-foundation','http-kernel','routing','yaml'] as $component) {
    $version = Composer\InstalledVersions::getVersion('symfony/'.$component);
    $check(is_string($version) && version_compare($version, '5.4.56', '>='), 'Symfony '.$component.' security patch is missing');
}
$get = new Symfony\Component\HttpFoundation\RequestMatcher(null, null, ['GET']);
$request = static fn(string $method) => Symfony\Component\HttpFoundation\Request::create('/admin', $method);
$check($get->matches($request('GET')), 'GET rule no longer matches GET');
$check($get->matches($request('HEAD')), 'GET rule must also cover HEAD');
$check(!$get->matches($request('POST')), 'GET rule unexpectedly matches POST');
$head = new Symfony\Component\HttpFoundation\RequestMatcher(null, null, ['HEAD']);
$check($head->matches($request('HEAD')), 'HEAD rule no longer matches HEAD');
$check(!$head->matches($request('GET')), 'HEAD-only rule must not match GET');
$get->matchMethod('POST');
$check(!$get->matches($request('HEAD')), 'Replacing the method rule must remove implicit HEAD');
$check($get->matches($request('POST')), 'Replacing the method rule must still match POST');
$routes=new Symfony\Component\Routing\RouteCollection();
$routes->add('admin',new Symfony\Component\Routing\Route('/admin/report/{id}',[],['domain'=>'.+'],[],'app.{domain}'));
$context=new Symfony\Component\Routing\RequestContext();$context->setHost('app.example.test');
$compiled=(new Symfony\Component\Routing\Matcher\Dumper\CompiledUrlMatcherDumper($routes))->getCompiledRoutes();
$matcher=new Symfony\Component\Routing\Matcher\CompiledUrlMatcher($compiled,$context);
$check($matcher->match('/admin/report/1')['_route']==='admin','Compiled host routes stopped matching valid paths');
try {$matcher->match('/x./admin/report/1');$check(false,'A host placeholder consumed part of the URL path');}
catch (Symfony\Component\Routing\Exception\ResourceNotFoundException $expected) {$check(true,'Host and path remain separate');}
$document=['ipv6'=>false,'proxies'=>[],'proxy-groups'=>[['name'=>'example','type'=>'select','proxies'=>['01','true','node:443']]]];
$yaml=Symfony\Component\Yaml\Yaml::dump($document,2,4,Symfony\Component\Yaml\Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
$check(Symfony\Component\Yaml\Yaml::parse($yaml)===$document,'Subscription YAML round-trip changed scalar or sequence values');
$check(Symfony\Component\Yaml\Yaml::parse("port: 443\nserver: node.example.test\ntls: true\n")===['port'=>443,'server'=>'node.example.test','tls'=>true],'YAML parsing changed expected configuration types');
echo 'PASS Symfony security patches, HEAD/GET matching and YAML compatibility (', $checks, " checks)\n";
