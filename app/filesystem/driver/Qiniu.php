<?php


namespace app\filesystem\driver;


use app\filesystem\AdapterFactoryInterface;
use League\Flysystem\Filesystem;
use Overtrue\Flysystem\Qiniu\Plugins\FetchFile;
use Overtrue\Flysystem\Qiniu\Plugins\FileUrl;
use Overtrue\Flysystem\Qiniu\Plugins\PrivateDownloadUrl;
use Overtrue\Flysystem\Qiniu\Plugins\RefreshFile;
use Overtrue\Flysystem\Qiniu\Plugins\UploadToken;
use Overtrue\Flysystem\Qiniu\QiniuAdapter;

class Qiniu implements AdapterFactoryInterface
{

    public function make(array $options)
    {
        $adapter =  new QiniuAdapter(
            $options['access_key'], $options['secret_key'],
            $options['bucket'], $options['domain']
        );
        $flysystem = new Filesystem($adapter);

        $flysystem->addPlugin(new FetchFile());
        $flysystem->addPlugin(new UploadToken());
        $flysystem->addPlugin(new FileUrl());
        $flysystem->addPlugin(new PrivateDownloadUrl());
        $flysystem->addPlugin(new RefreshFile());

        return $flysystem;
    }
}