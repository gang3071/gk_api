<?php

namespace app\service;
/**
 * 发送短信接口
 */
interface BaseSmsServices
{
    /**
     * 发送短信
     * @param string $phone
     * @param int $type
     * @return mixed
     */
    public function send(string $phone, int $type);
}