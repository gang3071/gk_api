<?php

return [
    // 通用
    'ticket' => '出票',
    'ticket_system' => '出票系統',
    'ticket_record' => '出票記錄',
    'ticket_redeem' => '核銷出票',
    'ticket_open_score' => '掃碼開分',

    // 状态
    'status_disabled' => '禁用',
    'status_normal' => '正常',
    'status_printed' => '已列印',
    'status_used' => '已使用',
    'status_pending' => '待核銷',

    // 类型
    'type_recharge' => '開分',
    'type_withdraw' => '洗分',

    // 消息
    'ticket_success' => '出票成功',
    'ticket_failed' => '出票失敗',
    'open_score_success' => '上分成功',
    'open_score_failed' => '上分失敗',
    'generate_code_success' => '開分碼生成成功',
    'generate_code_failed' => '生成失敗',

    // 错误
    'score_must_positive' => '出票金額必須大於0',
    'balance_insufficient' => '餘額不足',
    'invalid_open_code' => '無效的開分碼',
    'player_not_match' => '此開分碼不屬於當前玩家',
    'record_not_found' => '開分記錄不存在',
    'code_used_or_invalid' => '此開分碼已使用或已失效',
    'score_not_match' => '開分金額不匹配',
    'player_not_found' => '玩家不存在',
    'encrypt_key_not_configured' => '加密密鑰未配置',
    'not_logged_in' => '未登錄',
];
