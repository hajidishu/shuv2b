<?php

namespace Tests\P1;

use App\Http\Controllers\V1\Admin\Server\ExternalController;
use App\Models\ServerExternal;
use App\Models\User;
use App\Services\ExternalNodeSchema;
use App\Services\ExternalNodeSettings;
use App\Utils\Helper;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\DatabasePresenceVerifier;
use Illuminate\Validation\ValidationException;

class ExternalNodeTest extends DatabaseTestCase
{
    private const UUID = 'c0d71ce0-1d13-4c78-a329-a16e0521f061';
    private const PANEL = 'fb91d2b8-a013-4b7d-a762-78689d978da1';

    protected function setUp(): void
    {
        parent::setUp();
        app('validator')->setPresenceVerifier(new DatabasePresenceVerifier(app('db')));
        Request::macro('validate', function ($rules) { return app('validator')->make($this->all(), $rules)->validate(); });
        DB::getSchemaBuilder()->dropIfExists('v2_server_group');
        DB::getSchemaBuilder()->create('v2_server_group', function (Blueprint $t) { $t->increments('id'); $t->string('name'); });
        DB::table('v2_server_group')->insert([['id'=>1,'name'=>'A'],['id'=>2,'name'=>'B'],['id'=>3,'name'=>'C']]);
        (new ExternalNodeSchema())->ensure();
    }

    private function config(string $protocol): array
    {
        $auth = in_array($protocol,['vless','vmess'],true) ? ['uuid'=>self::UUID] :
            ($protocol==='tuic' ? ['uuid'=>self::UUID,'password'=>'fixed-password'] :
                (in_array($protocol,['naive','mieru'],true) ? ['username'=>'upstream-user','password'=>'fixed-password'] : ['password'=>'fixed-password']));
        return ExternalNodeSettings::normalize(['protocol'=>$protocol,'host'=>'external.example.test','port'=>443,'auth'=>$auth,'cipher'=>'aes-128-gcm']);
    }

    private function save(array $extra = []): ServerExternal
    {
        $payload = array_replace(['name'=>'External fixture','group_id'=>[1], 'show'=>1, 'config'=>$this->config('vless')], $extra);
        (new ExternalController())->save(Request::create('/','POST',$payload));
        return isset($extra['id']) ? ServerExternal::find($extra['id']) : ServerExternal::orderByDesc('id')->first();
    }

    private function node(string $protocol): array
    {
        ServerExternal::query()->delete();
        $this->save(['config'=>$this->config($protocol)]);
        return ExternalNodeSettings::available(new User(['group_id'=>1]))[0];
    }

    private function emptyManagedTables(): void
    {
        foreach (\App\Services\LeakProbeService::TYPES as $type) {
            DB::getSchemaBuilder()->dropIfExists('v2_server_'.$type);
            DB::getSchemaBuilder()->create('v2_server_'.$type,function (Blueprint $t) {$t->increments('id');$t->integer('sort')->nullable();});
        }
    }

    public function testGroupScopeVisibilityAndCrudNeverAffectOtherGroups(): void
    {
        $controller = new ExternalController();
        $shared=$this->save(['group_id'=>['1','2']]);
        $this->save(['group_id'=>[3]]); $this->save(['show'=>0]);
        $this->assertCount(1,ExternalNodeSettings::available(new User(['group_id'=>1])));
        $this->assertCount(1,ExternalNodeSettings::available(new User(['group_id'=>'2'])));
        $this->assertCount(1,ExternalNodeSettings::available(new User(['group_id'=>3])));
        $this->assertSame([],ExternalNodeSettings::available(new User(['group_id'=>99])));
        $rows=$controller->fetch(Request::create('/','GET',['group_id'=>2]))->getData(true)['data'];
        $this->assertCount(1,$rows); $this->assertSame($shared->id,$rows[0]['id']);
        $this->save(['id'=>$shared->id,'name'=>'Edited','group_id'=>[2]]);
        $this->assertSame([],ExternalNodeSettings::available(new User(['group_id'=>1])));
        $this->assertSame('Edited',ExternalNodeSettings::available(new User(['group_id'=>2]))[0]['name']);
        $controller->update(Request::create('/','POST',['id'=>$shared->id,'show'=>0]));
        $this->assertSame([],ExternalNodeSettings::available(new User(['group_id'=>2])));
        $controller->drop(Request::create('/','POST',['id'=>$shared->id]));
        $this->assertNull(ServerExternal::find($shared->id)); $this->assertSame(2,ServerExternal::count());
    }

    public function testSortIsAtomicAndRequiresTheCompleteCurrentGroup(): void
    {
        $a=$this->save(); $b=$this->save(); $other=$this->save(['group_id'=>[3]]);
        $controller=new ExternalController();
        $controller->sort(Request::create('/','POST',['group_id'=>1,'ids'=>[$b->id,$a->id]]));
        $this->assertSame([$b->id,$a->id],array_column($controller->fetch(Request::create('/','GET',['group_id'=>1]))->getData(true)['data'],'id'));
        foreach ([[$a->id],[$a->id,$other->id]] as $ids) {
            try {$controller->sort(Request::create('/','POST',['group_id'=>1,'ids'=>$ids]));$this->fail('Invalid sort accepted');}
            catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {$this->assertSame(422,$e->getStatusCode());}
        }
        $this->assertSame(3,$other->fresh()->sort);
    }

    public function testRealSubscriptionControllerUsesGroupVisibilityAndRejectsUnavailableAccounts(): void
    {
        $this->emptyManagedTables();
        $this->save(['name'=>'Group A only']); $this->save(['name'=>'Group B only','group_id'=>[2]]); $this->save(['name'=>'Hidden','show'=>0]);
        $user=new User(['id'=>111,'uuid'=>self::PANEL,'group_id'=>1,'transfer_enable'=>1073741824,'u'=>0,'d'=>0,'expired_at'=>time()+3600,'banned'=>0]);
        $controller=new \App\Http\Controllers\V1\Client\ClientController();
        foreach ([1=>'Group A only',2=>'Group B only'] as $group=>$name) {
            $user->group_id=$group; $request=Request::create('/','GET',['flag'=>'general']);$request->user=$user;
            $body=base64_decode($controller->subscribe($request));
            $this->assertStringContainsString(rawurlencode($name),$body);$this->assertSame(1,substr_count($body,'vless://'));
            $this->assertStringContainsString(self::UUID,$body);$this->assertStringNotContainsString(self::PANEL,$body);
        }
        foreach ([['banned'=>1],['expired_at'=>time()-10],['transfer_enable'=>0]] as $change) {
            $blocked=new User(array_merge($user->getAttributes(),$change));$request=Request::create('/','GET',['flag'=>'general']);$request->user=$blocked;
            $this->assertNull($controller->subscribe($request));
        }
        $this->assertSame([], (new \App\Services\ServerService())->getAllServers());
        $groups=new \App\Http\Controllers\V1\Admin\Server\GroupController();
        try {$groups->drop(Request::create('/','POST',['id'=>1]));$this->fail('An assigned group was deleted');}
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422,$e->getStatusCode()); $this->assertStringContainsString('外部节点',$e->getMessage());
        }
        $this->assertTrue(DB::table('v2_server_group')->where('id',1)->exists());
        $groups->drop(Request::create('/','POST',['id'=>3]));
        $this->assertFalse(DB::table('v2_server_group')->where('id',3)->exists());
    }

    public function testAllExternalRoutesRetainTheExistingAdminAuthorization(): void
    {
        config(['v2board.secure_path'=>'fixture-admin']);
        $router=new \Illuminate\Routing\Router(new \Illuminate\Events\Dispatcher(app()),app());
        $router->group(['namespace'=>'App\\Http\\Controllers'],function ($router) {(new \App\Http\Routes\V1\AdminRoute())->map($router);});
        foreach (['fetch','parse','save','update','drop','sort'] as $operation) {
            $route=$router->getRoutes()->match(Request::create('/fixture-admin/server/group/external/'.$operation,$operation==='fetch'?'GET':'POST'));
            $this->assertContains('admin',$route->gatherMiddleware());
        }
    }

    public function testSchemaUpgradeIsRepeatableAndDoesNotModifyExistingAccounts(): void
    {
        $node=$this->save(); (new ExternalNodeSchema())->ensure();
        $this->assertSame(self::UUID,ServerExternal::find($node->id)->config['auth']['uuid']);
        $root=getenv('P1_SOURCE_ROOT') ?: dirname(__DIR__,2);
        foreach (['install','update'] as $name) {
            $this->assertSame(1,preg_match('/CREATE TABLE `v2_server_external`\s*\(.*?ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;/s',file_get_contents($root.'/database/'.$name.'.sql'),$m));
            (new \App\Services\DatabaseUpgrade())->run($m[0],true);
            (new \App\Services\DatabaseUpgrade())->verify($m[0]);
            $this->assertNotNull(ServerExternal::find($node->id));
        }
        DB::getSchemaBuilder()->table('v2_server_external',function (Blueprint $t) {$t->dropColumn('group_sort');});
        $sql='ALTER TABLE `v2_server_external` ADD `group_sort` text DEFAULT NULL;';
        (new \App\Services\DatabaseUpgrade())->run($sql,true);
        (new \App\Services\DatabaseUpgrade())->run($sql,true);
        DB::getSchemaBuilder()->table('v2_server_external',function (Blueprint $t) {$t->dropColumn('group_sort');});
        (new ExternalNodeSchema())->ensure(); (new ExternalNodeSchema())->ensure();
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('v2_server_external','group_sort'));
        $this->assertSame(self::UUID,ServerExternal::find($node->id)->config['auth']['uuid']);
    }

    public function testSharedNodesKeepIndependentGroupOrdersAndCounts(): void
    {
        $a=$this->save(['name'=>'A only']); $shared=$this->save(['name'=>'Shared','group_id'=>[1,2]]); $b=$this->save(['name'=>'B only','group_id'=>[2]]);
        $controller=new ExternalController();
        $controller->sort(Request::create('/','POST',['group_id'=>1,'ids'=>[$shared->id,$a->id]]));
        $controller->sort(Request::create('/','POST',['group_id'=>2,'ids'=>[$b->id,$shared->id]]));
        foreach ([1=>[$shared->id,$a->id],2=>[$b->id,$shared->id]] as $group=>$expected) {
            $data=$controller->fetch(Request::create('/','GET',['group_id'=>$group]))->getData(true)['data'];
            $this->assertSame($expected,array_column($data,'id'));
            $this->assertSame(array_map(function ($id) {return -$id;},$expected),array_column(ExternalNodeSettings::available(new User(['group_id'=>$group])),'id'));
        }
        $this->emptyManagedTables();
        $groups=(new \App\Http\Controllers\V1\Admin\Server\GroupController())->fetch(Request::create('/'))->getData(true)['data'];
        $this->assertSame([2,2,0],array_column($groups,'server_count'));
    }

    public function testTransportNormalizationIsStableAcrossRepeatedSaves(): void
    {
        foreach (['vless','vmess'] as $protocol) {
            foreach (['http'=>['host'=>'front.example.test','path'=>'/edge'], 'ws'=>['path'=>'/socket','headers'=>['Host'=>'front.example.test']],
                'grpc'=>['serviceName'=>'fixture-service'], 'xhttp'=>['path'=>'/x','host'=>'front.example.test','mode'=>'packet-up','extra'=>['xmux'=>['maxConcurrency'=>8]]]] as $network=>$settings) {
                $config=$this->config($protocol);$config['network']=$network;$config['network_settings']=$settings;
                $normalized=ExternalNodeSettings::normalize($config);
                $this->assertSame($normalized,ExternalNodeSettings::normalize($normalized));
                $node=$this->save(['config'=>$normalized]);
                $this->save(['id'=>$node->id,'config'=>$node->config]);
                $this->assertEquals($settings,$node->fresh()->config['network_settings']);
            }
        }
        $legacy=$this->config('vless');$legacy['network']='http';$legacy['network_settings']=['Host'=>'front.example.test','path'=>'/edge'];
        $this->assertSame(['path'=>'/edge','host'=>'front.example.test'],ExternalNodeSettings::normalize($legacy)['network_settings']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testRealSubscriptionsOmitUnsupportedTransportsBeforeRegisteringProxyNames(): void
    {
        $this->emptyManagedTables();
        config(['v2board.subscribe_url'=>'https://panel.example.test']);
        \Illuminate\Support\Facades\File::swap(new \Illuminate\Filesystem\Filesystem());
        if (!class_exists('File')) class_alias(\Illuminate\Support\Facades\File::class,'File');
        $this->save(['name'=>'safe-vless']);
        $xhttp=$this->config('vless');$xhttp['network']='xhttp';$xhttp['network_settings']=['path'=>'/x','extra'=>['xmux'=>['maxConcurrency'=>8]]];
        $this->save(['name'=>'xhttp-extra','config'=>$xhttp]);
        $http=$this->config('vmess');$http['network']='http';$http['network_settings']=['host'=>'front.example.test','path'=>'/edge'];
        $this->save(['name'=>'vmess-http','config'=>$http]);
        $trojan=$this->config('trojan');$this->save(['name'=>'safe-trojan','config'=>$trojan]);
        $trojan['auth']['password']='p,q';$this->save(['name'=>'comma-password','config'=>$trojan]);
        $user=new User(['id'=>111,'uuid'=>self::PANEL,'token'=>'fixture-subscription-token','group_id'=>1,'transfer_enable'=>1073741824,'u'=>0,'d'=>0,'expired_at'=>time()+3600,'banned'=>0]);
        $controller=new \App\Http\Controllers\V1\Client\ClientController();
        $request=Request::create('/','GET',['flag'=>'meta']);$request->user=$user;
        $yaml=\Symfony\Component\Yaml\Yaml::parse($controller->subscribe($request));
        $this->assertSame(['safe-vless','safe-trojan','comma-password'],array_column($yaml['proxies'],'name'));
        foreach ($yaml['proxy-groups'] as $group) {
            $this->assertNotContains('xhttp-extra',$group['proxies']);$this->assertNotContains('vmess-http',$group['proxies']);
        }
        $request=Request::create('/','GET',['flag'=>'sing'],[],[],['HTTP_USER_AGENT'=>'sing-box 1.12.0']);$request->user=$user;
        $response=$controller->subscribe($request);$json=json_decode($response->getData(true),true);
        $tags=array_column($json['outbounds'],'tag');
        $this->assertContains('safe-vless',$tags);$this->assertContains('safe-trojan',$tags);$this->assertContains('comma-password',$tags);
        $this->assertNotContains('vmess-http',$tags);$this->assertNotContains('xhttp-extra',$tags);
        $request=Request::create('/','GET',['flag'=>'general']);$request->user=$user;
        $uri=base64_decode($controller->subscribe($request));
        $this->assertStringContainsString('#xhttp-extra',$uri);$this->assertStringContainsString('p%2Cq@',$uri);
        preg_match('/vmess:\/\/([^\r\n]+)/',$uri,$match);$vmess=json_decode(base64_decode($match[1]),true);
        $this->assertSame('front.example.test',$vmess['host']);$this->assertSame('/edge',$vmess['path']);
        $previous=$_SERVER['HTTP_HOST'] ?? null;$_SERVER['HTTP_HOST']='panel.example.test';
        try {
            $request=Request::create('/','GET',['flag'=>'surge']);$request->user=$user;
            $text=$controller->subscribe($request);
            $this->assertStringContainsString('safe-trojan=trojan',$text);
            $this->assertStringNotContainsString('password=p,q',$text);
            $this->assertStringNotContainsString('vmess-http',$text);$this->assertStringNotContainsString('xhttp-extra',$text);
        } finally {
            if ($previous===null) unset($_SERVER['HTTP_HOST']);else $_SERVER['HTTP_HOST']=$previous;
        }
        $ordinary=['type'=>'vless','network'=>'xhttp','name'=>'ordinary','id'=>100];
        $this->assertSame([$ordinary],\App\Services\ExternalNodeFormats::filter([$ordinary],'Singbox'));
    }

    public function testTextDelimitersStayUsableInStructuredFormatsWithoutBreakingOtherNodes(): void
    {
        $node=$this->node('trojan');$normal=$node;unset($normal['_external_auth']);
        foreach (['p,q','p"q','p\\q'," p "] as $password) {
            $node['_external_auth']['password']=$password;
            foreach (['Surge','Surfboard','Loon','QuantumultX'] as $format) $this->assertSame([$normal],\App\Services\ExternalNodeFormats::filter([$node,$normal],$format));
            foreach (['General','ClashMeta','Singbox'] as $format) $this->assertCount(1,\App\Services\ExternalNodeFormats::filter([$node],$format));
            $this->assertSame($password,\App\Protocols\ClashMeta::buildTrojan(self::PANEL,$node)['password']);
        }
        $node=$this->node('trojan');$node['network']='ws';$node['network_settings']=['path'=>'/edge,tag=other'];
        $this->assertSame([],\App\Services\ExternalNodeFormats::filter([$node],'Surge'));
        $node['network_settings']=[];$node['name']='name=other';
        $this->assertSame([],\App\Services\ExternalNodeFormats::filter([$node],'Loon'));
    }

    public function testHysteriaSniCannotInjectQueryOptions(): void
    {
        $node=$this->node('hysteria');$sni='front.example.test&insecure=1#extra';
        $node['server_name']=$node['tls_settings']['server_name']=$sni;
        foreach (['buildHysteriaUri','buildHysteria2Uri'] as $method) {
            $uri=trim(Helper::$method(self::PANEL,$node));parse_str(parse_url($uri,PHP_URL_QUERY),$query);
            $this->assertSame('0',$query['insecure']);$this->assertSame($sni,$query['sni']);
            $this->assertSame($node['name'],rawurldecode(parse_url($uri,PHP_URL_FRAGMENT)));
        }
    }

    public function testHttpAndXhttpDetailsSurviveTheGeneralVmessAndVlessLinks(): void
    {
        foreach (['vmess','vless'] as $protocol) {
            $config=$this->config($protocol);$config['network']='http';$config['network_settings']=['host'=>'front.example.test','path'=>'/edge'];
            ServerExternal::query()->delete();$this->save(['config'=>$config]);$node=ExternalNodeSettings::available(new User(['group_id'=>1]))[0];
            $uri=trim(Helper::buildUri(self::PANEL,$node,111));
            if ($protocol==='vmess') $query=json_decode(base64_decode(substr($uri,8)),true);else parse_str(parse_url($uri,PHP_URL_QUERY),$query);
            $this->assertSame('front.example.test',$query['host']);$this->assertSame('/edge',$query['path']);
        }
        $extra=['xmux'=>['maxConcurrency'=>8]];
        $json=['v'=>'2','ps'=>'XHTTP','add'=>'node.example.test','port'=>'443','id'=>self::UUID,'net'=>'xhttp','type'=>'none','scy'=>'auto','aid'=>'0','host'=>'front.example.test','path'=>'/edge','mode'=>'packet-up','extra'=>json_encode($extra),'tls'=>'tls','fp'=>'safari'];
        $parsed=ExternalNodeSettings::parse('vmess://'.base64_encode(json_encode($json)));
        $this->assertSame($extra,$parsed['config']['network_settings']['extra']);
        ServerExternal::query()->delete();$this->save($parsed);$node=ExternalNodeSettings::available(new User(['group_id'=>1]))[0];
        $out=json_decode(base64_decode(substr(trim(Helper::buildUri(self::PANEL,$node,111)),8)),true);
        $this->assertSame('packet-up',$out['mode']);$this->assertSame($extra,json_decode($out['extra'],true));
        $this->assertSame('safari',$out['fp']);
        $ss=$this->node('shadowsocks');
        $this->assertArrayNotHasKey('plugin',\App\Protocols\Clash::buildShadowsocks(self::PANEL,$ss));
        foreach (['Singbox','SingboxOld'] as $name) {
            $class='App\\Protocols\\Singbox\\'.$name;$formatter=new $class(new User(['uuid'=>self::PANEL]),[]);
            $method=new \ReflectionMethod($formatter,'buildVmess');$method->setAccessible(true);$node=$this->node('vmess');
            $this->assertArrayNotHasKey('transport',$method->invoke($formatter,self::PANEL,$node));
        }
    }

    public function testSupportedShareLinksParseFixedCredentialsAndClientFields(): void
    {
        $uuid=self::UUID;
        $vmess='vmess://'.base64_encode(json_encode(['v'=>'2','ps'=>'VMess fixture','add'=>'external.example.test','port'=>'443','id'=>$uuid,'net'=>'ws','host'=>'cdn.example.test','path'=>'/socket','tls'=>'tls','sni'=>'tls.example.test']));
        $cases=[
            ['vless',"vless://$uuid@external.example.test:443?security=reality&type=tcp&sni=tls.example.test&pbk=fixture-public-key&sid=ab12&flow=xtls-rprx-vision#VLESS",'uuid',$uuid],
            ['vmess',$vmess,'uuid',$uuid],
            ['trojan','trojan://pa%40ss%3Aword@external.example.test:443?sni=tls.example.test','password','pa@ss:word'],
            ['shadowsocks','ss://'.rtrim(strtr(base64_encode('aes-128-gcm:fixed:password'),'+/','-_'),'=').'@external.example.test:443#SS','password','fixed:password'],
            ['hysteria','hy2://auth%3Apassword@external.example.test:443/?sni=tls.example.test&insecure=1&obfs=salamander&obfs-password=obfs','password','auth:password'],
            ['tuic',"tuic://$uuid:tuic-pass@external.example.test:443?alpn=h3",'password','tuic-pass'],
            ['anytls','anytls://any-pass@external.example.test:443?sni=tls.example.test','password','any-pass'],
            ['naive','naive+https://named-user:naive-pass@external.example.test:443?sni=tls.example.test','username','named-user'],
            ['mieru','mierus://named-user:mieru-pass@external.example.test?port=443&protocol=TCP&profile=Mieru','username','named-user'],
        ];
        foreach ($cases as [$protocol,$uri,$key,$expected]) {
            $parsed=ExternalNodeSettings::parse($uri); $this->assertSame($protocol,$parsed['config']['protocol']);
            $this->assertSame($expected,$parsed['config']['auth'][$key]);
            $this->assertSame('external.example.test',$parsed['config']['host']);
            $this->assertSame(443,$parsed['config']['port']);
        }
        $parsed=ExternalNodeSettings::parse($vmess)['config'];
        $this->assertSame('/socket',$parsed['network_settings']['path']);
        $this->assertSame('cdn.example.test',$parsed['network_settings']['headers']['Host']);
        $ipv6=ExternalNodeSettings::parse("vless://$uuid@[2001:db8::1]:8443?security=none");
        $this->assertSame('2001:db8::1',$ipv6['config']['host']);
        $this->assertSame(8443,$ipv6['config']['port']);
    }

    public function testUnknownProtocolsParametersAndWrongAuthenticationFailAtImport(): void
    {
        foreach (['https://example.test/sub','vless://123@external.example.test:443',
            'trojan://pass@external.example.test:443?unsupported=1',
            'trojan://pass@external.example.test:443?sni[]=bad',
            'ss://'.base64_encode('aes-128-gcm:pass').'@external.example.test:443?plugin=v2ray-plugin',
            "vless://".self::UUID.'@external.example.test:443?security=reality',
            'trojan://a%0Ab@external.example.test:443', 'vmess://not-base64'] as $uri) {
            try {ExternalNodeSettings::parse($uri);$this->fail('Invalid URI accepted: '.$uri);}
            catch (ValidationException $e) {$this->assertNotEmpty($e->errors());}
        }
    }

    public function testAllExistingProtocolBuildersUseTheExternalCredentials(): void
    {
        $classes=['Clash','ClashMeta','ClashNyanpasu','ClashVerge','Stash','Loon','QuantumultX','Surge','Surfboard','Shadowrocket','Singbox\\Singbox','Singbox\\SingboxOld'];
        $user=new User(['id'=>111,'uuid'=>self::PANEL,'speed_limit'=>null]);
        $count=0;
        foreach ($classes as $name) {
            $class='App\\Protocols\\'.$name;
            $instance=new $class($user,[],['version'=>'1.13.0']);
            foreach (['vless','vmess','trojan','shadowsocks','hysteria','tuic','anytls'] as $protocol) {
                $method='build'.ucfirst($protocol);
                if (!method_exists($instance,$method)) continue;
                $node=$this->node($protocol); $reflection=new \ReflectionMethod($instance,$method); $reflection->setAccessible(true);
                $value=$reflection->invoke($instance,self::PANEL,$node,$user); $serialized=is_array($value)?json_encode($value):$value;
                $credential=in_array($protocol,['vless','vmess'],true) ? self::UUID : 'fixed-password';
                if ($name==='Shadowrocket') $serialized=base64_decode(parse_url($value,PHP_URL_HOST) ?: substr(explode('?',$value)[0],8));
                $this->assertStringContainsString($credential,$serialized,$name.' '.$protocol);
                $this->assertStringNotContainsString(self::PANEL,$serialized,$name.' '.$protocol);
                if ($protocol==='tuic') $this->assertStringContainsString(self::UUID,$serialized,$name.' TUIC uuid');
                ++$count;
            }
        }
        $this->assertGreaterThan(45,$count);
    }

    public function testShadowsocks2022KeepsUpstreamPasswordRatherThanDerivingPanelKeys(): void
    {
        $node=$this->node('shadowsocks'); $node['cipher']='2022-blake3-aes-128-gcm';
        $password=base64_encode(str_repeat('s',16)).':'.base64_encode(str_repeat('u',16)); $node['_external_auth']['password']=$password;
        foreach (['ClashMeta','ClashNyanpasu','ClashVerge','Stash','Singbox\\Singbox','Singbox\\SingboxOld','QuantumultX','Loon','Surge','Surfboard'] as $name) {
            $class='App\\Protocols\\'.$name; $instance=new $class(new User(['uuid'=>self::PANEL]),[]);
            $method=new \ReflectionMethod($class,'buildShadowsocks'); $method->setAccessible(true);
            $result=$method->invoke($instance,self::PANEL,$node);
            if (is_array($result)) $this->assertSame($password,$result['password']);
            else $this->assertStringContainsString($password,$result);
        }
        $parts=parse_url(trim(Helper::buildUri(self::PANEL,$node,111)));
        $this->assertSame('2022-blake3-aes-128-gcm',rawurldecode($parts['user']));
        $this->assertSame($password,rawurldecode($parts['pass']));
        $this->assertSame($password,ExternalNodeSettings::parse(trim(Helper::buildUri(self::PANEL,$node,111)))['config']['auth']['password']);
        unset($node['_external_auth']);
        $ordinary=\App\Protocols\ClashMeta::buildShadowsocks(self::PANEL,$node);
        $this->assertNotSame($password,$ordinary['password']);
        $this->assertStringContainsString(Helper::uuidToBase64(self::PANEL,16),$ordinary['password']);
    }

    public function testShadowsocksPluginEscapingPreservesTheWholePath(): void
    {
        $config=$this->config('shadowsocks');$config['obfs']='http';
        $config['network_settings']=['host'=>'front.example.test','path'=>'/a&b=c;d:e\\f'];
        $this->save(['config'=>$config]);$node=ExternalNodeSettings::available(new User(['group_id'=>1]))[0];
        $uri=trim(Helper::buildUri(self::PANEL,$node,111));parse_str(parse_url($uri,PHP_URL_QUERY),$query);
        $this->assertSame(['plugin'],array_keys($query));
        $imported=ExternalNodeSettings::parse($uri)['config'];
        $this->assertSame($config['network_settings'],$imported['network_settings']);
        $this->assertSame($config['auth'],$imported['auth']);
    }

    public function testGeneralNaiveMieruAndTuicPreserveIndependentCredentialsAndEscaping(): void
    {
        foreach (['vless','vmess','trojan','shadowsocks','hysteria','tuic','anytls','naive','mieru'] as $protocol) {
            $node=$this->node($protocol);
            if (isset($node['_external_auth']['password'])) $node['_external_auth']['password']='p@ss:+#/&';
            $uri=trim(Helper::buildUri(self::PANEL,$node,111));
            $this->assertNotSame('',$uri,$protocol); $this->assertStringNotContainsString(self::PANEL,$uri);
            if ($protocol==='tuic') $this->assertSame(self::UUID,rawurldecode(parse_url($uri,PHP_URL_USER)));
            if (in_array($protocol,['trojan','hysteria','anytls'],true)) $this->assertSame('p@ss:+#/&',rawurldecode(parse_url($uri,PHP_URL_USER)));
            if (in_array($protocol,['tuic','naive','mieru'],true)) $this->assertSame('p@ss:+#/&',rawurldecode(parse_url($uri,PHP_URL_PASS)));
            if ($protocol==='naive') {
                $out=\App\Services\NaiveSettings::outbound(111,self::PANEL,$node);
                $this->assertSame('upstream-user',$out['username']); $this->assertSame('p@ss:+#/&',$out['password']);
            }
            if ($protocol==='mieru') {
                $out=\App\Services\MieruSettings::mihomo(111,self::PANEL,$node);
                $this->assertSame('upstream-user',$out['username']); $this->assertSame('p@ss:+#/&',$out['password']);
            }
            if (in_array($protocol,['naive','mieru'],true)) {
                $node['_external_auth']['username']='0';
                $this->assertStringContainsString('://0:',Helper::buildUri(self::PANEL,$node,111));
            }
        }
    }
}
