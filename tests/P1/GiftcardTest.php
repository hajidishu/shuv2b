<?php

namespace Tests\P1;

use App\Http\Controllers\V1\User\UserController;
use App\Http\Requests\User\UserRedeemGiftCard;
use App\Models\Giftcard;
use App\Models\Plan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class GiftcardTest extends DatabaseTestCase
{
    private function redeem($user, $card)
    {
        $r = new UserRedeemGiftCard();
        $r->merge(['user' => ['id' => $user->id], 'giftcard' => $card->code]);
        return (new UserController())->redeemgiftcard($r);
    }

    private function card(array $values = [])
    {
        return Giftcard::create(array_merge(['code' => uniqid('card-'), 'type' => 1, 'value' => 10000, 'limit_use' => 1], $values));
    }

    public function testCreditAndClaimAreCommittedTogetherWithNativeJsonArray()
    {
        $user = $this->user(['balance' => 50000]);
        $card = $this->card();
        $this->redeem($user, $card);
        $this->assertSame(60000, (int)$user->fresh()->balance);
        $this->assertSame(0, (int)$card->fresh()->limit_use);
        $this->assertSame([$user->id], $card->fresh()->used_user_ids);
        $raw = DB::table('v2_giftcard')->where('id', $card->id)->value('used_user_ids');
        $this->assertSame([$user->id], json_decode($raw, true));
    }

    public function testUnlimitedCardIsStillSingleUsePerUser()
    {
        $user = $this->user();
        $card = $this->card(['limit_use' => null]);
        $this->redeem($user, $card);
        try {
            $this->redeem($user, $card);
            $this->fail('Same user cannot claim again');
        } catch (HttpException $e) {
            $this->assertSame(10000, (int)$user->fresh()->balance);
            $this->assertSame([$user->id], $card->fresh()->used_user_ids);
            $this->assertNull($card->fresh()->limit_use);
        }
    }

    public function testLegacyDoubleEncodedClaimsRemainRecognized()
    {
        $user = $this->user();
        $card = $this->card(['limit_use' => 5]);
        DB::table('v2_giftcard')->where('id', $card->id)->update(['used_user_ids' => json_encode(json_encode([$user->id]))]);
        try {
            $this->redeem($user, $card);
            $this->fail('Legacy claim must not permit another credit');
        } catch (HttpException $e) {
            $this->assertSame(0, (int)$user->fresh()->balance);
            $this->assertSame(5, (int)$card->fresh()->limit_use);
        }
    }

    public function invalidCards()
    {
        return [[['started_at' => time() + 3600]], [['ended_at' => time() - 60]], [['limit_use' => 0]], [['value' => -1]], [['type' => 5, 'plan_id' => 999, 'value' => 7]]];
    }

    /** @dataProvider invalidCards */
    public function testRejectedCardDoesNotMutateBalanceOrClaims($values)
    {
        $user = $this->user();
        $card = $this->card($values);
        try {
            $this->redeem($user, $card);
            $this->fail('Invalid card should be rejected');
        } catch (HttpException $e) {
            $this->assertSame(0, (int)$user->fresh()->balance);
            $this->assertEmpty($card->fresh()->used_user_ids);
            $this->assertSame(0, DB::transactionLevel());
        }
    }

    public function testClaimSaveFailureRollsBackCreditAndCanRetry()
    {
        $user = $this->user();
        $card = $this->card();
        Giftcard::saving(function () { throw new \RuntimeException('injected claim failure'); });
        try {
            $this->redeem($user, $card);
            $this->fail('Claim save must fail');
        } catch (\RuntimeException $e) {
            $this->assertSame(0, (int)$user->fresh()->balance);
            $this->assertSame(1, (int)$card->fresh()->limit_use);
            $this->assertEmpty($card->fresh()->used_user_ids);
        }
        Giftcard::flushEventListeners();
        $this->redeem($user, $card);
        $this->assertSame(10000, (int)$user->fresh()->balance);
    }

    public function testDurationTrafficResetAndPlanCardsStillWork()
    {
        $expiry = time() + 10 * 86400;
        $user = $this->user(['expired_at' => $expiry, 'transfer_enable' => 1073741824, 'u' => 100, 'd' => 200]);
        $this->redeem($user, $this->card(['type' => 2, 'value' => 2]));
        $this->assertSame($expiry + 2 * 86400, (int)$user->fresh()->expired_at);
        $this->redeem($user, $this->card(['type' => 3, 'value' => 2]));
        $this->assertSame(3 * 1073741824, (int)$user->fresh()->transfer_enable);
        $this->redeem($user, $this->card(['type' => 4, 'value' => null]));
        $this->assertSame(0, (int)$user->fresh()->u);
        $this->assertSame(0, (int)$user->fresh()->d);
        $plan = Plan::create(['group_id' => 3, 'transfer_enable' => 50]);
        $newUser = $this->user();
        $this->redeem($newUser, $this->card(['type' => 5, 'plan_id' => $plan->id, 'value' => 0]));
        $this->assertSame($plan->id, $newUser->fresh()->plan_id);
        $this->assertSame(3, (int)$newUser->fresh()->group_id);
        $this->assertNull($newUser->fresh()->expired_at);
    }
}
