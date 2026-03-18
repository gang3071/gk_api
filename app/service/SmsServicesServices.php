<?php
namespace app\service;


use app\model\PhoneSmsLog;
use Exception;

/**
 * 短信服务
 */
class SmsServicesServices
{
    /**
     * @param int $countryCode
     * @param string $phone
     * @param int $type
     * @param int $playerId
     * @param string $name
     * @return bool
     * @throws Exception
     */
    public static function sendSms(int $countryCode, string $phone, int $type, int $playerId = 0, string $name = ''): bool
    {
        $openCountryCode = config('sms.open_country_code');
        if (!in_array($countryCode, $openCountryCode)) {
            $openCountryName = '';
            foreach ($openCountryCode as $item) {
                $openCountryName .= ',' . trans('country_code_name.' . $item, [], 'message');
            }
            throw new Exception(trans('currently_open_countries_and_regions', ['{openCountryCode}', $openCountryName], 'message'));
        }
        switch ($countryCode) {
            case PhoneSmsLog::COUNTRY_CODE_TW:
                return (new TwSmsServicesServices())->send($phone, $type, $playerId, $name);
            case PhoneSmsLog::COUNTRY_CODE_JP:
                return (new JpSmsServicesServices())->send($phone, $type, $playerId);
            case PhoneSmsLog::COUNTRY_CODE_CH:
                return (new ApiSmsServicesServices())->send($phone, $type, $playerId);
            default:
                throw new Exception(trans('country_code_error', [], 'message'));
        }
    }
}
