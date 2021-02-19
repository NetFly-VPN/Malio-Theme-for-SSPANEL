<?php
/**
 * Created by PhpStorm.
 * User: tonyzou
 * Date: 2018/9/24
 * Time: 下午4:23
 */

namespace App\Services\Gateway;

use App\Models\Paylist;
use App\Models\Payback;
use App\Models\User;
use App\Models\Shop;
use App\Models\Code;
use App\Models\Coupon;
use App\Models\Bought;
use App\Services\Config;
use App\Utils\Telegram;
use Slim\Http\{Request, Response};

abstract class AbstractPayment
{
    /**
     * @param Request   $request
     * @param Response  $response
     * @param array     $args
     */
    abstract public function purchase($request, $response, $args);

    /**
     * @param Request   $request
     * @param Response  $response
     * @param array     $args
     */
    abstract public function notify($request, $response, $args);

    /**
     * @param Request   $request
     * @param Response  $response
     * @param array     $args
     */
    abstract public function getReturnHTML($request, $response, $args);

    /**
     * @param Request   $request
     * @param Response  $response
     * @param array     $args
     */
    abstract public function getStatus($request, $response, $args);

    abstract public function getPurchaseHTML();

    public function postPayment($pid, $method)
    {
        $p = Paylist::where('tradeno', $pid)->first();

        if ($p->status == 1) {
            return json_encode(['errcode' => 0]);
        }

        $p->status = 1;
        $p->save();
        $user = User::find($p->userid);
        // 先保存code，防止用户账号余额重复到账
        $codeq = new Code();
        $codeq->code = $method;
        $codeq->isused = 1;
        $codeq->type = -1;
        $codeq->number = $p->total;
        $codeq->usedatetime = date('Y-m-d H:i:s');
        $codeq->userid = $user->id;
        $codeq->tradeno = $pid;

        $user->money += $p->total;
        $user->save();
        if (!$codeq->save()) {
            return json_encode(['errcode' => 0]); // failed
        }

        if ($user->ref_by >= 1) {
            $gift_user = User::where('id', '=', $user->ref_by)->first();
            $gift_user->money += ($codeq->number * (Config::get('code_payback') / 100));
            $gift_user->save();
            $Payback = new Payback();
            $Payback->total = $codeq->number;
            $Payback->userid = $user->id;
            $Payback->ref_by = $user->ref_by;
            $Payback->ref_get = $codeq->number * (Config::get('code_payback') / 100);
            $Payback->datetime = time();
            $Payback->save();
        }

        if (Config::get('enable_donate') == true) {
            if ($user->is_hide == 1) {
                Telegram::Send('一位不愿透露姓名的大老爷给我们捐了 ' . $codeq->number . ' 元!');
            } else {
                Telegram::Send($user->user_name . ' 大老爷给我们捐了 ' . $codeq->number . ' 元！');
            }
        }


        // 付款成功后，开通用户对应的商品
        $content = file_get_contents(BASE_PATH."/storage/{$user->id}_purchase_directly_{$pid}");
        if ($content) {
            $parts = explode(",", $content);
            if (count($parts) === 2) {
            $this->buyAfterPaymentNotify($parts[0], $parts[1], $user);
            }
        }

        return 0;
    }

    public function buyAfterPaymentNotify($shop, $coupon, $user)
    {
        $disableothers = true;
        $autorenew = false;

        $coupon = trim($coupon);
        $code = $coupon;

        $shop = Shop::where('id', $shop)->where('status', 1)->first();

        if (!$shop) {
            return;
        }

        $credit = 0;
        if ($coupon) {
            $coupon = Coupon::where('code', $coupon)->first();

            if ($coupon) {
                if ($coupon->onetime == 1) {
                    $onetime = true;
                }

                $credit = $coupon->credit;
            }
        }

        $price = $shop->price * ((100 - $credit) / 100);

        $user->money = bcsub($user->money, $price, 2);
        $user->save();

        if ($disableothers == 1) {
            $boughts = Bought::where('userid', $user->id)->get();
            foreach ($boughts as $disable_bought) {
                $disable_bought->renew = 0;
                $disable_bought->save();
            }
        }

        $bought = new Bought();
        $bought->userid = $user->id;
        $bought->shopid = $shop->id;
        $bought->datetime = time();
        if ($autorenew == 0 || $shop->auto_renew == 0) {
            $bought->renew = 0;
        } else {
            $bought->renew = time() + $shop->auto_renew * 86400;
        }
        if (isset($onetime)) {
            $bought->renew = 0;
        }
        $bought->coupon = $code;
        $bought->price = $price;
        $bought->save();

        $shop->buy($user);

        return;
    }

    public function postPaymentMaliopay($pid, $method)
    {
        $p = Paylist::where('tradeno', $pid)->first();

        if ($p->status == 1) {
            return json_encode(['errcode' => 0]);
        }

        $p->status = 1;
        $p->save();

        $user = User::find($p->userid);

        // 先保存code，防止用户账号余额重复到账
        $codeq = new Code();
        $codeq->code = $method;
        $codeq->isused = 1;
        $codeq->type = -1;
        $codeq->number = $p->total;
        $codeq->usedatetime = date('Y-m-d H:i:s');
        $codeq->userid = $user->id;
        $codeq->tradeno = $pid;

        $user->money += $p->total;
        $user->save();
        if (!$codeq->save()) {
            return 0; // failed
        }

        if ($user->ref_by >= 1) {
            $gift_user = User::where('id', '=', $user->ref_by)->first();
            $gift_user->money += ($codeq->number * (Config::get('code_payback') / 100));
            $gift_user->save();
            $Payback = new Payback();
            $Payback->total = $codeq->number;
            $Payback->userid = $user->id;
            $Payback->ref_by = $user->ref_by;
            $Payback->ref_get = $codeq->number * (Config::get('code_payback') / 100);
            $Payback->datetime = time();
            $Payback->save();
        }

        if (Config::get('enable_donate') == 'true') {
            if ($user->is_hide == 1) {
                Telegram::Send('一位不愿透露姓名的大老爷给我们捐了 ' . $codeq->number . ' 元!');
            } else {
                Telegram::Send($user->user_name . ' 大老爷给我们捐了 ' . $codeq->number . ' 元！');
            }
        }
        return 0;
    }

    public static function generateGuid()
    {
        mt_srand((double)microtime() * 10000);
        $charid = strtoupper(md5(uniqid(mt_rand() + time(), true)));
        $hyphen = chr(45);
        $uuid = chr(123)
            . substr($charid, 0, 8) . $hyphen
            . substr($charid, 8, 4) . $hyphen
            . substr($charid, 12, 4) . $hyphen
            . substr($charid, 16, 4) . $hyphen
            . substr($charid, 20, 12)
            . chr(125);
        $uuid = str_replace(['}', '{', '-'], '', $uuid);
        $uuid = substr($uuid, 0, 8);
        return $uuid;
    }
}
