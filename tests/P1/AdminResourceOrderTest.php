<?php
namespace Tests\P1;

use App\Http\Controllers\V1\Admin\PaymentController;
use App\Http\Controllers\V1\Admin\PlanController;
use App\Http\Requests\Admin\PlanSort;
use App\Models\Payment;
use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminResourceOrderTest extends DatabaseTestCase
{
    public function testPlanAndPaymentSortPayloadsPersistTheFullSubmittedOrder(): void
    {
        Request::macro('validate', function ($rules, $messages = []) { return app('validator')->make($this->all(), $rules, $messages)->validate(); });
        config(['v2board.app_url' => 'https://panel.example.test']);
        DB::getSchemaBuilder()->table('v2_payment', function ($table) { $table->integer('sort')->default(0); });
        $plans=[]; $payments=[];
        for ($i=1; $i<=3; $i++) {
            $plans[]=Plan::create(['name'=>'Plan '.$i])->id;
            $payments[]=Payment::create(['uuid'=>'fixture-'.$i,'payment'=>'EPay','config'=>[]])->id;
        }
        $planIds=[$plans[1],$plans[2],$plans[0]];
        $request=new PlanSort(); $request->merge(['plan_ids'=>$planIds]);
        $this->assertTrue((new PlanController())->sort($request)->getData(true)['data']);
        $this->assertSame($planIds,Plan::orderBy('sort')->pluck('id')->all());
        $this->assertSame([1,2,3],Plan::orderBy('sort')->pluck('sort')->all());
        $paymentIds=[$payments[2],$payments[0],$payments[1]];
        $this->assertTrue((new PaymentController())->sort(Request::create('/','POST',['ids'=>$paymentIds]))->getData(true)['data']);
        $this->assertSame($paymentIds,Payment::orderBy('sort')->pluck('id')->all());
        $this->assertSame([1,2,3],Payment::orderBy('sort')->pluck('sort')->all());
        $this->assertSame($paymentIds,array_column((new PaymentController())->fetch()->getData(true)['data'],'id'));
        $this->assertSame(3,Plan::count()); $this->assertSame(3,Payment::count());
    }
}
