<?php

namespace app\event;

use app\model\PlayerDeliveryRecord;
use app\model\PlayerGameRecord;
use app\model\PlayerLotteryRecord;
use app\model\PlayerPromoter;
use app\model\PlayerRechargeRecord;
use app\model\PlayerWithdrawRecord;
use app\model\PromoterProfitRecord;
use support\Db;
use support\Log;
use think\Exception;

/**
 * 分润事件增加
 */
class Promotion
{
    /**
     * 产生分润逻辑处理
     * @param $eventObj
     * @param $eventName
     * @return void
     */
    public function generateProfitSharing($eventObj, $eventName)
    {
        try {
            $openAmount = 0;
            $washAmount = 0;
            $rechargeAmount = 0;
            $withdrawAmount = 0;
            $adminAddAmount = 0;
            $adminDeductAmount = 0;
            $presentAmount = 0;
            $bonusAmount = 0;
            $lotteryAmount = 0;

            switch ($eventName) {
                case 'promotion.playerGame':
                    /** @var PlayerGameRecord $eventObj */
                    $openAmount = $eventObj->open_amount ?? 0;
                    $washAmount = $eventObj->wash_amount ?? 0;
                    break;
                case 'promotion.playerRecharge':
                    /** @var PlayerRechargeRecord $eventObj */
                    $rechargeAmount = $eventObj->point ?? 0;
                    break;
                case 'promotion.playerWithdraw':
                    /** @var PlayerWithdrawRecord $eventObj */
                    $withdrawAmount = $eventObj->point ?? 0;
                    break;
                case 'promotion.adminAdd':
                    /** @var PlayerDeliveryRecord $eventObj */
                    $adminAddAmount = $eventObj->amount ?? 0;
                    break;
                case 'promotion.adminDeduct':
                    /** @var PlayerDeliveryRecord $eventObj */
                    $adminDeductAmount = $eventObj->amount ?? 0;
                    break;
                case 'promotion.registerPresent':
                    /** @var PlayerDeliveryRecord $eventObj */
                    $presentAmount = $eventObj->amount ?? 0;
                    break;
                case 'promotion.activityBonus':
                    /** @var PlayerDeliveryRecord $eventObj */
                    $bonusAmount = $eventObj->amount ?? 0;
                    break;
                case 'promotion.lottery':
                    /** @var PlayerLotteryRecord $eventObj */
                    $lotteryAmount = $eventObj->amount ?? 0;
                    break;
                default:
                    return;
            }

            if (isset($eventObj->player->recommend_player) && $eventObj->player->recommend_player->is_promoter == 1) {
                $this->calculation($eventObj->player->recommend_id, $eventObj->player_id, $eventObj->player->department_id, [
                    'machine_up_amount' => $openAmount,
                    'machine_down_amount' => $washAmount,
                    'recharge_amount' => $rechargeAmount,
                    'withdraw_amount' => $withdrawAmount,
                    'admin_add_amount' => $adminAddAmount,
                    'admin_deduct_amount' => $adminDeductAmount,
                    'present_amount' => $presentAmount,
                    'bonus_amount' => $bonusAmount,
                    'lottery_Amount' => $lotteryAmount,
                ]);
            }
        } catch (Exception $e) {
            Log::error('分润计算错误', [$e->getMessage()]);
        }
    }


    /**
     * 计算分润
     * @param $promoterId
     * @param $playerId
     * @param $departmentId
     * @param $data
     * @return void
     * @throws Exception
     */
    public function calculation($promoterId, $playerId, $departmentId, $data)
    {
        /** @var PlayerPromoter $playerPromoter */
        $playerPromoter = PlayerPromoter::where('player_id', $promoterId)->first();
        if (empty($playerPromoter)) {
            throw new Exception('未找到推广员信息');
        }
        if ($playerPromoter->path) {
            $parentIdList = explode(',', $playerPromoter->path);
            $playerPromoterList = PlayerPromoter::whereIn('player_id', $parentIdList)->orderBy('id', 'desc')->get();
            $teamTotalProfitAmount = 0; // 现有团队分润
            $orgTeamTotalProfitAmount = 0; // 原有团队分润
            $subRatio = 0; // 子级分润
            $promoterProfitRecords = []; // 保存所有的PromoterProfitRecord
            /** @var PlayerPromoter $item */
            foreach ($playerPromoterList as $item) {
                // 计算分润比例
                $actualRatio = bcsub($item->ratio, $subRatio, 2); // 实际分润
                $ratio = bcdiv($actualRatio, 100, 2); // 分润比例
                $subRatio = $item->ratio; // 更新子级分润

                /** @var PromoterProfitRecord $promoterProfitRecord */
                $promoterProfitRecord = PromoterProfitRecord::where('promoter_player_id', $item->player_id)
                    ->where('status', 0)
                    ->where('player_id', $playerId)
                    ->where('actual_ratio', $actualRatio)
                    ->first();
                if (empty($promoterProfitRecord)) {
                    $promoterProfitRecord = new PromoterProfitRecord();
                    $promoterProfitRecord->player_id = $playerId;
                    $promoterProfitRecord->department_id = $departmentId;
                    $promoterProfitRecord->promoter_player_id = $item->player_id;
                    $promoterProfitRecord->source_player_id = $promoterId;
                    $promoterProfitRecord->withdraw_amount = $data['withdraw_amount'];
                    $promoterProfitRecord->recharge_amount = $data['recharge_amount'];
                    $promoterProfitRecord->bonus_amount = $data['bonus_amount'];
                    $promoterProfitRecord->admin_deduct_amount = $data['admin_deduct_amount'];
                    $promoterProfitRecord->admin_add_amount = $data['admin_add_amount'];
                    $promoterProfitRecord->present_amount = $data['present_amount'];
                    $promoterProfitRecord->machine_up_amount = $data['machine_up_amount'];
                    $promoterProfitRecord->machine_down_amount = $data['machine_down_amount'];
                    $promoterProfitRecord->lottery_amount = $data['machine_down_amount'];
                    $promoterProfitRecord->ratio = $item->ratio;
                    $promoterProfitRecord->model = PromoterProfitRecord::MODEL_EVENT;
                } else {
                    $promoterProfitRecord->withdraw_amount = bcadd($promoterProfitRecord->withdraw_amount, $data['withdraw_amount'], 2);
                    $promoterProfitRecord->recharge_amount = bcadd($promoterProfitRecord->recharge_amount, $data['recharge_amount'], 2);
                    $promoterProfitRecord->bonus_amount = bcadd($promoterProfitRecord->bonus_amount, $data['bonus_amount'], 2);
                    $promoterProfitRecord->admin_deduct_amount = bcadd($promoterProfitRecord->admin_deduct_amount, $data['admin_deduct_amount'], 2);
                    $promoterProfitRecord->admin_add_amount = bcadd($promoterProfitRecord->admin_add_amount, $data['admin_add_amount'], 2);
                    $promoterProfitRecord->present_amount = bcadd($promoterProfitRecord->present_amount, $data['present_amount'], 2);
                    $promoterProfitRecord->machine_up_amount = bcadd($promoterProfitRecord->machine_up_amount, $data['machine_up_amount'], 2);
                    $promoterProfitRecord->machine_down_amount = bcadd($promoterProfitRecord->machine_down_amount, $data['machine_down_amount'], 2);
                    $promoterProfitRecord->lottery_amount = bcadd($promoterProfitRecord->lottery_amount, $data['lottery_amount'], 2);
                    $item->total_profit_amount = bcsub($item->total_profit_amount, $promoterProfitRecord->profit_amount, 2);
                    $item->profit_amount = bcsub($item->profit_amount, $promoterProfitRecord->profit_amount, 2);
                }
                // 变更前分润
                $orgProfitAmount = $promoterProfitRecord->profit_amount;
                $orgPlayerProfitAmount = $promoterProfitRecord->player_profit_amount;
                $promoterProfitRecord->actual_ratio = $actualRatio;
                // (机台上分 + 管理员扣点) - (活动奖励 + 赠送 + 管理员充值 + 机台下分 + 彩金金额)
                $allProfit = bcsub(bcadd($promoterProfitRecord->machine_up_amount, $promoterProfitRecord->admin_deduct_amount, 2), bcadd(bcadd(bcadd($promoterProfitRecord->bonus_amount, $promoterProfitRecord->present_amount, 2), bcadd($promoterProfitRecord->admin_add_amount, $promoterProfitRecord->machine_down_amount, 2), 2), $promoterProfitRecord->lottery_amount, 2), 2);
                $promoterProfitRecord->profit_amount = bcmul($allProfit, $ratio, 2);
                // 统计未结算玩家分润
                if ($promoterProfitRecord->promoter_player_id == $promoterId) {
                    $promoterProfitRecord->player_profit_amount = bcadd(bcsub($promoterProfitRecord->player_profit_amount, $orgPlayerProfitAmount, 2), $promoterProfitRecord->profit_amount, 2);
                }
                $promoterProfitRecords[] = $promoterProfitRecord;
                // 原始团队分润
                $orgTeamTotalProfitAmount = bcadd($orgTeamTotalProfitAmount, $orgProfitAmount, 2);
                $teamTotalProfitAmount = bcadd($teamTotalProfitAmount, $promoterProfitRecord->profit_amount, 2);
                // 更新推广员信息
                $item->team_withdraw_total_amount = bcadd($item->team_withdraw_total_amount, $data['withdraw_amount'], 2);
                $item->team_recharge_total_amount = bcadd($item->team_recharge_total_amount, $data['recharge_amount'], 2);
                $item->total_profit_amount = bcadd($item->total_profit_amount, $promoterProfitRecord->profit_amount, 2);
                $item->player_profit_amount = bcadd($item->player_profit_amount, $promoterProfitRecord->player_profit_amount, 2);
                $item->profit_amount = bcadd($item->profit_amount, $promoterProfitRecord->profit_amount, 2);
                $item->team_total_profit_amount = bcadd(bcsub($item->team_total_profit_amount, $orgTeamTotalProfitAmount, 2), $teamTotalProfitAmount, 2);
                $item->team_profit_amount = bcadd(bcsub($item->team_profit_amount, $orgTeamTotalProfitAmount, 2), $teamTotalProfitAmount, 2);
            }

            DB::beginTransaction();
            try {
                foreach ($promoterProfitRecords as $promoterProfitRecord) {
                    $promoterProfitRecord->save();
                }
                foreach ($playerPromoterList as $item) {
                    $item->save();
                }
                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('生成分润记录错误', [$e->getMessage()]);
            }
        }
    }
}