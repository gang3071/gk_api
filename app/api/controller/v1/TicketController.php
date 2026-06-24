<?php

declare(strict_types=1);

namespace app\api\controller\v1;

use app\model\Player;
use app\model\PlayerDeliveryRecord;
use app\model\TicketRecord;
use app\service\WalletService;
use support\Db;
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
        if (!$player) {
            return jsonFailResponse('未登录');
        }

        $score = (float) $request->post('score', 0);
        $machineNo = $request->post('machine_no', '');
        $storeName = $request->post('store_name', '');

        // 验证参数
        if ($score <= 0) {
            return jsonFailResponse('出票金额必须大于0');
        }

        try {
            Db::beginTransaction();

            // 验证玩家余额（在事务内检查，避免竞态条件）
            $balance = WalletService::getBalance($player->id);
            if ($balance < $score) {
                Db::rollBack();
                return jsonFailResponse('余额不足');
            }

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
                Db::rollBack();
                return jsonFailResponse('加密失败');
            }

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

            // 扣分（使用原子操作，检查返回值）
            $previousBalance = WalletService::getBalance($player->id);
            $decrementResult = WalletService::atomicDecrement($player->id, $score);

            // 检查扣分是否成功
            if (!isset($decrementResult['ok']) || $decrementResult['ok'] != 1) {
                Db::rollBack();
                return jsonFailResponse('余额不足，扣分失败');
            }

            $currentBalance = WalletService::getBalance($player->id);

            // 记录金流明细
            PlayerDeliveryRecord::create([
                'player_id' => $player->id,
                'target' => 'ticket',
                'target_id' => $ticket->id,
                'department_id' => $player->department_id ?? 0,
                'type' => PlayerDeliveryRecord::TYPE_TICKET_REDEEM,
                'source' => 'ticket_redeem',
                'amount' => -$score,
                'amount_before' => $previousBalance,
                'amount_after' => $currentBalance,
                'tradeno' => $orderId,
                'remark' => "出票核销: {$score}元",
            ]);

            // 更新玩家累计统计
            $this->updatePlayerExtend($player->id, 'withdraw', $score);

            Db::commit();

            return jsonSuccessResponse('出票成功', [
                'order_id' => $orderId,
                'encrypted_content' => $encryptedContent,
                'qr_code_no' => $qrCodeNo,
                'score' => $score,
                'balance' => $currentBalance,
                'status' => TicketRecord::STATUS_PENDING,
            ]);

        } catch (\Exception $e) {
            Db::rollBack();
            return jsonFailResponse('出票失败: ' . $e->getMessage());
        }
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
            // 解密内容
            $decrypted = $this->decrypt($encryptedContent);
            if ($decrypted === false || empty($decrypted)) {
                return jsonFailResponse('解密失败，无效的开分码');
            }

            $data = json_decode($decrypted, true);
            if (!$data || !isset($data['order_id']) || !isset($data['player_id']) || !isset($data['score'])) {
                return jsonFailResponse('无效的开分码');
            }

            // 验证 player_id 匹配
            if ((int)$data['player_id'] !== (int)$player->id) {
                return jsonFailResponse('此开分码不属于当前玩家');
            }

            // 通过 order_id 查找出票记录
            $ticket = TicketRecord::where('order_id', $data['order_id'])->first();
            if (!$ticket) {
                return jsonFailResponse('开分记录不存在');
            }

            // 验证状态
            if ((int)$ticket->status !== TicketRecord::STATUS_NORMAL) {
                return jsonFailResponse('此开分码已使用或已失效');
            }

            // 验证金额
            if (abs((float)$ticket->score - (float)$data['score']) > 0.01) {
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

            return jsonSuccessResponse('上分成功', [
                'order_id' => $ticket->order_id,
                'score' => $score,
                'balance' => $currentBalance,
            ]);

        } catch (BusinessException $e) {
            if (Db::transactionLevel() > 0) {
                Db::rollBack();
            }
            return jsonFailResponse($e->getMessage());
        } catch (\Exception $e) {
            if (Db::transactionLevel() > 0) {
                Db::rollBack();
            }
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
            throw new BusinessException('加密密钥未配置');
        }

        // 处理 base64: 前缀的密钥
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }

        $key = substr($key, 0, 32);
        $decoded = base64_decode($value, true);
        if ($decoded === false || strlen($decoded) < 16) {
            return false;
        }

        $iv = substr($decoded, 0, 16);
        $encrypted = substr($decoded, 16);

        return openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv);
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
