<?php


namespace app\filesystem\driver;


use app\filesystem\AdapterFactoryInterface;
use Iidestiny\Flysystem\Oss\OssAdapter;
use Iidestiny\Flysystem\Oss\Plugins\FileUrl;
use Iidestiny\Flysystem\Oss\Plugins\Kernel;
use Iidestiny\Flysystem\Oss\Plugins\SetBucket;
use Iidestiny\Flysystem\Oss\Plugins\SignatureConfig;
use Iidestiny\Flysystem\Oss\Plugins\SignUrl;
use Iidestiny\Flysystem\Oss\Plugins\TemporaryUrl;
use Iidestiny\Flysystem\Oss\Plugins\Verify;
use League\Flysystem\Filesystem;

class Oss implements AdapterFactoryInterface
{

    public function make(array $options)
    {
        $root = $options['root'] ?? null;
        $buckets = isset($options['buckets'])?$options['buckets']:[];
        $adapter =  new OssAdapter(
            $options['access_key'],
            $options['secret_key'],
            $options['endpoint'],
            $options['bucket'],
            $options['isCName'],
            $root,
            $buckets
        );
        $filesystem = new Filesystem($adapter);

        $filesystem->addPlugin(new FileUrl());
        $filesystem->addPlugin(new SignUrl());
        $filesystem->addPlugin(new TemporaryUrl());
        $filesystem->addPlugin(new SignatureConfig());
        $filesystem->addPlugin(new SetBucket());
        $filesystem->addPlugin(new Verify());
        $filesystem->addPlugin(new Kernel());
        return $filesystem;
    }
}