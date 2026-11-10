<?php
namespace Tests\P1;

use App\Http\Controllers\V1\Admin\UserController;
use App\Http\Controllers\V1\Passport\AuthController;
use App\Http\Middleware\TrustProxies;
use App\Http\Requests\Admin\UserFetch;
use App\Http\Requests\Passport\AuthLogin;
use App\Http\Requests\Passport\AuthRegister;
use App\Models\User;
use App\Services\DatabaseUpgrade;
use App\Services\RegistrationService;
use App\Services\SubscriptionAuditSchema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

class RegistrationIpTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::getSchemaBuilder()->table('v2_user', function (Blueprint $table) { $table->integer('last_login_at')->nullable(); });
        config(['trustedproxy.proxies' => ['127.0.0.1', '::1'],
            'v2board.subscribe_url' => 'http://localhost', 'v2board.show_subscribe_method' => 0]);
        RateLimiter::swap(new \Illuminate\Cache\RateLimiter(app('cache')));
        Request::macro('validate', function (array $rules, array $messages = []) {
            return app('validator')->make($this->all(), $rules, $messages)->validate();
        });
    }

    protected function tearDown(): void
    {
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO);
        parent::tearDown();
    }

    private function registration(string $ip, array $headers = []): AuthRegister
    {
        $request = AuthRegister::create('/register', 'POST', [
            'email' => 'registration-' . bin2hex(random_bytes(5)) . '@example.test',
            'password' => 'fixture-password', 'register_ip' => '203.0.113.99',
        ], [], [], ['REMOTE_ADDR' => $ip] + $headers);
        (new TrustProxies(app('config')))->handle($request, static function () {});
        return $request;
    }

    public static function addresses(): array
    {
        return [
            ['192.0.2.5', [], '192.0.2.5'],
            ['2001:0DB8:0000:0000:0000:0000:0000:0005', [], '2001:db8::5'],
            ['127.0.0.1', ['HTTP_X_FORWARDED_FOR' => '198.51.100.5, 127.0.0.1'], '198.51.100.5'],
            ['::1', ['HTTP_X_FORWARDED_FOR' => '2001:db8::5, ::1'], '2001:db8::5'],
            ['192.0.2.5', ['HTTP_X_FORWARDED_FOR' => '203.0.113.99', 'HTTP_X_REAL_IP' => '203.0.113.99'], '192.0.2.5'],
            ['invalid-ip', [], null],
        ];
    }

    /** @dataProvider addresses */
    public function testRegistrationRecordsTheVerifiedAddressAndIgnoresBodyIp(string $ip, array $headers, ?string $expected): void
    {
        $request = $this->registration($ip, $headers);
        $result = (new AuthController())->register($request)->getData(true);
        $user = User::where('email', $request->input('email'))->firstOrFail();
        $this->assertSame($expected, $user->register_ip);
        $this->assertArrayHasKey('auth_data', $result['data']);
    }

    public function testLaterLoginDoesNotReplaceTheRegistrationAddress(): void
    {
        $request = $this->registration('192.0.2.5');
        (new AuthController())->register($request);
        $login = AuthLogin::create('/login', 'POST', $request->only('email', 'password'), [], [], ['REMOTE_ADDR' => '198.51.100.6']);
        $this->assertSame(200, (new AuthController())->login($login)->getStatusCode());
        $this->assertSame('192.0.2.5', User::where('email', $request->input('email'))->value('register_ip'));
    }

    public function testFailedRegistrationDoesNotLeaveAnIpRecord(): void
    {
        $request = $this->registration('192.0.2.5');
        try {
            (new RegistrationService())->register($request, true, false, static function () {
                throw new \RuntimeException('fixture identity failure');
            });
            $this->fail('Registration should roll back');
        } catch (\RuntimeException $error) { $this->assertSame('fixture identity failure', $error->getMessage()); }
        $this->assertFalse(User::where('email', $request->input('email'))->exists());
    }

    public function testExistingAndAdministrativelyCreatedAccountsStayUnknown(): void
    {
        $user = $this->user(['balance' => 500])->fresh();
        $this->assertNull($user->register_ip);
        $user->balance = 1000; $user->save();
        $this->assertNull($user->fresh()->register_ip);
    }

    public function testAdminFiltersNormalizeIpv6AndPreservePaginationScope(): void
    {
        (new SubscriptionAuditSchema())->ensure();
        $a = $this->user(['register_ip' => '2001:db8::5']);
        $this->user(['register_ip' => '192.0.2.5']); $this->user();
        $request = UserFetch::create('/', 'GET', ['filter' => [
            ['key' => 'register_ip', 'condition' => '=', 'value' => '2001:0DB8:0:0:0:0:0:5'],
        ]]);
        $response = (new UserController())->fetch($request)->getData(true);
        $this->assertSame(1, $response['total']);
        $this->assertSame([$a->id], array_column($response['data'], 'id'));
        $this->assertSame('2001:db8::5', $response['data'][0]['register_ip']);
    }

    public function testRegistrationIpFiltersRejectInvalidAddressesAndOperators(): void
    {
        foreach ([['模糊', '192.0.2.5'], ['=', 'invalid'], ['=', []], ['=', '127.0.0.1 OR 1=1']] as [$condition, $value]) {
            $request = UserFetch::create('/', 'GET', ['filter' => [['key' => 'register_ip', 'condition' => $condition, 'value' => $value]]]);
            $this->assertTrue(app('validator')->make($request->all(), $request->rules())->fails());
        }
    }

    public function testUpgradeAddsTheNullableIndexedColumnWithoutBackfillingOrLosingUsers(): void
    {
        $user = $this->user(['balance' => 54321]);
        DB::getSchemaBuilder()->table('v2_user', function (Blueprint $table) { $table->dropIndex('v2_user_register_ip_index'); });
        DB::getSchemaBuilder()->table('v2_user', function (Blueprint $table) { $table->dropColumn('register_ip'); });
        $statements = DatabaseUpgrade::statements(file_get_contents(dirname(__DIR__, 2) . '/database/update.sql'));
        $statement = array_values(array_filter($statements, static function ($sql) {
            return preg_match('/\AALTER TABLE `v2_user`\s+ADD `register_ip`/', $sql);
        }));
        $this->assertCount(1, $statement);
        $upgrade = new DatabaseUpgrade(); $upgrade->run($statement[0], true);
        $upgrade->run($statement[0], true);
        $this->assertNull($user->fresh()->register_ip);
        $this->assertSame(54321, (int)$user->fresh()->balance);
        if (DB::connection()->getDriverName() === 'mysql') {
            $indexes = DB::select('SHOW INDEX FROM v2_user WHERE Key_name = ?', ['v2_user_register_ip_index']);
            $this->assertCount(1, $indexes);
        }
    }
}
