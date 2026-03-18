<?php

return [
    'promotion.*' => [
        [app\event\Promotion::class, 'generateProfitSharing']
    ],
];
