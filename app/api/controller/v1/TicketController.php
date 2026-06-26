<?php

declare(strict_types=1);

namespace app\api\controller\v1;

use app\exception\PlayerCheckException;
use app\model\AdminUser;
use app\model\Channel;
use app\model\Currency;
use app\model\Player;
use app\model\PlayerDeliveryRecord;
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

        // 基础验证
        if ($player->is_coin == 1) {
            return jsonFailResponse(trans('coin_cannot_present', [], 'message'));
        }

        // 爆机检查：玩家不能出票
        $crashCheck = checkMachineCrash($player);
        if ($crashCheck['crashed']) {
            return jsonFailResponse(trans('machine_crashed_cannot_wash_score', [], 'message'));
        }

        // 渠道和货币验证（先验证，避免扣款后回退）
        /** @var Channel $channel */
        $channel = Channel::query()->where('department_id', \request()->department_id)->first();
        if ($player->status_withdraw != 1) {
            return jsonFailResponse(trans('player_withdraw_closed', [], 'message'));
        }
        if ($channel->withdraw_status == 0) {
            return jsonFailResponse(trans('self_withdraw_closed', [], 'message'));
        }
        /** @var Currency $currency */
        $currency = Currency::query()->where('identifying', $channel->currency)->where('status',
            1)->whereNull('deleted_at')->first();
        if (empty($currency)) {
            return jsonFailResponse(trans('currency_no_setting', [], 'message'));
        }

        // 🔥 获取后台配置的洗分数值
        // 从店家后台账号获取 wash_point_config 配置
        // 如果未配置或配置为0，则使用默认值100
        $washPointConfig = self::getWashPointConfig($player->store_admin_id);

        // 🔥 原子性洗分操作（完全避免 TOCTOU 问题）
        // 在 Redis 中原子性完成：读取余额 → 根据配置计算洗分金额 → 扣款
        try {
            $washResult = WalletService::atomicWash($player->id, $washPointConfig);
        } catch (\Throwable $e) {
            // Lua 脚本执行失败或其他异常
            Log::error('TicketController: Wash operation failed', [
                'player_id' => $player->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return jsonFailResponse(trans('system_error', [], 'message'));
        }

        if ($washResult['ok'] == 0) {
            // 洗分失败
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

        // 生成加密串（只加密 order_id 以缩短二维码长度）
        $encryptedContent = $this->encrypt($orderId);
        if ($encryptedContent === false) {
            // 加密失败，回退已扣除的余额
            WalletService::atomicIncrement($player->id, $washAmount);
            return jsonFailResponse('加密失败');
        }

        // 开始事务处理
        DB::beginTransaction();
        try {
            // 创建出票记录
            $ticket = TicketRecord::create([
                'order_id' => $orderId,
                'department_id' => $player->department_id ?? 0,
                'store_admin_id' => $player->store_admin_id ?? 0,
                'store_name' => '',
                'machine_no' => 0,
                'machine_id' => 0,
                'player_id' => $player->id,
                'player_name' => $player->name ?? '',
                'score' => $washAmount,
                'qr_code' => $encryptedContent,
                'qr_code_no' => $qrCodeNo,
                'encrypted_content' => $encryptedContent,
                'ticket_type' => TicketRecord::TYPE_WITHDRAW,
                'status' => TicketRecord::STATUS_PRINTED,
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
        } catch (Exception $e) {
            // 确保事务回滚
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            // 事务失败，回退已扣除的余额
            WalletService::atomicIncrement($player->id, $washAmount);

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

            Log::error('redeemTicket failed (Throwable), balance refunded', [
                'player_id' => $player->id,
                'wash_amount' => $washAmount,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return jsonFailResponse(trans('system_error', [], 'message'));
        }

        // ✅ 事务提交后更新爆机状态
        WalletService::checkMachineCrashAfterTransaction($player->id, $afterGameAmount, $beforeGameAmount);

        return jsonSuccessResponse('出票成功', [
            'encrypted_content' => $encryptedContent
        ]);
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

        $encryptedContent = $request->post('encrypted_content', '');
        if (empty($encryptedContent)) {
            return jsonFailResponse('加密内容不能为空');
        }

        try {
            // 🔍 解密前记录日志（截断显示，保护安全）
            Log::info('scanOpenScore: 开始解密', [
                'player_id' => $player->id,
                'encrypted_length' => strlen($encryptedContent),
                'encrypted_preview' => substr($encryptedContent, 0, 20) . '...',
            ]);

            // 解密内容（新格式：直接是 order_id）
            $orderId = $this->decrypt($encryptedContent);

            // 🔍 解密结果详细日志
            if (empty($orderId)) {
                Log::warning('scanOpenScore: 解密失败', [
                    'player_id' => $player->id,
                    'encrypted_length' => strlen($encryptedContent),
                    'encrypted_preview' => substr($encryptedContent, 0, 50) . '...',
                    'openssl_error' => openssl_error_string(),
                    'key_config' => substr(config('app.key', ''), 0, 10) . '...',
                ]);
                return jsonFailResponse('解密失败，无效的开分码');
            }

            // 🔍 解密成功
            Log::info('scanOpenScore: 解密成功', [
                'player_id' => $player->id,
                'order_id' => $orderId,
            ]);

            // 🔒 获取分布式锁，防止同一二维码被并发处理
            $lockKey = 'ticket:scan_lock:' . $orderId;
            $lockTtl = 10; // 锁超时时间（秒）
            $lock = \support\Redis::set($lockKey, 1, 'EX', $lockTtl, 'NX');

            if (!$lock) {
                Log::warning('scanOpenScore: 获取锁失败，正在处理中', [
                    'player_id' => $player->id,
                    'order_id' => $orderId,
                    'lock_key' => $lockKey,
                ]);
                return jsonFailResponse('该二维码正在处理中，请稍后再试');
            }

            try {
                // 通过 order_id 查询出票记录
                $ticket = TicketRecord::where('order_id', $orderId)->first();

                if (!$ticket) {
                    Log::warning('scanOpenScore: 出票记录不存在', [
                        'player_id' => $player->id,
                        'order_id' => $orderId,
                    ]);
                    return jsonFailResponse('开分记录不存在');
                }

                // 验证 player_id（安全检查）
                if ((int)$ticket->player_id !== (int)$player->id) {
                    Log::warning('scanOpenScore: player_id 不匹配', [
                        'current_player_id' => $player->id,
                        'ticket_player_id' => $ticket->player_id,
                        'order_id' => $orderId,
                    ]);
                    return jsonFailResponse('此开分码不属于当前玩家');
                }

                // 验证状态
                if ((int)$ticket->status !== TicketRecord::STATUS_NORMAL) {
                    Log::warning('scanOpenScore: 出票状态异常', [
                        'player_id' => $player->id,
                        'order_id' => $orderId,
                        'ticket_status' => $ticket->status,
                        'expected_status' => TicketRecord::STATUS_NORMAL,
                        'status_name' => $ticket->status_name ?? 'unknown',
                    ]);
                    return jsonFailResponse('此开分码已使用或已失效');
                }

                Db::beginTransaction();

                // 上分
                $score = (float) $ticket->score;
                $previousBalance = WalletService::getBalance($player->id);
                $incrementResult = WalletService::atomicIncrement($player->id, $score);

                // 检查上分是否成功
                if (!isset($incrementResult['ok']) || $incrementResult['ok'] != 1) {
                    Db::rollBack();
                    return jsonFailResponse('上分失败');
                }

                $currentBalance = WalletService::getBalance($player->id);

                // 更新出票记录状态
                $ticket->update([
                    'status' => TicketRecord::STATUS_USED,
                    'scanned_at' => date('Y-m-d H:i:s'),
                    'scanned_by' => 'player_' . $player->id,
                ]);

                // 记录金流明细
                PlayerDeliveryRecord::create([
                    'player_id' => $player->id,
                    'target' => 'ticket',
                    'target_id' => $ticket->id,
                    'department_id' => $player->department_id ?? 0,
                    'type' => PlayerDeliveryRecord::TYPE_MACHINE_UP,
                    'source' => 'ticket_open_score',
                    'amount' => $score,
                    'amount_before' => $previousBalance,
                    'amount_after' => $currentBalance,
                    'tradeno' => $ticket->order_id,
                    'remark' => "扫码开分: {$score}元",
                ]);

                // 更新玩家累计统计
                $this->updatePlayerExtend($player->id, 'recharge', $score);

                Db::commit();

                // 🔍 成功日志
                Log::info('scanOpenScore: 扫码开分成功', [
                    'player_id' => $player->id,
                    'order_id' => $ticket->order_id,
                    'score' => $score,
                    'previous_balance' => $previousBalance,
                    'current_balance' => $currentBalance,
                ]);

                return jsonSuccessResponse('上分成功', [
                    'order_id' => $ticket->order_id,
                    'score' => $score,
                    'balance' => $currentBalance,
                ]);

            } finally {
                // 🔓 释放锁
                \support\Redis::del($lockKey);
                Log::info('scanOpenScore: 锁已释放', [
                    'order_id' => $orderId,
                    'lock_key' => $lockKey,
                ]);
            }

        } catch (BusinessException $e) {
            if (Db::transactionLevel() > 0) {
                Db::rollBack();
            }
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
            Log::error('scanOpenScore: 系统异常', [
                'player_id' => $player->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return jsonFailResponse('上分失败: ' . $e->getMessage());
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
     * AES-256-CBC 加密
     *
     * @param string $value
     * @return string|false
     */
    protected function encrypt(string $value): string|false
    {
        $key = config('app.key', '');
        if (empty($key)) {
            throw new BusinessException('加密密钥未配置');
        }

        // 处理 base64: 前缀的密钥
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }

        $key = substr($key, 0, 32);
        // 使用 md5(key) 前16字节作为 IV（与gk_admin一致）
        $iv = substr(md5($key), 0, 16);

        $encrypted = openssl_encrypt($value, 'AES-256-CBC', $key, 0, $iv);
        if ($encrypted === false) {
            return false;
        }

        return base64_encode($iv . $encrypted);
    }

    /**
     * AES-256-CBC 解密
     *
     * @param string $value
     * @return string|false
     */
    protected function decrypt(string $value): string|false
    {
        $key = config('app.key', '');
        if (empty($key)) {
            Log::error('decrypt: 加密密钥未配置');
            throw new BusinessException('加密密钥未配置');
        }

        // 处理 base64: 前缀的密钥
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }

        $key = substr($key, 0, 32);
        $decoded = base64_decode($value, true);

        // 🔍 Base64 解码检查
        if ($decoded === false) {
            Log::warning('decrypt: Base64解码失败', [
                'input_length' => strlen($value),
                'input_preview' => substr($value, 0, 30) . '...',
            ]);
            return false;
        }

        if (strlen($decoded) < 16) {
            Log::warning('decrypt: 解码后数据长度不足', [
                'decoded_length' => strlen($decoded),
                'min_required' => 16,
                'input_length' => strlen($value),
            ]);
            return false;
        }

        $iv = substr($decoded, 0, 16);
        $encrypted = substr($decoded, 16);

        $result = openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv);

        // 🔍 OpenSSL 解密结果检查
        if ($result === false) {
            Log::warning('decrypt: OpenSSL解密失败', [
                'openssl_error' => openssl_error_string(),
                'decoded_length' => strlen($decoded),
                'iv_length' => strlen($iv),
                'encrypted_length' => strlen($encrypted),
                'key_length' => strlen($key),
            ]);
        }

        return $result;
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
     * 获取洗分配置
     *
     * @param int $storeAdminId 店家后台账号ID
     * @return float 洗分基数
     */
    private static function getWashPointConfig(int $storeAdminId): float
    {
        $config = AdminUser::query()
            ->where('id', $storeAdminId)
            ->value('wash_point_config');

        // 配置为 null、0 或 0.00 时使用默认值100
        if (empty($config) || $config <= 0) {
            Log::debug('TicketController: Using default wash point config', [
                'store_admin_id' => $storeAdminId,
                'original_config' => $config,
                'default_value' => 100.00,
            ]);
            return 100.00;
        }

        return floatval($config);
    }
}
