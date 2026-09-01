<?php

use Phinx\Migration\AbstractMigration;

class AddSplitMergeFieldsToTicketRecord extends AbstractMigration
{
    /**
     * 为 qr_ticket_record 表添加拆分/合并功能字段
     *
     * 新增字段：
     * - source_ticket_id: 来源原票ID（新票指向原票）
     * - source_type: 来源类型 split/merge（新票记录）
     * - related_ticket_ids: 关联的新票ID数组（原票记录）
     * - operation_type: 操作类型 0=无操作, 1=拆分, 2=合并
     * - operated_at: 操作时间
     * - operated_by: 操作人ID
     *
     * 新增状态：
     * - STATUS_SPLIT = 6 (已拆分)
     * - STATUS_MERGED = 7 (已合并)
     */
    public function change(): void
    {
        $table = $this->table('qr_ticket_record');

        // 新票字段：指向来源原票
        $table->addColumn('source_ticket_id', 'integer', [
            'limit' => 11,
            'signed' => false,
            'null' => true,
            'default' => null,
            'comment' => '来源原票ID（拆分/合并时新票指向原票）',
            'after' => 'extra_data',
        ])
        ->addColumn('source_type', 'string', [
            'limit' => 20,
            'null' => true,
            'default' => null,
            'comment' => '来源类型: split=拆分, merge=合并',
            'after' => 'source_ticket_id',
        ])
        // 原票字段：记录关联的新票ID数组
        ->addColumn('related_ticket_ids', 'json', [
            'null' => true,
            'default' => null,
            'comment' => '关联的新票ID数组（拆分/合并产生的新票）',
            'after' => 'source_type',
        ])
        // 通用字段：操作记录
        ->addColumn('operation_type', 'integer', [
            'limit' => 1,
            'signed' => false,
            'null' => false,
            'default' => 0,
            'comment' => '操作类型: 0=无操作, 1=拆分, 2=合并',
            'after' => 'related_ticket_ids',
        ])
        ->addColumn('operated_at', 'datetime', [
            'null' => true,
            'default' => null,
            'comment' => '操作时间（拆分/合并执行时间）',
            'after' => 'operation_type',
        ])
        ->addColumn('operated_by', 'integer', [
            'limit' => 11,
            'signed' => false,
            'null' => true,
            'default' => null,
            'comment' => '操作人ID（执行拆分/合并的管理员）',
            'after' => 'operated_at',
        ])
        // 索引
        ->addIndex(['source_ticket_id'], [
            'name' => 'idx_source_ticket_id',
        ])
        ->addIndex(['operation_type'], [
            'name' => 'idx_operation_type',
        ])
        ->save();
    }
}
