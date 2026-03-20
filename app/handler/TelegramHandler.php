<?php

namespace app\handler;

use GuzzleHttp\Client;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Monolog\LogRecord;

/**
 * Telegram 日志处理器
 * 将错误日志发送到 Telegram 机器人
 */
class TelegramHandler extends AbstractProcessingHandler
{
    /**
     * Telegram Bot Token
     * @var string
     */
    protected $botToken;

    /**
     * Telegram Chat ID
     * @var string
     */
    protected $chatId;

    /**
     * HTTP Client
     * @var Client
     */
    protected $client;

    /**
     * 应用名称
     * @var string
     */
    protected $appName;

    /**
     * 是否启用
     * @var bool
     */
    protected $enabled;

    /**
     * @param string $botToken Telegram Bot Token
     * @param string $chatId Telegram Chat ID
     * @param string $appName 应用名称
     * @param int $level 日志级别
     * @param bool $bubble
     */
    public function __construct(
        string $botToken,
        string $chatId,
        string $appName = 'Webman App',
        int $level = Logger::ERROR,
        bool $bubble = true
    ) {
        parent::__construct($level, $bubble);

        $this->botToken = $botToken;
        $this->chatId = $chatId;
        $this->appName = $appName;
        $this->enabled = !empty($botToken) && !empty($chatId);

        if ($this->enabled) {
            $this->client = new Client([
                'base_uri' => 'https://api.telegram.org',
                'timeout' => 5,
                'http_errors' => false,
            ]);
        }
    }

    /**
     * 处理日志记录
     *
     * @param LogRecord|array $record
     * @return void
     */
    protected function write($record): void
    {
        if (!$this->enabled) {
            return;
        }

        // 兼容不同版本的 Monolog
        if ($record instanceof LogRecord) {
            $message = $this->formatMessage($record->toArray());
        } else {
            $message = $this->formatMessage($record);
        }

        $this->sendToTelegram($message);
    }

    /**
     * 格式化消息
     *
     * @param array $record
     * @return string
     */
    protected function formatMessage(array $record): string
    {
        $level = $record['level_name'] ?? 'ERROR';
        $datetime = $record['datetime'] ?? date('Y-m-d H:i:s');
        $message = $record['message'] ?? '';
        $context = $record['context'] ?? [];

        // 构建消息
        $text = "🚨 *{$this->appName} - {$level}*\n\n";
        $text .= "⏰ 时间: `{$datetime}`\n\n";
        $text .= "📝 消息:\n```\n{$message}\n```\n";

        // 添加上下文信息
        if (!empty($context)) {
            $text .= "\n📊 上下文:\n";
            $contextText = $this->formatContext($context);
            if (strlen($contextText) > 500) {
                $contextText = substr($contextText, 0, 500) . '...';
            }
            $text .= "```\n{$contextText}\n```\n";
        }

        // 添加堆栈跟踪
        if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
            $trace = $context['exception']->getTraceAsString();
            // 限制堆栈长度
            if (strlen($trace) > 1000) {
                $trace = substr($trace, 0, 1000) . "\n... (truncated)";
            }
            $text .= "\n🔍 堆栈:\n```\n{$trace}\n```";
        } elseif (isset($context['trace'])) {
            $trace = is_string($context['trace']) ? $context['trace'] : print_r($context['trace'], true);
            if (strlen($trace) > 1000) {
                $trace = substr($trace, 0, 1000) . "\n... (truncated)";
            }
            $text .= "\n🔍 堆栈:\n```\n{$trace}\n```";
        }

        // Telegram 消息长度限制 4096
        if (strlen($text) > 4000) {
            $text = substr($text, 0, 4000) . "\n... (message truncated)";
        }

        return $text;
    }

    /**
     * 格式化上下文信息
     *
     * @param array $context
     * @return string
     */
    protected function formatContext(array $context): string
    {
        // 移除 exception 对象，单独处理
        unset($context['exception']);

        if (empty($context)) {
            return '';
        }

        $lines = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value)) {
                $lines[] = "{$key}: {$value}";
            } else {
                $lines[] = "{$key}: " . json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * 发送消息到 Telegram
     *
     * @param string $message
     * @return void
     */
    protected function sendToTelegram(string $message): void
    {
        try {
            $this->client->post("/bot{$this->botToken}/sendMessage", [
                'json' => [
                    'chat_id' => $this->chatId,
                    'text' => $message,
                    'parse_mode' => 'Markdown',
                    'disable_web_page_preview' => true,
                ],
            ]);
        } catch (\Throwable $e) {
            // 静默失败，避免因为 Telegram 发送失败而影响主流程
            // 可以记录到文件日志
            error_log("Telegram Handler Error: " . $e->getMessage());
        }
    }
}
