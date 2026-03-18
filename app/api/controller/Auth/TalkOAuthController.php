<?php

namespace app\api\controller\Auth;

use app\model\Channel;
use app\model\PhoneSmsLog;
use app\model\Player;
use app\service\ActivityServices;
use app\service\JpSmsServicesServices;
use Exception;
use Respect\Validation\Exceptions\AllOfException;
use Respect\Validation\Validator as v;
use support\Db;
use support\Request;
use support\Response;
use Tinywan\Jwt\JwtToken;
use WebmanTech\LaravelHttpClient\Facades\Http;

class TalkOAuthController
{
    /** 排除  */
    protected $noNeedSign = [];

    /**
     * 日本短信发送
     * @Inject
     * @var JpSmsServicesServices
     */
    protected $JpSMS;

    private const TALK_OAUTH_URL = '/oauth/auth/authorize';
    private const TALK_TOKEN_API_URL = '/oauth/auth/oauthToken';
    private const TALK_PROFILE_API_URL = '/oauth/auth/userProfile';
    /**
     * appid
     * @var array|mixed|null
     */
    private $client_id;

    /**
     * secret
     * @var array|mixed|null
     */
    private $client_secret;

    /**
     * 回调地址
     * @var array|mixed|null
     */
    private $callback_url;

    /**
     * Q-talk请求地址
     * @var array|mixed|null
     */
    private $talk_domain;

    public function __construct()
    {
        $this->talk_domain = Config('talk.talk_domain');
        $this->client_id = Config('talk.client_id');
        $this->client_secret = Config('talk.client_secret');
        $this->callback_url = Config('talk.callback_url');
    }

    /**
     * 绑定Q-talk账号
     * @param Request $request
     * @return Response
     */
    public function bindTalkProfile(Request $request): Response
    {
        $data = $request->all();
        $validator = v::key('type', v::stringType()->notEmpty()->in(['bind', 'register'])->setName(trans('type', [], 'message')))
            ->key('userUid', v::intVal()->notEmpty()->setName(trans('talk_user_uid', [], 'message')));

        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        switch ($data['type']) {
            case 'bind':
                return $this->bindPlayer($data);
            case 'register':
                return $this->registerPlayer($data);
            default:
                return jsonFailResponse('类型错误');
        }
    }

    /**
     * 绑定玩家
     * @param $data
     * @return Response
     */
    protected function bindPlayer($data): Response
    {
        $validator = v::key('phone', v::stringType()->notEmpty()->length(1, 20)->setName(trans('phone', [], 'message')))
            ->key('code', v::stringType()->notEmpty()->length(1, 10)->setName(trans('phone_code', [], 'message')))
            ->key('country_code', v::intVal()->notEmpty()->in(config('sms.open_country_code'))->setName(trans('country_code', [], 'message')))
            ->key('nickname', v::stringType()->setName(trans('talk_nickname', [], 'message')), false)
            ->key('avatar', v::stringType()->setName(trans('avatar', [], 'message')), false);

        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        /** @var Player $player */
        $player = Player::where('phone', $data['phone'])->where('department_id', request()->department_id)->first();
        if (empty($player)) {
            return jsonFailResponse(trans('player_not_fount', [], 'message'));
        }
        if ($player->status == Player::STATUS_STOP) {
            return jsonFailResponse(trans('player_stop', [], 'message'));
        }
        if (!empty($player->talk_user_id)) {
            return jsonFailResponse(trans('player_has_bind_talk', [], 'message'));
        }

        // 验证短信
        if (!verifySMS($data['country_code'], $data['phone'], $data['code'], PhoneSmsLog::TYPE_TALK_BIND)) {
            return jsonFailResponse(trans('phone_code_error', [], 'message'));
        }

        !empty($data['avatar']) && $player->avatar = saveAvatar($data['avatar']);
        !empty($data['nickname']) && $player->name = $data['nickname'];
        $player->talk_user_id = $data['userUid'];
        $player->save();
        addLoginRecord($player->id);

        return jsonSuccessResponse('success', [
            'token' => JwtToken::generateToken([
                'id' => $player->id,
                'avatar' => $player->avatar,
                'phone' => $player->phone,
                'type' => $player->type,
                'currency' => $player->currency,
                'recommended_code' => $player->recommended_code,
            ]),
            'player_activity_phase' => (new ActivityServices(null, $player))->playerUnreceivedActivity()
        ]);
    }

    /**
     * Q-talk注册玩家
     * @param $data
     * @return Response
     */
    protected function registerPlayer($data): Response
    {
        $validator = v::key('nickname', v::stringType()->setName(trans('talk_nickname', [], 'message')), false)
            ->key('avatar', v::stringType()->setName(trans('avatar', [], 'message')), false)
            ->key('talk_phone', v::stringType()->setName(trans('talk_phone', [], 'message')), false)
            ->key('talk_country_code', v::stringType()->setName(trans('talk_country_code', [], 'message')), false);

        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        $departmentId = request()->department_id;
        /** @var Channel $channel */
        $channel = Channel::where('department_id', $departmentId)->first();
        if (empty($channel)) {
            return jsonFailResponse(trans('channel_not_found', [], 'message'));
        }
        // 储存玩家信息
        Db::beginTransaction();
        try {
            if (!empty($data['talk_phone']) && in_array(trim(trim($data['talk_country_code'], '+')), config('sms.open_country_code'))) {
                /** @var Player $player */
                $player = Player::where('phone', $data['talk_phone'])->where('country_code', $data['talk_country_code'])->where('department_id', $departmentId)->first();
                if (!empty($player)) {
                    if ($player->status == Player::STATUS_STOP) {
                        throw new Exception(trans('player_stop', [], 'message'));
                    }

                    !empty($data['avatar']) && $player->avatar = saveAvatar($data['avatar']);
                    !empty($data['nickname']) && $player->name = $data['nickname'];
                    $player->talk_user_id = $data['userUid'];
                    $player->save();
                } else {
                    $player = createPlayer($data, $channel->currency, $departmentId, true);
                }
            } else {
                $player = createPlayer($data, $channel->currency, $departmentId);
            }

            addLoginRecord($player->id);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return jsonFailResponse($e->getMessage());
        }

        return jsonSuccessResponse('success', [
            'token' => JwtToken::generateToken([
                'id' => $player->id,
                'avatar' => $player->avatar,
                'phone' => $player->phone,
                'type' => $player->type,
                'currency' => $player->currency,
                'recommended_code' => $player->recommended_code,
            ]),
            'player_activity_phase' => (new ActivityServices(null, $player))->playerUnreceivedActivity()
        ]);
    }


    /**
     * 获取Q-talk账号信息
     * @param Request $request
     * @return Response
     */
    public function getTalkProfile(Request $request): Response
    {
        $data = $request->all();
        $validator = v::key('authorizeCode', v::stringType()->notEmpty()->setName(trans('talk_auth_code', [], 'message')));

        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }

        try {
            $token = $this->fetchTokenInfo($data['authorizeCode']);
            $userInfo = $this->fetchUserInfo($token);
            /** @var Player $player */
            $player = Player::where('talk_user_id', $userInfo['userUid'])->where('department_id', request()->department_id)->first();
            if (empty($player)) {
                return $this->registerPlayer([
                    'userUid' => $userInfo['userUid'],
                    'nickname' => $userInfo['nickname'],
                    'avatar' => $this->talk_domain . $userInfo['userAvatarFileName'],
                    'talk_phone' => $userInfo['phone'],
                    'talk_country_code' => $userInfo['areaCode'],
                ]);
            } else {
                if ($player->status == Player::STATUS_STOP) {
                    return jsonFailResponse(trans('player_stop', [], 'message'));
                }
                addLoginRecord($player->id);

                return jsonSuccessResponse('success', [
                    'token' => JwtToken::generateToken([
                        'id' => $player->id,
                        'avatar' => $player->avatar,
                        'phone' => $player->phone,
                        'type' => $player->type,
                        'currency' => $player->currency,
                        'recommended_code' => $player->recommended_code,
                    ]),
                    'player_activity_phase' => (new ActivityServices(null, $player))->playerUnreceivedActivity()
                ]);
            }
        } catch (\Exception $e) {
            return jsonFailResponse($e->getMessage());
        }
    }

    /**
     * 获取用户信息
     * @param $token
     * @return mixed|void
     * @throws Exception
     */
    private function fetchUserInfo($token)
    {
        $response = Http::timeout(5)->withToken($token)->get($this->talk_domain . self::TALK_PROFILE_API_URL);
        if ($response->status() == 200) {
            $data = json_decode($response->body(), true);
            if (empty($data) || (isset($data['errcode']) && $data['errcode'] != 200) || (isset($data['code']) && $data['code'] == 0) || empty($data['data'])) {
                throw new Exception($data->errmsg ?? '获取Q-talk账号用户信息失败');
            }
            return $data['data'];
        }
        throw new Exception('获取用户信息失败');
    }

    /**
     * 获取token
     * @param $code
     * @return mixed
     * @throws Exception
     */
    private function fetchTokenInfo($code)
    {
        $response = Http::timeout(5)->asJson()->get($this->talk_domain . self::TALK_TOKEN_API_URL, [
            'appId' => $this->client_id,
            'appSecret' => $this->client_secret,
            'authorizeCode' => $code,
        ]);
        if ($response->status() == 200) {
            $data = json_decode($response->body(), true);
            if (empty($data) || (isset($data['errcode']) && $data['errcode'] != 200) || (isset($data['code']) && $data['code'] == 0) || empty($data['data']['oauthToken'])) {
                throw new Exception($data->errmsg ?? '获取token失败');
            }
            return $data['data']['oauthToken'];
        }
        throw new Exception('获取token失败');
    }
}
