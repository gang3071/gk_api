<?php

namespace app\service;

use app\model\Channel;
use app\model\Player;
use app\model\PlayerRegisterRecord;
use ExAdmin\ui\response\Notification;
use ExAdmin\ui\support\Excel;
use PhpOffice\PhpSpreadsheet\Exception;
use support\Db;
use support\Log;

class ImportService
{
    
    /**
     * @param $departmentId
     * @return Notification
     * @throws Exception
     */
    public function importPlayer($departmentId): Notification
    {
        $repeatNum = 0;
        $recommendErrorNum = 0;
        $successNum = 0;
        $failNum = 0;
        $totalNum = 0;
        
        Excel::import(request()->file('file')->getRealPath(), [
            0 => 'name',
            1 => 'country_code',
            2 => 'phone',
            3 => 'uuid',
        ], function ($row) use ($departmentId, &$repeatNum, &$recommendErrorNum, &$successNum, &$totalNum, &$failNum) {
            $totalNum++;
            if (empty($row['phone']) || empty($row['country_code'])) {
                return;
            }
            DB::beginTransaction();
            try {
                $player = Player::query()
                    ->where('phone', $row['phone'])
                    ->where('department_id', $departmentId)
                    ->first();
                if ($player) {
                    $repeatNum++;
                    $failNum++;
                    throw new \Exception('玩家已存在');
                }
                if (!empty($row['uuid'])) {
                    $recommendPlayer = Player::query()
                        ->where('uuid', $row['uuid'])
                        ->where('is_promoter', 1)
                        ->first();
                    if (!$recommendPlayer) {
                        $recommendErrorNum++;
                        $failNum++;
                        throw new \Exception('推荐玩家玩家不存在');
                    }
                }
                $player = new Player();
                $player->phone = $row['phone'];
                $player->uuid = generate15DigitUniqueId();
                $player->country_code = $row['country_code'];
                $player->type = Player::TYPE_PLAYER;
                $player->currency = Channel::where('department_id', $departmentId)->value('currency');
                $player->password = $this->generateRandomPassword();
                $player->department_id = $departmentId;
                $player->recommend_id = $recommendPlayer->id ?? 0;
                $player->recommended_code = $row['recommended_code'] ?? 0;
                $player->avatar = config('def_avatar.1');
                $player->save();
                
                if (!empty($recommendPlayer->player_promoter)) {
                    $recommendPlayer->player_promoter->increment('player_num');
                }
                
                addPlayerExtend($player);
                addRegisterRecord($player->id, PlayerRegisterRecord::TYPE_CLIENT, $player->department_id);
                DB::commit();
                $successNum++;
            } catch (\Exception $e) {
                DB::rollBack();
                $failNum++;
                Log::error('Import error: ' . $e->getMessage());
            }
        });
        
        return notification_success(admin_trans('admin.success'),
            admin_trans('player.import_msg', [], [
                '{total_num}' => $totalNum,
                '{success_num}' => $successNum,
                '{fail_num}' => $failNum,
                '{repeat_num}' => $repeatNum,
                '{recommend_error_num}' => $recommendErrorNum,
            ]), ['duration' => 5]);
    }
    
    /**
     * 生成密码
     * @param int $length
     * @return string
     */
    public function generateRandomPassword(int $length = 6): string
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomPassword = '';
        
        for ($i = 0; $i < $length; $i++) {
            $randomPassword .= $characters[rand(0, $charactersLength - 1)];
        }
        
        return $randomPassword;
    }
}