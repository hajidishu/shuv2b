<?php
namespace Tests\P1;

use App\Http\Controllers\V1\User\TicketController;
use App\Http\Middleware\TrustProxies;
use App\Http\Requests\User\TicketSave;
use App\Jobs\SendTelegramJob;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TicketNotificationTest extends DatabaseTestCase
{
    private $owner;
    private $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $schema = DB::getSchemaBuilder();
        $schema->dropIfExists('v2_ticket_message'); $schema->dropIfExists('v2_ticket');
        $schema->create('v2_ticket', function (Blueprint $t) {
            $t->increments('id'); $t->integer('user_id'); $t->string('subject'); $t->integer('level')->default(0);
            $t->integer('status')->default(0); $t->integer('reply_status')->default(0);
            $t->integer('created_at')->nullable(); $t->integer('updated_at')->nullable();
        });
        $schema->create('v2_ticket_message', function (Blueprint $t) {
            $t->increments('id'); $t->integer('user_id'); $t->integer('ticket_id'); $t->text('message');
            $t->integer('created_at')->nullable(); $t->integer('updated_at')->nullable();
        });
        config(['v2board.telegram_bot_enable' => 1, 'v2board.ticket_status' => 0, 'trustedproxy.proxies' => []]);
        $this->owner = $this->user(['expired_at' => 0]);
        $this->admin = $this->user(['is_admin' => 1, 'telegram_admin_id' => 808001]);
        RateLimiter::swap(new \Illuminate\Cache\RateLimiter(app('cache')));
        Log::swap(new class extends \Psr\Log\NullLogger { public function channel($name) { return $this; } });
        Http::swap(new Factory());
        Http::fake(static function ($request) {
            if (parse_url($request->url(), PHP_URL_HOST) !== 'ip-api.com') throw new \RuntimeException('Unexpected location host');
            return Http::response(['status' => 'success', 'city' => 'FixtureCity', 'country' => 'FixtureCountry']);
        });
    }

    protected function tearDown(): void
    {
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO);
        DB::getSchemaBuilder()->dropIfExists('v2_ticket_message'); DB::getSchemaBuilder()->dropIfExists('v2_ticket');
        parent::tearDown();
    }

    private function request(array $server = [], ?User $owner = null): TicketSave
    {
        $owner = $owner ?? $this->owner;
        $request = TicketSave::create('/api/v1/user/ticket/save', 'POST',
            ['subject' => 'Synthetic ticket', 'level' => 0, 'message' => 'Synthetic message', 'user' => ['id' => $owner->id]],
            [], [], $server + ['REMOTE_ADDR' => '192.0.2.50']);
        $validator = app('validator')->make($request->all(), $request->rules());
        $validator->validate(); $request->setValidator($validator);
        app()->instance('request', $request);
        (new TrustProxies(app('config')))->handle($request, static function () {});
        return $request;
    }

    private function save(TicketSave $request)
    {
        $saved = $_SERVER; $_SERVER = $request->server->all();
        try { return (new TicketController())->save($request); }
        finally { $_SERVER = $saved; }
    }

    private function notification(): string
    {
        $job = Environment::$queue->pushed(SendTelegramJob::class)->last();
        $this->assertNotNull($job);
        $property = new \ReflectionProperty(SendTelegramJob::class, 'text'); $property->setAccessible(true);
        return $property->getValue($job);
    }

    public function testUntrustedHeadersCannotChangeNotificationOrLocationAddress(): void
    {
        $response = $this->save($this->request(['HTTP_X_REAL_IP' => '1.2.3.4@10.255.255.1/', 'HTTP_X_FORWARDED_FOR' => '198.51.100.8']));
        $this->assertSame(200, $response->getStatusCode());
        $text = $this->notification();
        $this->assertStringContainsString("IP:\n192.0.2.50\n", $text);
        $this->assertStringNotContainsString('@10.255.255.1', $text);
        $this->assertStringContainsString($this->owner->email, $text);
        $this->assertStringContainsString('Synthetic message', $text);
        Http::assertSent(static function ($request) { return strpos($request->url(), 'http://ip-api.com/json/192.0.2.50?') === 0; });
    }

    public function testConfiguredProxyPreservesTheVerifiedForwardedClient(): void
    {
        config(['trustedproxy.proxies' => ['127.0.0.1']]);
        $this->save($this->request(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.8, 127.0.0.1',
            'HTTP_X_REAL_IP' => '1.2.3.4@10.255.255.1/']));
        $this->assertStringContainsString("IP:\n198.51.100.8\n", $this->notification());
        Http::assertSent(static function ($request) { return strpos($request->url(), '/json/198.51.100.8?') !== false; });
    }

    public static function nonPublicAddresses(): array
    {
        return array_map(static function ($ip) {return [$ip];},
            ['127.0.0.1', '10.0.0.2', '192.168.1.2', '::1', 'fc00::1', '::ffff:10.0.0.2', '::ffff:a00:2', 'invalid-ip']);
    }

    /** @dataProvider nonPublicAddresses */
    public function testPrivateAndMalformedAddressesDoNotTriggerLookup($ip): void
    {
        $this->assertSame(200, $this->save($this->request(['REMOTE_ADDR' => $ip]))->getStatusCode());
        Http::assertNothingSent();
        $this->assertStringContainsString('无法确定用户地址', $this->notification());
        if ($ip === 'invalid-ip') $this->assertStringContainsString("IP:\n未知\n", $this->notification());
    }

    public function testLocationHttpErrorsAndMalformedBodiesDoNotFailSubmission(): void
    {
        foreach ([[503, '{}'], [302, ''], [200, '<html>bad response</html>'], [200, '{"status":"success","city":[],"country":"fixture"}']] as [$status, $body]) {
            Http::swap(new Factory()); Http::fake(['*' => Http::response($body, $status)]);
            $this->assertSame(200, $this->save($this->request())->getStatusCode());
            $this->assertStringContainsString('无法确定用户地址', $this->notification());
            $ticket = Ticket::orderByDesc('id')->first(); $ticket->status = 1; $ticket->save();
        }
        $this->assertSame(4, Ticket::count());
    }

    public function testNotificationQueueFailureDoesNotChangeSuccessfulTicketResult(): void
    {
        $bus = \Mockery::mock(Dispatcher::class); $bus->shouldReceive('dispatch')->andThrow(new \RuntimeException('fixture queue unavailable'));
        app()->instance(Dispatcher::class, $bus);
        $this->assertSame(['data' => true], $this->save($this->request())->getData(true));
        $this->assertSame(1, Ticket::count()); $this->assertSame(1, TicketMessage::count());
    }

    public function testNotificationAndFailureLoggingErrorsDoNotChangeSuccessfulTicketResult(): void
    {
        $bus = \Mockery::mock(Dispatcher::class);
        $bus->shouldReceive('dispatch')->andThrow(new \RuntimeException('fixture queue unavailable'));
        app()->instance(Dispatcher::class, $bus);
        Log::swap(new class extends \Psr\Log\NullLogger {
            public function channel($name) { throw new \RuntimeException('fixture log channel unavailable'); }
        });

        $this->assertSame(['data' => true], $this->save($this->request())->getData(true));
        $this->assertSame(1, Ticket::count());
        $this->assertSame(1, TicketMessage::count());
        $this->assertSame('Synthetic message', TicketMessage::firstOrFail()->message);
    }

    public function testReplyRemainsSuccessfulWhenItsNotificationQueueFails(): void
    {
        $this->save($this->request());
        $ticket = Ticket::firstOrFail();
        TicketMessage::create(['ticket_id' => $ticket->id, 'user_id' => $this->admin->id, 'message' => 'Fixture staff reply']);
        $bus = \Mockery::mock(Dispatcher::class); $bus->shouldReceive('dispatch')->andThrow(new \RuntimeException('fixture queue unavailable'));
        app()->instance(Dispatcher::class, $bus);
        $request = Request::create('/ticket/reply', 'POST', ['id' => $ticket->id, 'message' => 'Fixture user reply', 'user' => ['id' => $this->owner->id]],
            [], [], ['REMOTE_ADDR' => '192.0.2.50']);
        app()->instance('request', $request);
        $this->assertSame(['data' => true], (new TicketController())->reply($request)->getData(true));
        $this->assertSame(3, TicketMessage::where('ticket_id', $ticket->id)->count());
        $this->assertSame('Fixture user reply', TicketMessage::orderByDesc('id')->first()->message);
    }

    public function testDisabledTelegramDoesNotLookUpLocation(): void
    {
        config(['v2board.telegram_bot_enable' => 0]);
        $this->assertSame(200, $this->save($this->request())->getStatusCode());
        Http::assertNothingSent(); Environment::$queue->assertNotPushed(SendTelegramJob::class);
    }

    public function testClosingTicketsDoesNotResetTheAccountCreationLimit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(200, $this->save($this->request())->getStatusCode());
            $ticket = Ticket::orderByDesc('id')->first(); $ticket->status = 1; $ticket->save();
        }
        try { $this->save($this->request()); $this->fail('Sixth creation request accepted'); }
        catch (HttpException $error) { $this->assertSame(429, $error->getStatusCode()); $this->assertGreaterThan(0, $error->getHeaders()['Retry-After']); }
        $this->assertSame(5, Ticket::count());
        $other = $this->user();
        $this->assertSame(200, $this->save($this->request([], $other))->getStatusCode());
    }

    public function testExistingOpenTicketStillPreventsAnotherCreation(): void
    {
        $this->save($this->request());
        try { $this->save($this->request()); $this->fail('Duplicate open ticket accepted'); }
        catch (HttpException $error) { $this->assertSame(500, $error->getStatusCode()); }
        $this->assertSame(1, Ticket::count()); $this->assertSame(1, TicketMessage::count());
    }

    public function testClearingTheConfiguredLimitImmediatelyDisablesIt(): void
    {
        config(['v2board.ticket_create_limit' => 1]);
        $this->save($this->request());
        $ticket = Ticket::firstOrFail(); $ticket->status = 1; $ticket->save();
        try { $this->save($this->request()); $this->fail('Configured limit ignored'); }
        catch (HttpException $error) { $this->assertSame(429, $error->getStatusCode()); }
        config(['v2board.ticket_create_limit' => null]);
        for ($i = 0; $i < 6; $i++) {
            $this->assertSame(200, $this->save($this->request())->getStatusCode());
            $ticket = Ticket::orderByDesc('id')->first(); $ticket->status = 1; $ticket->save();
        }
        $this->assertSame(7, Ticket::count());
        $settings = (new \App\Http\Controllers\V1\Admin\ConfigController())->fetch(Request::create('/', 'GET', ['key' => 'ticket']))->getData(true);
        $this->assertNull($settings['data']['ticket']['ticket_create_limit']);
    }

    public function testCreationLimitConfigurationAcceptsBlankOrPositiveIntegers(): void
    {
        $rules = (new \App\Http\Requests\Admin\ConfigSave())->rules()['ticket_create_limit'];
        foreach ([null, '', 1, 5, '10', 1000] as $value) $this->assertFalse(app('validator')->make(['limit' => $value], ['limit' => $rules])->fails());
        foreach ([0, -1, 1.5, 'bad', []] as $value) $this->assertTrue(app('validator')->make(['limit' => $value], ['limit' => $rules])->fails());
    }

    public function testWithdrawalTicketCreationUsesTheSameConfigurableLimit(): void
    {
        config(['v2board.ticket_create_limit' => 1, 'v2board.commission_withdraw_limit' => 1,
            'v2board.commission_withdraw_method' => ['USDT']]);
        $this->owner->update(['commission_balance' => 10000]);
        $request = \App\Http\Requests\User\TicketWithdraw::create('/ticket/withdraw', 'POST',
            ['withdraw_method' => 'USDT', 'withdraw_account' => 'fixture-account', 'user' => ['id' => $this->owner->id]]);
        $validator = app('validator')->make($request->all(), $request->rules()); $validator->validate(); $request->setValidator($validator);
        app()->instance('request', $request);
        $this->assertSame(200, (new TicketController())->withdraw($request)->getStatusCode());
        try { $this->save($this->request()); $this->fail('Alternate ticket creation bypassed account limit'); }
        catch (HttpException $error) { $this->assertSame(429, $error->getStatusCode()); }
        $this->assertSame(1, Ticket::count());
        $this->assertSame(10000, (int)$this->owner->fresh()->commission_balance);
        config(['v2board.ticket_create_limit' => null]);
        app()->instance('request', $request);
        $this->assertSame(200, (new TicketController())->withdraw($request)->getStatusCode());
        $this->assertSame(2, Ticket::count());
    }
}
