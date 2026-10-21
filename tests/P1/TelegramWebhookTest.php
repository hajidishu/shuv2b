<?php
namespace Tests\P1;

use App\Http\Controllers\V1\Admin\ConfigController;
use App\Http\Requests\Admin\ConfigSave;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TelegramWebhookTest extends TestCase
{
    protected function setUp(): void
    {
        Environment::boot(false);
    }

    private function service()
    {
        return new class extends TelegramService {
            public $calls = [];
            public function getMe() { $this->calls[] = 'getMe'; return (object)['ok' => true]; }
            public function setWebhook(string $url) { $this->calls[] = $url; return (object)['ok' => true]; }
        };
    }

    public static function validDomains()
    {
        return [
            ['https://bot.example.test', 'https://bot.example.test'],
            ['HTTPS://Bot.Example.test/', 'https://bot.example.test'],
            ['https://bot.example.test:443/', 'https://bot.example.test'],
            ['https://bot.example.test:8443', 'https://bot.example.test:8443'],
            ['https://bot.example.test:88', 'https://bot.example.test:88'],
            ['https://bot.example.test:80', 'https://bot.example.test:80'],
        ];
    }

    /** @dataProvider validDomains */
    public function testHttpsOriginsAreAccepted($domain, $normalized)
    {
        $this->assertSame($normalized, TelegramService::normalizeWebhookDomain($domain));
        $factory = new Factory(Environment::$app['translator'], Environment::$app);
        $rules = (new ConfigSave())->rules();
        $this->assertFalse($factory->make(['telegram_webhook_domain' => $domain], ['telegram_webhook_domain' => $rules['telegram_webhook_domain']])->fails());
    }

    public static function invalidDomains()
    {
        return array_map(function ($value) { return [$value]; }, [
            'http://bot.example.test', 'bot.example.test', '//bot.example.test', 'javascript:alert(1)',
            'https://name:secret@bot.example.test', 'https://bot.example.test/path',
            'https://bot.example.test?token=value', 'https://bot.example.test#fragment',
            'https://bot.example.test:8080', "https://bot.example.test/\r\nheader", [], 123,
        ]);
    }

    /** @dataProvider invalidDomains */
    public function testInvalidDomainsAreRejected($domain)
    {
        $this->assertNull(TelegramService::normalizeWebhookDomain($domain));
        $factory = new Factory(Environment::$app['translator'], Environment::$app);
        $rules = (new ConfigSave())->rules();
        $this->assertTrue($factory->make(['telegram_webhook_domain' => $domain], ['telegram_webhook_domain' => $rules['telegram_webhook_domain']])->fails());
    }

    public function testWebhookUsesSavedDomainAndTokenInsteadOfRequestHostOrPayload()
    {
        config(['v2board.telegram_webhook_domain' => 'https://BOT.example.test:8443/', 'v2board.telegram_bot_token' => 'fixture-saved-bot-token']);
        $request = Request::create('http://internal.example.test/admin/config/setTelegramWebhook', 'POST', [
            'telegram_webhook_domain' => 'https://payload.example.test', 'telegram_bot_token' => 'different-token',
        ]);
        $request->headers->set('X-Forwarded-Host', 'forwarded.example.test');
        Environment::$app->instance('request', $request);
        $service = $this->service();
        (new ConfigController())->setTelegramWebhook($request, $service);
        $this->assertSame(['getMe', 'https://bot.example.test:8443/api/v1/guest/telegram/webhook?access_token=' . md5('fixture-saved-bot-token')], $service->calls);
    }

    public function testMissingDomainDoesNotFallBackOrContactTelegram()
    {
        config(['v2board.telegram_bot_token' => 'fixture-token']);
        $service = $this->service();
        try {
            (new ConfigController())->setTelegramWebhook(Request::create('https://current.example.test'), $service);
            $this->fail('Domain must be explicitly configured');
        } catch (HttpException $error) {
            $this->assertSame(422, $error->getStatusCode());
            $this->assertSame([], $service->calls);
        }
    }

    public function testMissingTokenDoesNotContactTelegram()
    {
        config(['v2board.telegram_webhook_domain' => 'https://bot.example.test']);
        $service = $this->service();
        try {
            (new ConfigController())->setTelegramWebhook(Request::create('https://current.example.test'), $service);
            $this->fail('Token must be saved before registration');
        } catch (HttpException $error) {
            $this->assertSame(422, $error->getStatusCode());
            $this->assertSame([], $service->calls);
        }
    }
}
