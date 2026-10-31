<?php

namespace Tests\P1;

use App\Http\Middleware\SubscriptionAudit;
use App\Jobs\WriteSubscriptionAuditJob;
use App\Services\SubscriptionAuditSchema;
use App\Services\SubscriptionAuditService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class SubscriptionLifetimeCountTest extends DatabaseTestCase
{
    private $audit;
    protected function setUp(): void
    {
        parent::setUp();
        foreach ([SubscriptionAuditService::TABLE, SubscriptionAuditService::SETTINGS] as $table) DB::getSchemaBuilder()->dropIfExists($table);
        (new SubscriptionAuditSchema())->ensure(); $this->audit = new SubscriptionAuditService();
    }
    private function event(int $id, array $extra = []): array
    {
        return array_merge(['event_id' => bin2hex(random_bytes(16)), 'user_id' => $id, 'email' => 'fixture@example.test',
            'ip' => '192.0.2.10', 'ua' => 'fixture-client', 'format' => '', 'result' => 'success', 'status' => 200, 'requested_at' => time()], $extra);
    }
    private function total(int $id): int { return (int)($this->audit->countsForUsers([$id])[$id] ?? 0); }
    private function request($user): Request
    {
        $r = Request::create('/subscribe', 'GET', [], [], [], ['REMOTE_ADDR' => '192.0.2.10']); $r->user = $user; return $r;
    }
    public function testRequestIsCountedBeforeTheDetailsQueueAndRetriesCannotRecountIt(): void
    {
        $u = $this->user();
        (new SubscriptionAudit())->handle($this->request($u), fn() => new Response('subscription'));
        $this->assertSame(1, $this->total($u->id)); $this->assertSame(0, DB::table(SubscriptionAuditService::TABLE)->count());
        $job = Environment::$queue->pushed(WriteSubscriptionAuditJob::class)->first();
        $this->assertTrue($job->event['count_recorded']); $job->handle($this->audit); $job->handle($this->audit);
        DB::table(SubscriptionAuditService::TABLE)->delete(); $job->handle($this->audit);
        $this->assertSame(1, $this->total($u->id));
        $this->assertSame(0, DB::table(SubscriptionAuditService::LEGACY_EVENTS)->count());
        (new SubscriptionAudit())->handle($this->request($u), fn() => new Response('next'));
        $this->assertSame(2, $this->total($u->id));
    }
    public function testExpirationLimitsAndManualTruncationPreserveTotals(): void
    {
        $u = $this->user(); $this->audit->configure(1, 1);
        for ($i = 0; $i < 3; $i++) $this->audit->append($this->event($u->id));
        $this->assertSame(3, $this->total($u->id)); $this->assertSame(1, DB::table(SubscriptionAuditService::TABLE)->count());
        DB::table(SubscriptionAuditService::TABLE)->update(['requested_at' => time() - 2 * 86400]); $this->audit->prune();
        $this->assertSame(3, $this->total($u->id));
        DB::table(SubscriptionAuditService::TABLE)->truncate(); (new SubscriptionAuditSchema())->ensure();
        $this->assertSame(3, $this->total($u->id));
        DB::getSchemaBuilder()->drop(SubscriptionAuditService::TABLE); DB::getSchemaBuilder()->drop(SubscriptionAuditService::SETTINGS);
        (new SubscriptionAuditSchema())->ensure(); $this->assertSame(3, $this->total($u->id));
        $this->audit->append($this->event($u->id)); $this->assertSame(4, $this->total($u->id));
    }
    public function testUpgradeBackfillsOldRowsExactlyOnceAndSurvivesDetailDeletion(): void
    {
        $u = $this->user(); $old = $this->event($u->id); $other = $this->event($u->id);
        DB::table(SubscriptionAuditService::TABLE)->insert([$old, $other]);
        (new SubscriptionAuditSchema())->ensure(); (new SubscriptionAuditSchema())->ensure();
        $this->assertSame(2, $this->total($u->id));
        DB::table(SubscriptionAuditService::TABLE)->truncate(); (new SubscriptionAuditSchema())->ensure();
        (new WriteSubscriptionAuditJob($old))->handle($this->audit);
        $this->assertSame(2, $this->total($u->id)); $this->assertSame(0, DB::table(SubscriptionAuditService::TABLE)->count());
        $this->audit->append($this->event($u->id)); $this->assertSame(3, $this->total($u->id));
    }
    public function testLegacyJobsRetryAfterEvictionOrExpiryWithoutDuplicatingTotals(): void
    {
        $u = $this->user(); $this->audit->configure(1, 1); $event = $this->event($u->id);
        $job = new WriteSubscriptionAuditJob($event); $job->handle($this->audit);
        $this->audit->append($this->event($u->id, ['requested_at' => time() + 1])); $job->handle($this->audit);
        $late = new WriteSubscriptionAuditJob($this->event($u->id, ['requested_at' => time() - 40 * 86400])); $late->handle($this->audit); $late->handle($this->audit);
        $this->assertSame(3, $this->total($u->id)); $this->assertSame(1, DB::table(SubscriptionAuditService::TABLE)->count());
    }
    public function testDisabledDetailsAndQueueOutageStillCountAuthenticatedPulls(): void
    {
        $u = $this->user(); config(['subscription_audit.enabled' => false]);
        $response = (new SubscriptionAudit())->handle($this->request($u), fn() => new Response('ok'));
        $this->assertSame('ok', $response->getContent()); $this->assertSame(1, $this->total($u->id));
        Environment::$queue->assertNotPushed(WriteSubscriptionAuditJob::class);
        config(['subscription_audit.enabled' => true]);
        $bus = \Mockery::mock(\Illuminate\Contracts\Bus\Dispatcher::class); $bus->shouldReceive('dispatch')->andThrow(new \RuntimeException('fixture queue unavailable')); app()->instance(\Illuminate\Contracts\Bus\Dispatcher::class, $bus);
        (new SubscriptionAudit())->handle($this->request($u), fn() => new Response('still works'));
        $this->assertSame(2, $this->total($u->id));
    }
    public function testFailedResponsesCountButUnauthenticatedRequestsDoNot(): void
    {
        $u = $this->user(); (new SubscriptionAudit())->handle($this->request($u), fn() => new Response('failed', 503));
        (new SubscriptionAudit())->handle(Request::create('/subscribe'), fn() => new Response('anonymous', 403));
        $this->assertSame(1, $this->total($u->id));
    }
    public function testFailedImmediateCountCanBeRepairedOnceByTheQueuedWriter(): void
    {
        $u = $this->user(); $failing = \Mockery::mock(SubscriptionAuditService::class);
        $failing->shouldReceive('recordPull')->once()->andThrow(new \RuntimeException('fixture database write failure'));
        app()->instance(SubscriptionAuditService::class, $failing);
        $response = (new SubscriptionAudit())->handle($this->request($u), fn() => new Response('ok'));
        $this->assertSame('ok', $response->getContent()); $this->assertSame(0, $this->total($u->id));
        $job = Environment::$queue->pushed(WriteSubscriptionAuditJob::class)->first(); $this->assertFalse($job->event['count_recorded']);
        $job->handle($this->audit); DB::table(SubscriptionAuditService::TABLE)->delete(); $job->handle($this->audit);
        $this->assertSame(1, $this->total($u->id));
    }
    public function testNormalIncrementUsesOneSqlStatementAndSupportsLargeTotals(): void
    {
        $u = $this->user(); DB::connection()->enableQueryLog(); DB::connection()->flushQueryLog();
        try { $this->audit->recordPull($u->id); $this->assertCount(1, DB::connection()->getQueryLog()); }
        finally { DB::connection()->disableQueryLog(); }
        DB::table(SubscriptionAuditService::TOTALS)->where('user_id', $u->id)->update(['pull_count' => 3000000000]);
        $this->audit->recordPull($u->id); $this->assertSame(3000000001, $this->total($u->id));
    }
    public function testConcurrentRequestIncrementsDoNotLoseCounts(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || !function_exists('proc_open')) $this->markTestSkipped('Separate MySQL worker processes required');
        $u = $this->user(); $children = [];
        try {
            for ($i = 0; $i < 2; $i++) {
                $pipes = []; $process = proc_open([PHP_BINARY, '-d', 'error_reporting=24575', __DIR__ . '/SubscriptionPullCountWorker.php', (string)$u->id, '25'], [['pipe','r'],['pipe','w'],['pipe','w']], $pipes);
                $this->assertIsResource($process); fclose($pipes[0]); $children[] = [$process, $pipes];
            }
            foreach ($children as [$process, $pipes]) {
                $out = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
                $this->assertSame(0, proc_close($process), $out . $errors);
            }
            $children = []; $this->assertSame(50, $this->total($u->id));
        } finally { foreach ($children as [$process, $pipes]) { if (is_resource($process)) proc_terminate($process); } }
    }
}
