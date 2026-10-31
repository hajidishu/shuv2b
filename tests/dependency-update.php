<?php
declare(strict_types=1);

// Real Composer, tiny synthetic installed packages and the pinned public SDK.
// No application boot, HTTP requests to a panel or database access.
function verify(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function command(array $args, string $cwd, array $env = []): array {
    $pipes=[];
    $p=proc_open($args, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, $cwd, array_merge(getenv(), ['COMPOSER_ALLOW_SUPERUSER'=>'1'], $env));
    verify(is_resource($p),'Cannot start test process'); fclose($pipes[0]);
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    return [proc_close($p),$out,$err];
}
function jsonFile(string $path, array $value): void { file_put_contents($path,json_encode($value,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n"); }
function tree(string $root): array {
    $out=[];
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS)) as $f) {
        if($f->getFilename()==='.v2board-recovery.lock')continue;
        $name=substr($f->getPathname(),strlen($root)+1);
        $out[$name]=$f->isLink()?'link:'.readlink($f->getPathname()):hash_file('sha256',$f->getPathname());
    }
    ksort($out);return $out;
}
$repo=dirname(__DIR__);
$composer=realpath($argv[1]??getenv('TEST_COMPOSER')?:$repo.'/composer.phar');
verify(is_string($composer),'Pass a Composer PHP executable/PHAR as the first argument');
$scratch=sys_get_temp_dir().'/v2board-dependency-test-'.bin2hex(random_bytes(6));mkdir($scratch,0700);
$count=0;
$pass=function(string $message)use(&$count){$count++;echo 'PASS ',$message,"\n";};
$fixture=function(string $name,bool $dev=true)use($scratch,$repo,$composer):string {
    $root=$scratch.'/'.$name;mkdir($root);mkdir($root.'/library');mkdir($root.'/vendor/composer',0755,true);
    copy($repo.'/library/WebAuthnPatch.php',$root.'/library/WebAuthnPatch.php');
    $manifest=['name'=>'v2board/v2board','description'=>'Dependency update fixture','license'=>'MIT',
        'require'=>['php'=>'>=8.0','laravel/framework'=>'^8.0','symfony/yaml'=>'^4.3','lbuchs/webauthn'=>'2.2.0'],
        'require-dev'=>['fixture/dev-package'=>'^1.0'],'minimum-stability'=>'dev','prefer-stable'=>true,
        'scripts'=>['post-autoload-dump'=>'@php -r \'file_put_contents("UNEXPECTED_SCRIPT", "ran");\'']];
    jsonFile($root.'/composer.json',$manifest);
    $packages=[];
    $rows=[['laravel/framework','8.x-dev','Illuminate\\','Foundation/Application'],
           ['symfony/yaml','4.4.x-dev','Symfony\\Component\\Yaml\\','Yaml'],
           ['joanhey/adapterman','0.7.1','Adapterman\\','Adapterman'],
           ['workerman/workerman','v5.2.0','Workerman\\','Worker'],
           ['workerman/coroutine','v1.1.5','WorkermanFixture\\','Coroutine']];
    if($dev)$rows[]=['fixture/dev-package','1.0.0','Fixture\\','DevPackage'];
    foreach($rows as [$pkg,$version,$prefix,$relative]) {
        $file=$root.'/vendor/'.$pkg.'/src/'.$relative.'.php';mkdir(dirname($file),0755,true);
        $parts=explode('/',$relative);$class=array_pop($parts);$namespace=rtrim($prefix,'\\').($parts?'\\'.implode('\\',$parts):'');
        file_put_contents($file,'<?php namespace '.$namespace.'; class '.$class." {}\n");
        $packages[]=['name'=>$pkg,'version'=>$version,'type'=>'library','require'=>['php'=>'>=8.0'],
            'autoload'=>['psr-4'=>[$prefix=>'src/']],'install-path'=>'../'.$pkg,
            'source'=>['type'=>'git','url'=>'https://example.invalid/fixture.git','reference'=>str_repeat('a',40)]];
    }
    jsonFile($root.'/vendor/composer/installed.json',['dev'=>$dev,'packages'=>$packages,'dev-package-names'=>$dev?['fixture/dev-package']:[]]);
    [$code,$out,$err]=command([PHP_BINARY,$composer,'--no-plugins','--no-scripts','--no-interaction','dump-autoload'],$root);
    verify($code===0,$out.$err);return $root;
};
$apply=function(string $root,bool $write=true)use($repo,$scratch,$composer):array {
    return command([PHP_BINARY,'-d','error_reporting=24575',$repo.'/library/UpdateDependencies.php',$root,$scratch,'--composer='.$composer,...($write?['--apply']:[])],$repo);
};
try {
    $root=$fixture('missing-lock');$manifest=file_get_contents($root.'/composer.json');
    [$code,$out,$err]=$apply($root);verify($code===0,$out.$err);
    verify(is_file($root.'/composer.lock')&&!is_file($root.'/UNEXPECTED_SCRIPT'),'Lock missing or project scripts ran');
    verify(file_get_contents($root.'/composer.json')===$manifest,'Root manifest changed');
    $lock=json_decode(file_get_contents($root.'/composer.lock'),true,512,JSON_THROW_ON_ERROR);
    $versions=array_column(array_merge($lock['packages'],$lock['packages-dev']),'version','name');
    foreach(['laravel/framework'=>'8.x-dev','symfony/yaml'=>'4.4.x-dev','joanhey/adapterman'=>'0.7.1','workerman/workerman'=>'v5.2.0']as$name=>$version)verify(($versions[$name]??null)===$version,'Deployed version changed: '.$name);
    $pass('missing lock recovers exact development refs and optional Webman packages, adding only WebAuthn');
    $sdk=tree($root.'/vendor/lbuchs/webauthn');
    [$code,$out,$err]=$apply($root);verify($code===0&&str_contains($out,'no dependency changes'),$out.$err);
    verify(tree($root.'/vendor/lbuchs/webauthn')===$sdk,'Repeated update changed SDK');
    $pass('repeated update preserves the recovered lock profile and working ROR adaptation');
    [$code,$out,$err]=command([PHP_BINARY,$composer,'--no-plugins','--no-scripts','--no-interaction','validate','--no-check-publish'],$root);
    verify($code===0,$out.$err);$pass('reconstructed lock passes Composer validation');

    $old=$fixture('old-lock');$oldLock=$lock;
    $oldLock['packages']=array_values(array_filter($oldLock['packages'],fn($p)=>$p['name']!=='lbuchs/webauthn'));
    jsonFile($old.'/composer.lock',$oldLock);
    [$code,$out,$err]=$apply($old);verify($code===0,$out.$err);$pass('existing pre-WebAuthn lock is supplemented without framework changes');

    $optional=$fixture('lock-missing-optional-runtime');$optionalLock=$oldLock;
    $optionalLock['packages']=array_values(array_filter($optionalLock['packages'],fn($p)=>!in_array($p['name'],['joanhey/adapterman','workerman/workerman','workerman/coroutine'],true)));
    jsonFile($optional.'/composer.lock',$optionalLock);
    [$code,$out,$err]=$apply($optional);verify($code===0,$out.$err);
    $pass('installed optional runtime survives even when omitted from the prior lock and root manifest');

    $installed=$fixture('sdk-installed-without-lock');
    [$code,$out,$err]=command(['cp','-a',$root.'/vendor/lbuchs',$installed.'/vendor/lbuchs'],$repo);verify($code===0,$out.$err);
    $meta=json_decode(file_get_contents($installed.'/vendor/composer/installed.json'),true,512,JSON_THROW_ON_ERROR);
    $complete=json_decode(file_get_contents($root.'/vendor/composer/installed.json'),true,512,JSON_THROW_ON_ERROR);
    foreach($complete['packages']as$package)if($package['name']==='lbuchs/webauthn')$meta['packages'][]=$package;
    jsonFile($installed.'/vendor/composer/installed.json',$meta);
    [$code,$out,$err]=command([PHP_BINARY,$composer,'--no-plugins','--no-scripts','--no-interaction','dump-autoload'],$installed);verify($code===0,$out.$err);
    [$code,$out,$err]=$apply($installed);verify($code===0&&str_contains($out,'no dependency changes'),$out.$err);
    $pass('missing lock with WebAuthn already installed is reconstructed without reinstalling packages');

    $prod=$fixture('production-with-lock',false);jsonFile($prod.'/composer.lock',$oldLock);
    [$code,$out,$err]=$apply($prod);verify($code===0,$out.$err);
    $meta=json_decode(file_get_contents($prod.'/vendor/composer/installed.json'),true,512,JSON_THROW_ON_ERROR);
    verify($meta['dev']===false&&!is_dir($prod.'/vendor/fixture/dev-package'),'Production update installed dev packages');
    $pass('production install profile stays production-only while preserving full existing lock information');

    $preview=$fixture('preview');$before=tree($preview.'/vendor');
    [$code,$out,$err]=$apply($preview,false);verify($code===0,$out.$err);
    verify(tree($preview.'/vendor')===$before&&!is_file($preview.'/composer.lock'),'Preview changed installed state');
    $pass('preparation mode leaves installed files and root lock untouched');

    $rollback=$fixture('rollback-no-lock');$before=tree($rollback.'/vendor');
    file_put_contents($rollback.'/library/WebAuthnPatch.php',"<?php exit(19);\n");
    [$code,$out,$err]=$apply($rollback);verify($code!==0&&str_contains($err,'restored and verified'),$out.$err);
    verify(tree($rollback.'/vendor')===$before&&!is_file($rollback.'/composer.lock'),'No-lock rollback differs');
    $pass('failed ROR adaptation restores all prior vendor files and missing-lock state');

    $rollback=$fixture('rollback-existing-lock');jsonFile($rollback.'/composer.lock',$oldLock);
    $before=tree($rollback.'/vendor');$lockBytes=file_get_contents($rollback.'/composer.lock');
    file_put_contents($rollback.'/library/WebAuthnPatch.php',"<?php exit(19);\n");
    [$code,$out,$err]=$apply($rollback);verify($code!==0&&str_contains($err,'restored and verified'),$out.$err);
    verify(tree($rollback.'/vendor')===$before&&file_get_contents($rollback.'/composer.lock')===$lockBytes,'Existing-lock rollback differs');
    $pass('failed update restores the original lock bytes as well as vendor files');

    $invalid=$fixture('incompatible-root');$manifest=json_decode(file_get_contents($invalid.'/composer.json'),true);
    $manifest['require']['symfony/yaml']='^7.0';jsonFile($invalid.'/composer.json',$manifest);$before=tree($invalid.'/vendor');
    [$code,$out,$err]=$apply($invalid);verify($code!==0,$out.$err);
    verify(tree($invalid.'/vendor')===$before&&!is_file($invalid.'/composer.lock'),'Incompatible requirement changed deployment');
    $pass('incompatible dependency requirements stop before mutating installed packages');

    $global=$fixture('global-composer-without-extension');$globalComposer=$scratch.'/composer';copy($composer,$globalComposer);
    [$code,$out,$err]=command([PHP_BINARY,'-d','error_reporting=24575',$repo.'/library/UpdateDependencies.php',$global,$scratch,'--composer='.$globalComposer,'--apply'],$repo);
    verify($code===0,$out.$err);$pass('global Composer PHAR without a filename extension is loaded correctly');

    $incomplete=$fixture('missing-development-metadata',false);$before=tree($incomplete.'/vendor');
    [$code,$out,$err]=$apply($incomplete);verify($code!==0&&str_contains($err,'development lock information'),$out.$err);
    verify(tree($incomplete.'/vendor')===$before&&!is_file($incomplete.'/composer.lock'),'Incomplete metadata changed deployment');
    $pass('insufficient information never triggers a whole-project dependency update');
    echo 'All ',$count," real Composer checks passed; fixture directory: ",$scratch,"\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\nFixtures retained: ".$scratch."\n");exit(1);}
