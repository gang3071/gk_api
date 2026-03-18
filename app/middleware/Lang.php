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
        $lang = $request->header('Lang') ?? 'zh_CN';
        locale(session('lang', Str::replace('-', '_', $lang)));
        return $handler($request);
    }
}
