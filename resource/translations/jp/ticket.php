<?php

return [
    // 一般
    'ticket' => '出票',
    'ticket_system' => '出票システム',
    'ticket_record' => '出票記録',
    'ticket_redeem' => '出票取消',
    'ticket_open_score' => 'スキャン開分',

    // ステータス
    'status_disabled' => '無効',
    'status_normal' => '正常',
    'status_printed' => '印刷済み',
    'status_used' => '使用済み',
    'status_pending' => '取消待ち',

    // タイプ
    'type_recharge' => '開分',
    'type_withdraw' => '洗分',

    // メッセージ
    'ticket_success' => '出票成功',
    'ticket_failed' => '出票失敗',
    'open_score_success' => '上分成功',
    'open_score_failed' => '上分失敗',
    'generate_code_success' => '開分コード生成成功',
    'generate_code_failed' => '生成失敗',

    // 金流明細 target/source 翻訳
    'target_ticket' => '出票記録',
    'source_ticket_redeem' => '出票キャンセル',
    'source_ticket_open_score' => 'スキャン開分',

    // エラー
    'score_must_positive' => '出票金額は0より大きくなければなりません',
    'balance_insufficient' => '残高不足',
    'invalid_open_code' => '無効な開分コード',
    'player_not_match' => 'このコードは現在のプレイヤーに属していません',
    'record_not_found' => '開分記録が見つかりません',
    'code_used_or_invalid' => 'このコードは使用済みまたは無効です',
    'score_not_match' => '開分金額が一致しません',
    'player_not_found' => 'プレイヤーが見つかりません',
    'encrypt_key_not_configured' => '暗号化キーが設定されていません',
    'not_logged_in' => 'ログインしていません',
    'ticket_cross_store_not_allowed' => 'この開分コードは現在の店舗に属していないため、使用できません',
];
