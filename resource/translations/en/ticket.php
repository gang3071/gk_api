<?php

return [
    // General
    'ticket' => 'Ticket',
    'ticket_system' => 'Ticket System',
    'ticket_record' => 'Ticket Record',
    'ticket_redeem' => 'Redeem Ticket',
    'ticket_open_score' => 'Scan to Open Score',

    // Status
    'status_disabled' => 'Disabled',
    'status_normal' => 'Normal',
    'status_printed' => 'Printed',
    'status_used' => 'Used',
    'status_pending' => 'Pending',

    // Type
    'type_recharge' => 'Open Score',
    'type_withdraw' => 'Wash Score',

    // Messages
    'ticket_success' => 'Ticket issued successfully',
    'ticket_failed' => 'Failed to issue ticket',
    'open_score_success' => 'Score added successfully',
    'open_score_failed' => 'Failed to add score',
    'generate_code_success' => 'Open score code generated successfully',
    'generate_code_failed' => 'Failed to generate code',

    // Delivery record target/source translations
    'target_ticket' => 'Ticket Record',
    'source_ticket_redeem' => 'Ticket Redeem',
    'source_ticket_open_score' => 'Scan Open Score',

    // Errors
    'score_must_positive' => 'Ticket amount must be greater than 0',
    'balance_insufficient' => 'Insufficient balance',
    'invalid_open_code' => 'Invalid open score code',
    'player_not_match' => 'This code does not belong to current player',
    'record_not_found' => 'Open score record not found',
    'code_used_or_invalid' => 'This code has been used or is invalid',
    'score_not_match' => 'Open score amount does not match',
    'player_not_found' => 'Player not found',
    'encrypt_key_not_configured' => 'Encryption key not configured',
    'not_logged_in' => 'Not logged in',
    'ticket_cross_store_not_allowed' => 'This open score code does not belong to current store and cannot be used',
];
