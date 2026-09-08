<?php

declare(strict_types=1);

namespace app\model;

use Illuminate\Database\Eloquent\Model;

/**
 * 储值机购分配置模型
 *
 * @property int $id
 * @property int $store_admin_id 店铺ID
 * @property int $score_1 购分选项1
 * @property int $score_2 购分选项2
 * @property int $score_3 购分选项3
 * @property int $score_4 购分选项4
 * @property int $score_5 购分选项5
 * @property int $score_6 购分选项6
 * @property int $default_scores 默认购分数
 * @property string $created_at 创建时间
 * @property string $updated_at 更新时间
 */
class PurchaseScoreSetting extends Model
{
    protected $table = 'purchase_score_setting';

    protected $guarded = [];

    /**
     * 获取所有有效的购分选项
     *
     * @return array
     */
    public function getScoreOptions(): array
    {
        $options = [];
        for ($i = 1; $i <= 6; $i++) {
            $score = $this->{"score_$i"};
            if ($score > 0) {
                $options[] = (int)$score;
            }
        }
        return $options;
    }

    /**
     * 验证分值是否为有效选项
     *
     * @param int $score
     * @return bool
     */
    public function isValidScore(int $score): bool
    {
        return in_array($score, $this->getScoreOptions());
    }

    /**
     * 根据店铺ID获取配置
     *
     * @param int $storeAdminId
     * @return static|null
     */
    public static function getByStoreId(int $storeAdminId): ?static
    {
        return static::query()
            ->where('store_admin_id', $storeAdminId)
            ->first();
    }
}
