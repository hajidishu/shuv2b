<?php

namespace Tests\P1;

use App\Http\Controllers\V1\Admin\SystemController;
use App\Models\Log;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SystemLogTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Request::macro('validate', function ($rules) { return app('validator')->make($this->all(), $rules)->validate(); });
        DB::getSchemaBuilder()->dropIfExists('v2_log');
        DB::getSchemaBuilder()->create('v2_log', function ($table) {
            $table->increments('id');
            foreach (['title', 'level', 'host', 'uri', 'method', 'ip'] as $key) $table->string($key);
            $table->text('data')->nullable(); $table->text('context')->nullable();
            $table->integer('created_at'); $table->integer('updated_at');
        });
        for ($i = 1; $i <= 95; $i++) Log::create([
            'title' => 'Synthetic log ' . $i, 'level' => $i % 2 ? 'ERROR' : 'INFO',
            'host' => 'https://panel.example.test', 'uri' => $i % 2 ? '/api/v1/server/UniProxy/alive?node_id=1' : '/api/v1/user/info',
            'method' => $i % 2 ? 'POST' : 'GET', 'ip' => $i % 3 ? '192.0.2.10' : '2001:db8::10',
            'created_at' => 1700000000, 'updated_at' => 1700000000,
        ]);
    }

    private function fetch(array $parameters = []): array
    {
        $request = Request::create('/', 'GET', $parameters);
        app()->instance('request', $request);
        return (new SystemController())->getSystemLog($request)->getData(true);
    }

    public function testPageSizeAndStablePagesUseTheRequestedRowCount(): void
    {
        foreach ([10, 20, 50, 100] as $size) {
            $body = $this->fetch(['page_size' => $size]);
            $this->assertSame(95, $body['total']);
            $this->assertCount(min(95, $size), $body['data']);
            $this->assertSame(range(95, max(1, 96 - $size)), array_column($body['data'], 'id'));
        }
        $this->assertSame(range(75, 56), array_column($this->fetch(['page_size' => 20, 'current' => 2])['data'], 'id'));
        $this->assertCount(10, $this->fetch()['data']);
        $this->assertCount(10, $this->fetch(['current' => null, 'page_size' => null])['data']);
    }

    public function testPathAndIpFiltersComposeAndPaginateTheFilteredTotal(): void
    {
        $filters = [
            ['key' => 'uri', 'condition' => 'like', 'value' => '/UniProxy/alive'],
            ['key' => 'ip', 'condition' => 'is', 'value' => '2001:db8::10'],
        ];
        $body = $this->fetch(['filter' => $filters, 'page_size' => 10]);
        $this->assertSame(16, $body['total']);
        $this->assertCount(10, $body['data']);
        foreach ($body['data'] as $row) {
            $this->assertStringContainsString('/UniProxy/alive', $row['uri']);
            $this->assertSame('2001:db8::10', $row['ip']);
        }
        $next = $this->fetch(['filter' => $filters, 'page_size' => 10, 'current' => 2]);
        $this->assertSame(16, $next['total']); $this->assertCount(6, $next['data']);
        $this->assertSame([], array_intersect(array_column($body['data'], 'id'), array_column($next['data'], 'id')));
    }

    public function testOtherLogFieldsAreFilterableWithoutReturningUnmatchedRows(): void
    {
        $body = $this->fetch(['filter' => [
            ['key' => 'level', 'condition' => 'is', 'value' => 'INFO'],
            ['key' => 'method', 'condition' => 'is', 'value' => 'GET'],
            ['key' => 'host', 'condition' => 'like', 'value' => 'panel.example.test'],
            ['key' => 'title', 'condition' => 'like', 'value' => 'log 2'],
        ]]);
        $this->assertSame([28, 26, 24, 22, 20, 2], array_column($body['data'], 'id'));
        $this->assertSame(6, $body['total']);
        $this->assertSame(0, $this->fetch(['filter' => [['key' => 'ip', 'condition' => 'is', 'value' => '203.0.113.99']]])['total']);
        $this->assertSame(95, $this->fetch(['filter' => []])['total']);
    }

    public function testInvalidPagingAndUnapprovedFilterColumnsAreRejected(): void
    {
        foreach ([['page_size' => 1001], ['page_size' => 0], ['current' => -1], ['page_size' => 'bad'],
            ['filter' => [['key' => 'password', 'condition' => 'is', 'value' => 'bad']]],
            ['filter' => [['key' => 'ip', 'condition' => 'sql', 'value' => 'bad']]]] as $parameters) {
            try { $this->fetch($parameters); $this->fail('Invalid log query was accepted'); }
            catch (ValidationException $error) { $this->assertNotEmpty($error->errors()); }
        }
        $this->assertSame(95, Log::count());
    }

    public function testExceptionContextKeepsAReadableFileLineAndTraceInsteadOfZero(): void
    {
        app()->instance('request', Request::create('https://panel.example.test/fixture', 'POST', ['fixture' => true]));
        $exception = new \TypeError('Synthetic exception');
        $logger = (new \App\Logging\MysqlLogger())([]);
        $logger->error('Synthetic exception', ['exception' => $exception, 'invalid_utf8_fixture' => "\xff"]);
        $row = Log::orderByDesc('id')->first();
        $context = json_decode($row->context, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(\TypeError::class, $context['exception']['class']);
        $this->assertSame($exception->getFile(), $context['exception']['file']);
        $this->assertSame($exception->getLine(), $context['exception']['line']);
        $this->assertSame($exception->getTraceAsString(), $context['exception']['trace']);
        $this->assertSame("\u{FFFD}", $context['invalid_utf8_fixture']);
    }
}
