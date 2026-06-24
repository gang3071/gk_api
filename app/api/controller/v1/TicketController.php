<?php

declare(strict_types=1);

namespace app\api\controller\v1;

use app\model\Player;
use app\model\PlayerDeliveryRecord;
use app\model\TicketRecord;
use app\service\WalletService;
use Illuminate\Database\Capsule\Manager as DB;
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
        $player = $this->getAuthPlayer($request);

        $score = (float) $request->post('score', 0);
        $machineNo = $request->post('machine_no', '');
        $storeName = $request->post('store_name', '');

        // 验证参数
        if ($score <= 0) {
            return jsonFailResponse('出票金额必须大于0');
        }

        // 验证玩家余额
        $balance = WalletService::getBalance($player->id);
        if ($balance < $score) {
            return jsonFailResponse('余额不足');
        }

        try {
            DB::beginTransaction();

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

            // 创建出票记录
            $ticket = TicketRecord::create([
                'order_id' => $orderId,
                'department_id' => $player->department_id ?? 0,
                'store_admin_id' => $player->store_admin_id ?? 0,
                'store_name' => $storeName,
                'machine_no' => $machineNo,
                'machine_id' => 0,
                'player_id' => $player->id,
                'player_name' => $player->name,
                'score' => $score,
                'qr_code' => $encryptedContent,
                'qr_code_no' => $qrCodeNo,
                'encrypted_content' => $encryptedContent,
                'ticket_type' => TicketRecord::TYPE_WITHDRAW,
                'status' => TicketRecord::STATUS_PENDING,
                'scan_status' => TicketRecord::SCAN_STATUS_PENDING,
                'print_count' => 0,
            ]);

            // 扣分
            $previousBalance = WalletService::getBalance($player->id);
            WalletService::atomicDecrement($player->id, $score * 100);
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

            DB::commit();

            return jsonSuccessResponse([
                'order_id' => $orderId,
                'encrypted_content' => $encryptedContent,
                'qr_code_no' => $qrCodeNo,
                'score' => $score,
                'balance' => $currentBalance,
                'status' => TicketRecord::STATUS_PENDING,
            ], '出票成功');

        } catch (\Exception $e) {
            DB::rollBack();
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
        $player = $this->getAuthPlayer($request);

        $encryptedContent = $request->post('encrypted_content', '');
        if (empty($encryptedContent)) {
            return jsonFailResponse('加密内容不能为空');
        }

        try {
            // 解密内容
            $decrypted = $this->decrypt($encryptedContent);
            $data = json_decode($decrypted, true);

            if (!$data || !isset($data['ticket_id']) || !isset($data['player_id']) || !isset($data['score'])) {
                return jsonFailResponse('无效的开分码');
            }

            // 验证 player_id 匹配
            if ($data['player_id'] != $player->id) {
                return jsonFailResponse('此开分码不属于当前玩家');
            }

            // 查找出票记录
            $ticket = TicketRecord::find($data['ticket_id']);
            if (!$ticket) {
                return jsonFailResponse('开分记录不存在');
            }

            // 验证状态
            if ($ticket->status != TicketRecord::STATUS_NORMAL) {
                return jsonFailResponse('此开分码已使用或已失效');
            }

            // 验证金额
            if ($ticket->score != $data['score']) {
                return jsonFailResponse('开分金额不匹配');
            }

            DB::beginTransaction();

            // 上分
            $score = (float) $ticket->score;
            $previousBalance = WalletService::getBalance($player->id);
            WalletService::atomicIncrement($player->id, $score * 100);
            $currentBalance = WalletService::getBalance($player->id);

            // 更新出票记录状态
            $ticket->update([
                'status' => TicketRecord::STATUS_USED,
                'scan_status' => TicketRecord::SCAN_STATUS_SCANNED,
                'scanned_at' => now(),
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

            DB::commit();

            return jsonSuccessResponse([
                'ticket_id' => $ticket->id,
                'order_id' => $ticket->order_id,
                'score' => $score,
                'balance' => $currentBalance,
                'message' => '上分成功',
            ], '上分成功');

        } catch (BusinessException $e) {
            DB::rollBack();
            return jsonFailResponse($e->getMessage());
        } catch (\Exception $e) {
            DB::rollBack();
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
        $player = $this->getAuthPlayer($request);

        $status = $request->post('status');
        $page = (int) $request->post('page', 1);
        $pageSize = (int) $request->post('page_size', 20);

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
                    'order_id' => $record->order_id,
                    'score' => $record->score,
                    'ticket_type' => $record->ticket_type,
                    'ticket_type_name' => $record->ticket_type_name,
                    'status' => $record->status,
                    'status_name' => $record->status_name,
                    'scan_status' => $record->scan_status,
                    'scanned_at' => $record->scanned_at?->toDateTimeString(),
                    'created_at' => $record->created_at->toDateTimeString(),
                ];
            });

        return jsonSuccessResponse([
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'records' => $records,
        ]);
    }

    /**
     * 获取当前登录玩家
     *
     * @param Request $request
     * @return Player
     */
    protected function getAuthPlayer(Request $request): Player
    {
        $playerId = $request->get('player_id', 0);
        if (!$playerId) {
            throw new BusinessException('未登录');
        }

        $player = Player::find($playerId);
        if (!$player) {
            throw new BusinessException('玩家不存在');
        }

        return $player;
    }

    /**
     * AES-256-CBC 加密
     *
     * @param string $value
     * @return string
     */
    protected function encrypt(string $value): string
    {
        $key = config('app.key', '');
        if (empty($key)) {
            throw new BusinessException('加密密钥未配置');
        }

        $key = substr($key, 0, 32);
        $iv = substr(md5($key), 0, 16);

        $encrypted = openssl_encrypt($value, 'AES-256-CBC', $key, 0, $iv);
        return base64_encode($iv . $encrypted);
    }

    /**
     * AES-256-CBC 解密
     *
     * @param string $value
     * @return string
     */
    protected function decrypt(string $value): string
    {
        $key = config('app.key', '');
        if (empty($key)) {
            throw new BusinessException('加密密钥未配置');
        }

        $key = substr($key, 0, 32);
        $decoded = base64_decode($value);
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
