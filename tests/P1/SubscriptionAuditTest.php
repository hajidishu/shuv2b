<?php

namespace Tests\P1;

use App\Http\Controllers\V1\Admin\SubscriptionAuditController;
use App\Http\Controllers\V1\Client\ClientController;
use App\Http\Middleware\SubscriptionAudit;
use App\Http\Requests\Admin\SubscriptionAuditFetch;
use App\Http\Requests\Admin\SubscriptionAuditDetail;
use App\Jobs\WriteSubscriptionAuditJob;
use App\Services\SubscriptionAuditSchema;
use App\Services\SubscriptionAuditService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Factory;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SubscriptionAuditTest extends DatabaseTestCase
{
    private $audit;
    protected function setUp(): void
    {
        parent::setUp();
        $schema = DB::connection()->getSchemaBuilder();
        $schema->dropIfExists(SubscriptionAuditService::TABLE);
        $schema->dropIfExists(SubscriptionAuditService::SETTINGS);
        (new SubscriptionAuditSchema())->ensure();
        $this->audit = new SubscriptionAuditService();
    }

    private function event(array $values = [])
    {
        return array_merge([
            'event_id' => bin2hex(random_bytes(16)), 'user_id' => 1, 'email' => 'audit@example.test',
            'ip' => '203.0.113.10', 'ua' => 'Clash.Meta/1.18', 'format' => 'ClashMeta',
            'result' => 'success', 'status' => 200, 'requested_at' => time(),
        ], $values);
    }

    private function fetch(array $filters = [], $page = 1, $size = 20)
    {
        $request = SubscriptionAuditFetch::create('/audit', 'GET', ['filter' => $filters, 'current' => $page, 'pageSize' => $size]);
        return (new SubscriptionAuditController())->fetch($request, $this->audit);
    }

    public function testInstallIsRepeatableAndPreservesCoreDataAndSettings()
    {
        $user = $this->user(['balance' => 12345]);
        $this->audit->append($this->event());
        $this->audit->configure(17, 123);
        (new SubscriptionAuditSchema())->ensure();
        $this->assertSame(12345, (int)$user->fresh()->balance);
        $this->assertSame(1, DB::table(SubscriptionAuditService::TABLE)->count());
        $this->assertSame(17, (int)$this->audit->settings()->retention_days);
        $this->assertSame(123, (int)$this->audit->settings()->max_records);
    }

    public function testDefaultAndCustomSubscriptionRoutesBothUseAuditAfterAuthentication()
    {
        $router = new \Illuminate\Routing\Router(new \Illuminate\Events\Dispatcher(Environment::$app), Environment::$app);
        Environment::$app->instance('router', $router);
        \Illuminate\Support\Facades\Route::swap($router);
        if (!class_exists('Route')) class_alias(\Illuminate\Support\Facades\Route::class, 'Route');
        Environment::$app['config']->set('app.key', 'test-key');
        Environment::$app['config']->set('v2board.secure_path', 'test-admin');
        $router->group(['namespace' => 'App\\Http\\Controllers'], function ($router) {
            (new \App\Http\Routes\V1\ClientRoute())->map($router);
        });
        $default = $router->getRoutes()->match(Request::create('/client/subscribe'));
        $this->assertSame(['client', 'subscription.audit', 'leak.delivery'], $default->gatherMiddleware());
        Environment::$app['config']->set('v2board.subscribe_path', '/custom-subscription');
        $override = getenv('P1_OVERRIDE_ROOT');
        $root = $override && is_file($override . '/routes/web.php') ? $override : (getenv('P1_SOURCE_ROOT') ?: dirname(__DIR__, 2));
        $router->group(['namespace' => 'App\\Http\\Controllers'], function () use ($root) {
            require $root . '/routes/web.php';
        });
        $custom = $router->getRoutes()->match(Request::create('/custom-subscription'));
        $this->assertSame(['client', 'subscription.audit', 'leak.delivery'], $custom->gatherMiddleware());
    }

    public function testIncompleteExistingSchemaFailsVisibly()
    {
        DB::connection()->getSchemaBuilder()->table(SubscriptionAuditService::TABLE, function ($table) { $table->dropIndex('subscribe_audit_ip_time'); });
        $this->expectException(\RuntimeException::class);
        (new SubscriptionAuditSchema())->ensure();
    }

    public function testQueueRetriesDoNotDuplicateEventsOrOverwriteOriginalTime()
    {
        $event = $this->event(['requested_at' => time() - 3600]);
        (new WriteSubscriptionAuditJob($event))->handle($this->audit);
        (new WriteSubscriptionAuditJob(array_merge($event, ['requested_at' => time()])))->handle($this->audit);
        $this->assertSame(1, DB::table(SubscriptionAuditService::TABLE)->count());
        $this->assertSame(1, (int)$this->audit->settings()->record_count);
        $this->assertSame($event['requested_at'], (int)DB::table(SubscriptionAuditService::TABLE)->value('requested_at'));
    }

    public function testCountLimitKeepsNewestRequestTimesIncludingOutOfOrderJobs()
    {
        $this->audit->configure(30, 3);
        $now = time();
        foreach ([5, 1, 4, 3, 2] as $age) $this->audit->append($this->event(['requested_at' => $now - $age * 60]));
        $this->assertSame([$now - 180, $now - 120, $now - 60], DB::table(SubscriptionAuditService::TABLE)->orderBy('requested_at')->pluck('requested_at')->map(function ($time) { return (int)$time; })->all());
        $this->assertSame(3, (int)$this->audit->settings()->record_count);
    }

    public function testEqualTimestampsUseIdForDeterministicEviction()
    {
        $this->audit->configure(30, 2);
        $event = $this->event();
        $this->audit->append($event);
        $this->audit->append($this->event()); $this->audit->append($this->event());
        $this->assertSame(0, DB::table(SubscriptionAuditService::TABLE)->where('event_id', $event['event_id'])->count());
        $this->assertSame(2, (int)$this->audit->settings()->record_count);
    }

    public function testThirtyDayRetentionAndLateExpiredJobs()
    {
        $this->assertSame(30, (int)$this->audit->settings()->retention_days);
        $old = $this->event(); $this->audit->append($old);
        DB::table(SubscriptionAuditService::TABLE)->where('event_id', $old['event_id'])->update(['requested_at' => time() - 31 * 86400]);
        $this->audit->append($this->event());
        $this->audit->append($this->event(['requested_at' => time() - 31 * 86400]));
        $this->audit->prune();
        $this->assertSame(1, DB::table(SubscriptionAuditService::TABLE)->count());
        $this->assertSame(1, (int)$this->audit->settings()->record_count);
    }

    public function testReducingBothLimitsPrunesInChunksAndCanBeRepeated()
    {
        for ($i = 0; $i < 12; $i++) $this->audit->append($this->event(['requested_at' => time() - $i * 86400]));
        $this->audit->configure(5, 3);
        $this->assertSame(9, $this->audit->prune());
        $this->assertSame(0, $this->audit->prune());
        $this->assertSame(3, (int)$this->audit->settings()->record_count);
        $this->assertSame(3, DB::table(SubscriptionAuditService::TABLE)->count());
    }

    public function testIdleExpiredRecordsAreRemovedWithoutNewSubscriptions()
    {
        $this->audit->append($this->event());
        DB::table(SubscriptionAuditService::TABLE)->update(['requested_at' => time() - 40 * 86400]);
        $this->assertSame(1, $this->audit->prune());
        $this->assertSame(0, (int)$this->audit->settings()->record_count);
    }

    public function testEmailUaIpFiltersIntersectAndWildcardsAreLiteral()
    {
        $this->audit->append($this->event(['ua' => 'client_100% stable']));
        $this->audit->append($this->event(['ua' => 'clientX100Y stable']));
        $this->audit->append($this->event(['email' => 'other@example.test', 'ua' => 'client_100% stable']));
        $filtered = $this->fetch([
            ['key' => 'email', 'condition' => '=', 'value' => 'audit@example.test'],
            ['key' => 'ip', 'condition' => '=', 'value' => '203.0.113.10'],
            ['key' => 'ua', 'condition' => '模糊', 'value' => '_100%'],
        ]);
        $this->assertSame(1, $filtered['total']);
        $this->assertSame('client_100% stable', $filtered['data'][0]->ua);
    }

    public function testLargeLimitReductionUsesSeveralChunksAndKeepsNewestRows()
    {
        $events = [];
        for ($i = 0; $i < 2010; $i++) $events[] = $this->event(['requested_at' => time() - 3000 + $i]);
        foreach (array_chunk($events, 200) as $chunk) DB::table(SubscriptionAuditService::TABLE)->insert($chunk);
        DB::table(SubscriptionAuditService::SETTINGS)->where('id', 1)->update(['record_count' => count($events)]);
        $this->audit->configure(30, 3);
        $this->assertSame(2007, $this->audit->prune());
        $this->assertSame(3, DB::table(SubscriptionAuditService::TABLE)->count());
        $this->assertSame(3, (int)$this->audit->settings()->record_count);
        $this->assertSame($events[2009]['event_id'], DB::table(SubscriptionAuditService::TABLE)->orderByDesc('requested_at')->value('event_id'));
    }

    public function testIpv6CanonicalFilterDatesAndMissingEmail()
    {
        $this->audit->append($this->event(['ip' => '2001:db8::1', 'requested_at' => time() - 600]));
        $this->assertSame(1, $this->fetch([
            ['key' => 'ip', 'condition' => '=', 'value' => '2001:0db8:0:0:0:0:0:1'],
            ['key' => 'requested_at', 'condition' => '<=', 'value' => time()],
        ])['total']);
        $this->assertSame(0, $this->fetch([['key' => 'email', 'condition' => '模糊', 'value' => 'missing']])['total']);
    }

    public function testPaginationCountsAndSnapshotSurvivesUserDeletion()
    {
        $user = $this->user(['email' => 'snapshot@example.test']);
        for ($i = 0; $i < 5; $i++) $this->audit->append($this->event(['user_id' => $user->id, 'email' => $user->email]));
        $user->delete();
        $page = $this->fetch([], 2, 2);
        $this->assertSame(5, $page['total']); $this->assertCount(2, $page['data']);
        $this->assertSame('snapshot@example.test', $page['data'][0]->email);
    }

    public function testInvalidFilterShapesOperatorsAndUnboundedPagesAreRejected()
    {
        $factory = new Factory(Environment::$app['translator'], Environment::$app);
        $rules = (new SubscriptionAuditFetch())->rules();
        foreach ([
            ['pageSize' => 1000], ['current' => -1],
            ['filter' => [['key' => 'password', 'condition' => '=', 'value' => 'x']]],
            ['filter' => [['key' => 'ua', 'condition' => 'OR 1=1', 'value' => 'x']]],
            ['filter' => [['key' => 'ip', 'condition' => '=', 'value' => ['x']]]],
            ['filter' => [['key' => 'requested_at', 'condition' => '模糊', 'value' => 123]]],
            ['filter' => [['key' => 'result', 'condition' => '=', 'value' => 'bad']]],
        ] as $data) $this->assertTrue($factory->make($data, $rules)->fails());
        $this->assertTrue($factory->make(['filter' => [['key' => 'user_id', 'condition' => '>=', 'value' => 0]]], $rules)->passes());
    }

    private function request()
    {
        $user = $this->user(['token' => 'secret-subscription-token']);
        $request = Request::create('/subscribe', 'GET', ['user' => $user, 'token' => $user->token], [], [], [
            'REMOTE_ADDR' => '2001:db8::1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.1',
            'HTTP_USER_AGENT' => 'Client ' . $user->token . str_repeat('测', 300),
        ]);
        return $request;
    }

    public function testMiddlewareUsesAuthenticatedUserRawUaTrustedIpAndScalarQueuePayload()
    {
        $request = $this->request();
        $response = (new SubscriptionAudit())->handle($request, function ($request) {
            $request->attributes->set('subscription_audit_result', 'success');
            $request->attributes->set('subscription_audit_format', 'ClashMeta');
            return new Response('original-subscription', 200);
        });
        $this->assertSame('original-subscription', $response->getContent());
        Environment::$queue->assertPushed(WriteSubscriptionAuditJob::class, function ($job) use ($request) {
            $this->assertSame('redis', $job->connection); $this->assertSame('subscription_audit', $job->queue);
            $this->assertSame('2001:db8::1', $job->event['ip']);
            $this->assertSame((int)$request->user->id, $job->event['user_id']);
            $this->assertSame('success', $job->event['result']);
            $this->assertLessThanOrEqual(512, strlen($job->event['ua']));
            $this->assertStringNotContainsString($request->user->token, $job->event['ua']);
            $this->assertSame($request->user->token, json_decode($job->event['request_details'], true)['query']['token']);
            foreach ($job->event as $value) $this->assertTrue(is_scalar($value));
            $this->assertSame(0, DB::table(SubscriptionAuditService::TABLE)->count());
            return true;
        });
    }

    public function testUnavailableAccountIsNotMislabelledSuccessfulBecauseHttpStatusIs200()
    {
        $request = $this->request(); $request->user->banned = 1;
        (new SubscriptionAudit())->handle($request, function ($request) {
            $this->assertNull((new ClientController())->subscribe($request));
            return new Response('', 200);
        });
        Environment::$queue->assertPushed(WriteSubscriptionAuditJob::class, function ($job) {
            return $job->event['result'] === 'unavailable' && $job->event['status'] === 200;
        });
    }

    public function testUnicodeEmailSnapshotUsesTheDatabaseCharacterLimit()
    {
        $request = $this->request();
        $request->user->email = str_repeat('用户', 12) . '@example.test';
        (new SubscriptionAudit())->handle($request, function () { return new Response('ok'); });
        Environment::$queue->assertPushed(WriteSubscriptionAuditJob::class, function ($job) use ($request) {
            $this->assertSame($request->user->email, $job->event['email']);
            return true;
        });
    }

    public function testExceptionsAreRecordedAndRethrownWithoutLeakingTokens()
    {
        try {
            (new SubscriptionAudit())->handle($this->request(), function () { throw new HttpException(503, 'upstream error'); });
            $this->fail('Original exception was lost');
        } catch (HttpException $error) { $this->assertSame(503, $error->getStatusCode()); }
        Environment::$queue->assertPushed(WriteSubscriptionAuditJob::class, function ($job) {
            return $job->event['status'] === 503 && $job->event['result'] === 'error';
        });
    }

    public function testAuditQueueFailureDoesNotBreakSubscriptionAndCanBeDisabled()
    {
        $bus = \Mockery::mock(\Illuminate\Contracts\Bus\Dispatcher::class);
        $bus->shouldReceive('dispatch')->andThrow(new \RuntimeException('Redis unavailable'));
        Environment::$app->instance(\Illuminate\Contracts\Bus\Dispatcher::class, $bus);
        $response = (new SubscriptionAudit())->handle($this->request(), function () { return new Response('ok'); });
        $this->assertSame('ok', $response->getContent());
        Environment::$app['config']->set('subscription_audit.enabled', false);
        $response = (new SubscriptionAudit())->handle($this->request(), function () { return new Response('disabled'); });
        $this->assertSame('disabled', $response->getContent());
    }

    public function testPreviousAuditSchemaUpgradesWithoutChangingExistingRecords()
    {
        $this->audit->append($this->event());
        $this->audit->configure(17, 500);
        DB::connection()->getSchemaBuilder()->table(SubscriptionAuditService::TABLE, function ($table) { $table->dropColumn('request_details'); });
        (new SubscriptionAuditSchema())->ensure();
        (new SubscriptionAuditSchema())->ensure();
        $this->assertSame(1, DB::table(SubscriptionAuditService::TABLE)->count());
        $this->assertNull(DB::table(SubscriptionAuditService::TABLE)->value('request_details'));
        $this->assertSame(17, (int)$this->audit->settings()->retention_days);
        $this->assertSame(500, (int)$this->audit->settings()->max_records);
    }

    public function testHttpMetadataKeepsSubscriptionTokenWhileRedactingOtherCredentialsAndMergedUser()
    {
        $request = $this->request();
        $request->user->password = 'private-password-hash';
        $request->query->set('flag', 'clash.meta');
        $request->query->set('credentials', ['password' => 'query-password']);
        $request->headers->set('Authorization', 'Bearer private-auth');
        $request->headers->set('Cookie', 'session=private-cookie');
        $request->headers->set('X-Api-Key', 'private-api-key');
        $request->headers->set('X-Diagnostic', 'visible-note');
        $request->headers->set('Referer', 'https://panel.test/?access_token=referer-secret');
        (new SubscriptionAudit())->handle($request, function () { return new Response('subscription-body-do-not-log'); });
        Environment::$queue->assertPushed(WriteSubscriptionAuditJob::class, function ($job) use ($request) {
            $encoded = $job->event['request_details'];
            $details = json_decode($encoded, true);
            foreach (['private-auth', 'private-cookie', 'private-api-key', 'query-password', 'referer-secret', 'private-password-hash', 'subscription-body-do-not-log'] as $secret) {
                $this->assertStringNotContainsString($secret, $encoded);
            }
            $this->assertSame('GET', $details['method']);
            $this->assertSame('clash.meta', $details['query']['flag']);
            $this->assertSame($request->user->token, $details['query']['token']);
            parse_str(parse_url($details['url'], PHP_URL_QUERY), $query);
            $this->assertSame($request->user->token, $query['token']);
            $this->assertSame('[redacted]', $details['headers']['authorization']);
            $this->assertSame('visible-note', $details['headers']['x-diagnostic']);
            $this->assertArrayNotHasKey('user', $details['query']);
            $this->assertGreaterThanOrEqual(0, $details['duration_ms']);
            return true;
        });
    }

    public function testOversizedNestedHttpMetadataStaysBoundedAndValidJson()
    {
        $request = $this->request();
        for ($i = 0; $i < 90; $i++) $request->headers->set('x-long-' . $i, str_repeat("\x01测", 2000));
        $request->query->set('huge', array_fill(0, 100, array_fill(0, 40, str_repeat('q', 5000))));
        $response = (new SubscriptionAudit())->handle($request, function () { return new Response('unchanged'); });
        $this->assertSame('unchanged', $response->getContent());
        Environment::$queue->assertPushed(WriteSubscriptionAuditJob::class, function ($job) {
            $this->assertLessThanOrEqual(24000, strlen($job->event['request_details']));
            $this->assertIsArray(json_decode($job->event['request_details'], true));
            $this->assertTrue(json_decode($job->event['request_details'], true)['truncated']);
            return true;
        });
    }

    public function testDetailReturnsSnapshotAndHttpDataWhileListsRemainLightweight()
    {
        $event = $this->event(['request_details' => json_encode(['method' => 'GET', 'headers' => ['accept' => '*/*']])]);
        $this->audit->append($event);
        $record = $this->fetch()['data'][0];
        $this->assertFalse(property_exists($record, 'request_details'));
        $this->assertSame(1, (int)$record->has_request_details);
        $details = (new SubscriptionAuditController())->detail(SubscriptionAuditDetail::create('/audit/detail', 'GET', ['id' => $record->id]));
        $this->assertSame('audit@example.test', $details['data']->email);
        $this->assertSame('GET', $details['data']->request_details['method']);
        $this->audit->append($this->event(['requested_at' => time() + 1]));
        $old = $this->fetch()['data'][0];
        $legacy = (new SubscriptionAuditController())->detail(SubscriptionAuditDetail::create('/audit/detail', 'GET', ['id' => $old->id]));
        $this->assertNull($legacy['data']->request_details);
    }

    public function testActualTemporaryTokenIsPreservedWithoutExposingOtherCredentials()
    {
        $request = $this->request();
        $request->query->set('token', 'temporary-subscription-token');
        $request->query->set('access_token', 'private-access-token');
        $request->query->set('nested', ['token' => 'private-nested-token']);
        $request->headers->set('token', 'private-header-token');
        (new SubscriptionAudit())->handle($request, function () { return new Response('unchanged'); });
        Environment::$queue->assertPushed(WriteSubscriptionAuditJob::class, function ($job) {
            $details = json_decode($job->event['request_details'], true);
            $this->assertSame('temporary-subscription-token', $details['query']['token']);
            $this->assertStringContainsString('token=temporary-subscription-token', $details['url']);
            foreach (['private-access-token', 'private-nested-token', 'private-header-token'] as $secret) $this->assertStringNotContainsString($secret, $job->event['request_details']);
            return true;
        });
    }

    public function testHistoricalDetailsOfferCurrentSubscriptionUrlWithoutFabricatingOldToken()
    {
        config(['v2board.subscribe_url' => 'https://subscribe.example.test', 'v2board.show_subscribe_method' => 0]);
        $user = $this->user(['token' => 'current-subscription-token']);
        $this->audit->append($this->event(['user_id' => $user->id, 'request_details' => json_encode(['query' => ['token' => '[redacted]']])]));
        $row = $this->fetch()['data'][0];
        $this->assertFalse(property_exists($row, 'current_subscribe_url'));
        $request = SubscriptionAuditDetail::create('/audit/detail', 'GET', ['id' => $row->id]);
        $controller = new SubscriptionAuditController();
        $record = $controller->detail($request)['data'];
        $this->assertSame('https://subscribe.example.test/api/v1/client/subscribe?token=current-subscription-token', $record->current_subscribe_url);
        $this->assertSame('[redacted]', $record->request_details['query']['token']);
        $user->update(['token' => 'rotated-subscription-token']);
        $this->assertStringContainsString('token=rotated-subscription-token', $controller->detail($request)['data']->current_subscribe_url);
        $user->delete();
        $this->assertNull($controller->detail($request)['data']->current_subscribe_url);
    }

    public function testMissingDetailIs404AndInvalidIdsAndDimensionsFailValidation()
    {
        $factory = new Factory(Environment::$app['translator'], Environment::$app);
        foreach ([-1, 'abc', 0, []] as $id) $this->assertTrue($factory->make(['id' => $id], (new SubscriptionAuditDetail())->rules())->fails());
        $this->assertTrue($factory->make(['dimension' => 'password'], (new SubscriptionAuditFetch())->rules())->fails());
        try {
            (new SubscriptionAuditController())->detail(SubscriptionAuditDetail::create('/audit/detail', 'GET', ['id' => 99999]));
            $this->fail('Missing record should be a 404');
        } catch (HttpException $error) { $this->assertSame(404, $error->getStatusCode()); }
    }

    private function stats($dimension, array $filters = [], $fresh = false)
    {
        return (new SubscriptionAuditController())->stats(SubscriptionAuditFetch::create('/audit/stats', 'GET', [
            'dimension' => $dimension, 'filter' => $filters, 'pageSize' => 1, 'current' => 99, 'fresh' => $fresh,
        ]), $this->audit)['data'];
    }

    public function testStatisticsCoverAllFilteredRowsAndGroupUaCaseSensitively()
    {
        foreach ([['203.0.113.1', 'Client', 1], ['203.0.113.1', 'Client', 2], ['2001:db8::1', 'client', 2]] as $row) {
            $this->audit->append($this->event(['ip' => $row[0], 'ua' => $row[1], 'user_id' => $row[2]]));
        }
        $ip = $this->stats('ip');
        $this->assertSame(3, $ip['total']); $this->assertSame(2, $ip['users']);
        $this->assertSame(2, $ip['ips']); $this->assertSame(2, $ip['uas']);
        $this->assertSame('203.0.113.1', $ip['ranking'][0]['label']); $this->assertSame(2, $ip['ranking'][0]['count']);
        $ua = $this->stats('ua');
        $this->assertCount(2, $ua['ranking']); $this->assertSame('Client', $ua['ranking'][0]['label']);
        $filtered = $this->stats('ip', [['key' => 'ua', 'condition' => '=', 'value' => 'missing']]);
        $this->assertSame(0, $filtered['total']); $this->assertSame([], $filtered['ranking']);
    }

    public function testUserRankUsesStableIdentityAndEmailsIncludingDeletedUsers()
    {
        $a = $this->user(['email' => 'current@example.test']); $b = $this->user(['email' => 'deleted@example.test']);
        $this->audit->append($this->event(['user_id' => $a->id, 'email' => 'previous@example.test']));
        $this->audit->append($this->event(['user_id' => $a->id, 'email' => $a->email]));
        $this->audit->append($this->event(['user_id' => $b->id, 'email' => 'z-previous@example.test', 'requested_at' => time() - 60]));
        $this->audit->append($this->event(['user_id' => $b->id, 'email' => 'a-latest@example.test']));
        $b->delete();
        $rank = $this->stats('user');
        $this->assertSame(2, $rank['users']); $this->assertCount(2, $rank['ranking']);
        $labels = array_column($rank['ranking'], 'label');
        $this->assertContains('current@example.test', $labels); $this->assertContains('a-latest@example.test', $labels);
    }

    public function testStatisticsCacheCanBeExplicitlyRefreshedAndIsFilterScoped()
    {
        Environment::$app->instance('cache', new \Illuminate\Cache\Repository(new \Illuminate\Cache\ArrayStore()));
        \Illuminate\Support\Facades\Cache::clearResolvedInstance('cache');
        $this->audit->append($this->event());
        $this->assertSame(1, $this->stats('ip')['total']);
        $this->audit->append($this->event());
        $this->assertSame(1, $this->stats('ip')['total']);
        $this->assertSame(2, $this->stats('ip', [], true)['total']);
        $this->assertSame(0, $this->stats('ip', [['key' => 'email', 'condition' => '=', 'value' => 'missing@example.test']])['total']);
    }
}
