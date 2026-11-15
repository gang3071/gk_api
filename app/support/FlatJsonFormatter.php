<?php

namespace app\support;

use Monolog\Formatter\FormatterInterface;

class FlatJsonFormatter implements FormatterInterface
{
    public function format(array $record): string
    {
        $base = [
            'datetime' => $record['datetime']->format('Y-m-d\TH:i:sP'),
            'level' => $record['level_name'],
            'channel' => $record['channel'],
            'message' => $record['message'],
        ];
        $flat = array_merge($base, $record['context']);
        return json_encode($flat,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            ) . "\n";
    }

    public function formatBatch(array $records): string
    {
        return implode('', array_map([$this, 'format'], $records));
    }
}
