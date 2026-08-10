<?php

declare(strict_types=1);

namespace app\api\controller\v1;

use app\exception\PlayerCheckException;
use app\model\AdminUser;
use app\model\Channel;
use app\model\Currency;
use app\model\Player;
use app\model\PlayerDeliveryRecord;
use app\model\PlayerRechargeRecord;
use app\model\PlayerWithdrawRecord;
use app\model\TicketRecord;
use app\service\WalletService;
use Exception;
use support\Db;
use support\Log;
use support\Request;
use support\Response;
use support\exception\BusinessException;
use Throwable;

/**
 * 出票系统 API 控制器
 */
class TicketController
{
    use \support\IdempotentTrait;

    /**
     * 核销出票 - 玩家端主动出票
     *
     * @param Request $request
     * @return Response
     * @throws Throwable
     */
    public function redeemTicket(Request $request): Response
    {
        $player = checkPlayer();

        // ==================== 阶段1：占位前检查（轻量级） ====================
        // 基础验证 - Player属性检查
        if ($player->is_coin == 1) {
            return jsonFailResponse(trans('coin_cannot_present', [], 'message'));
        }

        if ($player->status_withdraw != 1) {
            return jsonFailResponse(trans('player_withdraw_closed', [], 'message'));
        }

        // 🆕 获取客户端传入的出票金额
        $requestedAmount = $request->post('amount');
        if ($requestedAmount !== null && $requestedAmount !== '') {
            $requestedAmount = floatval($requestedAmount);

            // 金额必须大于0
            if ($requestedAmount <= 0) {
                return jsonFailResponse(trans('ticket_amount_must_positive', [], 'message'));
            }
        }

        // ==================== 阶段2：幂等性处理 ====================
        // 幂等性检查
        $requestId = $request->post('request_id');
        $idempotentResponse = $this->checkIdempotent($requestId, 'ticket-redeem', $player->id);
        if ($idempotentResponse !== null) {
            return $idempotentResponse;
        }

        // 提前占位（防止并发）
        if (!$this->reserveIdempotent($requestId, 'ticket-redeem', $player->id)) {
            // 占位失败，再次检查状态（可能已完成或正在处理）
            $response = $this->checkIdempotent($requestId, 'ticket-redeem', $player->id);
            return $response ?? jsonFailResponse(trans('request_processing', [], 'message'));
        }

        // ==================== 阶段3：占位后检查（需要查库，失败需释放占位） ====================
        // 爆机检查：玩家不能出票
        $crashCheck = checkMachineCrash($player);
        if ($crashCheck['crashed']) {
            $this->releaseIdempotent($requestId);
            return jsonFailResponse(trans('machine_crashed_cannot_wash_score', [], 'message'));
        }

        // 🔒 钱包锁定状态下，余额需达到配置分数才能出票
        if (\app\service\WalletService::isWalletLocked($player->id)) {
            $issueThreshold = (int) config('welfare_ticket.issue_threshold', 5000);
            $currentBalance = \app\service\WalletService::getBalance($player->id);
            if ($currentBalance < $issueThreshold) {
                $this->releaseIdempotent($requestId);
                Log::warning('redeemTicket: 钱包锁定，余额不足无法出票', [
                    'player_id' => $player->id,
                    'balance' => $currentBalance,
                    'threshold' => $issueThreshold,
                ]);
                return jsonFailResponse(trans('ticket_locked_insufficient_balance', [], 'message'));
            }
        }

        // 渠道和货币验证
        /** @var Channel $channel */
        $channel = Channel::query()->where('department_id', \request()->department_id)->first();
        if ($channel->withdraw_status == 0) {
            $this->releaseIdempotent($requestId);
            return jsonFailResponse(trans('self_withdraw_closed', [], 'message'));
        }

        /** @var Currency $currency */
        $currency = Currency::query()->where('identifying', $channel->currency)->where('status',
            1)->whereNull('deleted_at')->first();
        if (empty($currency)) {
            $this->releaseIdempotent($requestId);
            return jsonFailResponse(trans('currency_no_setting', [], 'message'));
        }

        // 🔥 获取后台配置的洗分数值
        $washPointConfig = self::getWashPointConfig($player->store_admin_id);

        // 🆕 如果客户端指定了金额，验证金额是否符合洗分基数
        if ($requestedAmount !== null) {
            // 🎯 特殊处理：配置为 0 时，只允许洗整数金额
            if ($washPointConfig == 0) {
                // 检查金额是否为整数（整元）
                if (fmod($requestedAmount, 1) > 0.01) {
                    $this->releaseIdempotent($requestId);
                    return jsonFailResponse(trans('ticket_amount_must_be_integer', [], 'message'));
                }

                // 检查余额是否足够
                $currentBalance = WalletService::getBalance($player->id);
                if ($currentBalance < $requestedAmount) {
                    $this->releaseIdempotent($requestId);
                    return jsonFailResponse(trans('ticket_balance_insufficient', [
                        'current' => number_format($currentBalance, 2),
                        'required' => number_format($requestedAmount, 2)
                    ], 'message'));
                }
            } else {
                // 正常配置：验证倍数关系

                // 检查金额是否小于洗分基数
                if ($requestedAmount < $washPointConfig) {
                    $this->releaseIdempotent($requestId);
                    return jsonFailResponse(trans('ticket_amount_less_than_base', [
                        'amount' => number_format($requestedAmount, 2),
                        'base' => number_format($washPointConfig, 2)
                    ], 'message'));
                }

                // 检查金额是否为洗分基数的整数倍
                $remainder = fmod($requestedAmount, $washPointConfig);
                if (abs($remainder) > 0.01) { // 允许0.01的浮点误差
                    $this->releaseIdempotent($requestId);
                    return jsonFailResponse(trans('ticket_amount_not_multiple', [
                        'amount' => number_format($requestedAmount, 2),
                        'base' => number_format($washPointConfig, 2)
                    ], 'message'));
                }

                // 检查余额是否足够
                $currentBalance = WalletService::getBalance($player->id);
                if ($currentBalance < $requestedAmount) {
                    $this->releaseIdempotent($requestId);
                    return jsonFailResponse(trans('ticket_balance_insufficient', [
                        'current' => number_format($currentBalance, 2),
                        'required' => number_format($requestedAmount, 2)
                    ], 'message'));
                }
            }
        }

        // 在 Redis 中原子性完成：读取余额 → 根据配置计算洗分金额 → 扣款
        try {
            if ($requestedAmount !== null) {
                // 使用指定金额扣款
                $washResult = WalletService::atomicWashWithAmount($player->id, $requestedAmount, $washPointConfig);
            } else {
                // 使用自动计算（全部洗分）
                $washResult = WalletService::atomicWash($player->id, $washPointConfig);
            }
        } catch (\Throwable $e) {
            // Lua 脚本执行失败 - 释放占位
            $this->releaseIdempotent($requestId);
            Log::error('TicketController: Wash operation failed', [
                'player_id' => $player->id,
                'requested_amount' => $requestedAmount ?? 'auto',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return jsonFailResponse(trans('system_error', [], 'message'));
        }

        if ($washResult['ok'] == 0) {
            // 洗分失败 - 释放占位（允许用户充值后重试）
            $this->releaseIdempotent($requestId);
            if ($washResult['error'] == 'insufficient_wash_amount') {
                // 余额不足：当前余额小于配置的洗分基数
                return jsonFailResponse(trans('insufficient_balance_wash', [
                    'min_amount' => number_format($washPointConfig, 2)
                ], 'message'));
            } else {
                return jsonFailResponse(trans('your_point_insufficient', [], 'message'));
            }
        }

        // 洗分成功，获取实际洗分金额
        $washAmount = $washResult['wash_amount'];      // 实际洗分金额
        $beforeGameAmount = $washResult['old_balance']; // 扣款前余额
        $afterGameAmount = $washResult['balance'];      // 扣款后余额

        // 计算货币金额
        $money = bcdiv((string)$washAmount, (string)$currency->ratio, 2);

        // 生成订单号
        $orderId = TicketRecord::generateOrderId();
        $qrCodeNo = TicketRecord::generateQrCodeNo();

        // 开始事务处理
        DB::beginTransaction();
        try {
            // 创建出票记录
            // 获取店家名称
            $storeName = '';
            if ($player->storeAdmin) {
                $storeName = $player->storeAdmin->nickname ?? $player->storeAdmin->username ?? '';
            }

            $ticket = TicketRecord::create([
                'order_id' => $orderId,
                'department_id' => $player->department_id ?? 0,
                'store_admin_id' => $player->store_admin_id ?? 0,
                'store_name' => $storeName,
                'machine_no' => 0,
                'machine_id' => 0,
                'player_id' => $player->id,
                'player_name' => $player->name ?? '',
                'score' => $washAmount,
                'qr_code' => $orderId,
                'qr_code_no' => $qrCodeNo,
                'encrypted_content' => $orderId,
                'ticket_type' => TicketRecord::TYPE_WITHDRAW,
                'status' => TicketRecord::STATUS_NORMAL,
                'print_count' => 0,
            ]);

            // 生成提现订单
            $playerWithdrawRecord = new PlayerWithdrawRecord();
            $playerWithdrawRecord->player_id = $player->id;
            $playerWithdrawRecord->talk_user_id = $player->talk_user_id;
            $playerWithdrawRecord->department_id = $player->department_id;
            $playerWithdrawRecord->tradeno = $orderId;
            $playerWithdrawRecord->player_name = $player->name ?? '';
            $playerWithdrawRecord->player_phone = $player->phone ?? '';
            $playerWithdrawRecord->rate = $currency->ratio;
            $playerWithdrawRecord->actual_rate = $currency->ratio;
            $playerWithdrawRecord->money = $money;
            $playerWithdrawRecord->point = $washAmount;
            $playerWithdrawRecord->fee = 0;
            $playerWithdrawRecord->inmoney = bcsub((string)$playerWithdrawRecord->money, (string)$playerWithdrawRecord->fee, 2);
            $playerWithdrawRecord->currency = $channel->currency;
            $playerWithdrawRecord->bank_name = '';
            $playerWithdrawRecord->account = '';
            $playerWithdrawRecord->account_name = '';
            $playerWithdrawRecord->wallet_address = '';
            $playerWithdrawRecord->qr_code = '';
            $playerWithdrawRecord->type = PlayerWithdrawRecord::TYPE_SELF;
            $playerWithdrawRecord->status = PlayerWithdrawRecord::STATUS_SUCCESS;
            $playerWithdrawRecord->bank_type = 4;
            $playerWithdrawRecord->remark = '出票核销';
            $playerWithdrawRecord->save();

            // 余额已在事务外通过 atomicWash 原子性扣除

            // 更新玩家提现统计
            $player->player_extend->withdraw_amount = bcadd((string)$player->player_extend->withdraw_amount,
                (string)$washAmount, 2);
            $player->push();

            // 写入金流明细
            $playerDeliveryRecord = new PlayerDeliveryRecord;
            $playerDeliveryRecord->player_id = $player->id;
            $playerDeliveryRecord->department_id = $player->department_id ?? 0;
            $playerDeliveryRecord->target = $ticket->getTable();
            $playerDeliveryRecord->target_id = $ticket->id;
            $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_WITHDRAWAL;
            $playerDeliveryRecord->withdraw_status = PlayerWithdrawRecord::STATUS_SUCCESS;
            $playerDeliveryRecord->source = 'ticket_redeem';
            $playerDeliveryRecord->amount = $washAmount;
            $playerDeliveryRecord->amount_before = $beforeGameAmount;
            $playerDeliveryRecord->amount_after = $afterGameAmount;
            $playerDeliveryRecord->tradeno = $orderId;
            $playerDeliveryRecord->remark = '出票核销';
            $playerDeliveryRecord->save();

            DB::commit();

            // ✅ 事务提交后更新爆机状态
            WalletService::checkMachineCrashAfterTransaction($player->id, $afterGameAmount, $beforeGameAmount);

            // 保存幂等性记录（覆盖占位）
            $response = jsonSuccessResponse(trans('ticket_redeem_success', [], 'message'), [
                'order_id' => $orderId,
                'encrypted_content' => $orderId,
                'score' => $washAmount,
                'store_name' => $storeName,
            ]);
            $this->saveIdempotent($requestId, $response, 'ticket-redeem', $player->id);

            return $response;
        } catch (Exception $e) {
            // 确保事务回滚
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            // 事务失败，回退已扣除的余额
            WalletService::atomicIncrement($player->id, $washAmount);

            // 释放占位（允许重试）
            $this->releaseIdempotent($requestId);

            Log::error('redeemTicket failed, balance refunded', [
                'player_id' => $player->id,
                'wash_amount' => $washAmount,
                'error' => $e->getMessage(),
                'trace' => $e->getTrace()
            ]);

            return jsonFailResponse($e->getMessage() ?? trans('system_error', [], 'message'));
        } catch (\Throwable $e) {
            // 捕获所有异常，确保事务回滚
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            // 事务失败，回退已扣除的余额
            WalletService::atomicIncrement($player->id, $washAmount);

            // 释放占位（允许重试）
            $this->releaseIdempotent($requestId);

            Log::error('redeemTicket failed (Throwable), balance refunded', [
                'player_id' => $player->id,
                'wash_amount' => $washAmount,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return jsonFailResponse(trans('system_error', [], 'message'));
        }
    }

    /**
     * 扫码开分 - 玩家端扫码上分
     *
     * @param Request $request
     * @return Response
     * @throws Throwable
     * @throws PlayerCheckException
     */
    public function scanOpenScore(Request $request): Response
    {
        $player = checkPlayer();

        $orderId = $request->post('order_id', '');
        if (empty($orderId)) {
            return jsonFailResponse(trans('ticket_order_id_empty', [], 'message'));
        }

        // 幂等性检查（如果客户端传递了 request_id）
        $requestId = $request->post('request_id');
        $idempotentResponse = $this->checkIdempotent($requestId, 'ticket-scan-open:' . $orderId, $player->id);
        if ($idempotentResponse !== null) {
            return $idempotentResponse;
        }

        // 提前占位（防止并发）
        if (!$this->reserveIdempotent($requestId, 'ticket-scan-open:' . $orderId, $player->id)) {
            // 占位失败，再次检查状态（可能已完成或正在处理）
            $response = $this->checkIdempotent($requestId, 'ticket-scan-open:' . $orderId, $player->id);
            return $response ?? jsonFailResponse(trans('request_processing', [], 'message'));
        }

        try {
            Log::info('scanOpenScore: 开始处理', [
                'player_id' => $player->id,
                'order_id' => $orderId,
            ]);

            // ==================== 阶段3：占位后检查（需要查库，失败需释放） ====================
            // 爆机检查：玩家不能开分
            $crashCheck = checkMachineCrash($player);
            if ($crashCheck['crashed']) {
                $this->releaseIdempotent($requestId);
                return jsonFailResponse(trans('machine_crashed_cannot_open_score', [], 'message'));
            }

            // 🔒 获取分布式锁，防止同一二维码被并发处理
            $lockKey = 'ticket:scan_lock:' . $orderId;
            $lockTtl = 10; // 锁超时时间（秒）
            $lock = \support\Redis::set($lockKey, 1, 'EX', $lockTtl, 'NX');

            if (!$lock) {
                $this->releaseIdempotent($requestId);
                Log::warning('scanOpenScore: 获取锁失败，正在处理中', [
                    'player_id' => $player->id,
                    'order_id' => $orderId,
                    'lock_key' => $lockKey,
                ]);
                return jsonFailResponse(trans('ticket_processing', [], 'message'));
            }

            try {
                // 通过 order_id 查询出票记录
                $ticket = TicketRecord::where('order_id', $orderId)->first();

                if (!$ticket) {
                    $this->releaseIdempotent($requestId);
                    Log::warning('scanOpenScore: 出票记录不存在', [
                        'player_id' => $player->id,
                        'order_id' => $orderId,
                    ]);
                    return jsonFailResponse(trans('ticket_not_found', [], 'message'));
                }

                // 验证状态（已打印或待核销状态才能扫码）
                if ((int)$ticket->status !== TicketRecord::STATUS_NORMAL) {
                    $this->releaseIdempotent($requestId);
                    Log::warning('scanOpenScore: 出票状态异常', [
                        'player_id' => $player->id,
                        'order_id' => $orderId,
                        'ticket_status' => $ticket->status,
                        'expected_status' => 'STATUS_NORMAL',
                        'status_name' => $ticket->status_name ?? 'unknown',
                    ]);
                    return jsonFailResponse(trans('ticket_already_used', [], 'message'));
                }

                // 🔒 检查钱包是否被锁定（福利卷/体验卷锁定后不能开分）
                if (\app\service\WalletService::isWalletLocked($player->id)) {
                    $this->releaseIdempotent($requestId);
                    Log::warning('scanOpenScore: 钱包已锁定，无法开分', [
                        'player_id' => $player->id,
                        'order_id' => $orderId,
                    ]);
                    return jsonFailResponse(trans('wallet_locked', [], 'message'));
                }

                // 验证玩家绑定关系
                // player_id = 0: 未绑定，任何人都能扫码
                // player_id > 0: 已绑定，只有绑定玩家能扫码
                if ((int)$ticket->player_id > 0 && (int)$ticket->player_id !== (int)$player->id) {
                    $this->releaseIdempotent($requestId);
                    Log::warning('scanOpenScore: 玩家绑定验证失败', [
                        'current_player_id' => $player->id,
                        'ticket_player_id' => $ticket->player_id,
                        'order_id' => $orderId,
                    ]);
                    return jsonFailResponse(trans('ticket_bound_other_player', [], 'message'));
                }

                // 福利卷/体验卷特殊验证
                if ($ticket->isWelfareOrExperience()) {
                    // 检查活动是否在有效期内
                    $activityEndTime = (string) config('welfare_ticket.activity_end_time', '');
                    if (!empty($activityEndTime) && time() > strtotime($activityEndTime)) {
                        $this->releaseIdempotent($requestId);
                        Log::warning('scanOpenScore: 福利卷/体验卷活动已结束', [
                            'player_id' => $player->id,
                            'order_id' => $orderId,
                            'activity_end_time' => $activityEndTime,
                        ]);
                        return jsonFailResponse(trans('welfare_activity_expired', [], 'message'));
                    }

                    // 检查是否已过期
                    if ($ticket->isExpired()) {
                        $this->releaseIdempotent($requestId);
                        Log::warning('scanOpenScore: 福利卷/体验卷已过期', [
                            'player_id' => $player->id,
                            'order_id' => $orderId,
                            'ticket_type' => $ticket->ticket_type,
                            'created_at' => $ticket->created_at,
                        ]);
                        return jsonFailResponse(trans('ticket_expired', [], 'message'));
                    }

                    // 检查钱包余额：余额必须低于配置值才能使用福利卷/体验卷
                    $openScoreLimit = (float) config('welfare_ticket.open_score_limit', 100);
                    $currentWalletBalance = WalletService::getBalance($player->id);
                    if ($currentWalletBalance >= $openScoreLimit) {
                        $this->releaseIdempotent($requestId);
                        Log::warning('scanOpenScore: 钱包余额超过限制，无法使用福利卷/体验卷', [
                            'player_id' => $player->id,
                            'order_id' => $orderId,
                            'ticket_type' => $ticket->ticket_type,
                            'wallet_balance' => $currentWalletBalance,
                            'open_score_limit' => $openScoreLimit,
                        ]);
                        return jsonFailResponse(trans('ticket_wallet_balance_too_high', [], 'message'));
                    }
                }

                Db::beginTransaction();

                // 上分
                $score = (float) $ticket->score;
                $previousBalance = WalletService::getBalance($player->id);

                // 获取渠道和货币信息
                $channel = Channel::query()->where('department_id', $player->department_id)->first();
                $currency = $channel ? Currency::query()->where('identifying', $channel->currency)->where('status', 1)->whereNull('deleted_at')->first() : null;
                $currencyIdentifying = $currency ? $currency->identifying : '';
                $money = $currency ? bcdiv((string)$score, (string)$currency->ratio, 2) : $score;

                // 生成充值订单
                $playerRechargeRecord = new PlayerRechargeRecord();
                $playerRechargeRecord->player_id = $player->id;
                $playerRechargeRecord->talk_user_id = $player->talk_user_id;
                $playerRechargeRecord->department_id = $player->department_id;
                $playerRechargeRecord->tradeno = $ticket->order_id;
                $playerRechargeRecord->player_name = $player->name ?? '';
                $playerRechargeRecord->player_phone = $player->phone ?? '';
                $playerRechargeRecord->money = $money;
                $playerRechargeRecord->inmoney = $money;
                $playerRechargeRecord->currency = $currencyIdentifying;
                $playerRechargeRecord->type = PlayerRechargeRecord::TYPE_ARTIFICIAL;
                $playerRechargeRecord->point = $score;
                $playerRechargeRecord->status = PlayerRechargeRecord::STATUS_RECHARGED_SUCCESS;
                $playerRechargeRecord->remark = "扫码开分: {$score}元";
                $playerRechargeRecord->finish_time = date('Y-m-d H:i:s');
                $playerRechargeRecord->user_id = 0;
                $playerRechargeRecord->user_name = '';
                $playerRechargeRecord->save();

                // ✅ Lua 原子性加款（自动同步数据库）
                $incrementResult = WalletService::atomicIncrement($player->id, $score);

                // 检查上分是否成功
                if (!isset($incrementResult['ok']) || $incrementResult['ok'] != 1) {
                    Db::rollBack();
                    return jsonFailResponse(trans('ticket_open_score_failed', [], 'message'));
                }

                $currentBalance = $incrementResult['balance'];

                // 更新出票记录状态（机台使用）
                $ticket->update([
                    'status' => TicketRecord::STATUS_MACHINE_USED,
                    'scanned_at' => date('Y-m-d H:i:s'),
                    'scanned_by' => 'player_' . $player->id,
                ]);

                // 更新玩家充值统计
                $player->player_extend->recharge_amount = bcadd((string)$player->player_extend->recharge_amount,
                    (string)$score, 2);
                $player->push();

                // 写入金流明细
                $playerDeliveryRecord = new PlayerDeliveryRecord;
                $playerDeliveryRecord->player_id = $player->id;
                $playerDeliveryRecord->department_id = $player->department_id ?? 0;
                $playerDeliveryRecord->target = $playerRechargeRecord->getTable();
                $playerDeliveryRecord->target_id = $playerRechargeRecord->id;
                $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_RECHARGE;
                $playerDeliveryRecord->source = 'ticket_open_score';
                $playerDeliveryRecord->amount = $score;
                $playerDeliveryRecord->amount_before = $incrementResult['old'] ?? $previousBalance;
                $playerDeliveryRecord->amount_after = $currentBalance;
                $playerDeliveryRecord->tradeno = $ticket->order_id;
                $playerDeliveryRecord->remark = "扫码开分: {$score}元";
                $playerDeliveryRecord->save();

                Db::commit();

                // 🔍 成功日志
                Log::info('scanOpenScore: 扫码开分成功', [
                    'player_id' => $player->id,
                    'order_id' => $ticket->order_id,
                    'score' => $score,
                    'previous_balance' => $previousBalance,
                    'current_balance' => $currentBalance,
                ]);

                // ✅ 事务提交后更新爆机状态
                \app\service\WalletService::checkMachineCrashAfterTransaction($player->id, $playerDeliveryRecord->amount_after, $playerDeliveryRecord->amount_before);

                // 🔒 福利卷/体验卷使用后锁定钱包
                if ($ticket->isWelfareOrExperience()) {
                    \app\service\WalletService::lockWallet($player->id, 'ticket_type_' . $ticket->ticket_type);
                }

                // 保存幂等性记录（覆盖占位）
                $response = jsonSuccessResponse(trans('ticket_open_score_success', [], 'message'), [
                    'order_id' => $ticket->order_id,
                    'score' => $score,
                    'balance' => $currentBalance,
                ]);
                $this->saveIdempotent($requestId, $response, 'ticket-scan-open:' . $orderId, $player->id);

                return $response;

            } finally {
                // 🔓 释放锁
                \support\Redis::del($lockKey);

                // ✅ 事务提交后更新爆机状态
                Log::info('scanOpenScore: 锁已释放', [
                    'order_id' => $orderId,
                    'lock_key' => $lockKey,
                ]);
            }


        } catch (BusinessException $e) {
            if (Db::transactionLevel() > 0) {
                Db::rollBack();
            }
            // 释放占位（允许重试）
            $this->releaseIdempotent($requestId);
            Log::error('scanOpenScore: 业务异常', [
                'player_id' => $player->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return jsonFailResponse($e->getMessage());
        } catch (\Exception $e) {
            if (Db::transactionLevel() > 0) {
                Db::rollBack();
            }
            // 释放占位（允许重试）
            $this->releaseIdempotent($requestId);
            Log::error('scanOpenScore: 系统异常', [
                'player_id' => $player->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return jsonFailResponse(trans('ticket_open_score_failed', [], 'message') . ': ' . $e->getMessage());
        }
    }

    /**
     * 查询出票记录
     *
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException
     */
    public function ticketRecords(Request $request): Response
    {
        $player = checkPlayer();

        $status = $request->post('status');
        $page = max(1, (int) $request->post('page', 1));
        $pageSize = max(1, min(100, (int) $request->post('page_size', 20)));

        $query = TicketRecord::where('player_id', $player->id)
            ->orderBy('created_at', 'desc');

        // 状态筛选
        if ($status !== null && $status !== '') {
            $query->where('status', (int) $status);
        }

        $total = $query->count();
        $records = $query->skip(($page - 1) * $pageSize)
            ->take($pageSize)
            ->get()
            ->map(function ($record) {
                return [
                    'id' => $record->id,
                    'order_id' => $record->order_id ?? '',
                    'score' => $record->score ?? 0,
                    'ticket_type' => $record->ticket_type ?? 0,
                    'ticket_type_name' => $record->ticket_type_name ?? '',
                    'status' => $record->status ?? 0,
                    'status_name' => $record->status_name ?? '',
                    'scanned_at' => $record->scanned_at ? $record->scanned_at->toDateTimeString() : null,
                    'created_at' => $record->created_at ? $record->created_at->toDateTimeString() : '',
                ];
            });

        return jsonSuccessResponse('success', [
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'records' => $records,
        ]);
    }

    /**
     * 更新玩家累计统计
     *
     * @param int $playerId
     * @param string $type recharge|withdraw
     * @param float $amount
     * @return void
     */
    protected function updatePlayerExtend(int $playerId, string $type, float $amount): void
    {
        $playerExtend = \app\model\PlayerExtend::where('player_id', $playerId)->first();
        if ($playerExtend) {
            $field = $type === 'recharge' ? 'recharge_amount' : 'withdraw_amount';
            $playerExtend->increment($field, $amount);
        }
    }

    /**
     * 获取店家的洗分配置
     *
     * @param int $storeAdminId 店家管理员ID
     * @return float 洗分配置值，如果未配置或配置为0则返回默认值100
     */
    private static function getWashPointConfig(int $storeAdminId): float
    {
        // 从洗分配置表获取
        $washSetting = \app\model\WashPointSetting::query()
            ->where('admin_user_id', $storeAdminId)
            ->first();

        if ($washSetting) {
            $effectiveWashPoint = $washSetting->getEffectiveWashPoint();
            Log::debug('TicketController: Using wash point config from setting table', [
                'store_admin_id' => $storeAdminId,
                'wash_point' => $effectiveWashPoint,
            ]);
            return $effectiveWashPoint;
        }

        // 未配置时使用默认值100
        Log::debug('TicketController: Using default wash point config', [
            'store_admin_id' => $storeAdminId,
            'default_value' => 100.00,
        ]);
        return 100.00;
    }
}
