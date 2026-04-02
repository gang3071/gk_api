<?php
/**
 * Here is your custom functions.
 */

use app\exception\PlayerCheckException;
use app\filesystem\Filesystem;
use app\model\ApiErrorLog;
use app\model\Channel;
use app\model\ChannelFinancialRecord;
use app\model\GameType;
use app\model\LevelList;
use app\model\Machine;
use app\model\MachineCategoryGiveRule;
use app\model\MachineKeepingLog;
use app\model\MachineMedia;
use app\model\MachineMediaPush;
use app\model\MachineTencentPlay;
use app\model\NationalProfitRecord;
use app\model\Notice;
use app\model\PhoneSmsLog;
use app\model\Player;
use app\model\PlayerDeliveryRecord;
use app\model\PlayerExtend;
use app\model\PlayerGameLog;
use app\model\PlayerGameRecord;
use app\model\PlayerGiftRecord;
use app\model\PlayerLoginRecord;
use app\model\PlayerLotteryRecord;
use app\model\PlayerMoneyEditLog;
use app\model\PlayerPlatformCash;
use app\model\PlayerPromoter;
use app\model\PlayerRechargeRecord;
use app\model\PlayerRegisterRecord;
use app\model\PlayerWithdrawRecord;
use app\model\StoreSetting;
use app\model\SystemSetting;
use app\service\ActivityServices;
use app\service\FishServices;
use app\service\LotteryServices;
use app\service\machine\MachineServices;
use app\service\machine\Slot;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Respect\Validation\Exceptions\AllOfException;
use support\Cache;
use support\Db;
use support\Log;
use support\Model;
use support\Response;
use think\Exception;
use Tinywan\Jwt\JwtToken;
use Webman\Push\Api;
use Webman\Push\PushException;
use Webman\RedisQueue\Client as queueClient;
use WebmanTech\LaravelHttpClient\Facades\Http;
use Workbunny\WebmanIpAttribution\Exceptions\IpAttributionException;
use Workbunny\WebmanIpAttribution\Location;
use yzh52521\WebmanLock\Locker;

/**
 * @param array $data
 * @param string $message
 * @return Response
 */
function jsonSuccessResponse(string $message = '', array $data = []): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], json_encode([
        'code' => 200,
        'msg' => $message,
        'data' => empty($data) ? new stdClass() : $data,
    ], JSON_UNESCAPED_UNICODE));
}

/**
 * @param array $data
 * @param string $message
 * @param integer $code
 * @return Response
 */
function jsonFailResponse(string $message = '', array $data = [], int $code = 100): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], json_encode([
        'code' => $code,
        'msg' => $message,
        'data' => empty($data) ? new stdClass() : $data,
    ], JSON_UNESCAPED_UNICODE));
}

/**
 * 检查用户
 * @param bool $hasTransfer
 * @return Player
 * @throws PlayerCheckException
 */
function checkPlayer(bool $hasTransfer = true): Player
{
    $departmentId = request()->department_id;
    $id = JwtToken::getCurrentId();
    /** @var Player $player */
    $player = Player::query()->where('id', $id)->where('department_id', $departmentId)->first();
    if (empty($player)) {
        throw new PlayerCheckException(trans('player_not_fount', [], 'message'), 100);
    }

    if ($player->status == Player::STATUS_STOP) {
        throw new PlayerCheckException(trans('player_stop', [], 'message'), 100);
    }
    if ($hasTransfer) {
        queueClient::send('game-transfer', [
            'player_id' => $player->id,
        ]);
    }


    return $player;
}

/**
 * 检查设备是否爆机
 *
 * 只检查钱包的 is_crashed 字段，不判断当前余额
 * 这样可以让最后一笔触发爆机的交易正常完成，从而更新爆机状态和发送通知
 *
 * @param Player $player 玩家对象
 * @return array 返回 ['crashed' => bool, 'crash_amount' => int|null, 'current_amount' => float]
 */
function checkMachineCrash(Player $player): array
{
    // 🚀 优化 #1: 使用 Redis 缓存爆机状态
    $cacheKey = "machine_crash_status:{$player->id}";

    try {
        $cached = \support\Redis::get($cacheKey);

        if ($cached !== null && $cached !== false) {
            // 缓存命中，解析缓存数据
            return json_decode($cached, true);
        }
    } catch (\Exception $e) {
        // Redis 故障时降级到数据库查询
        Log::error('checkMachineCrash: Redis get failed', [
            'player_id' => $player->id,
            'error' => $e->getMessage(),
        ]);
    }

    // 缓存未命中或 Redis 故障，从数据库查询
    $wallet = PlayerPlatformCash::where('player_id', $player->id)
        ->where('platform_id', PlayerPlatformCash::PLATFORM_SELF)
        ->first(['is_crashed', 'money']);

    $currentAmount = $wallet->money ?? 0;
    $isCrashed = $wallet && $wallet->is_crashed == 1;

    // 获取爆机金额配置（用于返回信息）
    $crashAmount = null;
    $adminUserId = $player->store_admin_id ?? null;

    if ($adminUserId) {
        $crashSetting = StoreSetting::getSetting(
            'machine_crash_amount',
            $player->department_id,
            null,
            $adminUserId
        );
        $crashAmount = ($crashSetting && $crashSetting->status == 1) ? ($crashSetting->num ?? 0) : null;
    }

    $result = [
        'crashed' => $isCrashed,
        'crash_amount' => $crashAmount,
        'current_amount' => $currentAmount,
    ];

    // 🚀 优化 #2: 根据爆机状态设置不同的缓存过期时间
    try {
        $ttl = $isCrashed ? 3600 : 600;  // 爆机1小时，未爆机10分钟
        \support\Redis::setex($cacheKey, $ttl, json_encode($result));
    } catch (\Exception $e) {
        // 缓存写入失败不影响业务
        Log::error('checkMachineCrash: Redis setex failed', [
            'player_id' => $player->id,
            'error' => $e->getMessage(),
        ]);
    }

    return $result;
}

/**
 * 通知设备爆机
 * @param Player $player 玩家对象
 * @param array $crashInfo 爆机信息
 * @return void
 */
function notifyMachineCrash(Player $player, array $crashInfo): void
{
    try {
        // 玩家端消息
        $playerMessage = [
            'msg_type' => 'machine_crash',
            'player_id' => $player->id,
            'crash_amount' => $crashInfo['crash_amount'],
            'current_amount' => $crashInfo['current_amount'],
            'message' => '⚠️ 您的設備餘額已達到爆機金額，請聯繫管理員處理！',
            'timestamp' => time(),
        ];

        // 后台消息（包含更多信息）
        $adminMessage = [
            'msg_type' => 'machine_crash',
            'event' => 'player_crashed',
            'player_id' => $player->id,
            'player_name' => $player->name ?? '',
            'player_uuid' => $player->uuid ?? '',
            'store_admin_id' => $player->store_admin_id ?? null,
            'department_id' => $player->department_id,
            'crash_amount' => $crashInfo['crash_amount'],
            'current_amount' => $crashInfo['current_amount'],
            'message' => "设备已爆机：{$player->name} (ID:{$player->id}) 余额达到 {$crashInfo['current_amount']}，超过爆机金额 {$crashInfo['crash_amount']}",
            'timestamp' => time(),
        ];

        // 1. 发送给玩家
        $playerChannel = 'player-' . $player->id;
        sendSocketMessage([$playerChannel], $playerMessage, 'system');

        // 2. 发送给渠道后台
        $channelAdminChannel = 'private-admin_group-channel-' . $player->department_id;
        sendSocketMessage($channelAdminChannel, $adminMessage, 'system');

        // 3. 创建通知记录（渠道后台）
        $channelNotice = new Notice();
        $channelNotice->department_id = $player->department_id;
        $channelNotice->player_id = $player->id;
        $channelNotice->source_id = $player->id;
        $channelNotice->type = Notice::TYPE_MACHINE_CRASH;
        $channelNotice->receiver = Notice::RECEIVER_DEPARTMENT;
        $channelNotice->is_private = 0;
        $channelNotice->title = '設備爆機通知';
        $channelNotice->content = "設備已爆機：玩家 {$player->name} (UID:{$player->uuid}) 餘額達到 " . number_format($crashInfo['current_amount'], 2) . "，超過爆機金額 " . number_format($crashInfo['crash_amount'], 2) . "，請聯繫管理員處理！";
        $channelNotice->save();

        Log::info('Machine crash notification sent', [
            'player_id' => $player->id,
            'player_name' => $player->name,
            'store_admin_id' => $player->store_admin_id,
            'department_id' => $player->department_id,
            'crash_amount' => $crashInfo['crash_amount'],
            'current_amount' => $crashInfo['current_amount'],
        ]);
    } catch (Exception $e) {
        Log::error('Failed to send machine crash notification', [
            'player_id' => $player->id,
            'error' => $e->getMessage(),
        ]);
    }
}

/**
 * 计算爆机状态下允许的最大洗分金额
 * 用于渠道后台洗分：如果余额超过爆机金额，只能洗到爆机金额
 * @param Player $player 玩家对象
 * @param float $requestedAmount 请求洗分的金额
 * @return array 返回 ['allowed_amount' => float, 'is_limited' => bool, 'crash_info' => array]
 */
function calculateAllowedWithdrawAmount(Player $player, float $requestedAmount): array
{
    $crashCheck = checkMachineCrash($player);
    $currentAmount = $player->machine_wallet->money ?? 0;
    $allowedAmount = $requestedAmount;
    $isLimited = false;

    // 如果当前爆机，并且有爆机金额设置
    if ($crashCheck['crashed'] && $crashCheck['crash_amount'] > 0) {
        // 最多只能洗到刚好等于爆机金额
        // 即：当前余额 - 爆机金额 = 最大可洗金额
        $maxAllowedAmount = $currentAmount - $crashCheck['crash_amount'];

        if ($maxAllowedAmount < 0) {
            $maxAllowedAmount = 0;
        }

        if ($requestedAmount > $maxAllowedAmount) {
            $allowedAmount = $maxAllowedAmount;
            $isLimited = true;
        }
    }

    return [
        'allowed_amount' => $allowedAmount,
        'is_limited' => $isLimited,
        'crash_info' => $crashCheck,
        'original_amount' => $requestedAmount,
    ];
}

/**
 * 检查并通知爆机解锁
 * 用于洗分后检查是否已解锁爆机状态
 * @param Player $player 玩家对象
 * @param float $previousAmount 洗分前的余额
 * @return void
 */
function checkAndNotifyCrashUnlock(Player $player, float $previousAmount): void
{
    try {
        $crashCheckBefore = checkMachineCrash($player);

        // 如果当前没有爆机，检查之前是否爆机
        if (!$crashCheckBefore['crashed'] && $crashCheckBefore['crash_amount'] > 0) {
            // 检查之前的余额是否达到爆机金额
            $wasCrashed = $previousAmount >= $crashCheckBefore['crash_amount'];

            // 如果之前爆机，现在已解锁，发送通知
            if ($wasCrashed) {
                // 玩家端消息
                $playerMessage = [
                    'msg_type' => 'machine_crash_unlock',
                    'player_id' => $player->id,
                    'crash_amount' => $crashCheckBefore['crash_amount'],
                    'current_amount' => $crashCheckBefore['current_amount'],
                    'message' => '✓ 您的设备爆机状态已解除，可继续正常使用。',
                    'timestamp' => time(),
                ];

                // 1. 发送给玩家
                $playerChannel = 'player-' . $player->id;
                sendSocketMessage([$playerChannel], $playerMessage, 'system');

                Log::info('Machine crash unlock notification sent', [
                    'player_id' => $player->id,
                    'player_name' => $player->name,
                    'store_admin_id' => $player->store_admin_id,
                    'department_id' => $player->department_id,
                    'previous_amount' => $previousAmount,
                    'current_amount' => $crashCheckBefore['current_amount'],
                    'crash_amount' => $crashCheckBefore['crash_amount'],
                ]);
            }
        }
    } catch (Exception $e) {
        Log::error('Failed to check and notify crash unlock', [
            'player_id' => $player->id,
            'error' => $e->getMessage(),
        ]);
    }
}

/**
 * 生成唯一邀请码
 * @return string
 */
function createCode(): string
{
    $code = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    do {
        $rand = $code[rand(0, 25)] . strtoupper(dechex(date('m'))) . date('d') . substr(time(),
                -5) . substr(microtime(), 2,
                5) . sprintf('%02d', rand(0, 99));

        for ($a = md5($rand,
            true), $s = '0123456789ABCDEFGHIJKLMNOPQRSTUV', $d = '', $f = 0; $f < 8; $g = ord($a[$f]), $d .= $s[($g ^ ord($a[$f + 8])) - $g & 0x1F], $f++) {
        }
    } while (Player::query()->where('recommend_code', $d)->withTrashed()->exists());

    return $d;
}

/**
 * 获取验证消息
 * @param AllOfException $e
 * @return mixed
 */
function getValidationMessages(AllOfException $e): mixed
{
    $message = $e->getMessages([
        'notOptional' => trans('required', [], 'validator'),
        'notEmpty' => trans('required', [], 'validator'),
        'email' => trans('email', [], 'validator'),
        'idCard' => trans('idCard', [], 'validator'),
        'url' => trans('url', [], 'validator'),
        'number' => trans('number', [], 'validator'),
        'integer' => trans('integer', [], 'validator'),
        'float' => trans('float', [], 'validator'),
        'mobile' => trans('mobile', [], 'validator'),
        'length' => trans('length', [], 'validator'),
        'alpha' => trans('alpha', [], 'validator'),
        'alnum' => trans('alnum', [], 'validator'),
        'alphaDash' => trans('alphaDash', [], 'validator'),
        'chs' => trans('chs', [], 'validator'),
        'chsAlpha' => trans('chsAlpha', [], 'validator'),
        'chsAlphaNum' => trans('chsAlphaNum', [], 'validator'),
        'chsDash' => trans('chsDash', [], 'validator'),
        'equals' => trans('equals', [], 'validator'),
        'in' => trans('in', [], 'validator'),
        'image' => trans('image', [], 'validator'),
        'creditCard' => trans('creditCard', [], 'validator'),
        'digit' => trans('digit', [], 'validator'),
        'base64' => trans('base64', [], 'validator'),
        'arrayVal' => trans('arrayVal', [], 'validator'),
    ])['key'];
    $message = is_array($message) ? Arr::first($message) : $message;

    return $message ?? trans('validation_error', [], 'message');
}

/**
 * 生成uuid
 * @return string
 */
function gen_uuid(): string
{
    $uuid['time_low'] = mt_rand(0, 0xffff) + (mt_rand(0, 0xffff) << 16);
    $uuid['time_mid'] = mt_rand(0, 0xffff);
    $uuid['time_hi'] = (4 << 12) | (mt_rand(0, 0x1000));
    $uuid['clock_seq_hi'] = (1 << 7) | (mt_rand(0, 128));
    $uuid['clock_seq_low'] = mt_rand(0, 255);

    for ($i = 0; $i < 6; $i++) {
        $uuid['node'][$i] = mt_rand(0, 255);
    }

    return sprintf('%08x-%04x-%04x-%02x%02x-%02x%02x%02x%02x%02x%02x',
        $uuid['time_low'],
        $uuid['time_mid'],
        $uuid['time_hi'],
        $uuid['clock_seq_hi'],
        $uuid['clock_seq_low'],
        $uuid['node'][0],
        $uuid['node'][1],
        $uuid['node'][2],
        $uuid['node'][3],
        $uuid['node'][4],
        $uuid['node'][5]
    );
}

/**
 * 機台是否維護中
 * @return bool
 */
function machineMaintaining(): bool
{
    //每周機台維護時段
    /** @var SystemSetting $setting */
    $setting = SystemSetting::query()->where('feature', 'machine_maintain')->first();
    if (!$setting || $setting->status == 0) {
        return false;
    } else {
        $week = $setting->num;
        $time_start = $setting->date_start;
        $time_end = $setting->date_end;
        $today_week = date('w');
        if ($today_week == '0') {
            $today_week = '7';
        }
        //判斷星期是否一樣
        if ($week != $today_week) {
            return false;
        }
        if (!empty($time_start) && !empty($time_end)) {
            $date_start = date('Y-m-d') . ' ' . $time_start;
            $date_end = date('Y-m-d') . ' ' . $time_end;
            if ($date_start > $date_end) {
                return false;
            }
            $now = time();
            //維護中
            if ($now >= strtotime($date_start) && $now <= strtotime($date_end)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * 获取游戏类型
 * @return array
 */
function getGameTypeCateList(): array
{
    return [
        [
            'id' => GameType::CATE_PHYSICAL_MACHINE,
            'name' => trans('game_type_cate.' . GameType::CATE_PHYSICAL_MACHINE, [], 'message')
        ],
        [
            'id' => GameType::CATE_COMPUTER_GAME,
            'name' => trans('game_type_cate.' . GameType::CATE_COMPUTER_GAME, [], 'message')
        ],
        [
            'id' => GameType::CATE_LIVE_VIDEO,
            'name' => trans('game_type_cate.' . GameType::CATE_LIVE_VIDEO, [], 'message')
        ],
    ];
}

/**
 * @param $player_id
 * @param int $is_system
 * @return bool
 */
function kickSingleMachinePlayer($player_id, int $is_system = 0): bool
{
    /** @var Player $player */
    $player = Player::query()->find($player_id);
    $machines = Machine::query()->where('gaming_user_id', $player->id)->get();
    /** @var Machine $machine */
    foreach ($machines as $machine) {
        try {
            $services = MachineServices::createServices($machine);
            if ($services->reward_status == 1) {
                continue;
            }
            machineWash($player, $machine, 'leave', $is_system);
        } catch (\Exception $e) {
            Log::error('kickSingleMachinePlayer: ' . $e->getMessage());
            continue;
        }
    }

    return true;
}

/**
 * 金额转换
 * @param $number
 * @return float|int
 */
function floorToPointSecondNumber($number): float|int
{
    return floor($number * 100) / 100;
}


/**
 * 上任意分
 * @param Player $player
 * @param Machine $machine
 * @param float $money
 * @param float $giftScore
 * @param MachineCategoryGiveRule|null $machineCategoryGiveRule
 * @return true
 * @throws Exception|PushException
 */
function machineOpenAny(
    Player                   $player,
    Machine                  $machine,
    float                    $money,
    float                    $giftScore,
    ?MachineCategoryGiveRule $machineCategoryGiveRule
): bool
{
    $orgTurn = 0;
    // 增加业务锁
    $actionLockerKey = 'machine_open_lock' . $machine->id;
    $lock = Locker::lock($actionLockerKey, 8, true);
    if (!$lock->acquire()) {
        throw new Exception(trans('machine_is_using_msg1', [], 'message'));
    }
    $lang = locale();
    $lang = Str::replace('_', '-', $lang);
    $services = MachineServices::createServices($machine, $lang);
    $giftCache = Cache::get('gift_cache_' . $machine->id . '_' . $player->id);
    $orgMachineGameUserId = $machine->gaming_user_id;
    if ($giftCache) {
        switch ($machine->type) {
            case GameType::TYPE_STEEL_BALL:
                if ($services->turn == 0) {
                    $services->gift_bet = 0;
                    Cache::delete('gift_cache_' . $machine->id . '_' . $player->id);
                } else {
                    throw new Exception(trans('currently_remaining_score', [], 'message'));
                }
                break;
            case GameType::TYPE_SLOT:
                if ($services->reward_status == 1) {
                    if ($services->point <= 0 && !empty($machineCategoryGiveRule)) {
                        throw new Exception(trans('reward_again_open_give', [], 'message'));
                    }
                    if ($services->point > 0) {
                        throw new Exception(trans('reward_point_zero_open_give', [], 'message'));
                    }
                } else {
                    if ($services->point > 0) {
                        throw new Exception(trans('use_give_point_zero', [], 'message'));
                    }
                }
                break;
        }
    }

    if ($player->machine_wallet->money < $money) {
        throw new Exception(trans('game_amount_insufficient', [], 'message'));
    }
    if ($services->last_point_at + 5 >= time() && Cache::has('machine_open_point' . $machine->id . '_' . $player->id)) {
        throw new Exception(trans('machine_open_wash_too_fast', [], 'message'));
    }
    //可以玩多台
    if ($player->machine_play_num > 1) {
        $self_machine_count = Machine::query()->where('gaming_user_id', $player->id)->where('id', '!=',
            $machine->id)->count();
        if ($player->machine_play_num <= $self_machine_count) {
            throw new Exception(trans('machine_only_msg1', [], 'message'));
        }
    }
    Cache::set('machine_open_point' . $machine->id . '_' . $player->id, 1, 5);

    DB::beginTransaction();
    try {
        //先扣點
        $player->machine_wallet->decrement('money', $money);
        $beforeGameAmount = $player->machine_wallet->money + $money;
        $afterGameAmount = $player->machine_wallet->money;
        $openScore = checkMachineOpenAny($machine, $money, $giftScore);
        if ($machine->min_point != 0 && $machine->min_point > $openScore) {
            throw new Exception(trans('machine_min_open', [], 'message') . $machine->min_point);
        }
        if ($machine->max_point != 0 && ($machine->max_point < $services->point || $machine->max_point < $openScore || $machine->max_point < ($services->point + $openScore))) {
            throw new Exception(trans('machine_max_open', [], 'message') . $machine->max_point);
        }

        //檢查玩家是否能開贈
        if ($player->status_open_point == 0) {
            throw new Exception(trans('machine_present_error_msg2', [], 'message'));
        }
        //斯洛分數不得超過2000
        if ($machine->type == GameType::TYPE_SLOT) {
            if ($services->point + $openScore > 4000) {
                throw new Exception(trans('machine_wash_limit_msg1', [], 'message'));
            }
        }
        if ($machine->gaming == 0 && $services->reward_status == 0) {
            switch ($machine->type) {
                case GameType::TYPE_SLOT:
                    if ($services->point > 0) {
                        $services->sendCmd($services::WASH_ZERO, 0, 'player', $player->id);
                    }
                    break;
                case GameType::TYPE_STEEL_BALL:
                    if ($machine->control_type == Machine::CONTROL_TYPE_MEI) {
                        if ($services->score > 0) {
                            $services->sendCmd($services::SCORE_TO_POINT, 0, 'player', $player->id);
                        }
                        if ($services->turn > 0) {
                            $services->sendCmd($services::TURN_DOWN_ALL, 0, 'player', $player->id);
                        }
                        if ($services->point > 0) {
                            $services->sendCmd($services::WASH_ZERO, 0, 'player', $player->id);
                        }
                    }
                    if ($machine->control_type == Machine::CONTROL_TYPE_SONG) {
                        $services->sendCmd($services::MACHINE_SCORE, 0, 'player', $player->id);
                        if ($services->score > 0) {
                            $services->sendCmd($services::SCORE_TO_POINT, 0, 'player', $player->id);
                        }
                        $services->sendCmd($services::MACHINE_TURN, 0, 'player', $player->id);
                        if ($services->turn > 0) {
                            $services->sendCmd($services::TURN_DOWN_ALL, 0, 'player', $player->id);
                        }
                        $services->sendCmd($services::MACHINE_POINT, 0, 'player', $player->id);
                        if ($services->point > 0) {
                            $services->sendCmd($services::WASH_ZERO, 0, 'player', $player->id);
                        }
                    }
                    break;
            }
        }
        //记录游戏局记录
        /** @var PlayerGameRecord $gameRecord */
        $gameRecord = PlayerGameRecord::query()->where('machine_id', $machine->id)
            ->where('player_id', $player->id)
            ->where('status', PlayerGameRecord::STATUS_START)
            ->orderBy('created_at', 'desc')
            ->first();
        if (!empty($gameRecord)) {
            // 超过一天的数据更新为已完结
            if (time() - strtotime($gameRecord->updated_at) > 24 * 60 * 60 * 2 && $machine->gaming_user_id != $player->id) {
                $gameRecord->status = PlayerGameRecord::STATUS_END;
            }
            $gameRecord->open_point = bcadd($gameRecord->open_point, $openScore, 2);
            $gameRecord->open_amount = bcadd($gameRecord->open_amount, $money, 2);
            $gameRecord->give_amount = bcadd($gameRecord->give_amount, $giftScore, 2);
            $gameRecord->save();
        }
        /** @var MachineMedia $media */
        $playerGameLog = createGameLog($gameRecord, $machine, $player, $openScore, $money, $afterGameAmount,
            $giftScore,
            $beforeGameAmount);
        if ($machineCategoryGiveRule) {
            $playersGiftRecord = new PlayerGiftRecord();
            $playersGiftRecord->player_game_log_id = $playerGameLog->id;
            $playersGiftRecord->machine_category_give_rule_id = $machineCategoryGiveRule->id;
            $playersGiftRecord->machine_id = $machine->id;
            $playersGiftRecord->player_id = $player->id;
            $playersGiftRecord->player_name = $player->name;
            $playersGiftRecord->machine_name = $machine->name;
            $playersGiftRecord->machine_type = $machine->type;
            $playersGiftRecord->open_num = $machineCategoryGiveRule->open_num;
            $playersGiftRecord->give_num = $machineCategoryGiveRule->give_num;
            $playersGiftRecord->condition = $machineCategoryGiveRule->condition;
            $playersGiftRecord->created_at = date('Y-m-d H:i:s');
            $playersGiftRecord->updated_at = date('Y-m-d H:i:s');
            $playersGiftRecord->save();
        }
        if ($machine->gaming == 0) {
            $machine->last_game_at = date('Y-m-d H:i:s');
        }
        $machine->gaming = 1;
        $machine->gaming_user_id = $player->id;
        $machine->last_point_at = date('Y-m-d H:i:s');
        $machine->save();
        //寫入金流明細
        createPlayerDeliveryRecord($player, $playerGameLog, $machine, $money, $beforeGameAmount, $afterGameAmount);
        if ($player->channel->activity_status == 1) {
            // 写入玩家活动参与记录
            $ActivityServices = new ActivityServices($machine, $player);
            $ActivityServices->addPlayerActivityRecord();
        }
        DB::commit();
    } catch (\Exception $e) {
        DB::rollback();
        throw new Exception($e->getMessage());
    }
    try {
        $services->player_open_point = bcadd($services->player_open_point, $openScore);
        $services->last_point_at = time();
        $services->last_play_time = time();
        switch ($machine->type) {
            case GameType::TYPE_STEEL_BALL:
                $services->sendCmd($services::OPEN_ANY_POINT, $openScore, 'player', $player->id);
                // 钢珠使用开分赠点,需要全部上转
                if ($giftScore > 0) {
                    $orgTurn = $services->turn;
                    $services->sendCmd($services::TURN_UP_ALL, 0, 'player', $player->id);
                }
                break;
            case GameType::TYPE_SLOT:
                // 只在换人或首次开分时清理机台状态
                if ($orgMachineGameUserId != $player->id) {
                    if ($machine->control_type == Machine::CONTROL_TYPE_MEI) {
                        $services->sendCmd($services::MOVE_POINT_OFF, 0, 'player', $player->id);
                    }
                    $bet = $services->bet;
                    $win = $services->win;
                    if ($bet > 0 || $win > 0) {
                        $services->sendCmd($services::ALL_DOWN, 0, 'player', $player->id);
                    }
                    $services->player_score = 0;
                    $services->player_pressure = 0;
                }
                $services->sendCmd($services::OPEN_ANY_POINT, $openScore, 'player', $player->id);
                break;
        }

        // 只在换人或首次开分时清理钢珠机台数据
        if ($orgMachineGameUserId != $player->id) {
            if ($machine->type == GameType::TYPE_STEEL_BALL) {
                if ($services->win_number > 0) {
                    $services->sendCmd($services::CLEAR_LOG, 0, 'player', $player->id);
                }
                $services->player_win_number = 0;
            }

            // 发送玩家上分消息，剔除其他观看中玩家
            sendSocketMessage('group-' . $machine->id, [
                'msg_type' => 'machine_start',
                'machine_id' => $machine->id,
                'machine_name' => $machine->name,
                'machine_code' => $machine->code,
                'gaming_user_id' => $machine->gaming_user_id,
            ]);

            /** @var SystemSetting $setting */
            $setting = SystemSetting::where('feature', 'gift_keeping_minutes')->where('status', 1)->first();
            if (!empty($setting) && $setting->num >= 0) {
                $services->keep_seconds = bcmul($setting->num, 60);
                // 发送增加保留时长消息
                sendSocketMessage('player-' . $machine->gaming_user_id . '-' . $machine->id, [
                    'msg_type' => 'player_machine_keeping',
                    'player_id' => $machine->gaming_user_id,
                    'machine_id' => $machine->id,
                    'keep_seconds' => $services->keep_seconds,
                    'keeping' => $services->keeping
                ]);
                sendSocketMessage('player-' . $machine->gaming_user_id, [
                    'msg_type' => 'player_machine_keeping',
                    'player_id' => $machine->gaming_user_id,
                    'machine_id' => $machine->id,
                    'keep_seconds' => $services->keep_seconds,
                    'keeping' => $services->keeping
                ]);
            }
        }

        // 所有开分操作都设置 gaming 状态，避免缓存丢失导致误判
        $services->gaming = 1;
        $services->gaming_user_id = $player->id;

        if ($giftScore > 0) {
            switch ($machine->type) {
                case GameType::TYPE_SLOT:
                    $services->gift_condition = $machineCategoryGiveRule->condition;
                    break;
                case GameType::TYPE_STEEL_BALL:
                    $services->gift_bet = $services->win_number;
                    break;
                default:
                    $services->gift_bet = 0;
            }
            Cache::set('gift_cache_' . $machine->id . '_' . $player->id, [
                'time' => millisecond(),
                'type' => $machine->type,
                'money' => $money,
                'gift_point' => $giftScore,
                'open_point' => $playerGameLog->open_point,
                'condition' => $machine->type == GameType::TYPE_SLOT ? $machineCategoryGiveRule->condition : ($services->turn - $orgTurn),
            ]);
        }
    } catch (\Exception $e) {
        $log = Log::channel('song_jackpot_machine');

        $log->error('消息处理错误: ', [
            $e->getTrace(),
            $e->getMessage()
        ]);
        throw new Exception(trans('open_any_fail', [$e->getTrace(), $e->getMessage()], 'message'));
    } finally {
        $lock->release();
    }

    return true;
}

/**
 * 检查是否可以点击开分赠点按钮
 * @param Machine $machine
 * @param Player $player
 * @return bool
 * @throws PushException
 */
function isAllowClientGivePoint(Machine $machine, Player $player): bool
{
    try {
        $services = MachineServices::createServices($machine, $player);
        switch ($machine->type) {
            case GameType::TYPE_SLOT:
                $services->sendCmd($services::READ_SCORE, 0, 'player', $player->id);
                if ($machine->control_type == Machine::CONTROL_TYPE_MEI) {
                    $services->sendCmd($services::READ_CREDIT2, 0, 'player', $player->id);
                }
                if ($services->point > 0 || $services->score > 0) {
                    return false;
                }
                break;
            case GameType::TYPE_STEEL_BALL:
                $services->sendCmd($services::MACHINE_POINT, 0, 'player', $player->id, 1);
                $services->sendCmd($services::MACHINE_SCORE, 0, 'player', $player->id, 1);
                $services->sendCmd($services::MACHINE_TURN, 0, 'player', $player->id, 1);
                if ($services->point > 0 || $services->score > 0 || $services->turn > 0) {
                    return false;
                }
                break;
        }
    } catch (Exception $e) {
        return false;
    }
    return true;
}

/**
 * 上任意分
 * @param Machine $machine
 * @param int $money
 * @param int $giftScore
 * @return float|int
 * @throws Exception
 */
function checkMachineOpenAny(Machine $machine, int $money, int $giftScore): float|int
{
    if (!is_numeric($money) || $money <= 0) {
        throw new InvalidArgumentException('Invalid money value');
    }
    if (!is_numeric($machine->odds_x) || $machine->odds_x <= 0) {
        throw new InvalidArgumentException('Invalid odds_x value');
    }
    if (!is_numeric($machine->odds_y) || $machine->odds_y <= 0) {
        throw new InvalidArgumentException('Invalid odds_y value');
    }
    if ($machine->odds_x == 0) {
        throw new Exception(trans('machine_odds_error', [], 'message'));
    }
    $yx = $machine->odds_y / $machine->odds_x;
    if ($machine->odds_y > $machine->odds_x && floor($yx) != $yx) {
        throw new Exception(trans('machine_odds_error', [], 'message'));
    }
    $open_score = $money * $machine->odds_y / $machine->odds_x;

    return floor($open_score) + $giftScore;
}

/**
 * 添加用户登录信息
 * @param $id
 * @return PlayerLoginRecord|Model
 */
function addLoginRecord($id): PlayerLoginRecord|Model
{
    $ip = request()->getRealIp();
    if (!empty($ip)) {
        try {
            $location = new Location();
            $result = $location->getLocation($ip);
        } catch (IpAttributionException $exception) {
            Log::error('获取ip信息错误');
        }
    }
    $country_name = ($result['country'] ?? '') . ($result['city'] ?? '');
    $domain = isset($_SERVER['HTTP_ORIGIN']) ? parse_url($_SERVER['HTTP_ORIGIN']) : null;

    return PlayerLoginRecord::query()->create([
        'player_id' => $id,
        'login_domain' => !empty($domain) ? $domain['host'] : null,
        'ip' => $ip,
        'country_name' => $country_name,
        'city_name' => $result['city'] ?? '',
        'remark' => $request->remark ?? null,
        'department_id' => request()->department_id,
    ]);
}

/**
 * 添加用户注册信息
 * @param $id
 * @param $type
 * @param $department_id
 * @return PlayerRegisterRecord|Model
 */
function addRegisterRecord($id, $type, $department_id): PlayerRegisterRecord|Model
{
    $ip = request()->getRealIp();
    if (!empty($ip)) {
        try {
            $location = new Location();
            $result = $location->getLocation($ip);
        } catch (IpAttributionException $exception) {
            Log::error('获取ip信息错误');
        }
    }
    $country_name = ($result['country'] ?? '') . ($result['city'] ?? '');
    $domain = isset($_SERVER['HTTP_ORIGIN']) ? parse_url($_SERVER['HTTP_ORIGIN']) : null;

    return PlayerRegisterRecord::query()->create([
        'player_id' => $id,
        'register_domain' => !empty($domain) ? $domain['host'] : null,
        'ip' => $ip,
        'country_name' => $country_name,
        'city_name' => $result['city'] ?? '',
        'device' => 'app',
        'type' => $type,
        'department_id' => $department_id,
    ]);
}

/**
 * 更新保留日志
 * @param $machineId
 * @param $playerId
 * @return void
 */
function updateKeepingLog($machineId, $playerId): void
{
    /** @var MachineKeepingLog $machineKeepingLog */
    $machineKeepingLog = MachineKeepingLog::query()->where([
        'machine_id' => $machineId,
        'player_id' => $playerId
    ])->where('status', MachineKeepingLog::STATUS_STAR)->first();
    if ($machineKeepingLog) {
        // 更新保留日志
        $machineKeepingLog->keep_seconds = time() - strtotime($machineKeepingLog->created_at);
        $machineKeepingLog->status = MachineKeepingLog::STATUS_END;
        $machineKeepingLog->save();
    }
}

/**
 * 保存头像到本地
 * @param $avatar
 * @return string
 */
function saveAvatar($avatar): string
{
    if (empty($avatar)) {
        return '';
    }
    try {
        if (strpos($avatar, 'http://') === 0 || strpos($avatar, 'https://') === 0) {
            $client = new Client(['verify' => false]);  //忽略SSL错误
            $fileName = md5($avatar) . '.jpg';
            $path = public_path() . '/storage/avatar/';
            if (!is_dir($path) && !mkdir($path, 0777, true)) {
                throw new Exception('创建文件夹失败');
            }
            $client->request('GET', $avatar, ['sink' => public_path('/storage/avatar/' . $fileName)]);
        } else {
            throw new Exception('网络地址错误');
        }
    } catch (Exception|GuzzleException $e) {
        Log::error('保存头像错误' . $e->getMessage());
        return '';
    }

    return '/storage/avatar/' . $fileName;
}

/**
 * 生成唯一单号
 * @return string
 */
function createOrderNo(): string
{

    $yCode = [
        'A',
        'B',
        'C',
        'D',
        'E',
        'F',
        'G',
        'H',
        'I',
        'J',
        'K',
        'L',
        'M',
        'N',
        'O',
        'P',
        'Q',
        'R',
        'S',
        'T',
        'U',
        'V',
        'W',
        'X',
        'Y',
        'Z'
    ];
    return $yCode[intval(date('Y')) - 2011] . strtoupper(dechex(date('m'))) . date('d') . substr(time(),
            -5) . substr(microtime(), 2, 5) . sprintf('%02d', rand(0, 99));
}

/**
 * Q-talk充值成功
 * @param PlayerRechargeRecord $recharge
 * @return bool
 */
function talkPaySuccess(PlayerRechargeRecord $recharge): bool
{
    DB::beginTransaction();
    try {
        $beforeGameAmount = $recharge->player->machine_wallet->money;
        $recharge->status = PlayerRechargeRecord::STATUS_RECHARGED_SUCCESS;
        $recharge->finish_time = date('Y-m-d H:i:s');
        $recharge->player->machine_wallet->money = bcadd($recharge->player->machine_wallet->money,
            $recharge->point);
        $recharge->player->player_extend->recharge_amount = bcadd($recharge->player->player_extend->withdraw_amount,
            $recharge->point);
        $recharge->player->player_extend->third_recharge_amount = bcadd($recharge->player->player_extend->third_withdraw_amount,
            $recharge->point);
        $recharge->push();
        //寫入金流明細
        $playerDeliveryRecord = new PlayerDeliveryRecord;
        $playerDeliveryRecord->player_id = $recharge->player_id;
        $playerDeliveryRecord->department_id = $recharge->department_id;
        $playerDeliveryRecord->target = $recharge->getTable();
        $playerDeliveryRecord->target_id = $recharge->id;
        $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_RECHARGE;
        $playerDeliveryRecord->source = 'talk_recharge';
        $playerDeliveryRecord->amount = $recharge->point;
        $playerDeliveryRecord->amount_before = $beforeGameAmount;
        $playerDeliveryRecord->amount_after = $recharge->player->machine_wallet->money;
        $playerDeliveryRecord->tradeno = $recharge->tradeno ?? '';
        $playerDeliveryRecord->remark = $recharge->remark ?? '';
        $playerDeliveryRecord->save();

        DB::commit();
    } catch (\Exception $e) {
        DB::rollBack();
        Log::error($e->getMessage());
        return false;
    }
    return true;
}

/**
 * 设置短信key
 * @param string $phone 手机号
 * @param int $type 模式 1 为修改密码短信
 * @return string
 */
function setSmsKey(string $phone, int $type): string
{
    return match ($type) {
        PhoneSmsLog::TYPE_LOGIN => 'sms-login' . $phone,
        PhoneSmsLog::TYPE_REGISTER => 'sms-register' . $phone,
        PhoneSmsLog::TYPE_CHANGE_PASSWORD => 'sms-change-password' . $phone,
        PhoneSmsLog::TYPE_CHANGE_PAY_PASSWORD => 'sms-change-pay-password' . $phone,
        PhoneSmsLog::TYPE_CHANGE_PHONE => 'sms-change-phone' . $phone,
        PhoneSmsLog::TYPE_BIND_NEW_PHONE => 'sms-type-bind-new-phone' . $phone,
        PhoneSmsLog::TYPE_TALK_BIND => 'sms-type-talk-bind' . $phone,
        default => 'sms-' . $phone,
    };
}

/**
 * 验证短信
 * @param string $country_code 国家编号
 * @param string $phone 手机号
 * @param string $code 验证码
 * @param int $type 类型
 * @return string
 */
function verifySMS(string $country_code, string $phone, string $code, int $type): string
{
    $phoneCode = Cache::get(setSmsKey($phone, $type));

    return $phoneCode == $code;
}

/**
 * 获取短信消息
 * @param int $type 模式 1 为修改密码短信
 * @param string $source 来源
 * @return string
 */
function getContent(int $type, string $source): string
{
    return match ($type) {
        PhoneSmsLog::TYPE_LOGIN => config($source . '-sms.login_content'),
        PhoneSmsLog::TYPE_REGISTER => config($source . '-sms.register_content'),
        PhoneSmsLog::TYPE_CHANGE_PASSWORD => config($source . '-sms.change_password_content'),
        PhoneSmsLog::TYPE_CHANGE_PAY_PASSWORD => config($source . '-sms.change_pay_password'),
        PhoneSmsLog::TYPE_CHANGE_PHONE => config($source . '-sms.change_phone'),
        PhoneSmsLog::TYPE_BIND_NEW_PHONE => config($source . '-sms.bind_new_phone'),
        PhoneSmsLog::TYPE_TALK_BIND => config($source . '-sms.talk_bind'),
        PhoneSmsLog::TYPE_LINE_BIND => config($source . '-sms.line_bind'),
        default => config($source . '-sms.sm_content'),
    };
}

/**
 * 提现订单回滚
 * @param PlayerWithdrawRecord $playerWithdrawRecord
 * @param string $rejectReason
 * @param int $withdrawStatus
 * @return string
 * @throws Exception
 */
function withdrawBack(
    PlayerWithdrawRecord $playerWithdrawRecord,
    string               $rejectReason = '',
    int                  $withdrawStatus = PlayerWithdrawRecord::STATUS_PENDING_REJECT
): string
{
    DB::beginTransaction();
    try {
        // 更新提现订单
        $playerWithdrawRecord->status = $withdrawStatus;
        $playerWithdrawRecord->reject_reason = $rejectReason;
        $playerWithdrawRecord->finish_time = date('Y-m-d H:i:s');
        $playerWithdrawRecord->user_id = 0;
        $playerWithdrawRecord->user_name = 'system';
        // 更新玩家钱包
        $beforeGameAmount = $playerWithdrawRecord->player->machine_wallet->money;
        $playerWithdrawRecord->player->machine_wallet->money = bcadd($playerWithdrawRecord->player->machine_wallet->money,
            $playerWithdrawRecord->point, 2);
        // 跟新玩家统计
        $playerWithdrawRecord->player->player_extend->withdraw_amount = bcsub($playerWithdrawRecord->player->player_extend->withdraw_amount,
            $playerWithdrawRecord->point, 2);
        $playerWithdrawRecord->push();
        //寫入金流明細
        $playerDeliveryRecord = new PlayerDeliveryRecord;
        $playerDeliveryRecord->player_id = $playerWithdrawRecord->player_id;
        $playerDeliveryRecord->department_id = $playerWithdrawRecord->department_id;
        $playerDeliveryRecord->target = $playerWithdrawRecord->getTable();
        $playerDeliveryRecord->target_id = $playerWithdrawRecord->id;
        $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_WITHDRAWAL_BACK;
        $playerDeliveryRecord->source = 'withdraw_back';
        $playerDeliveryRecord->amount = $playerWithdrawRecord->point;
        $playerDeliveryRecord->amount_before = $beforeGameAmount;
        $playerDeliveryRecord->amount_after = $playerWithdrawRecord->player->machine_wallet->money;
        $playerDeliveryRecord->tradeno = $playerWithdrawRecord->tradeno ?? '';
        $playerDeliveryRecord->remark = $playerWithdrawRecord->remark ?? '';
        $playerDeliveryRecord->save();

        DB::commit();
    } catch (\Exception $e) {
        DB::rollBack();
        throw new Exception($e->getMessage());
    }
    return true;
}

/**
 * 添加渠道财务操作
 * @param $target
 * @param $action
 * @return void
 */
function saveChannelFinancialRecord($target, $action): void
{
    $channelFinancialRecord = new ChannelFinancialRecord();
    $channelFinancialRecord->action = $action;
    $channelFinancialRecord->department_id = 0;
    $channelFinancialRecord->player_id = $target->player_id ?? 0;
    $channelFinancialRecord->target = $target->getTable();
    $channelFinancialRecord->target_id = $target->id;
    $channelFinancialRecord->user_id = 0;
    $channelFinancialRecord->tradeno = $target->tradeno ?? '';
    $channelFinancialRecord->user_name = 'system';
    $channelFinancialRecord->save();
}

/**
 * 保存网络头像
 * @param $avatar
 * @return false|mixed|string
 */
function saveImg($avatar): mixed
{
    try {
        $avatarContent = file_get_contents($avatar);
        if ($avatarContent === false) {
            return config('def_avatar.1');
        }
        $imageInfo = getimagesizefromstring($avatarContent);
        if ($imageInfo === false) {
            return config('def_avatar.1');
        }
    } catch (\Exception $e) {
        return config('def_avatar.1');
    }

    $mimeType = $imageInfo['mime'];
    switch ($mimeType) {
        case 'image/jpeg':
            $format = 'jpg';
            break;
        case 'image/png':
            $format = 'png';
            break;
        case 'image/gif':
            $format = 'gif';
            break;
        default:
            return config('def_avatar.1');
    }
    $savePath = '/storage/avatar/' . date("Ymd", time()) . "/";
    $newPath = public_path() . $savePath;
    if (!file_exists($newPath)) {
        mkdir($newPath, 0755, true);
    }
    $filename = time() . '_' . uniqid() . ".{$format}"; //文件名
    $newPath = $newPath . $filename;
    if (file_put_contents($newPath, $avatarContent)) {
        $avatar = env('APP_URL', 'http://127.0.0.1:8787') . $savePath . $filename;
    }

    return $avatar;
}

/**
 * 创建玩家
 * @param $data array Q-talk数据
 * @param $currency string 币种
 * @param $departmentId int 渠道id
 * @param bool $phoneBind
 * @return Player
 */
function createPlayer(array $data, string $currency, int $departmentId, bool $phoneBind = false): Player
{
    $player = new Player();
    $player->uuid = generate15DigitUniqueId();
    $player->type = Player::TYPE_PLAYER;
    $player->department_id = $departmentId;
    $player->currency = $currency;
    $player->avatar = config('def_avatar.1');
    if (!empty($data['avatar'])) {
        $player->avatar = saveAvatar($data['avatar']);
    }
    !empty($data['nickname']) && $player->name = $data['nickname'];
    if ($phoneBind) {
        !empty($data['talk_phone']) && $player->phone = trim(trim($data['talk_phone'],
            '+'), '');
        !empty($data['talk_country_code']) && $player->country_code = trim($data['talk_country_code'],
            '+');
    }
    isset($data['userUid']) && !empty($data['userUid']) && $player->talk_user_id = $data['userUid'];
    $player->save();

    addPlayerExtend($player);

    addRegisterRecord($player->id, PlayerRegisterRecord::TYPE_TALK, $player->department_id);

    return $player;
}

/**
 * 发送socket消息
 * @param $channels
 * @param $content
 * @param string $form
 * @return bool|string
 * @throws PushException
 */
function sendSocketMessage($channels, $content, string $form = 'system'): bool|string
{
    try {
        // 发送进入保留状态消息
        $api = new Api(
            'http://127.0.0.1:3232',
            config('plugin.webman.push.app.app_key'),
            config('plugin.webman.push.app.app_secret')
        );
        return $api->trigger($channels, 'message', [
            'from_uid' => $form,
            'content' => json_encode($content)
        ]);
    } catch (Exception $e) {
        Log::error('sendSocketMessage', [$e->getMessage()]);
        return false;
    }
}

/**
 * 增加玩家扩展信息
 * @param Player $player
 * @return void
 */
function addPlayerExtend(Player $player): void
{
    $registerPresent = SystemSetting::query()->where('feature', 'register_present')->where('status', 1)->value('num') ?? 0;

    PlayerPlatformCash::query()->firstOrCreate([
        'player_id' => $player->id,
        'platform_id' => PlayerPlatformCash::PLATFORM_SELF,
        'money' => $registerPresent,
    ]);

    PlayerExtend::query()->firstOrCreate([
        'player_id' => $player->id,
    ]);

    if (isset($registerPresent) && $registerPresent > 0) {
        //添加玩家钱包日志
        $playerMoneyEditLog = new PlayerMoneyEditLog;
        $playerMoneyEditLog->player_id = $player->id;
        $playerMoneyEditLog->department_id = $player->department_id;
        $playerMoneyEditLog->type = PlayerMoneyEditLog::TYPE_INCREASE;
        $playerMoneyEditLog->action = PlayerMoneyEditLog::OTHER;
        $playerMoneyEditLog->tradeno = date('YmdHis') . rand(10000, 99999);
        $playerMoneyEditLog->currency = $player->currency;
        $playerMoneyEditLog->money = $registerPresent;
        $playerMoneyEditLog->inmoney = $registerPresent;
        $playerMoneyEditLog->remark = '';
        $playerMoneyEditLog->user_id = 0;
        $playerMoneyEditLog->user_name = trans('system_automatic',
            [], 'message');
        $playerMoneyEditLog->save();

        //寫入金流明細
        $playerDeliveryRecord = new PlayerDeliveryRecord;
        $playerDeliveryRecord->player_id = $player->id;
        $playerDeliveryRecord->department_id = $player->department_id;
        $playerDeliveryRecord->target = $playerMoneyEditLog->getTable();
        $playerDeliveryRecord->target_id = $playerMoneyEditLog->id;
        $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_REGISTER_PRESENT;
        $playerDeliveryRecord->source = 'register_present';
        $playerDeliveryRecord->amount = $playerMoneyEditLog->money;
        $playerDeliveryRecord->amount_before = 0;
        $playerDeliveryRecord->amount_after = $registerPresent;
        $playerDeliveryRecord->tradeno = $playerMoneyEditLog->tradeno ?? '';
        $playerDeliveryRecord->remark = $playerMoneyEditLog->remark ?? '';
        $playerDeliveryRecord->save();
    }
}

/**
 * 获取攻略地址
 * @param $strategy_id
 * @return string
 */
function getStrategyUrl($strategy_id): string
{
    return env('STRATEGY_URL', 'http://8.218.226.64:777/#/pages/detail/index?id=') . $strategy_id;
}

/**
 * 获取渠道信息
 * @param $siteId
 * @return Channel|array
 */
function getChannel($siteId): array|Channel
{
    $cacheKey = "channel_" . $siteId;
    $channel = Cache::get($cacheKey);
    if (empty($channel)) {
        /** @var Channel $channel */
        $channel = Channel::query()->where('id', $siteId)->whereNull('deleted_at')->first()->toArray();
        if (!empty($channel)) {
            $cacheKey = "channel_" . $channel->site_id;
            Cache::set($cacheKey, $channel->toArray());
        } else {
            return [];
        }
    }
    return $channel;
}

/**
 * 设置推广员
 * @param $id
 * @param $ratio
 * @param $name
 * @return true
 * @throws Exception
 */
function setPromoter($id, $ratio, $name): bool
{
    DB::beginTransaction();
    try {
        /** @var Player $player */
        $player = Player::query()->find($id);
        if (empty($player)) {
            throw new Exception(trans('player_not_found', [], 'message'));
        }
        if (!empty($player->player_promoter)) {
            throw new Exception(trans('player_is_promoter', [], 'message'));
        }
        $promoter = new PlayerPromoter();

        /** @var PlayerPromoter $parentPromoter */
        $parentPromoter = PlayerPromoter::query()->where('player_id', $player->recommend_id)->first();
        $maxRatio = $parentPromoter->ratio ?? 100;
        if ($ratio > $maxRatio) {
            throw new Exception(trans('ratio_max_error', ['{max_ratio}' => $maxRatio], 'message'));
        }

        /** @var PlayerPromoter $subPromoter */
        $subPromoter = PlayerPromoter::query()->where('recommend_id', $player->id)->orderBy('ratio', 'asc')->first();
        if (!empty($subPromoter)) {
            if ($ratio < $subPromoter->ratio) {
                throw new Exception(trans('ratio_min_error', ['{min_ratio}' => $subPromoter->ratio], 'message'));
            }
        }
        $orgPromoter = $player->is_promoter;
        $path = [];
        if (isset($parentPromoter->path) && !empty($parentPromoter->path)) {
            $path = explode(',', $parentPromoter->path);
        }
        $path[] = $player->id;
        $promoter->ratio = $ratio;
        $promoter->player_id = $player->id;
        $promoter->recommend_id = $parentPromoter->player_id ?? 0;
        $promoter->department_id = $player->department_id;
        $promoter->name = !empty($name) ? $name : $player->name;
        $promoter->path = implode(',', $path);
        $promoter->player_num = Player::query()->where('recommend_id', $player->id)->count() ?? 0;
        $promoter->save();
        // 更新玩家信息
        $player->is_promoter = 1;
        $player->save();

        $parentPromoter && $orgPromoter == 0 && $parentPromoter->increment('team_num');
        DB::commit();
    } catch (\Exception $e) {
        DB::rollBack();
        throw new Exception($e->getMessage());
    }

    return true;
}

/**
 * 设置推广员
 * @param $id
 * @param $ratio
 * @param $name
 * @return true
 * @throws Exception
 */
function setPromoterPortrait($id, $ratio, $name): bool
{
    DB::beginTransaction();
    try {
        /** @var Player $player */
        $player = Player::query()->find($id);
        if (empty($player)) {
            throw new Exception(trans('player_not_found', [], 'message'));
        }
        if (!empty($player->player_promoter)) {
            throw new Exception(trans('player_is_promoter', [], 'message'));
        }
        $promoter = new PlayerPromoter();

        /** @var PlayerPromoter $parentPromoter */
        $parentPromoter = PlayerPromoter::query()->where('player_id', $player->recommend_id)->first();
        $maxRatio = 100;
        if ($ratio > $maxRatio) {
            throw new Exception(trans('ratio_max_error', ['{max_ratio}' => $maxRatio], 'message'));
        }

        /** @var PlayerPromoter $subPromoter */
        $subPromoter = PlayerPromoter::query()->where('recommend_id', $player->id)->orderBy('ratio', 'asc')->first();
        if (!empty($subPromoter)) {
            if ($ratio < 0) {
                throw new Exception(trans('ratio_min_error', ['{min_ratio}' => 0], 'message'));
            }
            //代理上缴比例必须小于他下面所有店家的上缴比例
            if ($ratio > $subPromoter->ratio) {
                throw new Exception(trans('ratio_min_error', ['{min_ratio}' => 0], 'message'));
            }
        }
        $orgPromoter = $player->is_promoter;
        $path = [];
        if (isset($parentPromoter->path) && !empty($parentPromoter->path)) {
            $path = explode(',', $parentPromoter->path);
        }
        $path[] = $player->id;
        $promoter->ratio = $ratio;
        $promoter->player_id = $player->id;
        $promoter->recommend_id = $parentPromoter->player_id ?? 0;
        $promoter->department_id = $player->department_id;
        $promoter->name = !empty($name) ? $name : $player->name;
        $promoter->path = implode(',', $path);
        $promoter->player_num = Player::query()->where('recommend_id', $player->id)->count() ?? 0;
        $promoter->save();
        // 更新玩家信息
        $player->is_promoter = 1;
        $player->save();

        $parentPromoter && $orgPromoter == 0 && $parentPromoter->increment('team_num');
        DB::commit();
    } catch (\Exception $e) {
        DB::rollBack();
        throw new Exception($e->getMessage());
    }

    return true;
}

/**
 * 组装请求
 * @param string $url
 * @param array $params
 * @param int $gaming_user_id
 * @param int $machine_id
 * @return array|mixed|null
 * @throws \Exception
 */
function doCurl(string $url, int $gaming_user_id, int $machine_id, array $params = []): mixed
{
    $result = Http::timeout(7)->contentType('application/json')->accept('application/json')->asJson()->post($url,
        $params);
    if (!isset($result['result'])) {
        $apiErrorLog = new ApiErrorLog;
        $apiErrorLog->player_id = $gaming_user_id;
        $apiErrorLog->target = 'machine';
        $apiErrorLog->target_id = $machine_id;
        $apiErrorLog->url = $url;
        $apiErrorLog->params = json_encode($params);
        $apiErrorLog->content = '後台 api timeout';
        $apiErrorLog->save();
    }
    return $result->json();
}

/**
 * 鱼机上任意分
 * @param Player $player
 * @param Machine $machine
 * @param int $money
 * @param FishServices $services
 * @return Machine
 * @throws Exception
 */
function fishMachineOpenAny(Player $player, Machine $machine, int $money, FishServices $services): Machine
{
    openAnyCheck($machine, $player, $money);
    DB::beginTransaction();
    try {
        //原先餘額
        $beforeGameAmount = $player->machine_wallet->money;
        //先扣點
        $player->machine_wallet->decrement('money', $money);

        if ($player->machine_wallet->money < 0) {
            throw new Exception(trans('game_amount_insufficient', [], 'message'));
        }
        //扣點後餘額
        $afterGameAmount = $player->machine_wallet->money;
        $openScore = checkMachineOpenAny($machine, $money, 0);
        //记录游戏局记录
        /** @var PlayerGameRecord $gameRecord */
        $gameRecord = PlayerGameRecord::query()->where('machine_id', $machine->id)
            ->where('player_id', $player->id)
            ->where('status', PlayerGameRecord::STATUS_START)
            ->orderBy('created_at', 'desc')
            ->first();
        if (!empty($gameRecord)) {
            // 超过一天的数据更新为已完结
            if (time() - strtotime($gameRecord->updated_at) > 24 * 60 * 60 && $player->id != $machine->gaming_user_id) {
                $gameRecord->status = PlayerGameRecord::STATUS_END;
            }
            $gameRecord->open_point = bcadd($gameRecord->open_point, $openScore);
            $gameRecord->open_amount = bcadd($gameRecord->open_amount, $money);
            $gameRecord->save();
        }
        //创建游戏日志
        $playerGameLog = createGameLog($gameRecord, $machine, $player, $openScore, $money, $afterGameAmount, 0,
            $beforeGameAmount);
        //寫入金流明細
        createPlayerDeliveryRecord($player, $playerGameLog, $machine, $money, $beforeGameAmount, $afterGameAmount);
        //更新机台信息
        $machine->open_point = bcadd($machine->open_point, $openScore);
        $machine->gaming = 1;
        $machine->gaming_user_id = $player->id;
        $machine->last_game_at = date('YmdHis');
        $machine->last_point_at = date('YmdHis');
        $machine->save();
        //执行开分api
        $services->machineAction('open_point', ['open_point' => $openScore]);
        DB::commit();
    } catch (\Exception $e) {
        DB::rollback();
        throw new Exception($e->getMessage());
    }

    return $machine;
}

/**
 * @param $gameRecord
 * @param Machine $machine
 * @param Player $player
 * @param $openScore
 * @param int $money
 * @param float $afterGameAmount
 * @param $gift_score
 * @param float $beforeGameAmount
 * @return PlayerGameLog
 */
function createGameLog(
    $gameRecord,
    Machine $machine,
    Player $player,
    $openScore,
    int $money,
    float $afterGameAmount,
    $gift_score,
    float $beforeGameAmount
): PlayerGameLog
{
    if (empty($gameRecord) || $gameRecord->status == PlayerGameRecord::STATUS_END) {
        $gameRecord = new PlayerGameRecord();
        $gameRecord->game_id = $machine->machineCategory->game_id;
        $gameRecord->machine_id = $machine->id;
        $gameRecord->player_id = $player->id;
        $gameRecord->parent_player_id = $player->recommend_id ?? 0;
        $gameRecord->agent_player_id = $player->recommend_promoter->recommend_id ?? 0;
        $gameRecord->type = $machine->type;
        $gameRecord->code = $machine->code;
        $gameRecord->odds = $machine->odds_x . ':' . $machine->odds_y;
        $gameRecord->open_point = $openScore ?? 0;
        $gameRecord->open_amount = $money ?? 0;
        $gameRecord->after_game_amount = $afterGameAmount;
        $gameRecord->save();
    }
    $odds = $machine->odds_x . ':' . $machine->odds_y;
    if ($machine->type == GameType::TYPE_STEEL_BALL) {
        $odds = $machine->machineCategory->name;
    }
    $playerGameLog = new PlayerGameLog;
    $playerGameLog->player_id = $player->id;
    $playerGameLog->parent_player_id = $player->recommend_id ?? 0;
    $playerGameLog->agent_player_id = $player->recommend_promoter->recommend_id ?? 0;
    $playerGameLog->department_id = $player->department_id;
    $playerGameLog->game_id = $machine->machineCategory->game_id;
    $playerGameLog->machine_id = $machine->id;
    $playerGameLog->game_record_id = $gameRecord->id;
    $playerGameLog->type = $machine->type;
    $playerGameLog->odds = $odds;
    $playerGameLog->control_open_point = $machine->control_open_point;
    $playerGameLog->open_point = $openScore;
    $playerGameLog->wash_point = 0;
    $playerGameLog->action = PlayerGameLog::ACTION_OPEN;
    $playerGameLog->gift_point = $gift_score ?? 0;
    $playerGameLog->game_amount = (0 - $money);
    $playerGameLog->before_game_amount = $beforeGameAmount;
    $playerGameLog->after_game_amount = $afterGameAmount;
    $playerGameLog->is_test = $player->is_test ?? 0; //标记为测试数据
    $playerGameLog->save();
    return $playerGameLog;
}

/**
 * @param Player $player
 * @param PlayerGameLog $playerGameLog
 * @param Machine $machine
 * @param int $money
 * @param float $beforeGameAmount
 * @param float $afterGameAmount
 * @return void
 */
function createPlayerDeliveryRecord(
    Player        $player,
    PlayerGameLog $playerGameLog,
    Machine       $machine,
    int           $money,
    float         $beforeGameAmount,
    float         $afterGameAmount
): void
{
    $playerDeliveryRecord = new PlayerDeliveryRecord;
    $playerDeliveryRecord->player_id = $player->id;
    $playerDeliveryRecord->department_id = $player->department_id;
    $playerDeliveryRecord->target = $playerGameLog->getTable();
    $playerDeliveryRecord->target_id = $playerGameLog->id;
    $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_MACHINE_UP;
    $playerDeliveryRecord->machine_id = $machine->id;
    $playerDeliveryRecord->machine_name = $machine->name;
    $playerDeliveryRecord->machine_type = $machine->type;
    $playerDeliveryRecord->code = $machine->code;
    $playerDeliveryRecord->source = 'game_machine';
    $playerDeliveryRecord->amount = $money;
    $playerDeliveryRecord->amount_before = $beforeGameAmount;
    $playerDeliveryRecord->amount_after = $afterGameAmount;
    $playerDeliveryRecord->tradeno = '';
    $playerDeliveryRecord->remark = '';
    $playerDeliveryRecord->save();
}

/**
 * 开分检查
 * @param Machine $machine
 * @param Player $player
 * @param int $money
 * @return void
 * @throws Exception
 */
function openAnyCheck(Machine $machine, Player $player, int $money): void
{
    //檢查餘額
    if ($player->machine_wallet->money < $money) {
        throw new Exception(trans('game_amount_insufficient', [], 'message'));
    }
    //最小上分金額
    if ($machine->min_point != 0 && $machine->min_point > $money) {
        throw new Exception(trans('machine_min_open', [], 'message') . $machine->min_point);
    }
    //最大上分金額
    if ($machine->max_point != 0 && $machine->max_point < $money) {
        throw new Exception(trans('machine_max_open', [], 'message') . $machine->max_point);
    }
}

/**
 * 获取增点缓存
 * @param $playerId
 * @param $machineId
 * @return mixed
 */
function getGivePoints($playerId, $machineId): mixed
{
    return Cache::get('gift_cache_' . $machineId . '_' . $playerId);
}

/**
 * 获取毫秒级
 * @return float
 */
function millisecond(): float
{
    [$millisecond, $sec] = explode(' ', microtime());
    return (float)sprintf('%.0f', (floatval($millisecond) + floatval($sec)) * 1000);
}

/**
 * crc8Maxim检查
 * @param $str
 * @param $polynomial
 * @param $ini
 * @param $xor
 * @param bool $ref_in
 * @param bool $ref_out
 * @param bool $has_fill
 * @return string
 * @throws Exception
 */
function crc8(
    $str,
    $polynomial,
    $ini,
    $xor,
    bool $ref_in = true,
    bool $ref_out = true,
    bool $has_fill = true
): string
{
    if (!is_scalar($str)) {
        throw new exception(
            "Variable for CRC calculation must be a scalar."
        );
    }
    $crc = $ini;
    for ($i = 0; $i < strlen($str); $i++) {
        $byte = ord($str[$i]);

        if ($ref_in) {
            reflect_bits($byte, 8);
        }
        $crc ^= $byte;
        for ($j = 0; $j < 8; $j++) {
            if ($crc & 0x80) {
                $crc = (($crc << 1) & 0xff) ^ $polynomial;
            } else {
                $crc = ($crc << 1) & 0xff;
            }
        }
    }

    $result = ($crc ^ $xor) & 0xff;

    if ($ref_out) {
        reflect_bits($result, 8);
    }
    $result = sprintf("%02X", $result);

    if ($has_fill) {
        $hex = '';
        for ($i = strlen($result) - 1; $i >= 0; $i--) {
            $hex .= sprintf("%02X", hexdec($result[$i]));
        }
        return $hex;
    }

    return $result;
}

/**
 * @param $num
 * @param $width
 * @return void
 */
function reflect_bits(&$num, $width): void
{
    $ref = 0;

    for ($i = 0; $i < $width; $i++) {
        $bit = ($num >> $i) & 0b1;
        $bit = ($bit << (($width - 1) - $i));
        $ref = $ref | $bit;
    }

    $num = $ref;
}

/**
 * 数据位只能有3byte
 * @param $data
 * @return string
 * @throws Exception
 */
function encodeData($data): string
{
    $dataStr = sprintf("%06X", $data);
    if (strlen($dataStr) > 6) {
        throw new Exception('数据异常');
    }
    $dataStr = strrev($dataStr);
    $paddedStr = "";
    foreach (str_split($dataStr) as $char) {
        $paddedStr .= str_pad($char, 2, '0', STR_PAD_LEFT);
    }
    return str_pad($paddedStr, 12, '0');
}

/**
 * 数据位只能有3byte
 * @param $data
 * @return string
 * @throws Exception
 */
function jackpotEncodeData($data): string
{
    $dataStr = sprintf("%06X", $data);
    if (strlen($dataStr) > 6) {
        throw new Exception('数据异常');
    }
    return substr($dataStr, 4, 2) . substr($dataStr, 2, 2) . substr($dataStr, 0, 2);
}

/**
 * 数据位只能有3byte
 * @param $data
 * @return string
 * @throws Exception
 */
function encodeDataXor55($data): string
{
    $cmd = sprintf("%06X", $data);
    if (strlen($cmd) > 6) {
        throw new Exception('数据异常');
    }
    $result = intval(hexdec(substr($cmd, 4, 2))) ^ intval(hexdec(substr($cmd, 2, 2))) ^ intval(hexdec(substr($cmd,
            0,
            2))) ^ 0x55;
    $result = sprintf("%02X", $result);
    $hex = "";
    for ($i = strlen($result) - 1; $i >= 0; $i--) {
        $hex .= sprintf("%02X", hexdec($result[$i]));
    }
    return $hex;
}

/**
 * 数据位只能有3byte
 * @param $data
 * @return string
 * @throws Exception
 */
function jackpotEncodeDataXor55($data): string
{
    $cmd = sprintf("%06X", $data);
    if (strlen($cmd) > 6) {
        throw new Exception('数据异常');
    }
    $result = intval(hexdec(substr($cmd, 0, 2))) ^ intval(hexdec(substr($cmd, 2, 2))) ^ intval(hexdec(substr($cmd,
            4,
            2))) ^ 0x55;
    return sprintf("%02X", $result);
}

/**
 * 检查crc8
 * @param string $data
 * @return bool
 * @throws Exception
 */
function checkCRC8(string $data): bool
{
    $str = substr($data, 0, 28);
    $crc8 = substr($data, 28, 4);
    if ($crc8 !== crc8(hex2bin($str), 0x31, 0x00, 0x00)) {
        throw new Exception('crc8检查不通过' . $crc8 . crc8(hex2bin($str), 0x31, 0x00, 0x00));
    }

    return true;
}

/**
 * slot检查Xor55
 * @param string $msg
 * @param string $data
 * @return bool
 * @throws Exception
 */
function checkSlotXor55(string $msg, string $data): bool
{
    $fun = substr($msg, 2, 2);
    if ($fun == Slot::MACHINE_BUSY) {
        return true;
    }
    $xor55 = substr($msg, 20, 4);
    if ($xor55 !== encodeDataXor55($data)) {
        throw new Exception('xor55检查不通过');
    }

    return true;
}

/**
 * slot检查Xor55
 * @param string $msg
 * @param string $data
 * @return bool
 * @throws Exception
 */
function checkJackpotXor55(string $msg, string $data): bool
{
    $fun = substr($msg, 2, 2);
    if ($fun == '2B') {
        return true;
    }
    $xor55 = substr($msg, 14, 2);
    if ($xor55 !== jackpotEncodeDataXor55($data)) {
        throw new Exception('xor55检查不通过');
    }

    return true;
}

/**
 * 检查crc8
 * @param string $data
 * @return bool
 * @throws Exception
 */
function jackPotCheckCRC8(string $data): bool
{
    $str = substr($data, 0, 28);
    $crc8 = substr($data, 28, 2);
    if ($crc8 !== crc8(hex2bin($str), 0x31, 0x00, 0x00, true, true, false)) {
        throw new Exception('crc8检查不通过');
    }

    return true;
}

/**
 * 解码数据位
 * @param $msg
 * @return string
 */
function decodeData($msg): string
{
    $str = substr($msg, 8, 12);
    $data2HI = substr(substr($str, 10, 2), 1, 1);
    $data2LO = substr(substr($str, 8, 2), 1, 1);

    $data1HI = substr(substr($str, 6, 2), 1, 1);
    $data1LO = substr(substr($str, 4, 2), 1, 1);

    $data0HI = substr(substr($str, 2, 2), 1, 1);
    $data0LO = substr(substr($str, 0, 2), 1, 1);

    $input = ltrim($data2HI . $data2LO . $data1HI . $data1LO . $data0HI . $data0LO, '0');
    return intval(hexdec($input));
}

/**
 * 解码数据位
 * @param $msg
 * @return string
 */
function jackpotDecodeData($msg): string
{
    $str = substr($msg, 8, 6);

    $data0 = substr($str, 4, 2);
    $data1 = substr($str, 2, 2);
    $data2 = substr($str, 0, 2);

    $input = ltrim($data0 . $data1 . $data2, '0');
    return intval(hexdec($input));
}

/**
 * slot自动卡检查crc8
 * @param string $data
 * @return bool
 * @throws Exception
 */
function slotCheckCRC8(string $data): bool
{
    $str = substr($data, 0, 12);
    $crc8 = substr($data, 12, 2);
    if ($crc8 !== crc8(hex2bin($str), 0x31, 0x00, 0x00, true, true, false)) {
        throw new Exception('crc8检查不通过');
    }
    return true;
}

/**
 * 解码机台状态
 * @param $data
 * @return string
 */
function decodeStatus($data): string
{
    $decoded_stat = hexdec($data);
    return sprintf("%08b", $decoded_stat);
}

/**
 * 解码机台状态
 * @param Machine $machine
 * @param $type
 * @param int $playerId
 * @throws PushException
 */
function sendMachineException(Machine $machine, $type, int $playerId = 0): void
{
    $notice = new Notice();
    $notice->department_id = 1;
    $notice->player_id = 0;
    $notice->source_id = $machine->id;
    $notice->receiver = Notice::RECEIVER_ADMIN;
    $notice->is_private = 0;
    switch ($type) {
        case Notice::TYPE_MACHINE_BET:
            $content = '斯洛';
            $content .= '機台編號為: ' . $machine->code . ', 發生bet（壓分）數據异常，請聯系設備管理員處理！';
            $notice->content = $content;
            $notice->title = '機台bet（壓分）异常通知';
            $notice->type = Notice::TYPE_MACHINE_BET;
            $notice->save();
            $msgType = 'machine_bet_error';
            break;
        case Notice::TYPE_MACHINE_WIN:
            $content = '斯洛';
            $content .= '機台編號為: ' . $machine->code . ', 發生win（得分）數據异常，請聯系設備管理員處理！';
            $notice->content = $content;
            $notice->title = '機台win（得分）异常通知';
            $notice->type = Notice::TYPE_MACHINE_WIN;
            $notice->save();
            $msgType = 'machine_win_error';
            break;
        case Notice::TYPE_MACHINE_WIN_NUMBER:
            $content = '钢珠';
            $content .= '機台編號為: ' . $machine->code . ', 發生中洞兑奖次数（压转）數據异常，請聯系設備管理員處理！';
            $notice->content = $content;
            $notice->title = '機台中洞兑奖次数（压转）异常通知';
            $notice->type = Notice::TYPE_MACHINE_WIN_NUMBER;
            $notice->save();
            $msgType = 'machine_win_error';
            break;
        case Notice::TYPE_MACHINE:
            $content = $machine->type == GameType::TYPE_SLOT ? '斯洛' : '鋼珠';
            $content .= '機台編號為: ' . $machine->code . ', 發生异常離線，請聯系設備管理員處理！';
            $notice->content = $content;
            $notice->title = '機台離線通知';
            $notice->type = Notice::TYPE_MACHINE;
            $notice->save();
            $msgType = 'machine_online';
            break;
        case Notice::TYPE_MACHINE_LOCK:
            $content = $machine->type == GameType::TYPE_SLOT ? '斯洛' : '鋼珠';
            $content .= '機台編號為: ' . $machine->code . ', 發生异常鎖定，請聯系設備管理員處理！';
            $notice->content = $content;
            $notice->title = '機台鎖定通知';
            $notice->type = Notice::TYPE_MACHINE_LOCK;
            $notice->save();
            $msgType = 'machine_lock';
            if (!empty($playerId)) {
                /** @var Player $player */
                $player = Player::query()->find($playerId);
                sendSocketMessage('private-admin_group-channel-' . $player->department_id, [
                    'msg_type' => 'machine_lock',
                    'id' => $machine->id,
                    'player_id' => $player->id,
                ]);
                $content = $machine->type == GameType::TYPE_SLOT ? '斯洛' : '鋼珠';
                $content .= '機台編號為: ' . $machine->code . ', 發生异常鎖定';
                $content .= '當前使用玩家為: ' . $player->uuid . ', 發生异常鎖定，請聯系設備管理員處理！';
                $notice = new Notice();
                $notice->department_id = $player->department_id;
                $notice->player_id = $player->id;
                $notice->source_id = $machine->id;
                $notice->type = Notice::TYPE_MACHINE_LOCK;
                $notice->receiver = Notice::RECEIVER_DEPARTMENT;
                $notice->is_private = 0;
                $notice->title = '機台鎖定通知';
                $notice->content = $content;
                $notice->save();
            }
            break;
        default:
            return;
    }
    sendSocketMessage('private-admin_group-admin-1', [
        'msg_type' => $msgType,
        'id' => $machine->id,
    ]);
}

/**
 * 获取毫秒级时间戳
 * @return float
 */
function getMillisecond(): float
{
    [$t1, $t2] = explode(' ', microtime());
    return (float)sprintf('%.0f', (floatval($t1) + floatval($t2)) * 1000000);
}

/**
 * 毫秒转时间戳
 * @param $millisecond
 * @return string
 */
function millisecondsToTimeFormat($millisecond): string
{
    $seconds = floor($millisecond / 1000000); // 将毫秒转换为秒

    $date = new DateTime();
    $date->setTimestamp($seconds);
    return $date->format('Y-m-d H:i:s');
}

/**
 * 获取当前usdt汇率
 * @param $currency
 * @return mixed|null
 */
function getUSDTExchangeRate($currency): mixed
{
    $currency = strtolower($currency);
    $cacheKey = 'usdt_rate_' . $currency;
    $cacheData = Cache::get($cacheKey);
    if (!empty($cacheData)) {
        return $cacheData;
    }
    $response = Http::timeout(5)->get('https://api.coingecko.com/api/v3/simple/price?ids=tether&vs_currencies=' . $currency);
    if ($response->successful()) {
        $data = $response->json();
        Cache::set($cacheKey, $data['tether'][$currency], 30 * 60);
        return $data['tether'][$currency];
    }

    return null;
}

/**
 * 生成唯一15位UUID
 * @return string
 */
function generate15DigitUniqueId(): string
{
    do {
        $timestamp = time();
        $randomNumber = str_pad(rand(0, 99999), 5, '0', STR_PAD_LEFT);
        $uniqueNumericId = substr($timestamp, -5) . $randomNumber;

    } while (Player::query()->where('uuid', $uniqueNumericId)->withTrashed()->exists());

    return $uniqueNumericId;
}

/**
 * 全民代理返佣
 * @param $data
 * @return true
 */
function nationalPromoterSettlement($data): bool
{
    foreach ($data as $item) {
        /** @var Player $player */
        $player = Player::query()->find($item['player_id']);
        //玩家上级详情
        $recommendPromoter = Player::query()->find($player->recommend_id);
        //计算所有玩家打码量
        if ($item['bet'] > 0) {
            //当前玩家打码量
            $player->national_promoter->chip_amount = bcadd($player->national_promoter->chip_amount, $item['bet'],
                2);
            //根据打码量查询玩家当前全民代理等级
            $levelId = LevelList::query()->where('department_id', $player->department_id)
                ->where('must_chip_amount', '<=',
                    $player->national_promoter->chip_amount)->orderBy('must_chip_amount', 'desc')->first();
            if (!empty($levelId) && isset($levelId->id)) {
                //根据打码量提升玩家全民代理等级
                $player->national_promoter->level = $levelId->id;
            }
            $player->push();
        }
        //当前玩家渠道未开通全民代理功能
        if ($player->channel->national_promoter_status == 0) {
            continue;
        }
        //上级是全民代理,并且当前玩家已充值激活全民代理身份
        if (!empty($recommendPromoter) && !empty($recommendPromoter->national_promoter) && $item['diff'] != 0 && !empty($player->national_promoter) && $player->national_promoter->status == 1 && $recommendPromoter->is_promoter < 1) {
            $damageRebateRatio = isset($recommendPromoter->national_promoter->level_list->damage_rebate_ratio) ? $recommendPromoter->national_promoter->level_list->damage_rebate_ratio : 0;
            $money = bcdiv(bcmul(-$item['diff'], $damageRebateRatio, 2), 100, 2);
            $recommendPromoter->national_promoter->pending_amount = bcadd($recommendPromoter->national_promoter->pending_amount,
                $money, 2);
            $recommendPromoter->push();
            /** @var NationalProfitRecord $nationalProfitRecord */
            $nationalProfitRecord = NationalProfitRecord::query()->where('uid', $player->id)
                ->where('type', 1)
                ->whereDate('created_at', date('Y-m-d'))->first();
            if (!empty($nationalProfitRecord)) {
                $nationalProfitRecord->money = bcadd($nationalProfitRecord->money, $money, 2);
            } else {
                $nationalProfitRecord = new NationalProfitRecord();
                $nationalProfitRecord->uid = $player->id;
                $nationalProfitRecord->recommend_id = $player->recommend_id;
                $nationalProfitRecord->money = $money;
                $nationalProfitRecord->type = 1;
            }
            $nationalProfitRecord->save();
        }
    }
    return true;
}

/**
 * 时间格式转换
 * @param $datetimeString
 * @return string
 * @throws Exception|\Exception
 */
function dateFormat($datetimeString): string
{
    return (new DateTime($datetimeString))->format('Y-m-d H:i:s');
}

/**
 * 生成随机1位字符串
 * @param int $length
 * @return string
 */
function generateRandomString(int $length = 1): string
{
    // 定义字符集
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $randomString = '';

    // 生成随机字符串
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[mt_rand(0, $charactersLength - 1)];
    }

    return $randomString;
}

/**
 *  获取推流地址
 *  如果不传key和过期时间，将返回不含防盗链的url
 * @param $machineCode
 * @param string $pushDomain
 * @param string $pushKey
 * @return array
 */
function getPushUrl($machineCode, string $pushDomain = '', string $pushKey = ''): array
{
    $pushUrl = '';
    $endpointServiceId = uniqid();
    if (!empty($machineCode) && !empty($pushDomain)) {
        $name = $machineCode . '_' . $endpointServiceId;
        if (!empty($pushKey)) {
            $time = date('Y-m-d H:i:s'); // 获取当前时间
            $timePlus24Hours = date('Y-m-d H:i:s', strtotime($time) + 24 * 60 * 60 * 30 * 24);
            $txTime = strtoupper(base_convert(strtotime($timePlus24Hours), 10, 16));
            $txSecret = md5($pushKey . $name . $txTime);
            $ext_str = "?" . http_build_query(array(
                    "txSecret" => $txSecret,
                    "txTime" => $txTime
                ));
        }
        $pushUrl = [
            'rtmp_url' => "rtmp://" . $pushDomain . "/live/" . $name . ($ext_str ?? ""),
            'expiration_date' => $timePlus24Hours ?? '',
            'endpoint_service_id' => $endpointServiceId,
            'machine_code' => $machineCode,
        ];
    }

    return $pushUrl;
}

/**
 * 获取啦流地址
 * 如果不传key和过期时间，将返回不含防盗链的url
 * @param $machineCode
 * @param $machineTencentPlayId
 * @param string $ip
 * @return string
 */
function getPullUrl($machineCode, $machineTencentPlayId, string $ip = ''): string
{
    $pullUrl = '';
    /** @var MachineTencentPlay $machineTencentPlay */
    $machineTencentPlay = MachineTencentPlay::query()
        ->when($machineTencentPlayId, function (Builder $q) use ($machineTencentPlayId) {
            $q->where('id', $machineTencentPlayId);
        })->first();
    $pullDomain = $machineTencentPlay->pull_domain;
    $pullKey = $machineTencentPlay->pull_key;
    if (!empty($ip) && !empty($machineTencentPlay->pull_domain_cn) && !empty($machineTencentPlay->pull_key_cn)) {
        try {
            if (isIPInChina($ip)) {
                $pullDomain = $machineTencentPlay->pull_domain_cn;
                $pullKey = $machineTencentPlay->pull_key_cn;
            }
        } catch (\Exception $e) {
            Log::error('获取玩家IP地区失败');
        }
    }
    if (!empty($pullDomain)) {
        if (!empty($pullKey)) {
            $time = date('Y-m-d H:i:s');
            $timePlus24Hours = date('Y-m-d H:i:s', strtotime($time) + 24 * 60 * 60 * 3);
            $txTime = strtoupper(base_convert(strtotime($timePlus24Hours), 10, 16));
            $txSecret = md5($pullKey . $machineCode . $txTime);
            $ext_str = "?" . http_build_query(array(
                    "txSecret" => $txSecret,
                    "txTime" => $txTime
                ));
        }
        $pullUrl = "webrtc://" . $pullDomain . "/live/" . $machineCode . ($ext_str ?? "");
    }

    return $pullUrl;
}

/**
 * 判断玩家ip所在启动
 * @param $ip
 * @return bool
 * @throws \Exception
 */
function isIPInChina($ip): bool
{
    $ip2region = new Ip2Region();
    $info = $ip2region->btreeSearch($ip);
    // 检查地区是否为中国大陆
    if (str_contains($info['region'], '中国') &&
        !str_contains($info['region'], '香港') &&
        !str_contains($info['region'], '澳门') &&
        !str_contains($info['region'], '台湾')) {
        return true; // IP 地址来自中国大陆
    }

    return false;
}

/**
 * 获取总的观看人数(延迟最多2分钟)
 * @param $machineId
 * @return int|mixed
 */
function getViewers($machineId): mixed
{
    $viewers = 0;
    $machineMediaList = MachineMedia::query()
        ->where('status', 1)
        ->where('machine_id', $machineId)
        ->get();
    /** @var MachineMedia $media */
    foreach ($machineMediaList as $media) {
        $num = Cache::get('ams_viewers_' . $media->machine_id . '_' . $media->id) ?? 0;
        $viewers += $num;
    }
    $machineMediaPushList = MachineMediaPush::query()
        ->where('status', 1)
        ->where('machine_id', $machineId)
        ->get();
    /** @var MachineMediaPush $machineMediaPush */
    foreach ($machineMediaPushList as $machineMediaPush) {
        $num = Cache::get('tencent_viewers_' . $machineMediaPush->machine_id . '_' . $machineMediaPush->id) ?? 0;
        $viewers += $num;
    }

    return $viewers;
}

/**
 * 上传 base64 图片到 Google Cloud Storage
 *
 * @param string $base64Data base64 图片数据
 * @param string $directory 存储目录
 * @return string|false 成功返回文件URL，失败返回false
 * @throws Exception
 */
function uploadBase64ToGCS(string $base64Data, string $directory = 'avatar'): bool|string
{
    // 检查 base64 数据格式
    if (str_contains($base64Data, ';base64,')) {
        [$type, $base64Data] = explode(';', $base64Data);
        [, $base64Data] = explode(',', $base64Data);
        [, $imageType] = explode('/', $type);
    } else {
        // 如果没有头部信息，尝试检测图片类型
        $imageInfo = getimagesizefromstring(base64_decode($base64Data));
        if (!$imageInfo) {
            throw new Exception(trans('invalid_image', [], 'message'));
        }
        $imageType = image_type_to_extension($imageInfo[2], false);
    }

    // 验证图片类型
    $allowedTypes = ['jpg', 'jpeg', 'png', 'gif'];
    if (!in_array(strtolower($imageType), $allowedTypes)) {
        throw new Exception(trans('image_format_error', [], 'message'));
    }

    // 解码 base64 数据
    $imageData = base64_decode($base64Data);
    if ($imageData === false) {
        throw new Exception(trans('base64_decode_error', [], 'message'));
    }

    // 检查图片大小 (2MB限制)
    if (strlen($imageData) >= 1024 * 1024 * 2) {
        throw new Exception(trans('image_size_error', [], 'message'));
    }
    try {
        // 生成唯一文件名
        $filename = uniqid() . '_' . time() . '.' . $imageType;
        $cloudPath = $directory . '/' . date('Ymd') . '/' . $filename;

        // 使用 Google Cloud Storage
        $storage = Filesystem::disk('google_oss');

        // 上传到 GCS
        $result = $storage->put($cloudPath, $imageData, [
            'metadata' => [
                'contentType' => 'image/' . $imageType,
                'cacheControl' => 'public, max-age=31536000',
            ]
        ]);

        if ($result) {
            // 返回文件的公开URL
            return $storage->url($cloudPath);
        }

        return false;

    } catch (Exception $e) {
        Log::error('GCS Base64 上传失败: ' . $e->getMessage());
        return false;
    }
}

/**
 * 删除
 * @param $imagePath
 * @return void
 */
function deleteToGCS($imagePath): void
{
    try {
        $storage = Filesystem::disk('google_oss');
        if ($storage->exists($imagePath)) {
            $storage->delete($imagePath);
            Log::info('图片删除成功: ' . $imagePath);
        }
    } catch (Exception $e) {
        Log::error('图片失败: ' . $e->getMessage());
    }
}

/**
 * 洗分
 * @param Player $player
 * @param Machine $machine
 * @param string $path
 * @param int $is_system
 * @param bool $hasLottery
 * @return PlayerLotteryRecord|true
 * @throws Exception
 * @throws PushException
 */
function machineWash(
    Player  $player,
    Machine $machine,
    string  $path = 'leave',
    int     $is_system = 0,
    bool    $hasLottery = false
): bool|PlayerLotteryRecord
{
    try {
        $lang = locale();
        $services = MachineServices::createServices($machine, $lang);
        if ($services->last_point_at + 5 >= time()) {
            throw new Exception(trans('exception_msg.point_must_5seconds', [], 'message', $lang));
        }
        // 洗分限制（强制退出洗分）
        $giftPoint = getGivePoints($player->id, $machine->id);
        $gamingTurnPoint = 0; // 转数
        $gamingPressure = 0; // 压分
        $gamingScore = 0; // 得分
        $money = 0; // 机台下分
        //斯洛 需要判斷下分限制
        switch ($machine->type) {
            case GameType::TYPE_STEEL_BALL:
                // 弃台需要下转,下珠
                if ($path == 'leave') {
                    if ($machine->control_type == Machine::CONTROL_TYPE_MEI) {
                        $services->sendCmd($services::PUSH . $services::PUSH_STOP, 0, 'player', $player->id,
                            $is_system);
                        if ($services->auto == 1) {
                            $services->sendCmd($services::AUTO_UP_TURN, 0, 'player', $player->id, $is_system);
                        }
                        if ($services->score > 0) {
                            $services->sendCmd($services::SCORE_TO_POINT, 0, 'player', $player->id, $is_system);
                        }
                        if ($services->turn > 0) {
                            $services->sendCmd($services::TURN_DOWN_ALL, 0, 'player', $player->id, $is_system);
                        }
                    }
                    if ($machine->control_type == Machine::CONTROL_TYPE_SONG) {
                        if ($services->auto == 1) {
                            $services->sendCmd($services::AUTO_UP_TURN, 0, 'player', $player->id, $is_system);
                        }
                        $services->sendCmd($services::MACHINE_TURN, 0, 'player', $player->id, $is_system);
                        $services->sendCmd($services::MACHINE_SCORE, 0, 'player', $player->id, $is_system);
                        if ($services->score > 0) {
                            $services->sendCmd($services::SCORE_TO_POINT, 0, 'player', $player->id, $is_system);
                        }
                        if ($services->turn > 0) {
                            $services->sendCmd($services::TURN_DOWN_ALL, 0, 'player', $player->id, $is_system);
                        }
                    }
                }
                $services->sendCmd($services::MACHINE_POINT, 0, 'player', $player->id, $is_system);
                $services->sendCmd($services::WIN_NUMBER, 0, 'player', $player->id, $is_system);
                $gamingTurnPoint = $services->player_win_number;
                $money = $services->point;
                if (!empty($giftPoint) && $path == 'leave') {
                    $money = max($money - $giftPoint['gift_point'], 0);
                }
                break;
            case GameType::TYPE_SLOT:
                if ($services->move_point == 1 && $machine->control_type == Machine::CONTROL_TYPE_MEI) {
                    $services->sendCmd($services::MOVE_POINT_OFF, 0, 'player', $player->id, $is_system);
                }
                if ($services->auto == 1) {
                    $services->sendCmd($services::OUT_OFF, 0, 'player', $player->id, $is_system);
                }
                $services->sendCmd($services::STOP_ONE, 0, 'player', $player->id, $is_system);
                $services->sendCmd($services::STOP_TWO, 0, 'player', $player->id, $is_system);
                $services->sendCmd($services::STOP_THREE, 0, 'player', $player->id, $is_system);
                $services->sendCmd($services::READ_SCORE, 0, 'player', $player->id, $is_system);
                Log::channel('song_slot_machine')->info('slot -> wash', [
                    'point' => $money,
                    'code' => $machine->code,
                    'bet' => $services->bet,
                    'player_pressure' => $services->player_pressure,
                ]);
                $services->sendCmd($services::READ_BET, 0, 'player', $player->id, $is_system);
                $gamingPressure = bcsub($services->bet, $services->player_pressure);
                $gamingScore = bcsub($services->win, $services->player_score);
                $money = $services->point;
                Log::channel('slot_machine')->info('slot -> wash', [
                    'point' => $money,
                    'code' => $machine->code,
                ]);
                if (!empty($giftPoint)) {
                    if ($money < $giftPoint['open_point'] * $giftPoint['condition']) {
                        $money = max($money - $giftPoint['gift_point'], 0);
                    }
                }
                break;
        }
    } catch (\Exception $e) {
        throw new Exception($e->getMessage());
    }

    /** 彩金预留检查 */
    if ($hasLottery && $machine->type == GameType::TYPE_SLOT && $path == 'down' && $money > 0) {
        try {
            $playerLotteryRecord = (new LotteryServices())->setMachine($machine)->setPlayer($player)->fixedPotCheckLottery($money,
                true);
            if ($playerLotteryRecord) {
                return $playerLotteryRecord;
            }
        } catch (\Exception $e) {
            throw new Exception($e->getMessage());
        }
    }
    DB::beginTransaction();
    try {
        if ($money >= 0) {
            $machine = machineWashZero($player, $machine, $money, $is_system, max($gamingPressure, 0),
                max($gamingScore, 0), max($gamingTurnPoint, 0), $path);
        }
        if ($path == 'leave') {
            if ($services->keeping == 1) {
                // 更新保留日志
                updateKeepingLog($machine->id, $player->id);
            }
            $machine->gaming = 0;
            $machine->gaming_user_id = 0;
            $machine->save();

            if ($machine->type == GameType::TYPE_STEEL_BALL) {
                $activityServices = new ActivityServices($machine, $player);
                $activityServices->playerFinishActivity(true);
            }
            /** TODO 计算打码量 */
        }
        // 斯洛离开机台或弃台下分重置活动 检查彩金中奖情况
        if ($machine->type == GameType::TYPE_SLOT) {
            // 离开机台参与活动结束
            $activityServices = new ActivityServices($machine, $player);
            $activityServices->playerFinishActivity(true);
            // 下分检查彩金获奖情况
            if ($money > 0) {
                $playerLotteryRecord = (new LotteryServices())->setMachine($machine)->setPlayer($player)->fixedPotCheckLottery($money,
                    false, $path == 'leave');
            }
        }
        DB::commit();
        // 执行下分操作
        switch ($machine->type) {
            case GameType::TYPE_STEEL_BALL:
                $services->sendCmd($services::WASH_ZERO, 0, 'player', $player->id, $is_system);
                $services->sendCmd($services::CLEAR_LOG, 0, 'player', $player->id, $is_system);
                $services->player_win_number = 0;
                break;
            case GameType::TYPE_SLOT:
                $services->sendCmd($services::WASH_ZERO, 0, 'player', $player->id, $is_system);
                $services->sendCmd($services::ALL_DOWN, 0, 'player', $player->id, $is_system);
                $services->player_pressure = 0;
                $services->player_score = 0;
                $services->bet = 0;
                break;
        }
    } catch (\Exception $e) {
        DB::rollback();
        throw new Exception($e->getMessage());
    }
    // 游戏结束同步Redis彩金到数据库（新版：独立彩池模式）
    // 强制同步所有彩金的Redis数据到数据库
    try {
        LotteryServices::forceSyncRedisToDatabase();
    } catch (\Exception $e) {
        Log::error('游戏结束同步彩金失败: ' . $e->getMessage());
    }
    queueClient::send('media-recording', [
        'machine_id' => $machine->id,
        'action' => 'stop',
    ], 10);
    //下分成功 下分&下轉限制歸零 開獎中結束 關閉 push auto
    $services->last_play_time = time();
    if ($path == 'leave') {
        $services->gaming_user_id = 0;
        $services->gaming = 0;
        $services->keeping_user_id = 0;
        $services->keeping = 0;
        $services->last_keep_at = 0;
        $services->keep_seconds = 0;
        if ($machine->type == GameType::TYPE_SLOT) {
            $services->player_pressure = 0;
            $services->player_score = 0;
        }
        if ($machine->type == GameType::TYPE_STEEL_BALL) {
            $services->player_win_number = 0;
        }
        $services->player_open_point = 0;
        $services->player_wash_point = 0;
    }
    switch ($machine->type) {
        case GameType::TYPE_STEEL_BALL:
            if ($path == 'leave') {
                $services->gift_bet = 0;
                Cache::delete('gift_cache_' . $machine->id . '_' . $player->id);
            }
            break;
        case GameType::TYPE_SLOT:
            Cache::delete('gift_cache_' . $machine->id . '_' . $player->id);
            break;
    }

    // 清理消息缓存
    LotteryServices::clearNoticeCache($player->id, $machine->id);

    return $playerLotteryRecord ?? true;
}

/**
 * 洗分清零算法
 * @param Player $player
 * @param Machine $machine
 * @param $money
 * @param int $is_system
 * @param int $gamingPressure
 * @param int $gamingScore
 * @param int $gamingTurnPoint
 * @param string $action
 * @return Machine
 * @throws Exception
 */
function machineWashZero(
    Player  $player,
    Machine $machine,
            $money,
    int     $is_system = 0,
    int     $gamingPressure = 0,
    int     $gamingScore = 0,
    int     $gamingTurnPoint = 0,
    string  $action = 'leave'
): Machine
{
    try {
        $services = MachineServices::createServices($machine);
        $control_open_point = !empty($machine->control_open_point) ? $machine->control_open_point : 100;
        //记录游戏局记录
        /** @var PlayerGameRecord $gameRecord */
        $gameRecord = PlayerGameRecord::query()->where('machine_id', $machine->id)
            ->where('player_id', $player->id)
            ->where('status', PlayerGameRecord::STATUS_START)
            ->orderBy('created_at', 'desc')
            ->first();
        /** @var PlayerPlatformCash $machineWallet */
        $machineWallet = PlayerPlatformCash::query()->where('platform_id',
            PlayerPlatformCash::PLATFORM_SELF)->where('player_id', $player->id)->lockForUpdate()->first();
        $beforeGameAmount = $machineWallet->money;
        if ($money > 0) {
            //api洗分
            $wash_point = $money;
            //依照比值轉成錢包幣值 無條件捨去
            $game_amount = floor($money * ($machine->odds_x ?? 1) / ($machine->odds_y ?? 1));
            $machineWallet->money = bcadd($machineWallet->money, $game_amount, 2);
            $machineWallet->save();
            if (!empty($gameRecord)) {
                $gameRecord->wash_point = bcadd($gameRecord->wash_point, $wash_point, 2);
                $gameRecord->wash_amount = bcadd($gameRecord->wash_amount, $game_amount, 2);
                $gameRecord->after_game_amount = $machineWallet->money;
                if ($action == 'leave') {
                    $gameRecord->status = PlayerGameRecord::STATUS_END;
                    /** TODO 计算客损 */
                    $diff = bcsub($gameRecord->wash_amount, $gameRecord->open_amount, 2);
                    nationalPromoterSettlement([
                        ['player_id' => $player->id, 'bet' => 0, 'diff' => $diff]
                    ]);
                    if (!empty($player->recommend_id)) {
                        $recommendPromoter = Player::query()->find($player->recommend_id);
                        $gameRecord->national_damage_ratio = $recommendPromoter->national_promoter->level_list->damage_rebate_ratio ?? 0;
                    }
                }
                $gameRecord->save();
            }

            //添加机台点数转换记录
            $playerGameLog = addPlayerGameLog($player, $machine, $gameRecord, $control_open_point);
            $playerGameLog->wash_point = $wash_point;
            $playerGameLog->game_amount = $game_amount;
            $playerGameLog->before_game_amount = $beforeGameAmount;
            $playerGameLog->after_game_amount = $machineWallet->money;
            $playerGameLog->action = ($action == 'leave' ? PlayerGameLog::ACTION_LEAVE : PlayerGameLog::ACTION_DOWN);
            $playerGameLog->chip_amount = 0;
            if ($machine->type == GameType::TYPE_SLOT) {
                $ratio = ($machine->odds_x ?? 1) / ($machine->odds_y ?? 1);
                $playerGameLog->chip_amount = bcmul($gamingPressure, $ratio, 2);
            } elseif ($machine->type == GameType::TYPE_STEEL_BALL) {
                $playerGameLog->chip_amount = bcmul($machine->machineCategory->turn_used_point, $gamingTurnPoint);
            }
            extracted($is_system, $playerGameLog, $gamingPressure, $gamingScore, $gamingTurnPoint);

            //寫入金流明細
            $playerDeliveryRecord = new PlayerDeliveryRecord;
            $playerDeliveryRecord->player_id = $player->id;
            $playerDeliveryRecord->department_id = $player->department_id;
            $playerDeliveryRecord->target = $playerGameLog->getTable();
            $playerDeliveryRecord->target_id = $playerGameLog->id;
            $playerDeliveryRecord->machine_id = $machine->id;
            $playerDeliveryRecord->machine_name = $machine->name;
            $playerDeliveryRecord->machine_type = $machine->type;
            $playerDeliveryRecord->code = $machine->code;
            $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_MACHINE_DOWN;
            $playerDeliveryRecord->source = 'game_machine';
            $playerDeliveryRecord->amount = $game_amount;
            $playerDeliveryRecord->amount_before = $beforeGameAmount;
            $playerDeliveryRecord->amount_after = $machineWallet->money;
            $playerDeliveryRecord->tradeno = $target->tradeno ?? '';
            $playerDeliveryRecord->remark = $target->remark ?? '';
            $playerDeliveryRecord->user_id = 0;
            $playerDeliveryRecord->user_name = '';
            $playerDeliveryRecord->save();

            //保存下分時間
            $services->last_point_at = time();
            //累計該玩家洗分
            $services->player_wash_point = bcadd($services->player_wash_point, $wash_point);
        } else {
            //添加机台点数转换记录
            $playerGameLog = addPlayerGameLog($player, $machine, $gameRecord, $control_open_point);
            $playerGameLog->wash_point = 0;
            $playerGameLog->game_amount = 0;
            $playerGameLog->before_game_amount = $machineWallet->money;
            $playerGameLog->after_game_amount = $machineWallet->money;
            $playerGameLog->action = ($action == 'leave' ? PlayerGameLog::ACTION_LEAVE : PlayerGameLog::ACTION_DOWN);
            extracted($is_system, $playerGameLog, $gamingPressure, $gamingScore, $gamingTurnPoint);

            if (!empty($gameRecord)) {
                $gameRecord->after_game_amount = $machineWallet->money;
                if ($action == 'leave') {
                    $gameRecord->status = PlayerGameRecord::STATUS_END;
                    /** TODO 计算客损 */
                    $diff = bcsub($gameRecord->wash_amount, $gameRecord->open_amount, 2);
                    nationalPromoterSettlement([
                        ['player_id' => $player->id, 'bet' => 0, 'diff' => $diff]
                    ]);
                    if (!empty($player->recommend_id)) {
                        $recommendPromoter = Player::query()->find($player->recommend_id);
                        $gameRecord->national_damage_ratio = $recommendPromoter->national_promoter->level_list->damage_rebate_ratio ?? 0;
                    }
                }
                $gameRecord->save();
            }
            //保存下分時間
            $services->last_point_at = time();
        }
    } catch (Exception $e) {
        throw new Exception($e->getMessage());
    }

    return $machine;
}

/**
 * @param int $is_system
 * @param PlayerGameLog $playerGameLog
 * @param int $gamingPressure 押分
 * @param int $gamingScore 得分
 * @param int $gamingTurnPoint 转数
 * @return void
 */
function extracted(
    int           $is_system,
    PlayerGameLog $playerGameLog,
    int           $gamingPressure,
    int           $gamingScore,
    int           $gamingTurnPoint
): void
{
    $playerGameLog->is_system = $is_system;
    $playerGameLog->pressure = $gamingPressure;
    $playerGameLog->score = $gamingScore;
    $playerGameLog->turn_point = $gamingTurnPoint;
    $playerGameLog->user_id = 0;
    $playerGameLog->user_name = '';
    $playerGameLog->save();
}

/**
 * @param Player $player
 * @param Machine $machine
 * @param PlayerGameRecord|null $gameRecord
 * @param int $control_open_point
 * @return PlayerGameLog
 */
function addPlayerGameLog(
    Player            $player,
    Machine           $machine,
    ?PlayerGameRecord $gameRecord,
    int               $control_open_point
): PlayerGameLog
{
    $odds = $machine->odds_x . ':' . $machine->odds_y;
    if ($machine->type == GameType::TYPE_STEEL_BALL) {
        $odds = $machine->machineCategory->name;
    }
    $playerGameLog = new PlayerGameLog;
    $playerGameLog->player_id = $player->id;
    $playerGameLog->parent_player_id = $player->recommend_id ?? 0;
    $playerGameLog->agent_player_id = $player->recommend_promoter->recommend_id ?? 0;
    $playerGameLog->department_id = $player->department_id;
    $playerGameLog->machine_id = $machine->id;
    $playerGameLog->game_record_id = isset($gameRecord) && !empty($gameRecord->id) ? $gameRecord->id : 0;
    $playerGameLog->game_id = $machine->machineCategory->game_id;
    $playerGameLog->type = $machine->type;
    $playerGameLog->odds = $odds;
    $playerGameLog->control_open_point = $control_open_point;
    $playerGameLog->open_point = 0;
    $playerGameLog->turn_used_point = $machine->machineCategory->turn_used_point;
    $playerGameLog->is_test = $player->is_test; //标记测试数据

    return $playerGameLog;
}

if (!function_exists('clearMachineCrashCache')) {
    /**
     * 清除玩家的爆机状态缓存
     * 在玩家充值或管理员修改爆机状态后调用
     *
     * @param int $playerId 玩家ID
     * @return bool
     */
    function clearMachineCrashCache(int $playerId): bool
    {
        try {
            $cacheKey = "machine_crash_status:{$playerId}";
            \support\Redis::del($cacheKey);
            
            Log::info('clearMachineCrashCache: 缓存已清除', [
                'player_id' => $playerId,
            ]);
            
            return true;
        } catch (\Exception $e) {
            Log::error('clearMachineCrashCache: 清除失败', [
                'player_id' => $playerId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
