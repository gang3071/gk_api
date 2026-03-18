<?php
/**
 * Here is your custom functions.
 */

use app\filesystem\Filesystem;
use app\model\ApiErrorLog;
use app\model\Channel;
use app\model\ChannelFinancialRecord;
use app\model\ChannelPlatformReverseWater;
use app\model\GameType;
use app\model\LevelList;
use app\model\Machine;
use app\model\MachineCategoryGiveRule;
use app\model\MachineGamingLog;
use app\model\MachineKeepingLog;
use app\model\MachineKickLog;
use app\model\MachineMedia;
use app\model\MachineMediaPush;
use app\model\MachineOpenCard;
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
use app\model\PlayerMoneyEditLog;
use app\model\PlayerPlatformCash;
use app\model\PlayerPromoter;
use app\model\PlayerRechargeRecord;
use app\model\PlayerRegisterRecord;
use app\model\PlayerReverseWaterDetail;
use app\model\PlayerWithdrawRecord;
use app\model\PlayGameRecord;
use app\model\PromoterProfitRecord;
use app\model\PromoterProfitSettlementRecord;
use app\model\SystemSetting;
use app\service\FishServices;
use app\service\JackpotService;
use app\service\MediaServer;
use app\service\SlotService;
use app\exception\PlayerCheckException;
use app\service\ActivityServices;
use app\service\game\GameServiceFactory;
use app\service\machine\MachineServices;
use app\service\machine\Slot;
use Carbon\Carbon;
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
        'data' => $data,
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
        'data' => $data,
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
function getValidationMessages(AllOfException $e)
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
    $setting = SystemSetting::where('feature', 'machine_maintain')->first();
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
 * 取得近7日 近30日累積轉數/壓/得
 * @param $machine_id
 * @return int[]
 */
function getMachineGamingLog($machine_id): array
{
    $today = date('Y-m-d');
    /** @var MachineOpenCard $machineOpenCard */
    $machineOpenCard = MachineOpenCard::where('machine_id', $machine_id)
        ->orderBy('id', 'desc')
        ->first();

    if (!empty($machineOpenCard)) {
        /** @var MachineGamingLog $machineGamingLog */
        $machineGamingLog = MachineGamingLog::where('machine_id', $machine_id)
            ->where('date', $today)
            ->where('updated_at', '>=', $machineOpenCard->created_at)
            ->first();
    } else {
        /** @var MachineGamingLog $machineGamingLog */
        $machineGamingLog = MachineGamingLog::where('machine_id', $machine_id)
            ->where('date', $today)
            ->first();
    }
    if (empty($machineGamingLog)) {
        return [
            'seventh_turn_point' => 0,
            'thirty_turn_point' => 0,
            'seventh_pressure' => 0,
            'seventh_score' => 0,
            'thirty_pressure' => 0,
            'thirty_score' => 0,
        ];
    }
    return [
        'seventh_turn_point' => $machineGamingLog->turn_point - $machineGamingLog->seventh_turn_point,
        'thirty_turn_point' => $machineGamingLog->turn_point - $machineGamingLog->thirty_turn_point,
        'seventh_pressure' => $machineGamingLog->pressure - $machineGamingLog->seventh_pressure,
        'seventh_score' => $machineGamingLog->score - $machineGamingLog->seventh_score,
        'thirty_pressure' => $machineGamingLog->pressure - $machineGamingLog->thirty_pressure,
        'thirty_score' => $machineGamingLog->score - $machineGamingLog->thirty_score,
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
function floorToPointSecondNumber($number)
{
    return floor($number * 100) / 100;
}

/**
 * 機台是否開獎中
 * @param Machine $machine
 * @param bool $isSystem
 * @return bool
 * @throws Exception
 */
function checkLottery(Machine $machine, bool $isSystem = false): bool
{
    //鋼珠
    if ($machine->type == GameType::TYPE_STEEL_BALL) {
        $result = (new JackpotService($machine))->machineAction('check_lottery', [], $isSystem);
        if (isset($result['result']) && $result['result'] == 1) {
            return true;
        }
    }

    //斯洛
    if ($machine->type == GameType::TYPE_SLOT) {
        $result = (new SlotService($machine))->machineAction('check_wash_key_status', [], $isSystem);
        //其中一個不為0 就是開獎中
        if ((isset($result['small_reward']) && !empty($result['small_reward'])) || (isset($result['big_reward']) && !empty($result['big_reward']))) {
            return true;
        }
    }

    return false;
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
    Player $player,
    Machine $machine,
    float $money,
    float $giftScore,
    ?MachineCategoryGiveRule $machineCategoryGiveRule
): bool {
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
        $gameRecord = PlayerGameRecord::where('machine_id', $machine->id)
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

        queueClient::send('media-recording', [
            'machine_id' => $machine->id,
            'action' => 'stop',
        ], 10);
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
function addLoginRecord($id)
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

    return PlayerLoginRecord::create([
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
function addRegisterRecord($id, $type, $department_id)
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

    return PlayerRegisterRecord::create([
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
 * 检查玩家游戏状态 5分钟没有使用机台玩家将被踢出(分数返还)
 * @return void
 * @throws Exception
 * @throws PushException
 */
function machineKeepOutPlayer(): void
{
    $log = Log::channel('machine_keeping');
    //機台例行維護中
    if (machineMaintaining()) {
        $log->info('PlayOutMachine', ['全站维护中']);
        return;
    }
    /** @var SystemSetting $setting */
    $setting = SystemSetting::where('feature', 'pending_minutes')->where('status', 1)->first();
    if (!$setting || $setting->num <= 0) {
        $settingMinutes = 2; // 默认2分钟进入保留状态
    } else {
        $settingMinutes = $setting->num;
    }

    // 不扣保留时间设置
    $isFreeTime = false;
    /** @var SystemSetting $keepingSetting */
    $keepingSetting = SystemSetting::where('feature', 'keeping_off')->where('status', 1)->first();
    if (!empty($keepingSetting)) {
        $offStart = $keepingSetting['date_start'] ?? '';
        $offEnd = $keepingSetting['date_end'] ?? '';
        if (!empty($offStart) && !empty($offEnd)) {
            $dateStart = date('Y-m-d') . ' ' . $offStart;
            $dateEnd = date('Y-m-d') . ' ' . $offEnd;

            if ($dateStart > $dateEnd) {
                $dateStart = date('Y-m-d H:i:s', strtotime($dateStart . '-1 day'));
            }

            $now = time();
            if ($now >= strtotime($dateStart) && $now <= strtotime($dateEnd)) {
                $isFreeTime = true;
            }
        }
    }
    //遊戲中玩家
    $gamingMachines = Machine::query()
        ->where('gaming', 1)
        ->where('gaming_user_id', '!=', 0)
        ->orderBy('type')
        ->get();
    /** @var Machine $machine */
    foreach ($gamingMachines as $machine) {
        try {
            if (Cache::has('machine_open_point' . $machine->id . '_' . $machine->gaming_user_id)) {
                continue;
            }
            /** @var Player $player */
            $player = $machine->gamingPlayer;
            $services = MachineServices::createServices($machine);
            if ($services->has_lock == 1) {
                $log->info('PlayOutMachine: 机台锁定跳过' . $machine->code);
                continue;
            }
            if ($machine->maintaining == 1) {
                $services->last_play_time = time();
            }
            $minutes = $settingMinutes * 60;
            if ($machine->type == GameType::TYPE_SLOT && $services->reward_status == 1) {
                $minutes = $settingMinutes + (15 * 60);
            }
            if ($services->keeping == 0 && time() - $services->last_play_time > $minutes) {
                if ($machine->type == GameType::TYPE_SLOT && $machine->is_special == 0 && $machine->control_type == Machine::CONTROL_TYPE_MEI) {
                    $services->sendCmd($services::OUT_OFF, 0, 'player', $player->id, 1);
                }
                $services->keeping = 1;
                $services->keeping_user_id = $machine->gaming_user_id;
                $services->last_keep_at = time();
                // 记录保留日志
                $machineKeepingLog = new MachineKeepingLog();
                $machineKeepingLog->player_id = $player->id;
                $machineKeepingLog->machine_id = $machine->id;
                $machineKeepingLog->machine_name = $machine->name;
                $machineKeepingLog->is_system = 1;
                $machineKeepingLog->department_id = $player->department_id;
                $machineKeepingLog->save();
                // 发送进入保留状态消息
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
            if ($services->keeping == 0) {
                $log->info('PlayOutMachine: 非保留状态跳过' . $machine->code);
                continue;
            }
            if ($isFreeTime && $services->keep_seconds > 1800) {
                $log->info('PlayOutMachine: 自由时间且时间大于1800秒跳过' . $machine->code);
                continue;
            }
            $keepSeconds = $services->keep_seconds;
            if ($keepSeconds > 0) {
                if ($services->reward_status == 1) {
                    if ($machine->type == GameType::TYPE_STEEL_BALL) {
                        $log->info('PlayOutMachine', [$machine->code . '开奖中15分钟内不扣除保留时间']);
                        continue;
                    }
                }
                $log->info('PlayOutMachine: 扣除保留时间', [$keepingSetting, $keepSeconds]);
                $services->keep_seconds = max(bcsub($keepSeconds, 10), 0);
                sendSocketMessage('player-' . $machine->gaming_user_id . '-' . $machine->id, [
                    'msg_type' => 'player_machine_keeping',
                    'player_id' => $machine->gaming_user_id,
                    'machine_id' => $machine->id,
                    'keep_seconds' => $keepSeconds,
                    'keeping' => $services->keeping
                ]);
                sendSocketMessage('player-' . $machine->gaming_user_id, [
                    'msg_type' => 'player_machine_keeping',
                    'player_id' => $machine->gaming_user_id,
                    'machine_id' => $machine->id,
                    'keep_seconds' => $keepSeconds,
                    'keeping' => $services->keeping
                ]);
            } else {
                // 保留时间为0时踢出玩家
                $beforeGameAmount = $player->machine_wallet->money;
                if (machineWash($player, $machine, 'leave', 1)) {
                    /** @var PlayerPlatformCash $playerPlatformWallet */
                    $playerPlatformWallet = PlayerPlatformCash::where([
                        'player_id' => $player->id,
                        'platform_id' => PlayerPlatformCash::PLATFORM_SELF,
                    ])->first();
                    //寫入踢人log
                    $afterGameAmount = $playerPlatformWallet->money;
                    $wash_point = abs($afterGameAmount - $beforeGameAmount);
                    $machineKickLog = new MachineKickLog;
                    $machineKickLog->player_id = $player->id;
                    $machineKickLog->machine_id = $machine->id;
                    $machineKickLog->platform_id = PlayerPlatformCash::PLATFORM_SELF;
                    $machineKickLog->wash_point = $wash_point;
                    $machineKickLog->before_game_amount = $beforeGameAmount;
                    $machineKickLog->after_game_amount = $afterGameAmount;

                    $machineKickLog->save();
                    // 更新保留日志
                    updateKeepingLog($machine->id, $player->id);
                    // 发送踢人消息
                    sendSocketMessage('player-' . $player->id . '-' . $machine->id, [
                        'msg_type' => 'kick_out',
                        'machine_id' => $machine->id,
                        'machine_name' => $machine->name,
                        'machine_code' => $machine->code,
                        'wash_point' => $wash_point,
                        'before_game_amount' => $beforeGameAmount,
                        'after_game_amount' => $afterGameAmount
                    ]);
                    sendSocketMessage('player-' . $player->id, [
                        'msg_type' => 'player_machine_keeping',
                        'player_id' => $player->id,
                        'machine_id' => $machine->id,
                        'keep_seconds' => '0',
                        'keeping' => '0'
                    ]);
                    // 清理赠点缓存
                    Cache::delete('gift_cache_' . $machine->id . '_' . $player->id);
                }
            }
        } catch (\Exception $e) {
            $log->error('PlayOutMachine', [$e->getMessage()]);
        }
    }
}

/**
 * 更新保留日志
 * @param $machineId
 * @param $playerId
 * @return void
 */
function updateKeepingLog($machineId, $playerId)
{
    /** @var MachineKeepingLog $machineKeepingLog */
    $machineKeepingLog = MachineKeepingLog::where([
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
 * 寫入每日累積轉數/壓/得
 * @return void
 * @throws Exception|\Exception
 */
function syncMachineGamingLog()
{
    $machines = Machine::where('status', 1)
        ->orderBy('type', 'asc')
        ->get();

    /** @var Machine $machine */
    $date = date('Y-m-d');
    foreach ($machines as $machine) {
        /** @var MachineOpenCard $machineOpenCard */
        $machineOpenCard = MachineOpenCard::where('machine_id', $machine->id)
            ->orderBy('id', 'desc')
            ->first();
        if (!empty($machineOpenCard)) {
            $seventh = MachineGamingLog::where('machine_id', $machine->id)
                ->where('updated_at', '>=', $machineOpenCard->created_at)
                ->orderBy('date', 'desc')
                ->limit(7)
                ->get()
                ->last();
        } else {
            /** @var MachineGamingLog $seventh */
            $seventh = MachineGamingLog::where('machine_id', $machine->id)
                ->orderBy('date', 'desc')
                ->limit(7)
                ->get()
                ->last();
        }
        if (!empty($machineOpenCard)) {
            /** @var MachineGamingLog $thirty */
            $thirty = MachineGamingLog::where('machine_id', $machine->id)
                ->where('updated_at', '>=', $machineOpenCard->created_at)
                ->orderBy('date', 'desc')
                ->limit(30)
                ->get()
                ->last();
        } else {
            /** @var MachineGamingLog $thirty */
            $thirty = MachineGamingLog::where('machine_id', $machine->id)
                ->orderBy('date', 'desc')
                ->limit(30)
                ->get()
                ->last();
        }

        if ($machine->type == GameType::TYPE_STEEL_BALL) {
            try {
                $services = MachineServices::createServices($machine);
                MachineGamingLog::updateOrCreate([
                    'machine_id' => $machine->id,
                    'type' => $machine->type,
                    'date' => $date,
                ], [
                    'turn_point' => $services->win_number ?? 0,
                    'seventh_turn_point' => $seventh->turn_point ?? 0,
                    'thirty_turn_point' => $thirty->turn_point ?? 0,
                ]);
            } catch (Exception $e) {
                continue;
            }
        }
        if ($machine->type == GameType::TYPE_SLOT) {
            try {
                $services = MachineServices::createServices($machine);
                $services->sendCmd($services::READ_BET, 0, 'admin', 0, 1);
                $services->sendCmd($services::READ_WIN, 0, 'admin', 0, 1);
                MachineGamingLog::query()->updateOrCreate([
                    'machine_id' => $machine->id,
                    'type' => $machine->type,
                    'date' => $date,
                ], [
                    'pressure' => $services->bet,
                    'score' => $services->win,
                    'seventh_pressure' => $seventh->pressure ?? 0,
                    'seventh_score' => $seventh->score ?? 0,
                    'thirty_pressure' => $thirty->pressure ?? 0,
                    'thirty_score' => $thirty->score ?? 0,
                ]);
            } catch (\Exception $e) {
                Log::error('syncMachineGamingLog', [$e->getMessage()]);
                continue;
            }
        }
    }
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
    switch ($type) {
        case PhoneSmsLog::TYPE_LOGIN:
            return 'sms-login' . $phone;
        case PhoneSmsLog::TYPE_REGISTER:
            return 'sms-register' . $phone;
        case PhoneSmsLog::TYPE_CHANGE_PASSWORD:
            return 'sms-change-password' . $phone;
        case PhoneSmsLog::TYPE_CHANGE_PAY_PASSWORD:
            return 'sms-change-pay-password' . $phone;
        case PhoneSmsLog::TYPE_CHANGE_PHONE:
            return 'sms-change-phone' . $phone;
        case PhoneSmsLog::TYPE_BIND_NEW_PHONE:
            return 'sms-type-bind-new-phone' . $phone;
        case PhoneSmsLog::TYPE_TALK_BIND:
            return 'sms-type-talk-bind' . $phone;
        default:
            return 'sms-' . $phone;
    }
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
//    switch ($country_code) {
//        case PhoneSmsLog::COUNTRY_CODE_JP:
//            $phone = ltrim($phone, '0');
//            break;
//        default:
//            break;
//    }
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
    switch ($type) {
        case PhoneSmsLog::TYPE_LOGIN:
            return config($source . '-sms.login_content');
        case PhoneSmsLog::TYPE_REGISTER:
            return config($source . '-sms.register_content');
        case PhoneSmsLog::TYPE_CHANGE_PASSWORD:
            return config($source . '-sms.change_password_content');
        case PhoneSmsLog::TYPE_CHANGE_PAY_PASSWORD:
            return config($source . '-sms.change_pay_password');
        case PhoneSmsLog::TYPE_CHANGE_PHONE:
            return config($source . '-sms.change_phone');
        case PhoneSmsLog::TYPE_BIND_NEW_PHONE:
            return config($source . '-sms.bind_new_phone');
        case PhoneSmsLog::TYPE_TALK_BIND:
            return config($source . '-sms.talk_bind');
        case PhoneSmsLog::TYPE_LINE_BIND:
            return config($source . '-sms.line_bind');
        default:
            return config($source . '-sms.sm_content');
    }
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
    string $rejectReason = '',
    int $withdrawStatus = PlayerWithdrawRecord::STATUS_PENDING_REJECT
): string {
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
function saveChannelFinancialRecord($target, $action)
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
 * 上传base64图片
 * @param $img
 * @param $path
 * @return false|string
 */
function uploadBaseImg($img, $path)
{
    if (preg_match('/^(data:\s*image\/(\w+);base64,)/', $img, $result)) {
        $type = $result[2];//图片后缀
        $savePath = '/storage/' . $path . '/' . date("Ymd", time()) . "/";
        $newPath = public_path() . $savePath;
        if (!file_exists($newPath)) {
            //检查是否有该文件夹，如果没有就创建，并给予最高权限
            mkdir($newPath, 0755, true);
        }

        $filename = time() . '_' . uniqid() . ".{$type}"; //文件名
        $newPath = $newPath . $filename;
        //写入操作
        if (file_put_contents($newPath, base64_decode(str_replace($result[1], '', $img)))) {
            return env('APP_URL', 'http://127.0.0.1:8787') . $savePath . $filename;
        }
        return false;
    }
    return false;
}

/**
 * 保存网络头像
 * @param $avatar
 * @return false|mixed|string
 */
function saveImg($avatar)
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
    isset($data['nickname']) && !empty($data['nickname']) && $player->name = $data['nickname'];
    if ($phoneBind) {
        isset($data['talk_phone']) && !empty($data['talk_phone']) && $player->phone = trim(trim($data['talk_phone'],
            '+'), '');
        isset($data['talk_country_code']) && !empty($data['talk_country_code']) && $player->country_code = trim($data['talk_country_code'],
            '+');
    }
    isset($data['userUid']) && !empty($data['userUid']) && $player->talk_user_id = $data['userUid'];
    $player->save();

    addPlayerExtend($player);

    addRegisterRecord($player->id, PlayerRegisterRecord::TYPE_TALK, $player->department_id);

    return $player;
}

/**
 * 检查充值订单取消超时订单
 * @throws Exception
 */
function cancelRecharge()
{
    /** @var SystemSetting $setting */
    $setting = SystemSetting::where('status', 1)->where('feature', 'recharge_order_expiration')->first();
    if (!empty($setting)) {
        $playerRechargeRecord = PlayerRechargeRecord::where('type', PlayerRechargeRecord::TYPE_SELF)
            ->where('status', PlayerRechargeRecord::STATUS_WAIT)
            ->where('created_at', '<', Carbon::now()->subMinutes($setting->num))
            ->get();
        /** @var PlayerRechargeRecord $order */
        foreach ($playerRechargeRecord as $order) {
            $order->status = PlayerRechargeRecord::STATUS_RECHARGED_SYSTEM_CANCEL;
            $order->cancel_time = date('Y-m-d H:i:s');
            $order->save();
        }
    }
}

/**
 * 发送socket消息
 * @param $channels
 * @param $content
 * @param string $form
 * @return bool|string
 * @throws PushException
 */
function sendSocketMessage($channels, $content, string $form = 'system')
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
function addPlayerExtend(Player $player)
{
    $registerPresent = SystemSetting::where('feature', 'register_present')->where('status', 1)->value('num') ?? 0;

    PlayerPlatformCash::firstOrCreate([
        'player_id' => $player->id,
        'platform_id' => PlayerPlatformCash::PLATFORM_SELF,
        'money' => $registerPresent,
    ]);

    PlayerExtend::firstOrCreate([
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
 * @return array
 */
function getChannel($siteId): array
{
    $cacheKey = "channel_" . $siteId;
    $channel = Cache::get($cacheKey);
    if (empty($channel)) {
        $channel = Channel::where('id', $siteId)->whereNull('deleted_at')->first()->toArray();
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
        $player = Player::find($id);
        if (empty($player)) {
            throw new Exception(trans('player_not_found', [], 'message'));
        }
        if (!empty($player->player_promoter)) {
            throw new Exception(trans('player_is_promoter', [], 'message'));
        }
        $promoter = new PlayerPromoter();

        /** @var PlayerPromoter $parentPromoter */
        $parentPromoter = PlayerPromoter::where('player_id', $player->recommend_id)->first();
        $maxRatio = $parentPromoter->ratio ?? 100;
        if ($ratio > $maxRatio) {
            throw new Exception(trans('ratio_max_error', ['{max_ratio}' => $maxRatio], 'message'));
        }

        /** @var PlayerPromoter $subPromoter */
        $subPromoter = PlayerPromoter::where('recommend_id', $player->id)->orderBy('ratio', 'asc')->first();
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
        $player = Player::find($id);
        if (empty($player)) {
            throw new Exception(trans('player_not_found', [], 'message'));
        }
        if (!empty($player->player_promoter)) {
            throw new Exception(trans('player_is_promoter', [], 'message'));
        }
        $promoter = new PlayerPromoter();

        /** @var PlayerPromoter $parentPromoter */
        $parentPromoter = PlayerPromoter::where('player_id', $player->recommend_id)->first();
        $maxRatio = 100;
        if ($ratio > $maxRatio) {
            throw new Exception(trans('ratio_max_error', ['{max_ratio}' => $maxRatio], 'message'));
        }

        /** @var PlayerPromoter $subPromoter */
        $subPromoter = PlayerPromoter::where('recommend_id', $player->id)->orderBy('ratio', 'asc')->first();
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
function doCurl(string $url, int $gaming_user_id, int $machine_id, array $params = [])
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
        $checkOpen = checkMachineOpenAny($machine, $money, 0);
        $openScore = $checkOpen['open_score'];
        //记录游戏局记录
        /** @var PlayerGameRecord $gameRecord */
        $gameRecord = PlayerGameRecord::where('machine_id', $machine->id)
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
): PlayerGameLog {
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
    Player $player,
    PlayerGameLog $playerGameLog,
    Machine $machine,
    int $money,
    float $beforeGameAmount,
    float $afterGameAmount
): void {
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
function getGivePoints($playerId, $machineId)
{
    return Cache::get('gift_cache_' . $machineId . '_' . $playerId);
}

/**
 * 获取堆栈信息
 * @return void
 */
function getStackList(): void
{
    $line = [];
    $debugList = array_reverse(debug_backtrace());
    foreach ($debugList as $key => $val) {
        $class = $val['class'] ?? "";
        $arg = $val['args'];
        $parameter = '';
        $stringLine = '';
        if (!empty($arg) && is_array($arg)) {
            foreach ($arg as $v) {
                $className = $v;
                if (is_object($v)) {
                    $className = get_class($v);
                } elseif (is_array($v)) {
                    $className = json_encode($v);
                }
                $parameter .= $className . ',';
            }
        }
        $stringLine .= '程序执行' . $key . ':=>';
        $stringLine .= '[1.所在文件（' . $val['file'] . '）]，';
        $stringLine .= '[2.函数调用情况[第' . $val['line'] . '行]：' . $class . '->' . $val['function'] . '(' . $parameter . ')]' . "\n";
        $line[] = $stringLine;
    }
    Log::error("堆栈信息", $line);
}

/**
 * 设置机台在线数据
 * @param Machine $machine
 * @return array|false
 */
function setMachineLive(Machine $machine)
{
    try {
        $machineStatus = [];
        $cacheKey = 'machine_live_status_' . $machine->id;
        $millisecond = millisecond();
        $machineCache = Cache::get($cacheKey);
        if (empty($machineCache) || (isset($machineCache['time']) && $machineCache['time'] < $millisecond)) {
            switch ($machine->type) {
                case GameType::TYPE_STEEL_BALL:
                    $service = new JackpotService($machine);
                    $combineStatus = $service->machineAction('combine_status', [], true);
                    $machineStatus['turn_point'] = $combineStatus['turn_point'] ?? 0;
                    $machineStatus['total_turn'] = $combineStatus['total_turn'] ?? 0;
                    $result = $service->machineAction('check_lottery', [], true);
                    if (isset($result['result']) && $result['result'] == 1) {
                        $checkLottery = 1;
                    }
                    // 获取珠数
                    $machineStatus['display_score'] = $service->machineAction('display_score', [],
                        true)['score'] ?? 0;
                    $machineStatus['check_lottery'] = $checkLottery ?? 0;
                    $machineStatus['gaming_user_id'] = $machine->gaming_user_id;
                    if ($machineStatus['turn_point'] == 0) {
                        /** @var PlayerGiftRecord $playerGiftRecord */
                        $playerGiftRecord = PlayerGiftRecord::where('machine_id', $machine->id)->orderBy('id',
                            'desc')->first();
                        if (!empty($playerGiftRecord)) {
                            $giftCacheKey = 'gift_cache_' . $machine->id . '_' . $playerGiftRecord->player_id;
                            $giftCache = Cache::get($giftCacheKey);
                            if ($giftCache && $giftCache['time'] < $millisecond) {
                                Cache::delete($giftCacheKey);
                            }
                        }
                    }
                    if (!empty($machineCache)) {
                        $machineStatus['last_data'] = [
                            'turn_point' => $machineCache['turn_point'],
                            'total_turn' => $machineCache['total_turn'],
                            'display_score' => $machineCache['display_score'],
                            'check_lottery' => $machineCache['check_lottery'],
                            'gaming_user_id' => $machineCache['gaming_user_id'],
                        ];
                    } else {
                        $machineStatus['last_data'] = $machineStatus;
                    }
                    break;
                case GameType::TYPE_SLOT:
                    $service = new SlotService($machine);
                    $combineStatus = $service->machineAction('combine_status', [], true);
                    $machineStatus['pressure'] = $combineStatus['pressure'] ?? 0;
                    $machineStatus['score'] = $combineStatus['score'] ?? 0;
                    $machineStatus['seven_display'] = $combineStatus['seven_display'] ?? 0;
                    $result = $service->machineAction('check_wash_key_status', [], true);
                    if ((isset($result['small_reward']) && !empty($result['small_reward'])) || (isset($result['big_reward']) && !empty($result['big_reward']))) {
                        $checkLottery = 1;
                    }
                    $machineStatus['check_lottery'] = $checkLottery ?? 0;
                    $machineStatus['gaming_user_id'] = $machine->gaming_user_id;
                    // 分数为0清理缓存
                    /** @var PlayerGiftRecord $playerGiftRecord */
                    $playerGiftRecord = PlayerGiftRecord::where('machine_id', $machine->id)->orderBy('id',
                        'desc')->first();
                    if (!empty($playerGiftRecord)) {
                        $giftCache = Cache::get('gift_cache_' . $machine->id . '_' . $playerGiftRecord->player_id);
                        if ($giftCache && $giftCache['time'] < $millisecond) {
                            if ($machineStatus['seven_display'] == 0 || ($machineStatus['seven_display'] >= $giftCache['open_point'] * $giftCache['condition'])) {
                                Cache::delete('gift_cache_' . $machine->id . '_' . $playerGiftRecord->player_id);
                            }
                        }
                    }
                    if (!empty($machineCache)) {
                        $machineStatus['last_data'] = [
                            'pressure' => $machineCache['pressure'],
                            'score' => $machineCache['score'],
                            'seven_display' => $machineCache['seven_display'],
                            'check_lottery' => $machineCache['check_lottery'],
                            'gaming_user_id' => $machineCache['gaming_user_id'],
                        ];
                    } else {
                        $machineStatus['last_data'] = $machineStatus;
                    }
                    break;
                default:
                    return false;
            }
            $machineStatus['status'] = 200;
            $machineStatus['time'] = $millisecond;
            $machineStatus['give_data'] = getGivePoints($machine->gaming_user_id, $machine->id);
            Cache::set($cacheKey, $machineStatus, 600);
        }
    } catch (Exception $e) {
        return false;
    }

    return $machineStatus ?? $machineCache;
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
): string {
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
 * 检查data55
 * @param string $data
 * @return bool
 * @throws Exception
 */
function checkData55(string $data): bool
{
    $str = substr($data, 8, 8);
    $data55 = substr($data, 20, 4);

    $result = intval(hexdec(substr($str, 0, 2))) ^ intval(hexdec(substr($str, 2, 2))) ^ intval(hexdec(substr($str, 4,
            2))) ^ 0x55;
    $result = sprintf("%02X", $result);
    $hex = "";
    for ($i = strlen($result) - 1; $i >= 0; $i--) {
        $hex .= sprintf("%02X", hexdec($result[$i]));
    }
    if ($data55 != $hex) {
        throw new Exception('data55检查不通过');
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
 * 结算
 * @param $id
 * @param int $userId
 * @param string $userName
 * @return void
 * @throws Exception
 */
function doSettlement($id, int $userId = 0, string $userName = '')
{
    /** @var PlayerPromoter $playerPromoter */
    $playerPromoter = PlayerPromoter::where('player_id', $id)->first();
    if (empty($playerPromoter)) {
        throw new Exception(trans('profit_amount_not_found', [], 'message'));
    }
    if ($playerPromoter->status == 0) {
        throw new Exception(trans('player_promoter_has_disable', [], 'message'));
    }
    if (!isset($playerPromoter->profit_amount)) {
        throw new Exception(trans('profit_amount_not_found', [], 'message'));
    }
    $profitAmount = PromoterProfitRecord::where('status', PromoterProfitRecord::STATUS_UNCOMPLETED)
        ->where('promoter_player_id', $id)
        ->first([
            DB::raw('SUM(`withdraw_amount`) as total_withdraw_amount'),
            DB::raw('SUM(`recharge_amount`) as total_recharge_amount'),
            DB::raw('SUM(`commission`) as total_commission_amount'),
            DB::raw('SUM(`bonus_amount`) as total_bonus_amount'),
            DB::raw('SUM(`admin_deduct_amount`) as total_admin_deduct_amount'),
            DB::raw('SUM(`admin_add_amount`) as total_admin_add_amount'),
            DB::raw('SUM(`present_amount`) as total_present_amount'),
            DB::raw('SUM(`machine_up_amount`) as total_machine_up_amount'),
            DB::raw('SUM(`machine_down_amount`) as total_machine_down_amount'),
            DB::raw('SUM(`lottery_amount`) as total_lottery_amount'),
            DB::raw('SUM(`profit_amount`) as total_profit_amount'),
            DB::raw('SUM(`player_profit_amount`) as total_player_profit_amount'),
            DB::raw('SUM(`game_amount`) as total_game_amount'),
        ])
        ->toArray();

    DB::beginTransaction();
    try {
        $promoterProfitSettlementRecord = new PromoterProfitSettlementRecord();
        $promoterProfitSettlementRecord->department_id = $playerPromoter->player->department_id;
        $promoterProfitSettlementRecord->promoter_player_id = $playerPromoter->player_id;
        $promoterProfitSettlementRecord->total_withdraw_amount = $profitAmount['total_withdraw_amount'] ?? 0;
        $promoterProfitSettlementRecord->total_recharge_amount = $profitAmount['total_recharge_amount'] ?? 0;
        $promoterProfitSettlementRecord->total_commission_amount = $profitAmount['total_commission_amount'] ?? 0;
        $promoterProfitSettlementRecord->total_bonus_amount = $profitAmount['total_bonus_amount'] ?? 0;
        $promoterProfitSettlementRecord->total_admin_deduct_amount = $profitAmount['total_admin_deduct_amount'] ?? 0;
        $promoterProfitSettlementRecord->total_admin_add_amount = $profitAmount['total_admin_add_amount'] ?? 0;
        $promoterProfitSettlementRecord->total_present_amount = $profitAmount['total_present_amount'] ?? 0;
        $promoterProfitSettlementRecord->total_machine_up_amount = $profitAmount['total_machine_up_amount'] ?? 0;
        $promoterProfitSettlementRecord->total_machine_down_amount = $profitAmount['total_machine_down_amount'] ?? 0;
        $promoterProfitSettlementRecord->total_lottery_amount = $profitAmount['total_lottery_amount'] ?? 0;
        $promoterProfitSettlementRecord->total_profit_amount = $profitAmount['total_profit_amount'];
        $promoterProfitSettlementRecord->total_player_profit_amount = $profitAmount['total_player_profit_amount'] ?? 0;
        $promoterProfitSettlementRecord->total_game_amount = $profitAmount['total_game_amount'] ?? 0;
        $promoterProfitSettlementRecord->last_profit_amount = $playerPromoter->last_profit_amount;
        $promoterProfitSettlementRecord->adjust_amount = $playerPromoter->adjust_amount;
        $promoterProfitSettlementRecord->type = PromoterProfitSettlementRecord::TYPE_SETTLEMENT;
        $promoterProfitSettlementRecord->tradeno = createOrderNo();
        $promoterProfitSettlementRecord->user_id = $userId;
        $promoterProfitSettlementRecord->user_name = $userName;
        $settlement = $amount = bcsub(bcadd($promoterProfitSettlementRecord->total_profit_amount,
            $promoterProfitSettlementRecord->adjust_amount, 2),
            $promoterProfitSettlementRecord->total_commission_amount, 2);
        if ($amount > 0) {
            if ($playerPromoter->settlement_amount < 0) {
                $diffAmount = bcadd($amount, $playerPromoter->settlement_amount, 2);
                $settlement = max($diffAmount, 0);
            }
        }
        $promoterProfitSettlementRecord->actual_amount = $settlement;
        $promoterProfitSettlementRecord->save();
        // 更新结算报表
        PromoterProfitRecord::where('status', PromoterProfitRecord::STATUS_UNCOMPLETED)
            ->where('promoter_player_id', $id)
            ->update([
                'status' => PromoterProfitRecord::STATUS_COMPLETED,
                'settlement_time' => date('Y-m-d H:i:s'),
                'settlement_tradeno' => $promoterProfitSettlementRecord->tradeno,
                'settlement_id' => $promoterProfitSettlementRecord->id,
            ]);
        // 结算后这些数据清零
        $playerPromoter->profit_amount = 0;
        $playerPromoter->player_profit_amount = 0;
        $playerPromoter->team_recharge_total_amount = 0;
        $playerPromoter->total_commission = 0;
        $playerPromoter->team_withdraw_total_amount = 0;
        $playerPromoter->adjust_amount = 0;
        // 更新数据
        $playerPromoter->team_profit_amount = bcsub($playerPromoter->team_profit_amount,
            $promoterProfitSettlementRecord->total_profit_amount, 2);
        $playerPromoter->last_profit_amount = $settlement;
        $playerPromoter->settlement_amount = bcadd($playerPromoter->settlement_amount, $amount, 2);
        $playerPromoter->team_settlement_amount = bcadd($playerPromoter->team_settlement_amount,
            $promoterProfitSettlementRecord->total_profit_amount, 2);
        $playerPromoter->last_settlement_time = date('Y-m-d', strtotime('-1 day'));

        if (!empty($playerPromoter->path)) {
            PlayerPromoter::where('player_id', '!=', $playerPromoter->player_id)
                ->whereIn('player_id', explode(',', $playerPromoter->path))
                ->update([
                    'team_profit_amount' => DB::raw("team_profit_amount - {$promoterProfitSettlementRecord->total_profit_amount}"),
                    'team_settlement_amount' => DB::raw("team_settlement_amount + $promoterProfitSettlementRecord->total_profit_amount"),
                ]);
        }
        if ($settlement > 0) {
            // 增加钱包余额
            $amountBefore = $playerPromoter->player->machine_wallet->money;
            $amountAfter = bcadd($amountBefore, $settlement, 2);
            $playerDeliveryRecord = new PlayerDeliveryRecord;
            $playerDeliveryRecord->player_id = $playerPromoter->player_id;
            $playerDeliveryRecord->department_id = $playerPromoter->department_id;
            $playerDeliveryRecord->target = $promoterProfitSettlementRecord->getTable();
            $playerDeliveryRecord->target_id = $promoterProfitSettlementRecord->id;
            $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_PROFIT;
            $playerDeliveryRecord->source = 'profit';
            $playerDeliveryRecord->amount = $settlement;
            $playerDeliveryRecord->amount_before = $amountBefore;
            $playerDeliveryRecord->amount_after = $amountAfter;
            $playerDeliveryRecord->tradeno = $promoterProfitSettlementRecord->tradeno ?? '';
            $playerDeliveryRecord->remark = '';
            $playerDeliveryRecord->save();

            $playerPromoter->player->machine_wallet->money = $amountAfter;
        }
        $playerPromoter->push();
        DB::commit();
    } catch (\Exception $e) {
        DB::rollback();
        throw new Exception($e->getMessage());
    }
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
function sendMachineException(Machine $machine, $type, int $playerId = 0)
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
 * 发送提现待审核消息
 * @return void
 * @throws PushException
 */
function reviewedWithdrawMessage()
{
    $subQuery = PlayerWithdrawRecord::query()
        ->select(DB::raw('MAX(id) as id'))
        ->where('status', PlayerWithdrawRecord::STATUS_WAIT)
        ->groupBy('department_id');
    /** @var PlayerWithdrawRecord $playerLotteryRecord */
    $playerWithdrawRecordList = PlayerWithdrawRecord::query()
        ->whereIn('id', $subQuery)
        ->get();
    if (!empty($playerWithdrawRecordList)) {
        /** @var PlayerWithdrawRecord $item */
        foreach ($playerWithdrawRecordList as $item) {
            sendSocketMessage('private-admin_group-channel-' . $item->department_id, [
                'msg_type' => 'player_create_withdraw_order',
                'id' => $item->id,
                'player_id' => $item->player_id,
                'player_name' => $item->player_name,
                'player_phone' => $item->player_phone,
                'money' => $item->money,
                'point' => $item->point,
                'status' => $item->status,
                'tradeno' => $item->tradeno,
            ]);
        }
    }
}

/**
 * 发送充值待审核消息
 * @return void
 * @throws PushException
 */
function reviewedRechargeMessage()
{
    $subQuery = PlayerRechargeRecord::query()
        ->select(DB::raw('MAX(id) as id'))
        ->where('status', PlayerRechargeRecord::STATUS_RECHARGING)
        ->whereIn('type', [PlayerRechargeRecord::TYPE_SELF, PlayerRechargeRecord::TYPE_BUSINESS])
        ->groupBy('department_id');
    /** @var PlayerRechargeRecord $playerRechargeRecord */
    $playerRechargeRecordList = PlayerRechargeRecord::query()
        ->whereIn('id', $subQuery)
        ->get();
    if (!empty($playerRechargeRecordList)) {
        /** @var PlayerRechargeRecord $item */
        foreach ($playerRechargeRecordList as $item) {
            sendSocketMessage('private-admin_group-channel-' . $item->department_id, [
                'msg_type' => 'player_examine_recharge_order',
                'id' => $item->id,
                'player_id' => $item->player_id,
                'player_name' => $item->player_name,
                'player_phone' => $item->player_phone,
                'money' => $item->money,
                'status' => $item->status,
                'tradeno' => $item->tradeno,
            ]);
        }
    }
}

/**
 * 获取当前usdt汇率
 * @param $currency
 * @return mixed|null
 */
function getUSDTExchangeRate($currency)
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
 * 获取结算日期条件
 * @param $type
 * @param $column
 * @return array
 */
function getDateWhere($type, $column): array
{
    $where = [];

    switch ($type) {
        case 1: // 今天
            $where[] = [
                function ($query) use ($column) {
                    $query->where($column, '>=', Carbon::today()->startOfDay())
                        ->where($column, '<=', Carbon::today()->endOfDay());
                }
            ];
            break;
        case 2: // 昨天
            $where[] = [
                function ($query) use ($column) {
                    $query->where($column, '>=', Carbon::yesterday()->startOfDay())
                        ->where($column, '<=', Carbon::yesterday()->endOfDay());
                }
            ];
            break;
        case 3: // 本周
            $where[] = [
                function ($query) use ($column) {
                    $query->where($column, '>=', Carbon::today()->startOfWeek()->startOfDay())
                        ->where($column, '<=', Carbon::today()->endOfWeek()->endOfDay());
                }
            ];
            break;
        case 4: // 上周
            $where[] = [
                function ($query) use ($column) {
                    $query->where($column, '>=', Carbon::today()->subWeek()->startOfWeek()->startOfDay())
                        ->where($column, '<=', Carbon::today()->subWeek()->endOfWeek()->endOfDay());
                }
            ];
            break;
        case 5: // 本月
            $where[] = [
                function ($query) use ($column) {
                    $query->where($column, '>=', Carbon::today()->firstOfMonth()->startOfDay())
                        ->where($column, '<=', Carbon::today()->endOfMonth()->endOfDay());
                }
            ];
            break;
        case 6: // 上月
            $where[] = [
                function ($query) use ($column) {
                    $query->where($column, '>=', Carbon::today()->subMonth()->firstOfMonth()->startOfDay())
                        ->where($column, '<=', Carbon::today()->subMonth()->endOfMonth()->endOfDay());
                }
            ];
            break;
        default:
            break;
    }

    return $where;
}

//终止机台录像
function machinesRecordingStop()
{
    $machines = Machine::query()
        ->where('status', 1)
        ->pluck('id');
    foreach ($machines as $machine) {
        queueClient::send('media-recording', [
            'machine_id' => $machine,
            'action' => 'stop',
        ], 10);
    }
}

/**
 * @return void
 */
function nationalPromoterRebate(): void
{
    $log = Log::channel('national_promoter');
    ini_set('memory_limit', '512M');
    $log->info('全民代理统计开始: NationalPromoterRebate' . date('Y-m-d H:i:s'));
    $time = date('Y-m-d H:i:s');
    $playGameRecord = PlayGameRecord::query()
        ->where('national_promoter_action', 0)
        ->where('created_at', '<=', $time)
        ->where('settlement_status', PlayGameRecord::SETTLEMENT_STATUS_SETTLED)
        ->selectRaw("player_id, sum(bet) as all_bet, sum(diff) as all_diff")
        ->groupBy('player_id')
        ->get();
    if (empty($playGameRecord->toArray())) {
        $log->info('全民代理统计开始: NationalPromoterRebate' . date('Y-m-d H:i:s') . '未产生数据');
        return;
    }
    foreach ($playGameRecord as $item) {
        Db::beginTransaction();
        try {
            $log->info('全民代理统计: NationalPromoterRebate' . date('Y-m-d H:i:s'), [
                $item->toArray()
            ]);
            //计算所有玩家打码量
            if ($item->all_bet > 0 && !empty($item->player->national_promoter)) {
                //当前玩家打码量
                $item->player->national_promoter->chip_amount = bcadd($item->player->national_promoter->chip_amount,
                    $item->all_bet, 2);
                //根据打码量查询玩家当前全民代理等级
                /** @var LevelList $levelId */
                $levelId = LevelList::query()
                    ->where('department_id', $item->player->department_id)
                    ->where('must_chip_amount', '<=', $item->player->national_promoter->chip_amount)
                    ->orderBy('must_chip_amount', 'desc')
                    ->first();
                if (!empty($levelId) && isset($levelId->id)) {
                    //根据打码量提升玩家全民代理等级
                    $item->player->national_promoter->level = $levelId->id;
                }
                $item->player->push();
                if (!empty($item->player->recommend_id) && $item->all_diff != 0 && $item->player->national_promoter->status == 1 && !empty($levelId)) {
                    /** @var Player $recommendPromoter */
                    $recommendPromoter = Player::query()->with([
                        'national_promoter',
                        'national_promoter.level_list'
                    ])->find($item->player->recommend_id);
                    if (!empty($recommendPromoter->national_promoter) && $recommendPromoter->is_promoter < 1 && $recommendPromoter->status_national == 1) {
                        $damageRebateRatio = isset($recommendPromoter->national_promoter->level_list->damage_rebate_ratio) ? $recommendPromoter->national_promoter->level_list->damage_rebate_ratio : 0;
                        $money = bcdiv(bcmul(-$item->all_diff, $damageRebateRatio, 2), 100, 2);
                        $recommendPromoter->national_promoter->pending_amount = bcadd($recommendPromoter->national_promoter->pending_amount,
                            $money, 2);
                        $recommendPromoter->push();
                        /** @var NationalProfitRecord $nationalProfitRecord */
                        $nationalProfitRecord = NationalProfitRecord::query()->where('uid', $item->player->id)
                            ->where('type', 1)
                            ->whereDate('created_at', date('Y-m-d'))
                            ->first();
                        if (!empty($nationalProfitRecord)) {
                            $nationalProfitRecord->money = bcadd($nationalProfitRecord->money, $money, 2);
                        } else {
                            $nationalProfitRecord = new NationalProfitRecord();
                            $nationalProfitRecord->uid = $item->player->id;
                            $nationalProfitRecord->recommend_id = $item->player->recommend_id;
                            $nationalProfitRecord->money = $money;
                            $nationalProfitRecord->type = 1;
                        }
                        $nationalProfitRecord->save();
                    }
                }
            }
            PlayGameRecord::query()
                ->where('national_promoter_action', 0)
                ->where('settlement_status', PlayGameRecord::SETTLEMENT_STATUS_SETTLED)
                ->where('player_id', $item->player_id)
                ->where('created_at', '<=', $time)
                ->update([
                    'national_promoter_action' => 1,
                    'national_damage_ratio' => $damageRebateRatio ?? 0
                ]);
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            $log->error('全民代理统计错误: NationalPromoterRebate' . date('Y-m-d H:i:s') . $e->getMessage());
        }
    }
    $log->info('全民代理统计结束: NationalPromoterRebate' . date('Y-m-d H:i:s'));
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
 * @return void
 */
function playerLeve(): void
{
    $playGameRecord = PlayerReverseWaterDetail::query()
        ->selectRaw('sum(point) as bet, sum(all_diff) as diff, player_id')
        ->groupBy('player_id')
        ->get()->toArray();
    $playerGameLog = Db::select("SELECT
        	sum( pressure + turn_point * turn_used_point) AS bet,
            	player_id
            FROM
            	(
            	SELECT
            		max( pressure ) AS pressure,
            		max( turn_point) AS turn_point,
            		max( turn_used_point) AS turn_used_point,
            		game_record_id,
            		player_id
            	FROM
            		player_game_log
            	WHERE
            		created_at > '2025-01-23'
            		AND game_record_id NOT IN ( SELECT id FROM player_game_record WHERE STATUS = 1 AND created_at > '2025-01-23' )
            	GROUP BY
            		game_record_id,
            		player_id
            	) AS a
            GROUP BY
            	player_id");
    $record = [];
    $log = [];
    foreach ($playGameRecord as $gameRecord) {
        $record[$gameRecord['player_id']] = $gameRecord;
    }
    foreach ($playerGameLog as $gameLog) {
        $log[$gameLog->player_id] = ['bet' => $gameLog->bet, 'player_id' => $gameLog->player_id];
    }
    foreach ($record as $key => $value) {
        if (isset($log[$key])) {
            $log[$key]['bet'] += $value['bet'];
        } else {
            $log[$key]['bet'] = $value['bet'];
            $log[$key]['player_id'] = $value['player_id'];
        }
    }
    foreach ($log as $item) {
        try {
            $player = Player::query()->find($item['player_id']);
            //玩家是全民代理
            if (!empty($player->national_promoter) && $item['bet'] > 0) {
                //当前玩家打码量
                $player->national_promoter->chip_amount = $item['bet'];
                //根据打码量查询玩家当前全民代理等级
                /** @var LevelList $levelId */
                $levelId = LevelList::query()
                    ->where('department_id', $player->department_id)
                    ->where('must_chip_amount', '<=', $player->national_promoter->chip_amount)
                    ->orderBy('must_chip_amount', 'desc')
                    ->first();
                if (!empty($levelId) && isset($levelId->id)) {
                    //根据打码量提升玩家全民代理等级
                    $player->national_promoter->level = $levelId->id;
                }
                $player->push();
            }
        } catch (\Exception $e) {
            Log::info($e->getMessage());
        }
    }

    return;
}


/**
 * 返水测试
 * @param $playerId
 * @return array|void
 */
function testRakeBack($playerId)
{
    $now = Carbon::now()->format('H:i:s');
// 构建渠道配置映射表
    $waterMap = [];
    $departmentIds = Channel::query()->where('reverse_water_status', 1)->pluck('department_id');

    ChannelPlatformReverseWater::query()
        ->whereIn('department_id', $departmentIds)
        ->where('status', 1)
        ->where('checkout_time', '<=', $now)
        ->each(function ($waterModel) use (&$waterMap) {
            $waterMap[$waterModel->department_id][$waterModel->platform_id] = $waterModel;
        });

    if (empty($waterMap)) {
        return;
    }

    $playRecords = PlayGameRecord::with('player:id,department_id,status_reverse_water')
        ->select([
            'player_id',
            'platform_id',
            DB::raw('SUM(bet) as total_bet'),
            DB::raw('SUM(diff) as total_diff'),
            DB::raw('GROUP_CONCAT(id ORDER BY id) as record_ids')
        ])
        ->whereDate('created_at', date('Y-m-d'))
        ->where('is_reverse', 0)
        ->whereHas('player', function ($query) use ($waterMap) {
            $query->whereIn('department_id', array_keys($waterMap))
                ->where('status_reverse_water', 1);
        })
        ->where('player_id', $playerId)
        ->groupBy('player_id', 'platform_id')
        ->get();


    $groupData = [];
    /** @var PlayGameRecord $record */
    foreach ($playRecords as $record) {
        if (!$player = $record->player) {
            continue;
        }

        $key = "{$record->player_id}_{$record->platform_id}";

        $recordIds = explode(',', $record->record_ids ?? '');
        $validIds = array_filter($recordIds, function ($id) {
            return is_numeric($id) && $id !== '';
        });

        if (!isset($groupData[$key])) {
            $groupData[$key] = [
                'player' => $player,
                'waterModel' => $waterMap[$player->department_id][$record->platform_id] ?? null,
                'total_bet' => (float)$record->total_bet,
                'total_diff' => (float)$record->total_diff,
                'record_ids' => $validIds
            ];
        }
    }

    $insertData = [];
    $playGameIds = [];
    $noticeData = [];
    $time = Carbon::now();

    foreach ($groupData as $item) {
        if (!$item['waterModel'] || $item['total_bet'] <= 0) {
            continue;
        }

        /** @var Player $player */
        $player = $item['player'];
        // 获取玩家等级反水比例
        $levelRatio = $player->national_promoter()
            ->first()
            ?->level_list()
            ->first()->reverse_water ?? 0;

        // 获取平台反水比例
        $waterRatio = $item['waterModel']->setting()
            ->where('point', '<=', $item['total_bet'])
            ->orderBy('point', 'desc')
            ->value('ratio') ?? 0;

        $reverseWater = (float)bcmul($item['total_bet'], ($levelRatio + $waterRatio) / 100, 2);

        $insertData[] = [
            'admin_id' => 0,
            'player_id' => $player->id,
            'platform_id' => $item['waterModel']->platform_id,
            'point' => $item['total_bet'],
            'all_diff' => $item['total_diff'],
            'date' => date('Y-m-d'),
            'reverse_water' => $reverseWater,
            'level_ratio' => $levelRatio,
            'created_at' => $time,
            'platform_ratio' => $waterRatio,
            'status' => 0
        ];

        $playGameIds = array_merge($playGameIds, $item['record_ids']);

        if (!isset($noticeData[$player->id])) {
            $noticeData[$player->id] = [
                'reverse_water' => 0,
                'department_id' => $player->department_id,
                'date' => date('Y-m-d')
            ];
        }
        $noticeData[$player->id]['reverse_water'] += $reverseWater;
    }

    $noticeInsert = [];
    Db::beginTransaction();
    try {
        PlayerReverseWaterDetail::query()->insert($insertData);
        $detailIds = PlayerReverseWaterDetail::query()
            ->where('date', date('Y-m-d'))
            ->pluck('id', 'player_id')
            ->toArray();

        foreach ($noticeData as $playerId => $item) {
            $noticeInsert[] = [
                'department_id' => $item['department_id'],
                'player_id' => $playerId,
                'source_id' => $detailIds[$playerId] ?? 0,
                'type' => Notice::TYPE_REVERSE_WATER,
                'receiver' => Notice::RECEIVER_PLAYER,
                'is_private' => 1,
                'title' => '電子遊戲反水獎勵',
                'created_at' => $time,
                'content' => sprintf('恭喜您在昨日的電子遊戲中獲得反水獎勵，奖励游戏点数%.2f', $item['reverse_water'])
            ];
        }

        //批量插入消息通知
        Notice::query()->insert($noticeInsert);

        PlayGameRecord::query()->whereIn('id', $playGameIds)->update(['is_reverse' => 1]);
        Db::commit();
    } catch (\Exception $e) {
        echo $e->getMessage();
        Log::channel('reverse_water')->error('反水错误: ' . $e->getMessage());
        Db::rollback();
    }

    return $insertData;
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
 * 批量添加流地址
 * 如果不传key和过期时间，将返回不含防盗链的url
 * @return bool
 */
function addPush(): bool
{
    $insertData = [];
    $machineTencentPlay = MachineTencentPlay::query()->get();
    $machineMediaList = MachineMedia::with('machine') // 预加载 machine 关联
    ->whereIn('push_ip', [
        '60.249.10.215:5080',
        '118.163.197.107:5080',
        '118.163.197.108:5080',
        '118.163.177.107:5080',
        '118.163.177.108:5080'
    ])
        ->has('machine') // 确保 machine 关联不为空
        ->get();
    /** @var MachineMedia $media */
    foreach ($machineMediaList as $media) {
        /** @var MachineTencentPlay $item */
        foreach ($machineTencentPlay as $item) {
            $pushData = getPushUrl($media->machine->code, $item->push_domain, $item->push_key);
            try {
                (new MediaServer($media->push_ip, $media->media_app))->rtmpEndpoint($pushData['rtmp_url'],
                    $pushData['endpoint_service_id'], $media->stream_name);
            } catch (\Exception $e) {
                Log::error('批量添加流地址:' . $e->getMessage());
            }
            $insertData[] = [
                'machine_id' => $media->machine_id,
                'media_id' => $media->id,
                'stream_name' => $media->stream_name,
                'endpoint_service_id' => $pushData['endpoint_service_id'],
                'expiration_date' => $pushData['expiration_date'],
                'machine_code' => $media->machine->code,
                'rtmp_url' => $pushData['rtmp_url'],
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
                'machine_tencent_play_id' => $item->id,
            ];
        }
    }
    MachineMediaPush::query()->insert($insertData);

    return true;
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
 * 清理媒体流
 * @return void
 */
function mediaClear(): void
{
    MachineMedia::query()
        ->whereHas('machine', function ($query) {
            $query->where('status', 1)->whereNull('deleted_at');
        })->chunk(100, function ($machineMediaList) {
            /** @var MachineMedia $machineMedia */
            foreach ($machineMediaList as $machineMedia) {
                $mediaServer = new MediaServer($machineMedia->push_ip, $machineMedia->media_app);
                try {
                    $endpointServiceId = [];
                    $streamInfo = $mediaServer->getBroadcasts($machineMedia->stream_name);
                    if (!empty($streamInfo['endPointList'])) {
                        foreach ($streamInfo['endPointList'] as $endPoint) {
                            if (!MachineMediaPush::query()->where('endpoint_service_id',
                                $endPoint['endpointServiceId'])->exists()) {
                                $mediaServer->deleteRtmpEndpoint($endPoint['endpointServiceId'],
                                    $machineMedia->stream_name);
                            }
                            $endpointServiceId[] = $endPoint['endpointServiceId'];
                        }
                    }
                    $mediaServer->log->error('MediaClear',
                        [$streamInfo, $endpointServiceId, $machineMedia->machine->code]);
                } catch (Exception $e) {
                    $mediaServer->log->error('MediaClear', [$e->getMessage(), $machineMedia->machine->code]);
                }
            }
        });
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
 * 获取结算日期条件
 * @param $type
 * @param $column
 * @return array
 */
function getWhereDate($type, $column): array
{
    $where = [];

    switch ($type) {
        case 1: // 今天
            $where[] = [
                function ($query) use ($column) {
                    $query->where($column, '>=', Carbon::today()->startOfDay())
                        ->where($column, '<=', Carbon::today()->endOfDay());
                }
            ];
            break;
        case 2: // 昨天
            $where[] = [
                function ($query) use ($column) {
                    $query->where($column, '>=', Carbon::yesterday()->startOfDay())
                        ->where($column, '<=', Carbon::yesterday()->endOfDay());
                }
            ];
            break;
        case 3: // 本周
            $where[] = [
                function ($query) use ($column) {
                    $query->where($column, '>=', Carbon::today()->startOfWeek()->format('Y-m-d'))
                        ->where($column, '<=', Carbon::today()->endOfWeek()->format('Y-m-d'));
                }
            ];
            break;
        case 4: // 上周
            $where[] = [
                function ($query) use ($column) {
                    $query->where($column, '>=', Carbon::today()->subWeek()->startOfWeek()->format('Y-m-d'))
                        ->where($column, '<=', Carbon::today()->subWeek()->endOfWeek()->format('Y-m-d'));
                }
            ];
            break;
        case 5: // 本月
            $where[] = [
                function ($query) use ($column) {
                    $query->where($column, '>=', Carbon::today()->firstOfMonth()->format('Y-m-d'))
                        ->where($column, '<=', Carbon::today()->endOfMonth()->format('Y-m-d'));
                }
            ];
            break;
        case 6: // 上月
            $where[] = [
                function ($query) use ($column) {
                    $query->where($column, '>=', Carbon::today()->subMonth()->firstOfMonth()->format('Y-m-d'))
                        ->where($column, '<=', Carbon::today()->subMonth()->endOfMonth()->format('Y-m-d'));
                }
            ];
            break;
        default:
            break;
    }

    return $where;
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


if(!function_exists('getWebIds')){
    /**
     * 批量生成各渠道webid
     * @return array
     */
    function getWebIds($typeArr)
    {
        //TODO 确认各平台webid规则之后再进行优化处理
        $webIds = [];

        foreach ($typeArr as $type){
            $webIds[$type] = GameServiceFactory::generateWebId();
        }

        return $webIds;
    }
}

