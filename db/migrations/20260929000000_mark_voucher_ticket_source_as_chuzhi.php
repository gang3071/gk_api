<?php

use Phinx\Migration\AbstractMigration;

/**
 * 体验券/福利券出票来源标注为储值机
 *
 * 领取体验券/福利券的入口仅在储值机（/chuzhi/ticket/print-voucher），
 * 新记录在出票时写入 source_type=chuzhi；此迁移把存量未标注来源的
 * 体验券/福利券记录补上，便于后台出票记录的「来源」列展示为储值机。
 *
 * 已有 split/merge/purchase 等来源的记录保持不变。
 */
class MarkVoucherTicketSourceAsChuzhi extends AbstractMigration
{
    /**
     * Up Method.
     */
    public function up(): void
    {
        // 3=体验券(TYPE_EXPERIENCE) 4=福利券(TYPE_WELFARE)
        $this->execute("
            UPDATE qr_ticket_record
            SET source_type = 'chuzhi'
            WHERE ticket_type IN (3, 4)
              AND (source_type IS NULL OR source_type = '')
        ");
    }

    /**
     * Down Method.
     */
    public function down(): void
    {
        $this->execute("
            UPDATE qr_ticket_record
            SET source_type = NULL
            WHERE ticket_type IN (3, 4)
              AND source_type = 'chuzhi'
        ");
    }
}
