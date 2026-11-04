<?php
namespace Tests\P1;

use App\Http\Controllers\V1\Admin\UserController;
use App\Http\Controllers\V1\Admin\PlanController;
use App\Http\Requests\Admin\UserUpdate;
use App\Http\Requests\Admin\PlanSave;
use App\Models\Plan;
use App\Services\OrderService;
use App\Services\UserGroupSchema;
use Illuminate\Support\Facades\DB;

class UserGroupTest extends DatabaseTestCase
{
    private $plan;
    protected function setUp(): void
    {
        parent::setUp();$schema=DB::connection()->getSchemaBuilder();$schema->dropIfExists('v2_server_group');
        $schema->create('v2_server_group',function ($t) {$t->increments('id');$t->string('name');});
        foreach ([1=>'默认组',2=>'新套餐组',3=>'自定义组'] as $id=>$name) DB::table('v2_server_group')->insert(compact('id','name'));
        $this->plan=Plan::create(['name'=>'原套餐','group_id'=>1,'month_price'=>1000,'transfer_enable'=>100,'device_limit'=>3,'speed_limit'=>100]);
    }

    private function update($user,array $changes=[],bool $staff=false): void
    {
        $request=$staff ? new class extends \App\Http\Requests\Staff\UserUpdate {
            public $values;public function validated($key=null,$default=null) {return $this->values;}
        } : new class extends UserUpdate {
            public $values;public function validated($key=null,$default=null) {return $this->values;}
        };
        $request->values=$changes+['email'=>$user->email,'is_admin'=>0,'is_staff'=>0,'banned'=>0];$request->merge(['id'=>$user->id]);
        if ($staff) {(new \App\Http\Controllers\V1\Staff\UserController())->update($request);return;}
        (new UserController())->update($request);
    }

    public function testAdministratorCanChooseCustomGroupWithoutChangingPlanAndLaterRestoreFollowing(): void
    {
        $user=$this->user(['plan_id'=>$this->plan->id,'group_id'=>1]);
        $this->update($user,['group_mode'=>1,'group_id'=>3]);
        $this->assertSame(3,(int)$user->fresh()->group_id);$this->assertSame(1,(int)$user->fresh()->group_mode);
        $this->assertSame($this->plan->id,(int)$user->fresh()->plan_id);
        $this->update($user->fresh(),['plan_id'=>$this->plan->id,'balance'=>500]);
        $this->assertSame(3,(int)$user->fresh()->group_id);$this->assertSame(1,(int)$user->fresh()->group_mode);
        $this->update($user->fresh(),['group_mode'=>0]);
        $this->assertSame(1,(int)$user->fresh()->group_id);$this->assertSame(0,(int)$user->fresh()->group_mode);
    }

    public function testNoPlanAndLegacyPartialUpdatesPreserveTheCorrectGroupMode(): void
    {
        $user=$this->user(['plan_id'=>$this->plan->id,'group_id'=>1]);$this->update($user,['balance'=>123]);
        $this->assertSame(1,(int)$user->fresh()->group_id);$this->assertSame($this->plan->id,(int)$user->fresh()->plan_id);
        $this->update($user->fresh(),['plan_id'=>null,'group_mode'=>1,'group_id'=>3]);
        $this->assertNull($user->fresh()->plan_id);$this->assertSame(3,(int)$user->fresh()->group_id);
        $this->update($user->fresh(),['group_mode'=>0]);$this->assertNull($user->fresh()->group_id);
    }

    public function testZeroPlanIdIsRejectedWithoutChangingUserForBothAdminAndStaff(): void
    {
        foreach ([false,true] as $staff) {
            $user=$this->user(['plan_id'=>$this->plan->id,'group_id'=>3,'group_mode'=>1]);
            try {$this->update($user,['plan_id'=>0],$staff);$this->fail('A nonexistent plan ID was saved');}
            catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {$this->assertSame(500,$error->getStatusCode());}
            $this->assertSame($this->plan->id,(int)$user->fresh()->plan_id);$this->assertSame(3,(int)$user->fresh()->group_id);
            $this->assertSame(1,(int)$user->fresh()->group_mode);$this->assertSame(0,DB::transactionLevel());
        }
    }

    public function testStaffUpdatesKeepCustomGroupsWithoutGainingAnOverrideApi(): void
    {
        $user=$this->user(['plan_id'=>$this->plan->id,'group_id'=>3,'group_mode'=>1]);$other=Plan::create(['group_id'=>2]);
        $this->update($user,['plan_id'=>$other->id,'balance'=>500],true);
        $this->assertSame(3,(int)$user->fresh()->group_id);$this->assertSame(1,(int)$user->fresh()->group_mode);
        $this->assertSame($other->id,(int)$user->fresh()->plan_id);
        foreach ([new \App\Http\Requests\Staff\UserUpdate(),new \App\Http\Requests\User\UserUpdate()] as $request) {
            $this->assertArrayNotHasKey('group_mode',$request->rules());$this->assertArrayNotHasKey('group_id',$request->rules());
        }
    }

    public function testCustomGroupValidationRejectsMissingAndInvalidGroups(): void
    {
        app('validator')->setPresenceVerifier(new \Illuminate\Validation\DatabasePresenceVerifier(app('db')));
        $rules=(new UserUpdate())->rules();$rules=array_intersect_key($rules,array_flip(['group_mode','group_id']));
        foreach ([['group_mode'=>2],['group_mode'=>1],['group_mode'=>1,'group_id'=>null],['group_mode'=>1,'group_id'=>999],['group_mode'=>1,'group_id'=>[3]]] as $params)
            $this->assertTrue(app('validator')->make($params,$rules)->fails());
        $this->assertFalse(app('validator')->make(['group_mode'=>1,'group_id'=>3],$rules)->fails());
        $this->assertFalse(app('validator')->make(['group_mode'=>0],$rules)->fails());
    }

    public function testRenewalAlwaysKeepsCustomGroupButStillExtendsTheSubscription(): void
    {
        config(['v2board.reset_custom_group_on_purchase'=>1]);$expiry=time()+86400;
        $user=$this->user(['plan_id'=>$this->plan->id,'group_id'=>3,'group_mode'=>1,'expired_at'=>$expiry]);
        $order=$this->order($user,['status'=>1,'type'=>2,'plan_id'=>$this->plan->id,'period'=>'month_price']);
        (new OrderService($order))->open();$saved=$user->fresh();
        $this->assertSame(3,(int)$saved->group_id);$this->assertSame(1,(int)$saved->group_mode);
        $this->assertSame(strtotime('+1 month',$expiry),(int)$saved->expired_at);
        $this->assertSame(100*1073741824,(int)$saved->transfer_enable);$this->assertSame(3,(int)$order->fresh()->status);
    }

    public function purchaseCases(): array
    {
        return [[0,1,'month_price'],[1,1,'month_price'],[0,3,'month_price'],[1,3,'month_price'],[0,1,'onetime_price'],[1,1,'onetime_price']];
    }

    /** @dataProvider purchaseCases */
    public function testNewPurchaseAndPlanChangeRespectTheConfiguredReset(int $reset,int $type,string $period): void
    {
        config(['v2board.reset_custom_group_on_purchase'=>$reset]);$target=Plan::create(['group_id'=>2,'transfer_enable'=>200]);
        $user=$this->user(['plan_id'=>$this->plan->id,'group_id'=>3,'group_mode'=>1,'expired_at'=>time()+86400]);
        $order=$this->order($user,['status'=>1,'type'=>$type,'plan_id'=>$target->id,'period'=>$period]);
        $service=new OrderService($order);$service->open();$service->open();$saved=$user->fresh();
        $this->assertSame($reset ? 2 : 3,(int)$saved->group_id);$this->assertSame($reset ? 0 : 1,(int)$saved->group_mode);
        $this->assertSame($target->id,(int)$saved->plan_id);$this->assertSame(3,(int)$order->fresh()->status);
    }

    public function testRechargeAndTrafficResetNeverResetTheCustomGroup(): void
    {
        config(['v2board.reset_custom_group_on_purchase'=>1]);
        $user=$this->user(['plan_id'=>$this->plan->id,'group_id'=>3,'group_mode'=>1,'expired_at'=>time()+86400,'u'=>100]);
        foreach ([[9,'deposit'],[4,'reset_price']] as [$type,$period]) {
            $order=$this->order($user,['status'=>1,'type'=>$type,'period'=>$period,'plan_id'=>$this->plan->id]);(new OrderService($order))->open();
            $this->assertSame(3,(int)$user->fresh()->group_id);$this->assertSame(1,(int)$user->fresh()->group_mode);
        }
        $this->assertSame(10000,(int)$user->fresh()->balance);$this->assertSame(0,(int)$user->fresh()->u);
    }

    public function testLongLivedWorkersUseTheSavedPurchasePolicyWithoutARestart(): void
    {
        $original=app()->basePath();$dir=sys_get_temp_dir().'/v2board-group-policy-'.bin2hex(random_bytes(5));mkdir($dir);mkdir($dir.'/config');
        Environment::$app->setBasePath($dir);
        try {
            foreach ([0,1] as $saved) {
                config(['v2board.reset_custom_group_on_purchase'=>1-$saved]);
                file_put_contents($dir.'/config/v2board.php','<?php return '.var_export(['reset_custom_group_on_purchase'=>$saved],true).';');
                $user=$this->user(['group_id'=>3,'group_mode'=>1]);
                \App\Services\UserGroupService::applyPlan($user,$this->plan,1);
                $this->assertSame($saved ? 1 : 3,(int)$user->group_id);$this->assertSame($saved ? 0 : 1,(int)$user->group_mode);
            }
        } finally {Environment::$app->setBasePath($original);unlink($dir.'/config/v2board.php');rmdir($dir.'/config');rmdir($dir);}
    }

    public function testPlanForceUpdateSkipsAllFieldsForCustomGroupUsers(): void
    {
        $follow=$this->user(['plan_id'=>$this->plan->id,'group_id'=>1,'transfer_enable'=>500,'speed_limit'=>10]);
        $custom=$this->user(['plan_id'=>$this->plan->id,'group_id'=>3,'group_mode'=>1,'transfer_enable'=>500,'speed_limit'=>10]);
        $request=new class extends PlanSave {public $values;public function validated($key=null,$default=null) {return $this->values;}};
        $request->merge(['id'=>$this->plan->id,'force_update'=>1]);$request->values=['name'=>'修改后的套餐','group_id'=>2,'transfer_enable'=>200,'speed_limit'=>100,'device_limit'=>5];
        (new PlanController())->save($request);
        $this->assertSame(2,(int)$follow->fresh()->group_id);$this->assertSame(200*1073741824,(int)$follow->fresh()->transfer_enable);
        $this->assertSame(3,(int)$custom->fresh()->group_id);$this->assertSame(500,(int)$custom->fresh()->transfer_enable);$this->assertSame(10,(int)$custom->fresh()->speed_limit);
    }

    public function testSchemaUpgradeDoesNotRewriteGroupsAndCanBeReplayed(): void
    {
        $user=$this->user(['plan_id'=>$this->plan->id,'group_id'=>3]);
        DB::statement('ALTER TABLE v2_user DROP COLUMN group_mode');(new UserGroupSchema())->ensure();
        $this->assertSame(3,(int)$user->fresh()->group_id);$this->assertSame(0,(int)$user->fresh()->group_mode);
        $user->refresh();$user->group_mode=1;$user->save();(new UserGroupSchema())->ensure();
        $this->assertSame(1,(int)$user->fresh()->group_mode);
        $root=getenv('P1_SOURCE_ROOT') ?: dirname(__DIR__,2);
        $this->assertMatchesRegularExpression('/ALTER TABLE `v2_user` ADD `group_mode` tinyint unsigned NOT NULL DEFAULT \'0\';/',file_get_contents($root.'/database/update.sql'));
        $this->assertStringContainsString('reset_custom_group_on_purchase',(new \App\Http\Controllers\V1\Admin\ConfigController())->fetch(\Illuminate\Http\Request::create('/','GET'))->getContent());
        $rules=(new \App\Http\Requests\Admin\ConfigSave())->rules();$this->assertSame('in:0,1',$rules['reset_custom_group_on_purchase']);
    }

    public function testResumedInstallAddsTheGroupModeBeforeVerifyingTheExistingUserTable(): void
    {
        if (DB::connection()->getDriverName()==='sqlite' && DB::connection()->getDatabaseName()===':memory:')
            $this->markTestSkipped('Resumed installation requires a database that survives reconnects.');
        $user=$this->user(['group_id'=>3]);DB::statement('ALTER TABLE v2_user DROP COLUMN group_mode');
        if (!class_exists('File',false)) class_alias(\Illuminate\Support\Facades\File::class,'File');
        if (!class_exists('Artisan',false)) class_alias(\Illuminate\Support\Facades\Artisan::class,'Artisan');
        $base=app()->basePath();$dir=sys_get_temp_dir().'/v2board-resume-group-'.bin2hex(random_bytes(5));mkdir($dir);
        app()->setBasePath($dir);$cache=app()->getCachedConfigPath();$this->assertStringStartsWith($dir,$cache);mkdir(dirname($cache),0777,true);
        file_put_contents($cache,'<?php return '.var_export(config()->all(),true).';');
        app()->instance('files',new \Illuminate\Filesystem\Filesystem());
        \Illuminate\Support\Facades\File::shouldReceive('exists')->with($dir.'/.env')->once()->andReturn(true);
        \Illuminate\Support\Facades\File::shouldReceive('get')->with($dir.'/database/install.sql')->once()
            ->andReturn('CREATE TABLE `v2_user` (`id` int, `group_mode` tinyint unsigned NOT NULL DEFAULT 0);');
        \Illuminate\Support\Facades\Artisan::swap(new class {
            public function call($command) {if (!in_array($command,['config:clear','config:cache'],true)) throw new \RuntimeException('Unexpected command');return 0;}
        });
        app()->instance(\App\Services\DatabaseUpgrade::class,new class extends \App\Services\DatabaseUpgrade {
            public function verify(string $sql): void {parent::verify($sql);throw new \RuntimeException('fixture schema verified');}
        });
        $command=new class extends \App\Console\Commands\V2boardInstall {
            public $errors=[];public function option($key=null) {return $key==='resume';}
            public function info($string,$verbosity=null) {}
            public function error($string,$verbosity=null) {$this->errors[]=$string;}
        };
        try {
            $this->assertSame(1,$command->handle());$this->assertSame(['fixture schema verified'],$command->errors);
            $this->assertSame(0,(int)$user->fresh()->group_mode);$this->assertSame(3,(int)$user->fresh()->group_id);
        } finally {app()->setBasePath($base);unlink($cache);rmdir(dirname($cache));rmdir($dir.'/bootstrap');rmdir($dir);}
    }
}
