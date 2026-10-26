<?php

namespace Tests\P1;

use App\Http\Controllers\V1\Guest\TelegramController;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TelegramWebhookAuthTest extends TestCase
{
    protected function setUp(): void
    {
        Environment::boot(false);
    }

    private function rejected($access): void
    {
        $request = Request::create('/api/v1/guest/telegram/webhook', 'POST', ['access_token' => $access]);
        try { new TelegramController($request); $this->fail('An unauthenticated webhook must be rejected before dispatch'); }
        catch (HttpException $error) { $this->assertSame(401, $error->getStatusCode()); }
    }

    public function testUnconfiguredEmptyAndNonStringBotTokensAlwaysRejectCallbacks(): void
    {
        foreach ([null, '', '   ', false, 0, []] as $token) {
            config(['v2board.telegram_bot_enable' => 1, 'v2board.telegram_bot_token' => $token]);
            $this->rejected(md5(''));
        }
    }

    public function testMissingMalformedAndIncorrectCallbackCredentialsAreRejected(): void
    {
        config(['v2board.telegram_bot_token' => 'fixture-current-bot-token']);
        foreach ([null, '', true, 1, [], md5('fixture-other-bot-token')] as $access) $this->rejected($access);
    }

    public function testLegacyCallbackFormatStillDispatchesWhenNotificationsAreDisabled(): void
    {
        // This setting controls notifications; it is not a webhook-auth toggle.
        config(['v2board.telegram_bot_enable' => 0, 'v2board.telegram_bot_token' => 'fixture-current-bot-token']);
        $request = Request::create('/api/v1/guest/telegram/webhook?access_token=' . md5('fixture-current-bot-token'), 'POST', [
            'message' => ['text' => '/unbind', 'chat' => ['id' => 12345, 'type' => 'private'], 'message_id' => 1],
        ]);
        $controller = new class($request) extends TelegramController {
            public $received;
            public function handle() { $this->received = $this->msg; }
        };
        $controller->webhook($request);
        $this->assertSame('/unbind', $controller->received->command);
        $this->assertSame(12345, $controller->received->chat_id);
    }

    public function testTokenRotationInvalidatesTheOldCallbackCredential(): void
    {
        config(['v2board.telegram_bot_token' => 'fixture-new-bot-token']);
        $this->rejected(md5('fixture-old-bot-token'));
    }
}
