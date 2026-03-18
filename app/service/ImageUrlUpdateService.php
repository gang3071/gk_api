<?php

namespace app\service;

use app\filesystem\Filesystem;

class ImageUrlUpdateService
{
    private $oldDomain = 'api.baozhuangmall.com';
    private $newDomain = 'storage.googleapis.com';
    // 只设置到images目录
    private $ossBasePath = 'images/';
    
    private $storage;
    
    public function __construct()
    {
        // 使用配置的google_oss磁盘
        $this->storage = Filesystem::disk('google_oss');
    }
    
    /**
     * 上传文件并更新数据库
     */
    public function uploadAndUpdate($modelClass, $urlField = 'image_url', $progressCallback = null)
    {
        $records = $modelClass::where($urlField, 'like', '%' . $this->oldDomain . '%')->get();
        
        $updated = 0;
        $uploaded = 0;
        $exists = 0;
        $failed = 0;
        
        foreach ($records as $record) {
            $oldUrl = $record->{$urlField};
            
            $filename = $this->extractFilename($oldUrl);
            $gcsPath = $this->ossBasePath . $filename;
            
            // 检查文件是否已存在
            if (!$this->fileExistsInGcs($gcsPath)) {
                // 获取本地文件路径（在public_path中）
                $localFilePath = $this->getLocalFilePath($oldUrl);
                
                // 上传本地文件到GCS
                if ($localFilePath) {
                    $uploadResult = $this->uploadLocalFileToGcs($localFilePath, $gcsPath);
                    if ($uploadResult) {
                        $uploaded++;
                        
                        if ($progressCallback) {
                            $progressCallback("成功上传: {$filename}");
                        }
                    } else {
                        $failed++;
                        
                        if ($progressCallback) {
                            $progressCallback("上传失败: {$filename}");
                        }
                        
                        // 上传失败，跳过此记录
                        continue;
                    }
                } else {
                    $failed++;
                    
                    if ($progressCallback) {
                        $progressCallback("上传失败: {$filename} - 本地文件不存在");
                    }
                    
                    // 上传失败，跳过此记录
                    continue;
                }
            } else {
                $exists++;
                
                if ($progressCallback) {
                    $progressCallback("文件已存在: {$filename}");
                }
            }
            
            // 构建新的URL
            $newUrl = $this->storage->url($gcsPath);
            
            // 更新数据库记录
            $record->{$urlField} = $newUrl;
            $record->save();
            $updated++;
        }
        
        return [
            'updated' => $updated,
            'uploaded' => $uploaded,
            'exists' => $exists,
            'failed' => $failed
        ];
    }
    
    /**
     * 构建新的URL
     */
    private function buildNewUrl($gcsPath)
    {
        // 根据环境变量配置，构建正确的URL
        // 由于环境变量已经配置了存储桶和前缀，我们只需要返回正确的路径
        return 'https://' . $this->newDomain . '//yjbfile/test/' . $gcsPath;
    }
    
    /**
     * 从URL中提取完整路径
     */
    private function extractOldPath($url)
    {
        $parsedUrl = parse_url($url);
        return $parsedUrl['path'] ?? '';
    }
    
    /**
     * 从URL中提取文件名
     */
    private function extractFilename($url)
    {
        $path = parse_url($url, PHP_URL_PATH);
        return basename($path);
    }
    
    /**
     * 检查文件是否已在GCS中存在
     */
    private function fileExistsInGcs($gcsPath)
    {
        try {
            return $this->storage->exists($gcsPath);
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * 从URL获取本地文件路径（在public_path中）
     */
    private function getLocalFilePath($url)
    {
        // 提取旧路径（相对路径）
        $oldRelativePath = $this->extractOldPath($url);
        
        // 直接使用原始相对路径在public_path中查找
        $path = public_path($oldRelativePath);
        
        // 检查文件是否存在
        if (file_exists($path) && is_file($path)) {
            return $path;
        }
        
        return null;
    }
    
    /**
     * 上传本地文件到GCS
     */
    private function uploadLocalFileToGcs($localFilePath, $gcsPath)
    {
        try {
            // 检查本地文件是否存在
            if (!file_exists($localFilePath) || !is_file($localFilePath)) {
                return false;
            }
            
            // 使用Laravel Filesystem的put方法上传本地文件
            return $this->storage->put($gcsPath, fopen($localFilePath, 'r'));
            
        } catch (\Exception $e) {
            // 记录错误日志
            error_log("上传本地文件到GCS失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 试运行更新，返回统计信息但不实际更新
     */
    public function dryRunUpdate($modelClass, $urlField = 'image_url', $checkUpload = false)
    {
        $records = $modelClass::where($urlField, 'like', '%' . $this->oldDomain . '%')->get();
        
        $samples = [];
        $total = 0;
        $needUpload = 0;
        $exists = 0;
        $localFileMissing = 0;
        
        foreach ($records as $record) {
            $oldUrl = $record->{$urlField};
            $filename = $this->extractFilename($oldUrl);
            $gcsPath = $this->ossBasePath . $filename;
            $newUrl = $this->buildNewUrl($gcsPath);
            
            if ($oldUrl !== $newUrl) {
                $total++;
                
                // 检查是否需要上传文件
                if ($checkUpload) {
                    if ($this->fileExistsInGcs($gcsPath)) {
                        $exists++;
                    } else {
                        // 检查本地文件是否存在
                        $localFilePath = $this->getLocalFilePath($oldUrl);
                        if ($localFilePath) {
                            $needUpload++;
                        } else {
                            $localFileMissing++;
                        }
                    }
                }
                
                // 只保留前5个示例
                if (count($samples) < 5) {
                    $samples[] = [
                        'old' => $oldUrl,
                        'new' => $newUrl
                    ];
                }
            }
        }
        
        return [
            'total' => $total,
            'samples' => $samples,
            'need_upload' => $needUpload,
            'exists' => $exists,
            'local_file_missing' => $localFileMissing
        ];
    }
    
    /**
     * 更新特定模型的URL（不包含上传）
     */
    public function updateSpecificModelUrls($modelClass, $urlField = 'image_url')
    {
        $records = $modelClass::where($urlField, 'like', '%' . $this->oldDomain . '%')->get();
        
        $updatedCount = 0;
        
        foreach ($records as $record) {
            $oldUrl = $record->{$urlField};
            $filename = $this->extractFilename($oldUrl);
            $gcsPath = $this->ossBasePath . $filename;
            $newUrl = $this->buildNewUrl($gcsPath);
            
            if ($oldUrl !== $newUrl) {
                $record->{$urlField} = $newUrl;
                $record->save();
                $updatedCount++;
            }
        }
        
        return $updatedCount;
    }
    
    /**
     * 设置新路径（如果需要上传到其他目录如avatar、certificate等）
     */
    public function setNewPath($path)
    {
        $this->ossBasePath = rtrim($path, '/') . '/';
        return $this;
    }
}