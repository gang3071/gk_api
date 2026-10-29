<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int id 主鍵
 * @property string code 榜單代碼：golden_weekly/golden_monthly/store_weekly
 * @property string name 榜單名稱（結算時快照）
 * @property int period_type 週期：1=週榜 2=月榜
 * @property int scope_type 排名範圍：1=全站 3=店家(店內各自)
 * @property string period_key 期別標識：週=起始週一日期(2026-08-31)、月=2026-08
 * @property string start_at 期開始（週一08:00 / 每月1號08:00）
 * @property string end_at 期結束（下週一08:00 / 下月1號08:00）
 * @property string settle_at 結算完成時間（NULL=未結算）
 * @property float threshold 門檻分數（0=不限，結算時快照）
 * @property string prize_config 獎勵金額設定（結算時快照）
 * @property int entry_count 本期名次筆數
 * @property string created_at 建立時間
 * @property string updated_at 更新時間
 *
 * @package app\model
 */
class Leaderboard extends Model
{
    use HasDateTimeFormatter;

    const PERIOD_TYPE_WEEK = 1;  // 週榜
    const PERIOD_TYPE_MONTH = 2;  // 月榜

    const SCOPE_TYPE_ALL = 1;  // 全站
    const SCOPE_TYPE_STORE = 3;  // 門店

    protected $table = 'leaderboard';

    protected $guarded = [];
}
