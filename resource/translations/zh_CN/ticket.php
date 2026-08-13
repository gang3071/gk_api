<?php

return [
    // 通用
    'ticket' => '出票',
    'ticket_system' => '出票系统',
    'ticket_record' => '出票记录',
    'ticket_redeem' => '核销出票',
    'ticket_open_score' => '扫码开分',

    // 状态
    'status_disabled' => '禁用',
    'status_normal' => '正常',
    'status_printed' => '已打印',
    'status_used' => '已使用',
    'status_pending' => '待核销',

    // 类型
    'type_recharge' => '开分',
    'type_withdraw' => '洗分',

    // 消息
    'ticket_success' => '出票成功',
    'ticket_failed' => '出票失败',
    'open_score_success' => '上分成功',
    'open_score_failed' => '上分失败',
    'generate_code_success' => '开分码生成成功',
    'generate_code_failed' => '生成失败',

    // 金流明细 target/source 翻译
    'target_ticket' => '出票记录',
    'source_ticket_redeem' => '出票核销',
    'source_ticket_open_score' => '扫码开分',

    // 错误
    'score_must_positive' => '出票金额必须大于0',
    'balance_insufficient' => '余额不足',
    'invalid_open_code' => '无效的开分码',
    'player_not_match' => '此开分码不属于当前玩家',
    'record_not_found' => '开分记录不存在',
    'code_used_or_invalid' => '此开分码已使用或已失效',
    'score_not_match' => '开分金额不匹配',
    'player_not_found' => '玩家不存在',
    'encrypt_key_not_configured' => '加密密钥未配置',
    'not_logged_in' => '未登录',
    'ticket_cross_store_not_allowed' => '此开分码不属于当前店铺，无法使用',
];
