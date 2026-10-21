<?php
namespace Tests\P1;

use App\Http\Controllers\V1\Admin\Server\BatchController;
use App\Http\Controllers\V1\Admin\UserController;
use App\Http\Requests\Admin\UserFetch;
use App\Models\ServerVmess;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\DatabasePresenceVerifier;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AdminManagementTest extends DatabaseTestCase
{
    private $factory;
    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = new Factory(Environment::$app['translator'], Environment::$app);
        $this->factory->setPresenceVerifier(new DatabasePresenceVerifier(Environment::$capsule->getDatabaseManager()));
        Environment::$app->instance('validator', $this->factory);
        $schema = Environment::$capsule->getConnection('p1')->getSchemaBuilder();
        Environment::$app->instance('db.schema', $schema);
        Request::macro('validate', function (array $rules, array $messages = []) {
            return app('validator')->make($this->all(), $rules, $messages)->validate();
        });
        foreach (['v2_server_vmess', 'v2_server_group', 'v2_server_route'] as $table) $schema->dropIfExists($table);
        foreach (['v2_server_group', 'v2_server_route'] as $table) $schema->create($table, function (Blueprint $t) { $t->increments('id'); });
        DB::table('v2_server_group')->insert(['id' => 1]);
        $schema->create('v2_server_vmess', function (Blueprint $t) {
            $t->increments('id');
            foreach (['name','host','port','network'] as $field) $t->string($field);
            foreach (['group_id','route_id','tags','networkSettings','tlsSettings','ruleSettings','dnsSettings'] as $field) $t->text($field)->nullable();
            foreach (['server_port','tls','show','sort','parent_id','created_at','updated_at'] as $field) $t->integer($field)->nullable();
            $t->decimal('rate', 10, 3);
        });
    }
    private function node($name)
    {
        return ServerVmess::create(['name'=>$name,'host'=>'node.example.invalid','port'=>'443','server_port'=>443,'network'=>'ws','networkSettings'=>['path'=>'/preserve','security'=>'auto'],'tls'=>1,'tlsSettings'=>['server_name'=>'original.example.invalid'],'group_id'=>[1],'route_id'=>[],'tags'=>['original'],'rate'=>1,'show'=>1]);
    }
    private function batch($data)
    {
        return (new BatchController())->execute(Request::create('/batch', 'POST', $data));
    }
    public function testBatchPatchChangesOnlySelectedFieldAndNodes()
    {
        $a=$this->node('a');$b=$this->node('b');$c=$this->node('unselected');
        $result=$this->batch(['action'=>'update','nodes'=>[['type'=>'vmess','id'=>$a->id],['type'=>'vmess','id'=>$b->id]],'changes'=>['rate'=>2.25]]);
        $this->assertSame(2,$result->getData(true)['affected']);
        foreach ([$a,$b] as $node) {
            $fresh=$node->fresh();$this->assertEquals(2.25,$fresh->rate);
            $this->assertSame($node->networkSettings,$fresh->networkSettings);
            $this->assertSame($node->tlsSettings,$fresh->tlsSettings);
            $this->assertSame($node->group_id,$fresh->group_id);
        }
        $this->assertEquals(1,$c->fresh()->rate);
    }
    public function testMissingSecondNodeRollsBackFirstUpdate()
    {
        $node=$this->node('first');
        try {$this->batch(['action'=>'update','nodes'=>[['type'=>'vmess','id'=>$node->id],['type'=>'vmess','id'=>999999]],'changes'=>['rate'=>9]]);$this->fail('Missing node must fail');}
        catch (HttpException $e) {$this->assertSame(404,$e->getStatusCode());}
        $this->assertEquals(1,$node->fresh()->rate);
    }
    public function testMissingSecondNodeRollsBackFirstDeletion()
    {
        $node=$this->node('first');
        try {$this->batch(['action'=>'delete','nodes'=>[['type'=>'vmess','id'=>$node->id],['type'=>'vmess','id'=>999999]]]);$this->fail('Missing node must fail');}
        catch (HttpException $e) {$this->assertSame(404,$e->getStatusCode());}
        $this->assertNotNull($node->fresh());
    }
    public function testDuplicateSelectionRejectedWithoutMutation()
    {
        $node=$this->node('first');
        try {$this->batch(['action'=>'update','nodes'=>[['type'=>'vmess','id'=>$node->id],['type'=>'vmess','id'=>$node->id]],'changes'=>['show'=>0]]);$this->fail('Duplicate node must fail');}
        catch (ValidationException $e) {$this->assertArrayHasKey('nodes',$e->errors());}
        $this->assertSame(1,(int)$node->fresh()->show);
    }
    public function testIdentityAndUnknownFieldsCannotBePatched()
    {
        $node=$this->node('first');
        foreach (['id','created_at','type','nonexistent'] as $field) {
            try {$this->batch(['action'=>'update','nodes'=>[['type'=>'vmess','id'=>$node->id]],'changes'=>[$field=>2]]);$this->fail('Unknown field must fail');}
            catch (ValidationException $e) {$this->assertArrayHasKey('changes',$e->errors());}
        }
        $this->assertSame('first',$node->fresh()->name);
    }
    public function testClearingRoutesAndZeroRateAreExplicitUpdates()
    {
        $node=$this->node('first');$node->update(['route_id'=>[10]]);
        $this->batch(['action'=>'update','nodes'=>[['type'=>'vmess','id'=>$node->id]],'changes'=>['rate'=>0,'route_id'=>[],'show'=>0]]);
        $this->assertEquals(0,$node->fresh()->rate);$this->assertSame([],$node->fresh()->route_id);$this->assertSame(0,(int)$node->fresh()->show);
    }
    public function testCopyPreservesSettingsAndHidesNewNode()
    {
        $node=$this->node('first');$this->batch(['action'=>'copy','nodes'=>[['type'=>'vmess','id'=>$node->id]]]);
        $copy=ServerVmess::where('id','!=',$node->id)->first();
        $this->assertNotNull($copy);$this->assertSame($node->tlsSettings,$copy->tlsSettings);$this->assertSame(0,(int)$copy->show);$this->assertSame(1,(int)$node->fresh()->show);
    }
    public function testSortPatchWorksForLegacyProtocolSaveValidator()
    {
        $node=$this->node('first');
        $this->batch(['action'=>'update','nodes'=>[['type'=>'vmess','id'=>$node->id]],'changes'=>['sort'=>42]]);
        $this->assertSame(42,(int)$node->fresh()->sort);
        $this->assertSame($node->tlsSettings,$node->fresh()->tlsSettings);
    }
    private function filtered($params)
    {
        $builder=User::query();$method=new \ReflectionMethod(UserController::class,'filter');$method->setAccessible(true);
        $method->invoke(new UserController(),Request::create('/users','GET',$params),$builder);return $builder->pluck('id')->all();
    }
    public function testBalanceAndCommissionFiltersMatchTogetherIncludingZero()
    {
        $a=$this->user(['balance'=>1999,'commission_balance'=>201]);$this->user(['balance'=>1999,'commission_balance'=>0]);$zero=$this->user(['balance'=>0,'commission_balance'=>0]);
        $this->assertSame([$a->id],$this->filtered(['filter'=>[['key'=>'balance','condition'=>'>=','value'=>1999],['key'=>'commission_balance','condition'=>'=','value'=>201]]]));
        $this->assertSame([$zero->id],$this->filtered(['filter'=>[['key'=>'balance','condition'=>'=','value'=>0]]]));
    }
    public function testSelectedIdsScopeAlsoAppliesToBulkFilter()
    {
        $a=$this->user(['balance'=>10]);$b=$this->user(['balance'=>10]);
        $this->assertSame([$b->id],$this->filtered(['ids'=>[$b->id],'filter'=>[['key'=>'balance','condition'=>'=','value'=>10]]]));
    }
    public function testTotalTrafficFilterUsesUploadPlusDownloadInGB()
    {
        $a=$this->user(['u'=>1073741824,'d'=>1073741824]);$this->user(['u'=>0,'d'=>0]);
        $this->assertSame([$a->id],$this->filtered(['filter'=>[['key'=>'total_used','condition'=>'>=','value'=>2]]]));
    }
    public function testBulkFilterRejectsUnknownColumnsAndOperators()
    {
        foreach ([['key'=>'password','condition'=>'=','value'=>'x'],['key'=>'balance','condition'=>'OR 1=1','value'=>0]] as $filter) {
            try {$this->filtered(['filter'=>[$filter]]);$this->fail('Invalid filter must be rejected');}
            catch (ValidationException $e) {$this->assertNotEmpty($e->errors());}
        }
    }
    public function testOriginalUserTableSortFieldsRemainWhitelisted()
    {
        foreach (['banned', 'plan_id', 'group_id'] as $field) {
            $validator = $this->factory->make(['sort' => $field, 'sort_type' => 'ASC'], (new UserFetch())->rules());
            $this->assertTrue($validator->passes(), $field);
        }
        $this->assertTrue($this->factory->make(['sort' => 'password'], (new UserFetch())->rules())->fails());
        $this->assertTrue($this->factory->make(['sort' => 'plan_name'], (new UserFetch())->rules())->fails());
    }
}
