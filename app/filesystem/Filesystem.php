<?php


namespace app\filesystem;


use Illuminate\Filesystem\FilesystemAdapter;

class Filesystem
{


    /**
     * @param string|null $disk
     * @return FilesystemAdapter
     */
    public function driver(string $disk = null): FilesystemAdapter
    {
        $disk = $disk ?: config('plugin.rockys.ex-admin-webman.filesystems.default');
        $config = config('plugin.rockys.ex-admin-webman.filesystems.disks.'.$disk);
        $driver = (new $config['driver'])->make($config);
        if($driver instanceof \League\Flysystem\Filesystem){
           $filesystem = $driver;
        }else{
           $filesystem  = new \League\Flysystem\Filesystem($driver,$config);
        }
        return new FilesystemAdapter($filesystem);
    }
    public static function __callStatic($name, $arguments)
    {
        $self = new static();
        if($name == 'disk'){
            return $self->driver(...$arguments);
        }else{
            return $self->driver()->$name(...$arguments);
        }
    }
}