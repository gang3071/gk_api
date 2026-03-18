<?php


namespace app\filesystem\driver;


use app\filesystem\AdapterFactoryInterface;

class Local implements AdapterFactoryInterface
{
    public function make(array $options)
    {
        return new \League\Flysystem\Adapter\Local($options['root'], $options['lock'] ?? LOCK_EX);
    }
}