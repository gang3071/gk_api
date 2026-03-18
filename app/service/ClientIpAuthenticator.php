<?php

namespace app\service;

use app\model\IpWhitelist;

class ClientIpAuthenticator
{
    private $config;
    
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'enable_whitelist' => false,
            'enable_blacklist' => false,
            'enable_rate_limit' => false,
            'enable_geo_check' => false,
            'whitelist' => [],
            'blacklist' => [],
            'trusted_proxies' => [],
        ], $config);
    }
    
    /**
     * 综合IP认证
     */
    public function authenticate(string $ip): array
    {
        // 验证IP格式
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return $this->failedResult('IP_INVALID', 'IP地址格式无效', $ip);
        }
        
        $result = [
            'success' => true,
            'code' => 'SUCCESS',
            'message' => '认证通过',
            'ip' => $ip,
            'checks' => []
        ];
        
        // 2. 白名单检查
        if ($this->config['enable_whitelist']) {
            $whitelistCheck = $this->checkWhitelist($ip);
            $result['checks']['whitelist'] = $whitelistCheck;
            if (!$whitelistCheck['allowed']) {
                return $this->failedResult('IP_NOT_WHITELISTED', 'IP地址不在白名单中', $ip);
            }
        }
        
        return $result;
    }
    
    /**
     * 黑名单检查
     */
    private function checkBlacklist(string $ip): array
    {
        $isBlocked = $this->isIpInList($ip, $this->config['blacklist']);
        
        return [
            'allowed' => !$isBlocked,
            'blocked' => $isBlocked,
            'list_type' => 'blacklist'
        ];
    }
    
    /**
     * 白名单检查
     */
    private function checkWhitelist(string $ip): array
    {
        $isAllowed = $this->checkIp($ip);
        
        return [
            'allowed' => $isAllowed,
            'list_type' => 'whitelist'
        ];
    }
    
    /**
     * 检查IP是否在列表中
     */
    private function checkIp(string $ip): bool
    {
        return IpWhitelist::query()->where('ip_address', $ip)->exists();
    }
    
    /**
     * @param string $code
     * @param string $message
     * @param string $ip
     * @return array
     */
    private function failedResult(string $code, string $message, string $ip): array
    {
        return [
            'success' => false,
            'code' => $code,
            'message' => $message,
            'ip' => $ip,
            'timestamp' => time()
        ];
    }
}
