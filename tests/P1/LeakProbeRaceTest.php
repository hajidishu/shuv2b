<?php

namespace Tests\P1;

use App\Models\Plan;
use App\Models\ServerVmess;
use App\Models\User;
use App\Services\LeakProbeSchema;
use App\Services\LeakProbeService as Probe;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

class LeakProbeRaceTest extends DatabaseTestCase
{
    private $probe;
    private $plan;
    private $nodes;
    private $schemaReady = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('P1_DB_DRIVER') !== 'mysql') {
            $this->markTestSkipped('Set P1_DB_DRIVER=mysql and use an isolated v2board_p1_* MariaDB database.');
        }

        $version = DB::selectOne('SELECT VERSION() AS version')->version ?? '';
        if (stripos($version, 'mariadb') === false) {
            $this->markTestSkipped('This race test requires MariaDB row-lock behavior.');
        }

        $schema = DB::connection()->getSchemaBuilder();
        foreach (['event', 'active_user', 'member', 'round', 'task', 'node'] as $suffix) {
            $schema->dropIfExists('v2_leak_probe_' . $suffix);
        }
        $schema->dropIfExists('v2_server_vmess');
        $schema->create('v2_server_vmess', function (Blueprint $t) {
            $t->increments('id');
            foreach (['name', 'host', 'port', 'network', 'protocol'] as $field) $t->string($field)->nullable();
            foreach (['group_id', 'networkSettings', 'tlsSettings'] as $field) $t->text($field)->nullable();
            foreach (['server_port', 'tls', 'show', 'parent_id', 'created_at', 'updated_at'] as $field) $t->integer($field)->nullable();
        });
        (new LeakProbeSchema())->ensure();
        $this->schemaReady = true;

        $this->probe = new Probe();
        $this->plan = Plan::create(['name' => 'race-probe', 'group_id' => 1]);
        $this->nodes = [];
        for ($i = 1; $i <= 4; $i++) {
            $node = ServerVmess::create([
                'name' => 'race-probe-' . $i,
                'host' => '203.0.113.' . $i,
                'port' => '443',
                'server_port' => 443,
                'network' => 'tcp',
                'protocol' => 'vmess',
                'group_id' => [1],
                'networkSettings' => [],
                'tlsSettings' => [],
                'tls' => 0,
                'show' => 1,
            ]);
            $this->nodes[] = $this->probe->registerNode('vmess', $node->id);
        }
    }

    protected function tearDown(): void
    {
        if ($this->schemaReady) {
            $schema = DB::connection()->getSchemaBuilder();
            foreach (['event', 'active_user', 'member', 'round', 'task', 'node'] as $suffix) {
                $schema->dropIfExists('v2_leak_probe_' . $suffix);
            }
            $schema->dropIfExists('v2_server_vmess');
        }
        parent::tearDown();
    }

    private function waitForFile(string $path, ?Process $process = null, float $seconds = 10): void
    {
        $deadline = microtime(true) + $seconds;
        while (!is_file($path)) {
            if ($process && !$process->isRunning()) {
                $this->fail('Worker exited before writing ' . basename($path) . ': ' . $process->getOutput() . $process->getErrorOutput());
            }
            if (microtime(true) >= $deadline) $this->fail('Timed out waiting for ' . basename($path));
            usleep(10000);
            clearstatcache(true, $path);
        }
    }

    private function waitForDeleteLockWait(int $connectionId, Process $process): void
    {
        $deadline = microtime(true) + 8;
        do {
            if (!$process->isRunning()) {
                $this->fail('Delete worker exited before entering the row-lock wait: ' . $process->getOutput() . $process->getErrorOutput());
            }
            $wait = DB::selectOne(
                'SELECT COUNT(*) AS waiting
                 FROM information_schema.INNODB_LOCK_WAITS w
                 JOIN information_schema.INNODB_TRX t ON t.trx_id = w.requesting_trx_id
                 WHERE t.trx_mysql_thread_id = ?',
                [$connectionId]
            );
            if ((int)$wait->waiting > 0) return;
            // MariaDB caches InnoDB lock metadata for about 100ms; polling faster can repeat a stale view.
            usleep(200000);
        } while (microtime(true) < $deadline);

        $this->fail('Delete worker did not wait on the candidate row locked by advance.');
    }

    public function testDeleteWaitsForAdvanceLockThenRetryCompletesWithoutAttributionOrRolledBackWrites(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            User::create([
                'email' => 'leak-race-' . $i . '@example.test',
                'plan_id' => $this->plan->id,
                'group_id' => 1,
                'transfer_enable' => 100000,
                'expired_at' => time() + 86400,
            ]);
        }

        $scope = ['scope' => 'plans', 'plan_ids' => [$this->plan->id]];
        $preview = $this->probe->preview($scope);
        $taskId = $this->probe->create(array_merge($scope, [
            'name' => 'candidate-delete-race',
            'observation_hours' => 24,
            'a_node_id' => $this->nodes[0],
            'b_node_id' => $this->nodes[1],
            'expected_count' => $preview['count'],
            'expected_hash' => $preview['hash'],
        ]), 'admin@example.test');
        $task = DB::table(Probe::TASK)->where('id', $taskId)->first();
        $roundId = (int)$task->current_round_id;
        $candidateIds = DB::table(Probe::MEMBER)->where('round_id', $roundId)->where('branch', 'a')->orderBy('user_id')->pluck('user_id')->all();
        $this->assertCount(2, $candidateIds);
        $deletedId = (int)$candidateIds[0];
        $this->probe->mutate([
            'id' => $taskId,
            'round_id' => $roundId,
            'action' => 'results',
            'result_a' => 'abnormal',
            'result_b' => 'normal',
        ], 'admin@example.test');
        $eventsBeforeAdvance = DB::table(Probe::EVENT)->where('task_id', $taskId)->count();

        $gate = sys_get_temp_dir() . '/v2board-leak-probe-race-' . bin2hex(random_bytes(8));
        mkdir($gate);
        $phpArgs = ['-d', 'error_reporting=24575'];
        $advance = new Process(array_merge([PHP_BINARY], $phpArgs, [
            __DIR__ . '/LeakProbeRaceWorker.php', 'advance-fail', (string)$taskId, (string)$roundId, $gate,
        ]), dirname(__DIR__, 2));
        $advance->setTimeout(25);
        $delete = null;

        try {
            $advance->start();
            $this->waitForFile($gate . '/candidate-locked', $advance);

            $delete = new Process(array_merge([PHP_BINARY], $phpArgs, [
                __DIR__ . '/LeakProbeRaceWorker.php', 'delete-user', (string)$deletedId, $gate,
            ]), dirname(__DIR__, 2));
            $delete->setTimeout(25);
            $delete->start();
            $this->waitForFile($gate . '/delete-attempting', $delete);
            $connectionId = (int)file_get_contents($gate . '/delete-connection-id');
            $this->waitForDeleteLockWait($connectionId, $delete);
            $this->assertFileDoesNotExist($gate . '/delete-done');

            file_put_contents($gate . '/release-advance', 'go');
            $advance->wait();
            $delete->wait();
            $this->assertTrue($advance->isSuccessful(), $advance->getOutput() . $advance->getErrorOutput());
            $this->assertTrue($delete->isSuccessful(), $delete->getOutput() . $delete->getErrorOutput());
            $this->assertSame(['status' => 422], json_decode(trim($advance->getOutput()), true));
            $this->assertSame(['deleted' => true], json_decode(trim($delete->getOutput()), true));

            // The invalid next-round nodes abort after closeRound(); all transaction writes must roll back.
            $this->assertSame($roundId, (int)DB::table(Probe::TASK)->where('id', $taskId)->value('current_round_id'));
            $this->assertSame(1, DB::table(Probe::ROUND)->where('task_id', $taskId)->count());
            $this->assertSame($eventsBeforeAdvance, DB::table(Probe::EVENT)->where('task_id', $taskId)->count());
            $this->assertNull(DB::table(Probe::ROUND)->where('id', $roundId)->value('closed_at'));
            $this->assertSame(2, DB::table(Probe::NODE)->where('state', 'active')->count());
            $this->assertNull(User::find($deletedId));

            // Deletion committed before the retry. One surviving member plus one historical deletion must not identify.
            $this->probe->mutate([
                'id' => $taskId,
                'round_id' => $roundId,
                'action' => 'advance',
                'branch' => 'a',
            ], 'admin@example.test');
            $completed = DB::table(Probe::TASK)->where('id', $taskId)->first();
            $this->assertSame('completed', $completed->status);
            $this->assertNull($completed->final_branch);
            $this->assertSame(1, DB::table(Probe::ROUND)->where('task_id', $taskId)->count());
            $this->assertSame(4, DB::table(Probe::MEMBER)->where('round_id', $roundId)->count());
            $this->assertSame(0, DB::table(Probe::ACTIVE)->where('task_id', $taskId)->count());
            $this->assertSame(4, DB::table(Probe::NODE)->where('state', 'ready')->count());
            $this->assertSame($eventsBeforeAdvance + 2, DB::table(Probe::EVENT)->where('task_id', $taskId)->count());
        } finally {
            file_put_contents($gate . '/release-advance', 'go');
            foreach ([$advance, $delete] as $process) {
                if ($process && $process->isRunning()) $process->stop();
            }
            foreach (glob($gate . '/*') ?: [] as $path) unlink($path);
            rmdir($gate);
        }
    }
}
