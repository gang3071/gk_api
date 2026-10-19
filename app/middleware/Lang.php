<?php
namespace app\middleware;

use Illuminate\Support\Str;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

class Lang implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $lang = $request->header('Lang') ?? 'zh_TW';
        $lang = Str::replace('-', '_', $lang);
        // 系統僅在台灣使用，zh_CN 統一對應繁體
        if ($lang === 'zh_CN') {
            $lang = 'zh_TW';
        }
        locale(session('lang', $lang));
        return $handler($request);
    }
}
