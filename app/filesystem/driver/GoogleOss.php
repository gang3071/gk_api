<?php

namespace app\filesystem\driver;

use Google\Cloud\Storage\StorageClient;
use League\Flysystem\Filesystem;
use Superbalist\Flysystem\GoogleStorage\GoogleStorageAdapter;

class GoogleOss
{
    /**
     * 创建 Google Cloud Storage 驱动实例
     */
    public function make(array $config): Filesystem
    {
        $storageClient = $this->createStorageClient($config);
        $bucket = $storageClient->bucket($config['bucket']);
        
        // 使用 Superbalist 的适配器
        $adapter = new GoogleStorageAdapter($storageClient, $bucket, $config['prefix'] ?? '');
        
        return new Filesystem($adapter);
    }
    
    /**
     * 创建 Google Cloud Storage 客户端
     */
    private function createStorageClient(array $config): StorageClient
    {
        $clientConfig = [];
        
        // 使用密钥文件认证
        if (!empty($config['key_file'])) {
            $clientConfig['keyFilePath'] = $config['key_file'];
        }
        
        // 使用项目ID
        if (!empty($config['project_id'])) {
            $clientConfig['projectId'] = $config['project_id'];
        }
        
        return new StorageClient($clientConfig);
    }
}