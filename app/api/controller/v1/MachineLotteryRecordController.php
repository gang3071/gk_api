<?php

namespace app\api\controller\v1;

use app\model\GameType;
use app\model\MachineLotteryRecord;
use support\Request;
use support\Response;

/**
 * 得珠记录（机台大赏中奖记录，玩家端，只读）
 *
 * 口径与后台「机台大赏报表」（gk_admin MachineLotteryRecordController）一致：
 * machine_lottery_record 明细（不含 rush 开奖），只回传登录玩家本人的记录。
 */
class MachineLotteryRecordController
{
    /**
     * 得珠记录（登录玩家的机台大赏中奖记录）
     *
     * 分页参数：page（默认 1）、size（默认 10，最大 100）
     *
     * @param Request $request
     * @return Response
     */
    public function records(Request $request): Response
    {
        $player = checkPlayer();
        $data = $request->all();
        $page = max(1, (int)($data['page'] ?? 1));
        $size = max(1, min(100, (int)($data['size'] ?? 10)));

        $records = MachineLotteryRecord::query()
            ->with(['machine.machineLabel'])
            ->where('player_id', $player->id)
            ->where('has_rush', 0)
            ->orderBy('id', 'desc')
            ->forPage($page, $size)
            ->get();

        $typeNames = [
            GameType::TYPE_STEEL_BALL => trans('machine_type_steel_ball', [], 'message'),  // 钢珠机
            GameType::TYPE_SLOT       => trans('machine_type_slot', [], 'message'),        // 斯洛机
            GameType::TYPE_FISH       => trans('machine_type_fish', [], 'message'),        // 捕鱼机
        ];

        $list = [];
        foreach ($records as $record) {
            $machine = $record->machine;
            $machineType = (int)($machine->type ?? 0);

            $list[] = [
                'id'                => (int)$record->id,
                'machine_code'      => (string)($machine->code ?? ''),
                'machine_name'      => (string)($machine->name ?? ''),
                'machine_type'      => $machineType,
                'machine_type_text' => (string)($typeNames[$machineType] ?? ''),
                'use_turn'          => (int)$record->use_turn,
                'draw_bet'          => (int)$record->draw_bet,
                'lottery_win'       => (int)$record->lottery_win,
                'lottery_bet'       => (int)$record->lottery_bet,
                'odds_x'            => (int)$record->odds_x,
                'odds_y'            => (int)$record->odds_y,
                'created_at'        => $record->created_at ? date('Y-m-d H:i:s', strtotime((string)$record->created_at)) : null,
            ];
        }

        return jsonSuccessResponse('success', [
            'list' => $list,
        ]);
    }
}
