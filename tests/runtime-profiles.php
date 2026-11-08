<?php
declare(strict_types=1);

// Real Composer/profile integration. Application commands are intercepted;
// these fixtures never connect to an application database or send email.
namespace Tests\RuntimeProfiles;

function need(bool $ok, string $message): void { if (!$ok) throw new \RuntimeException($message); }
function run(array $args, string $cwd, array $env = []): array {
    $pipes=[];$p=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$cwd,array_merge(getenv(),['COMPOSER_ALLOW_SUPERUSER'=>'1'],$env));
    need(is_resource($p),'Cannot start test command');fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return[proc_close($p),$out,$err];
}
function put(string $path,array $value):void {file_put_contents($path,json_encode($value,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");}
function copyTree(string $from,string $to):void {[$c,$o,$e]=run(['cp','-a',$from,$to],dirname($to));need($c===0,$o.$e);}
function hashes(string $path):array {
    $out=[];foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path,\FilesystemIterator::SKIP_DOTS))as$f){if($f->getFilename()==='.v2board-recovery.lock')continue;$n=substr($f->getPathname(),strlen($path)+1);$out[$n]=$f->isLink()?'link:'.readlink($f->getPathname()):hash_file('sha256',$f->getPathname());}ksort($out);return$out;
}
function packageHashes(string $path):array {return array_filter(hashes($path),fn($k)=>!str_starts_with($k,'composer/')&&!in_array($k,['autoload.php','.htaccess'],true),ARRAY_FILTER_USE_KEY);}
$repo=dirname(__DIR__);$composer=realpath($argv[1]??getenv('TEST_COMPOSER')?:$repo.'/composer.phar');need(is_string($composer),'Pass the Composer PHP executable/PHAR');
$base=sys_get_temp_dir().'/v2board-profiles-test-'.bin2hex(random_bytes(5));mkdir($base,0700);$checks=0;
$pass=function(string $name)use(&$checks){$checks++;echo 'PASS ',$name,"\n";};
$fixture=function(string$name)use($repo,$base,$composer):string{
    $root=$base.'/'.$name;mkdir($root);mkdir($root.'/bootstrap/cache',0755,true);mkdir($root.'/config');mkdir($root.'/storage');
    foreach(['composer.json','composer.fpm.json','composer.fpm.lock','composer.fpm-php80.json','composer.fpm-php80.lock','composer.webman.json','composer.webman.lock','init.sh','update.sh']as$f)copy($repo.'/'.$f,$root.'/'.$f);
    copyTree($repo.'/library',$root.'/library');copy($composer,$root.'/composer.phar');
    foreach(['app','database','tests']as$d)symlink($repo.'/'.$d,$root.'/'.$d);
    file_put_contents($root.'/artisan', <<<'APP'
<?php
$command=$argv[1]??'';
file_put_contents(__DIR__.'/application.log',$command."\n",FILE_APPEND);
if(getenv('FAIL_APP_COMMAND')===$command)exit(23);
if($command==='v2board:install'&&!is_file(__DIR__.'/.env'))file_put_contents(__DIR__.'/.env',"APP_KEY=fixture-install-key\n");
echo "Fixture application command: ",$command,"\n";
APP
    );
    file_put_contents($root.'/config/v2board.php',"<?php return ['fixture'=>true];\n");
    file_put_contents($root.'/.gitignore',"vendor/\ncomposer.phar\ncomposer.lock\n.env\nconfig/v2board.php\nbootstrap/cache/*\napplication.log\n.v2board-*\n");
    return$root;
};
$manager=function(string$action,string$root,?string$snapshot=null,?string$runtime=null)use($repo,$composer,$base):array{
    $args=[PHP_BINARY,'-d','error_reporting=24575',$root.'/library/DependencyProfiles.php',$action,$root,'--composer='.$composer];
    if($action==='snapshot')$args[]='--backup-dir='.$base;
    if($snapshot!==null)$args[]='--snapshot='.$snapshot;if($runtime!==null)$args[]='--runtime='.$runtime;
    return run($args,$root);
};
$snapshot=function(string$root)use($manager):string{[$c,$o,$e]=$manager('snapshot',$root);need($c===0,$o.$e);return trim($o);};
try{
    $fresh=[];
    foreach(PHP_VERSION_ID>=80100?['fpm','webman']:['fpm']as$runtime){
        $root=$fixture('fresh-'.$runtime);$s=$snapshot($root);[$c,$o,$e]=$manager('install',$root,$s,$runtime);need($c===0,$o.$e);
        $state=json_decode(file_get_contents($root.'/.v2board-runtime.json'),true);need($state['runtime']===$runtime&&!$state['legacy'],'Incorrect runtime state');
        $meta=json_decode(file_get_contents($root.'/vendor/composer/installed.json'),true);$names=array_column($meta['packages'],'name');
        need(in_array('joanhey/adapterman',$names,true)===($runtime==='webman'),'Adapterman profile mismatch');
        need(in_array('workerman/workerman',$names,true)===($runtime==='webman'),'Workerman profile mismatch');
        [$c,$o,$e]=$manager('commit',$root,$s);need($c===0,$o.$e);
        $autoload=file_get_contents($root.'/vendor/composer/autoload_static.php');need(!str_contains($autoload,'/.v2board-dependencies-'),'Activated autoload contains stage paths');
        [$c,$o,$e]=$manager('check',$root);need($c===0,$o.$e);$fresh[$runtime]=$root;
        [$c,$o,$e]=run([PHP_BINARY,$repo.'/tests/symfony-security.php',$root.'/vendor/autoload.php'],$root);need($c===0,$o.$e);
        $pass($runtime.' Symfony security patches, HEAD matching and YAML compatibility');
        $pass('fresh '.$runtime.' installation uses the published lock and final autoload paths');
    }
    $root=$fresh['fpm'];$before=hashes($root.'/vendor');$s=$snapshot($root);[$c,$o,$e]=$manager('update',$root,$s);need($c===0,$o.$e);[$c,$o,$e]=$manager('commit',$root,$s);need($c===0,$o.$e);
    need(hashes($root.'/vendor')===$before,'Repeated target installation changed vendor files');$pass('repeat update is reproducible');

    // A legacy, production-only vendor with no lock is completed using reviewed
    // development metadata, while the deployed packages remain byte-identical.
    $legacy=$fixture('legacy-production-no-lock');copyTree($root.'/vendor',$legacy.'/vendor');$before=packageHashes($legacy.'/vendor');
    $s=$snapshot($legacy);[$c,$o,$e]=$manager('update',$legacy,$s);need($c===0,$o.$e);need(str_contains($o,'Legacy installation adopted'),$o);
    $state=json_decode(file_get_contents($legacy.'/.v2board-runtime.json'),true);need($state['legacy']===true,'Legacy state not recorded');
    [$c,$o,$e]=$manager('commit',$legacy,$s);need($c===0,$o.$e);need(packageHashes($legacy.'/vendor')===$before,'Legacy adoption replaced installed package files');
    $pass('legacy production vendor without a lock is adopted without package replacement');
    if(isset($fresh['webman'])){
        $legacyWebman=$fixture('legacy-webman-no-lock');copyTree($fresh['webman'].'/vendor',$legacyWebman.'/vendor');$before=packageHashes($legacyWebman.'/vendor');
        $s=$snapshot($legacyWebman);[$c,$o,$e]=$manager('update',$legacyWebman,$s);need($c===0,$o.$e);
        $state=json_decode(file_get_contents($legacyWebman.'/.v2board-runtime.json'),true);need($state['runtime']==='webman'&&$state['legacy'],'Legacy Webman not retained');
        [$c,$o,$e]=$manager('commit',$legacyWebman,$s);need($c===0,$o.$e);need(packageHashes($legacyWebman.'/vendor')===$before,'Legacy Webman package bytes changed');
        $pass('legacy Webman is inferred conservatively and its runtime packages are retained');
    }

    // Change a reviewed target package in the fixture. This must be installed
    // on the next ordinary update instead of permanently freezing old vendor.
    require_once $repo.'/library/UpdateDependencies.php';\Library\UpdateDependencies::composerAutoload($composer);
    $key=PHP_VERSION_ID<80100?'fpm-php80':'fpm';$manifest=$root.'/composer.'.$key.'.json';$lockFile=$root.'/composer.'.$key.'.lock';
    $pkg=$base.'/published-package';mkdir($pkg);mkdir($pkg.'/src');file_put_contents($pkg.'/src/Addon.php',"<?php namespace PublishedFixture; class Addon { const VERSION='1.0.0'; }\n");
    put($pkg.'/composer.json',['name'=>'fixture/published-addon','version'=>'1.0.0','autoload'=>['psr-4'=>['PublishedFixture\\'=>'src/']]]);
    $zip=$base.'/published-addon.tar';$archive=new \PharData($zip);$archive->buildFromDirectory($pkg);unset($archive);
    $m=json_decode(file_get_contents($manifest),true);$m['require']['fixture/published-addon']='1.0.0';put($manifest,$m);
    $lock=json_decode(file_get_contents($lockFile),true);$lock['content-hash']=\Composer\Package\Locker::getContentHash(file_get_contents($manifest));
    $lock['packages'][]=['name'=>'fixture/published-addon','version'=>'1.0.0','type'=>'library','autoload'=>['psr-4'=>['PublishedFixture\\'=>'src/']],
        'dist'=>['type'=>'tar','url'=>'file://'.$zip,'shasum'=>sha1_file($zip)]];put($lockFile,$lock);
    $s=$snapshot($root);[$c,$o,$e]=$manager('update',$root,$s);need($c===0,$o.$e);[$c,$o,$e]=$manager('commit',$root,$s);need($c===0,$o.$e);
    $meta=json_decode(file_get_contents($root.'/vendor/composer/installed.json'),true);need(in_array('fixture/published-addon',array_column($meta['packages'],'name'),true),'Published dependency change was frozen');
    $pass('a changed published dependency set is applied by an ordinary update');

    copy($manifest,$legacy.'/composer.'.$key.'.json');copy($lockFile,$legacy.'/composer.'.$key.'.lock');
    $s=$snapshot($legacy);[$c,$o,$e]=$manager('update',$legacy,$s);need($c===0,$o.$e);[$c,$o,$e]=$manager('commit',$legacy,$s);need($c===0,$o.$e);
    $state=json_decode(file_get_contents($legacy.'/.v2board-runtime.json'),true);need($state['legacy']===false,'Legacy installation stayed permanently frozen');
    $pass('a legacy installation transitions to a changed published dependency set');

    file_put_contents($pkg.'/src/Addon.php',"<?php namespace PublishedFixture; class Addon { const VERSION='1.1.0'; }\n");
    $nextArchive=$base.'/published-addon-next.tar';$archive=new \PharData($nextArchive);$archive->buildFromDirectory($pkg);unset($archive);
    $m['require']['fixture/published-addon']='1.1.0';put($manifest,$m);$lock['content-hash']=\Composer\Package\Locker::getContentHash(file_get_contents($manifest));
    foreach($lock['packages']as&$entry)if($entry['name']==='fixture/published-addon'){$entry['version']='1.1.0';$entry['dist']['url']='file://'.$nextArchive;$entry['dist']['shasum']=sha1_file($nextArchive);}unset($entry);put($lockFile,$lock);
    $s=$snapshot($root);[$c,$o,$e]=$manager('update',$root,$s);need($c===0,$o.$e);[$c,$o,$e]=$manager('commit',$root,$s);need($c===0,$o.$e);
    $meta=json_decode(file_get_contents($root.'/vendor/composer/installed.json'),true);$versions=array_column($meta['packages'],'version','name');need(($versions['fixture/published-addon']??null)==='1.1.0','Existing package version was frozen');
    $pass('an existing package is upgraded to the version in the new reviewed lock');

    $failed=$fixture('failed-application');copyTree($root.'/vendor',$failed.'/vendor');copy($root.'/composer.lock',$failed.'/composer.lock');copy($root.'/.v2board-runtime.json',$failed.'/.v2board-runtime.json');
    // Make the saved published hash match this fixture before the shell test.
    $state=json_decode(file_get_contents($failed.'/.v2board-runtime.json'),true);$state['release_hash']=hash('sha256',file_get_contents($failed.'/composer.'.$key.'.json')."\0".file_get_contents($failed.'/composer.'.$key.'.lock'));put($failed.'/.v2board-runtime.json',$state);
    file_put_contents($failed.'/.env',"APP_KEY=preserve-fixture-key\n");$before=hashes($failed.'/vendor');$oldLock=file_get_contents($failed.'/composer.lock');$oldState=file_get_contents($failed.'/.v2board-runtime.json');
    // The target lacks the fixture addon; it is now an installed optional extra
    // and therefore must be retained. Fail after activation and verify rollback.
    foreach([['init','-b','release'],['config','user.name','Dependency fixture'],['config','user.email','fixture@example.test'],['add','.'],['commit','-m','fixture']]as$args){[$c,$o,$e]=run(['git',...$args],$failed);need($c===0,$o.$e);}
    $remote=$base.'/origin.git';[$c,$o,$e]=run(['git','init','--bare',$remote],$base);need($c===0,$o.$e);
    foreach([['remote','add','origin',$remote],['push','--set-upstream','origin','release']]as$args){[$c,$o,$e]=run(['git',...$args],$failed);need($c===0,$o.$e);}
    [$c,$o,$e]=run(['bash','update.sh'],$failed,['FAIL_APP_COMMAND'=>'v2board:update','V2BOARD_UPDATE_BACKUP_DIR'=>$base]);need($c!==0&&!str_contains($o,'Code and database update finished'),$o.$e);
    need(hashes($failed.'/vendor')===$before&&file_get_contents($failed.'/composer.lock')===$oldLock&&file_get_contents($failed.'/.v2board-runtime.json')===$oldState,'Shell failure did not restore prior dependency state');
    need(file_get_contents($failed.'/.env')==="APP_KEY=preserve-fixture-key\n",'Application key changed');$pass('failed application update rolls back dependencies and preserves environment');

    $init=$fixture('script-install');[$c,$o,$e]=run(['bash','init.sh','--runtime=fpm'],$init,['V2BOARD_UPDATE_BACKUP_DIR'=>$base]);need($c===0,$o.$e);
    need(is_file($init.'/.env')&&is_file($init.'/.v2board-runtime.json'),'Install script did not finish');$pass('init.sh installs the selected locked profile before application initialization');
    echo 'All ',$checks," runtime profile checks passed; fixtures: ",$base,"\n";
}catch(\Throwable$e){fwrite(STDERR,$e->getMessage()."\nFixtures retained: ".$base."\n");exit(1);}
