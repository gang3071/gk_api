<?php

return [
    'domain' => env('SMS_DOMAIN', 'http://www.smsbao.com/'),
    'username' => env('SMS_USERNAME'),
    'password' => env('SMS_PASSWORD'),
    'sign_name' => env('SMS_SIGN_NAME', '金矩'),
    'template' => env('SMS_TEMPLATE', 'SMS_485275442'),
    'login_content' => '尊敬的用户,您的VIP验证码是{code}',
    'register_content' => '尊敬的用户,您的VIP验证码是{code}',
    'change_password_content' => '尊敬的用户,您的VIP验证码是{code}',
    'change_pay_password' => '尊敬的用户,您的VIP验证码是{code}',
    'change_phone' => '尊敬的用户,您的VIP验证码是{code}',
    'bind_new_phone' => '尊敬的用户,您的VIP验证码是{code}',
    'talk_bind' => '尊敬的用户,您的VIP验证码是{code}',
    'line_bind' => '尊敬的用户,您的VIP验证码是{code}',
    'sm_content' => '尊敬的用户,您的VIP验证码是{code}',
    'department_id' => [
        '31' => 'SMS_485475684',
        '34' => 'SMS_485275442'
    ],
];
