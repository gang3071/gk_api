<?php

namespace app\service\machine;
/**
 * 机台接口
 */
interface BaseMachine
{
    /**
     * 发送机台操作指令
     * @param string $cmd 指令
     * @param int $data 数据
     * @param string $source 来源（'player' / 'admin' / 'system'）
     * @param int $source_id 来源id
     * @param int $isSystem 是否系统操作（0=否，1=是）
     * @return mixed
     */
    public function sendCmd(string $cmd, int $data = 0, string $source = 'player', int $source_id = 0, int $isSystem = 0);
}