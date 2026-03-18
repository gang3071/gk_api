<?php

namespace app\service;

use app\model\SystemSetting;
use Exception;
use WebmanTech\LaravelHttpClient\Facades\Http;

class LineServices
{
    /**
     * 获取token
     * @param $code
     * @param $departmentId
     * @return mixed
     * @throws Exception
     */
    public static function getAccessToken($code, $departmentId)
    {
        $system = SystemSetting::query()->where('department_id', $departmentId)->where('status',
            1)->get()->pluck('content', 'feature');
        if (empty($system['line_redirect_uri']) || empty($system['line_key']) || empty($system['line_secret'])) {
            throw new Exception(trans('line_auth_error', [], 'message'));
        }
        $response = Http::timeout(5)->asForm()->post('https://api.line.me/oauth2/v2.1/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $system['line_redirect_uri'],
            'client_id' => $system['line_key'],
            'client_secret' => $system['line_secret'],
        ]);
        if ($response->status() == 200) {
            $tokenData = json_decode($response->body(), true);
            if (empty($tokenData['access_token'])) {
                throw new Exception(trans('line_auth_error', [], 'message'));
            }
            return $tokenData['access_token'];
        } else {
            throw new Exception(trans('line_auth_error', [], 'message'));
        }
    }
    
    /**
     * 获取信息
     * @param $accessToken
     * @return mixed
     * @throws Exception
     */
    public static function getUserProfile($accessToken)
    {
        $response = Http::timeout(5)->withHeaders([
            'Authorization' => 'Bearer ' . $accessToken
        ])->get('https://api.line.me/v2/profile');
        if ($response->status() == 200) {
            $userData = json_decode($response->body(), true);
            if (empty($userData['userId'])) {
                throw new Exception(trans('line_auth_error', [], 'message'));
            }
            return $userData;
        } else {
            throw new Exception(trans('line_auth_error', [], 'message'));
        }
    }
}
