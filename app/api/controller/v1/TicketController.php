<?php

declare(strict_types=1);

namespace app\api\controller\v1;

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

        // 获取出票金额
        $score = (float) $request->post('score', 0);
        $machineNo = $request->post('machine_no', '');
        $storeName = $request->post('store_name', '');

        // 验证参数
        if ($score <= 0) {
            return jsonFailResponse('出票金额必须大于0');
        }

        // 🔥 原子性扣分操作
        try {
            $decrementResult = WalletService::atomicDecrement($player->id, $score);
        } catch (\Throwable $e) {
            Log::error('TicketController: Decrement operation failed', [
                'player_id' => $player->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return jsonFailResponse(trans('system_error', [], 'message'));
        }

        if ($decrementResult['ok'] == 0) {
            // 扣分失败
            if ($decrementResult['error'] == 'insufficient') {
                return jsonFailResponse(trans('your_point_insufficient', [], 'message'));
            } else {
                return jsonFailResponse(trans('your_point_insufficient', [], 'message'));
            }
        }

        // 扣分成功，获取实际金额
        $beforeGameAmount = $decrementResult['old_balance']; // 扣款前余额
        $afterGameAmount = $decrementResult['balance'];      // 扣款后余额

        // 计算货币金额
        $money = bcdiv($score, $currency->ratio, 2);

        // 生成订单号
        $orderId = TicketRecord::generateOrderId();
        $qrCodeNo = TicketRecord::generateQrCodeNo();

        // 生成加密串
        $encryptData = json_encode([
            'order_id' => $orderId,
            'player_id' => $player->id,
            'score' => $score,
            'timestamp' => time(),
        ]);
        $encryptedContent = $this->encrypt($encryptData);
        if ($encryptedContent === false) {
            // 加密失败，回退已扣除的余额
            WalletService::atomicIncrement($player->id, $score);
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
                'store_name' => $storeName,
                'machine_no' => $machineNo,
                'machine_id' => 0,
                'player_id' => $player->id,
                'player_name' => $player->name ?? '',
                'score' => $score,
                'qr_code' => $encryptedContent,
                'qr_code_no' => $qrCodeNo,
                'encrypted_content' => $encryptedContent,
                'ticket_type' => TicketRecord::TYPE_WITHDRAW,
                'status' => TicketRecord::STATUS_PENDING,
                'scan_status' => TicketRecord::SCAN_STATUS_PENDING,
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
            $playerWithdrawRecord->point = $score;
            $playerWithdrawRecord->fee = 0;
            $playerWithdrawRecord->inmoney = bcsub($playerWithdrawRecord->money, $playerWithdrawRecord->fee, 2);
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

            // 余额已在事务外通过 atomicDecrement 原子性扣除

            // 更新玩家提现统计
            $player->player_extend->withdraw_amount = bcadd($player->player_extend->withdraw_amount,
                $score, 2);
            $player->push();

            // 写入金流明细
            $playerDeliveryRecord = new PlayerDeliveryRecord;
            $playerDeliveryRecord->player_id = $player->id;
            $playerDeliveryRecord->department_id = $player->department_id ?? 0;
            $playerDeliveryRecord->target = $ticket->getTable();
            $playerDeliveryRecord->target_id = $ticket->id;
            $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_TICKET_REDEEM;
            $playerDeliveryRecord->withdraw_status = PlayerWithdrawRecord::STATUS_SUCCESS;
            $playerDeliveryRecord->source = 'ticket_redeem';
            $playerDeliveryRecord->amount = $score;
            $playerDeliveryRecord->amount_before = $beforeGameAmount;
            $playerDeliveryRecord->amount_after = $afterGameAmount;
            $playerDeliveryRecord->tradeno = $orderId;
            $playerDeliveryRecord->remark = '出票核销';
            $playerDeliveryRecord->save();

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();

            // 事务失败，回退已扣除的余额
            WalletService::atomicIncrement($player->id, $score);

            Log::error('redeemTicket failed, balance refunded', [
                'player_id' => $player->id,
                'score' => $score,
                'error' => $e->getMessage(),
                'trace' => $e->getTrace()
            ]);

            return jsonFailResponse($e->getMessage() ?? trans('system_error', [], 'message'));
        }

        // ✅ 事务提交后更新爆机状态
        WalletService::checkMachineCrashAfterTransaction($player->id, $afterGameAmount, $beforeGameAmount);

        return jsonSuccessResponse('出票成功', [
            'order_id' => $orderId,
            'encrypted_content' => $encryptedContent,
            'qr_code_no' => $qrCodeNo,
            'score' => $score,
            'balance' => $afterGameAmount,
            'status' => TicketRecord::STATUS_PENDING,
        ]);
    }

    /**
     * 扫码开分 - 玩家端扫码上分
     *
     * @param Request $request
     * @return Response
     */
    public function scanOpenScore(Request $request): Response
    {
        $player = checkPlayer();
        if (!$player) {
            return jsonFailResponse('未登录');
        }

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

            // 解密内容
            $decrypted = $this->decrypt($encryptedContent);

            // 🔍 解密结果详细日志
            if (empty($decrypted)) {
                Log::warning('scanOpenScore: 解密失败', [
                    'player_id' => $player->id,
                    'encrypted_length' => strlen($encryptedContent),
                    'encrypted_preview' => substr($encryptedContent, 0, 50) . '...',
                    'decrypted_result' => $decrypted,
                    'openssl_error' => openssl_error_string(),
                    'key_config' => substr(config('app.key', ''), 0, 10) . '...',
                ]);
                return jsonFailResponse('解密失败，无效的开分码');
            }

            // 🔍 解密成功，检查内容格式
            Log::info('scanOpenScore: 解密成功', [
                'player_id' => $player->id,
                'decrypted_length' => strlen($decrypted),
                'decrypted_preview' => substr($decrypted, 0, 100) . '...',
            ]);

            $data = json_decode($decrypted, true);

            // 🔍 JSON 解析结果日志
            if (!$data || !isset($data['order_id']) || !isset($data['player_id']) || !isset($data['score'])) {
                Log::warning('scanOpenScore: JSON解析失败或字段缺失', [
                    'player_id' => $player->id,
                    'json_error' => json_last_error_msg(),
                    'decrypted_content' => $decrypted,
                    'parsed_data' => $data,
                    'has_order_id' => isset($data['order_id']),
                    'has_player_id' => isset($data['player_id']),
                    'has_score' => isset($data['score']),
                ]);
                return jsonFailResponse('无效的开分码');
            }

            // 验证 player_id 匹配
            if ((int)$data['player_id'] !== (int)$player->id) {
                Log::warning('scanOpenScore: player_id 不匹配', [
                    'current_player_id' => $player->id,
                    'data_player_id' => $data['player_id'],
                    'order_id' => $data['order_id'],
                ]);
                return jsonFailResponse('此开分码不属于当前玩家');
            }

            // 通过 order_id 查找出票记录
            $ticket = TicketRecord::where('order_id', $data['order_id'])->first();
            if (!$ticket) {
                Log::warning('scanOpenScore: 出票记录不存在', [
                    'player_id' => $player->id,
                    'order_id' => $data['order_id'],
                ]);
                return jsonFailResponse('开分记录不存在');
            }

            // 验证状态
            if ((int)$ticket->status !== TicketRecord::STATUS_NORMAL) {
                Log::warning('scanOpenScore: 出票状态异常', [
                    'player_id' => $player->id,
                    'order_id' => $data['order_id'],
                    'ticket_status' => $ticket->status,
                    'expected_status' => TicketRecord::STATUS_NORMAL,
                    'status_name' => $ticket->status_name ?? 'unknown',
                ]);
                return jsonFailResponse('此开分码已使用或已失效');
            }

            // 验证金额
            if (abs((float)$ticket->score - (float)$data['score']) > 0.01) {
                Log::warning('scanOpenScore: 金额不匹配', [
                    'player_id' => $player->id,
                    'order_id' => $data['order_id'],
                    'ticket_score' => $ticket->score,
                    'data_score' => $data['score'],
                ]);
                return jsonFailResponse('开分金额不匹配');
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
                'scan_status' => TicketRecord::SCAN_STATUS_SCANNED,
                'scanned_at' => date('Y-m-d H:i:s'),
                'scanned_by' => 'player_' . $player->id,
            ]);

            // 记录金流明细
            PlayerDeliveryRecord::create([
                'player_id' => $player->id,
                'target' => 'ticket',
                'target_id' => $ticket->id,
                'department_id' => $player->department_id ?? 0,
                'type' => PlayerDeliveryRecord::TYPE_TICKET_OPEN_SCORE,
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
     */
    public function ticketRecords(Request $request): Response
    {
        $player = checkPlayer();
        if (!$player) {
            return jsonFailResponse('未登录');
        }

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
                    'scan_status' => $record->scan_status ?? 0,
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
                'is_valid_base64' => base64_encode($decoded) === $value,
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
}
