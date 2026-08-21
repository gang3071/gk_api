<?php

namespace app\api\controller\web;

use app\exception\PlayerCheckException;
use app\model\AdminDevice;
use app\model\Announcement;
use app\model\Channel;
use app\model\PhoneSmsLog;
use app\model\Player;
use app\model\SystemSetting;
use app\service\SmsServicesServices;
use app\service\TwSmsServicesServices;
use Respect\Validation\Exceptions\AllOfException;
use Respect\Validation\Validator as v;
use support\Request;
use support\Response;
use Tinywan\Jwt\JwtToken;
use Webman\RateLimiter\Annotation\RateLimiter;

/**
 * 手機板玩家控制器
 * Class IndexController
 * @package app\api\controller\web
 */
class IndexController
{
    /** 排除 */
    protected $noNeedSign = [];

    #[RateLimiter(limit: 5)]
    /**
     * 發送簡訊驗證碼
     * @param Request $request
     * @return Response
     */
    public function smsSend(Request $request): Response
    {
        $data = $request->all();
        $validator = v::key('phone',
            v::stringType()->notEmpty()->regex('/^09\d{8}$/')->setName(trans('phone', [], 'message')));

        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return apiFailResponse(getValidationMessages($e), [], 'INVALID_PARAMS');
        }

        /** @var Player $player */
        $player = Player::query()->where('phone', $data['phone'])->where('department_id', request()->department_id)->first();
        if (empty($player)) {
            return apiFailResponse(trans('phone_not_register', [], 'message'), [], 'PLAYER_NOT_FOUND');
        }
        if ($player->status == Player::STATUS_STOP) {
            return apiFailResponse(trans('player_stop', [], 'message'), [], 'PLAYER_STOP');
        }

        try {
            SmsServicesServices::sendSms(PhoneSmsLog::COUNTRY_CODE_TW, $data['phone'], PhoneSmsLog::TYPE_LOGIN,
                $player->id, $player->name);
        } catch (\Exception $e) {
            return apiFailResponse($e->getMessage(), [], 'SMS_SEND_FAILED');
        }

        return apiSuccessResponse('ok', [
            'expiresIn' => (new TwSmsServicesServices())->expireTime,
            'resendAfter' => 0,
        ]);
    }

    #[RateLimiter(limit: 5)]
    /**
     * 簡訊驗證碼登入
     * @param Request $request
     * @return Response
     */
    public function smsLogin(Request $request): Response
    {
        /** @var Channel $channel */
        $channel = Channel::query()->where('department_id', request()->department_id)->first();
        if ($channel->web_login_status == 0) {
            return apiFailResponse(trans('web_login_close', [], 'message'), [], 'LOGIN_CLOSED');
        }

        $data = $request->all();
        $validator = v::key('phone', v::stringType()->notEmpty()->setName(trans('phone', [], 'message')))
            ->key('code', v::stringType()->notEmpty()->setName(trans('phone_code', [], 'message')));

        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return apiFailResponse(getValidationMessages($e), [], 'INVALID_PARAMS');
        }

        // 驗證簡訊驗證碼
        if (!verifySMS('886', $data['phone'], $data['code'], PhoneSmsLog::TYPE_LOGIN)) {
            return apiFailResponse(trans('phone_code_error', [], 'message'), [], 'AUTH_INVALID_CODE');
        }

        /** @var Player $player */
        $player = Player::where('phone', $data['phone'])->where('department_id', request()->department_id)->first();
        if (empty($player)) {
            return apiFailResponse(trans('player_not_fount', [], 'message'), [], 'PLAYER_NOT_FOUND');
        }
        if ($player->status == Player::STATUS_STOP) {
            return apiFailResponse(trans('player_stop', [], 'message'), [], 'PLAYER_STOP');
        }

        // 跨店登入校驗
        $deviceCheck = $this->checkDeviceStoreMatch($player);
        if ($deviceCheck !== true) {
            return $deviceCheck;
        }

        addLoginRecord($player->id);

        return $this->authLoginSuccess($player);
    }

    #[RateLimiter(limit: 5)]
    /**
     * 帳號密碼登入
     * @param Request $request
     * @return Response
     */
    public function passwordLogin(Request $request): Response
    {
        /** @var Channel $channel */
        $channel = Channel::query()->where('department_id', request()->department_id)->first();
        if ($channel->web_login_status == 0) {
            return apiFailResponse(trans('web_login_close', [], 'message'), [], 'LOGIN_CLOSED');
        }

        $data = $request->all();
        $validator = v::key('account', v::stringType()->notEmpty()->setName(trans('account', [], 'message')))
            ->key('password', v::stringType()->notEmpty()->setName(trans('password', [], 'message')));

        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return apiFailResponse(getValidationMessages($e), [], 'INVALID_PARAMS');
        }

        /** @var Player $player */
        $player = Player::query()->where('department_id', request()->department_id)
            ->where(function ($query) use ($data) {
                $query->where('name', $data['account'])->orWhere('phone', $data['account']);
            })
            ->first();
        if (empty($player)) {
            return apiFailResponse(trans('player_not_fount', [], 'message'), [], 'PLAYER_NOT_FOUND');
        }
        if ($player->status == Player::STATUS_STOP) {
            return apiFailResponse(trans('player_stop', [], 'message'), [], 'PLAYER_STOP');
        }
        if (empty($player->password)) {
            return apiFailResponse(trans('must_set_password', [], 'message'), [], 'PASSWORD_NOT_SET');
        }
        if (!password_verify($data['password'], $player->password)) {
            return apiFailResponse(trans('password_error', [], 'message'), [], 'PASSWORD_ERROR');
        }

        // 跨店登入校驗
        $deviceCheck = $this->checkDeviceStoreMatch($player);
        if ($deviceCheck !== true) {
            return $deviceCheck;
        }

        addLoginRecord($player->id);

        return $this->authLoginSuccess($player);
    }

    #[RateLimiter(limit: 5)]
    /**
     * 刷新 Token
     * @param Request $request
     * @return Response
     */
    public function refresh(Request $request): Response
    {
        $data = $request->all();
        $refreshToken = $data['refreshToken'] ?? $request->header('Authorization', '');
        $refreshToken = trim($refreshToken);
        if (str_starts_with($refreshToken, 'Bearer ')) {
            $refreshToken = substr($refreshToken, 7);
        }
        if (empty($refreshToken)) {
            return apiFailResponse(trans('please_relogin', [], 'message'), [], 'AUTH_EXPIRED');
        }

        $request->setHeader('authorization', 'Bearer ' . $refreshToken);

        $extend = [];
        try {
            $newToken = JwtToken::refreshToken($extend);
        } catch (\Throwable $e) {
            \support\Log::warning('[AuthRefresh] token刷新失敗', [
                'error' => $e->getMessage(),
                'department_id' => request()->department_id,
            ]);
            return apiFailResponse(trans('please_relogin', [], 'message'), [], 'AUTH_EXPIRED');
        }

        if (empty($extend['id'])) {
            return apiFailResponse(trans('please_relogin', [], 'message'), [], 'AUTH_EXPIRED');
        }

        /** @var Player $player */
        $player = Player::where('id', $extend['id'])->where('department_id', request()->department_id)->first();
        if (empty($player)) {
            return apiFailResponse(trans('player_not_fount', [], 'message'), [], 'PLAYER_NOT_FOUND');
        }
        if ($player->status == Player::STATUS_STOP) {
            return apiFailResponse(trans('player_stop', [], 'message'), [], 'PLAYER_STOP');
        }

        return apiSuccessResponse('ok', [
            'accessToken' => $newToken['access_token'],
            'refreshToken' => $newToken['refresh_token'] ?? '',
            'expiresIn' => config('plugin.tinywan.jwt.app.jwt.access_exp'),
        ]);
    }

    /**
     * 系統公告列表
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException
     */
    public function announcements(Request $request): Response
    {
        $player = checkPlayer();
        $page = (int)($request->get('page', 1));
        $pageSize = (int)($request->get('pageSize', 10));

        $query = Announcement::query()
            ->where('status', 1)
            ->where('department_id', $player->department_id)
            ->where('push_time', '<=', date('Y-m-d H:i:s'))
            ->where(function ($query) {
                $query->where('valid_time', '>=', date('Y-m-d H:i:s'))
                    ->orWhereNull('valid_time');
            })
            ->orderBy('priority', 'desc')
            ->orderBy('push_time', 'desc');

        $total = (clone $query)->count();
        $list = $query->forPage($page, $pageSize)->get();

        $announcements = [];

        /** @var Announcement $item */
        foreach ($list as $item) {
            $announcements[] = [
                'id' => (string)$item->id,
                'title' => $item->title,
                'body' => $item->content,
                'date' => !empty($item->push_time) ? date('Y/m/d', strtotime($item->push_time)) : '',
                'pinned' => $item->priority >= Announcement::PRIORITY_SENIOR,
            ];
        }

        return apiSuccessResponse('ok', [
            'list' => $announcements,
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $total,
        ]);
    }

    /**
     * 查询跑马灯
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException
     */
    public function marquee(Request $request): Response
    {
        $player = checkPlayer();

        $marqueeContent = SystemSetting::query()
            ->where('feature', 'marquee')
            ->where('department_id', $player->department_id)
            ->where('status', 1)
            ->value('content');

        return apiSuccessResponse('ok', [
            'content' => $marqueeContent ?? '',
        ]);
    }

    #[RateLimiter(limit: 5)]
    /**
     * 登出
     * @return Response
     * @throws PlayerCheckException
     */
    public function authLogout(): Response
    {
        checkPlayer();
        if (JwtToken::clear(getDeviceType())) {
            return apiSuccessResponse('ok');
        }

        return apiFailResponse(trans('logout_failed', [], 'message'), [], 'LOGOUT_FAILED');
    }

    /**
     * 登入成功統一回應
     * @param Player $player
     * @return Response
     */
    protected function authLoginSuccess(Player $player): Response
    {
        $tokenPayload = [
            'id' => $player->id,
            'avatar' => $player->avatar,
            'phone' => $player->phone,
            'type' => $player->type,
            'currency' => $player->currency,
            'recommended_code' => $player->recommended_code,
            'client' => getDeviceType(),
        ];

        $token = JwtToken::generateToken($tokenPayload);

        return apiSuccessResponse('ok', [
            'accessToken' => $token['access_token'],
            'refreshToken' => $token['refresh_token'],
            'expiresIn' => $token['expires_in'],
            'player' => [
                'id' => $player->id,
                'uid' => $player->uuid,
                'account' => $player->account,
                'nickname' => $player->name,
                'avatar' => $player->avatar,
                'phone' => $player->phone,
                'currency' => $player->currency,
                'machinePlayNum' => $player->machine_play_num,
                'createdAt' => $player->created_at,
            ],
        ]);
    }

    #[RateLimiter(limit: 5)]
    /**
     * 校验设备与玩家是否属于同一店铺
     * @param Player $player
     * @return bool|Response
     */
    protected function checkDeviceStoreMatch(Player $player): mixed
    {
        $deviceCpuId = request()->header('DeviceCpuID', '');
        if (empty($deviceCpuId)) {
            return true;
        }

        $setting = SystemSetting::query()->where('feature', 'device_collect')->where('status', 1)->first();
        if (!$setting) {
            return true;
        }

        /** @var AdminDevice $device */
        $device = AdminDevice::query()->where('device_no', $deviceCpuId)->first();
        if (!$device) {
            return apiFailResponse(trans('device_not_found', [], 'message'), [], 'DEVICE_NOT_FOUND');
        }

        if ($device->status == 0) {
            return apiFailResponse(trans('device_disabled', [], 'message'), [], 'DEVICE_DISABLED');
        }

        if ($device->store_admin_id != $player->store_admin_id) {
            return apiFailResponse(trans('device_store_mismatch', [], 'message'), [], 'DEVICE_STORE_MISMATCH');
        }

        return true;
    }
}
