<?php
/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

use Webman\Route;

Route::options('[{path:.+}]', function () {
    return response('');
});

Route::group('/api', function () {
    Route::group('/v1', function () {
        // 玩家登录
        Route::post('/login', [\app\api\controller\v1\IndexController::class, 'login']);
        // 刷新token
        Route::post('/refresh', [\app\api\controller\v1\IndexController::class, 'refreshToken']);
        // 注册接口
        Route::post('/register', [\app\api\controller\v1\IndexController::class, 'register']);
        // 发送短信
        Route::post('/send-code', [\app\api\controller\v1\IndexController::class, 'sendCode']);
        // 玩家登出
        Route::post('/logout', [\app\api\controller\v1\IndexController::class, 'logout']);
        // 获取用户信息
        Route::post('/player-info', [\app\api\controller\v1\PlayerController::class, 'playerInfo']);
        // 重置密码
        Route::post('/change-password', [\app\api\controller\v1\PlayerController::class, 'changePassword']);
        // 首页数据
        Route::post('/get-index', [\app\api\controller\v1\PlayerController::class, 'getIndex']);
        // 首页广告(轮播图,跑马灯)
        Route::post('/home-page-ads', [\app\api\controller\v1\PlayerController::class, 'homePageAds']);
        // 机台列表
        Route::post('/machine-list', [\app\api\controller\v1\MachineController::class, 'machineList']);
        // 机台详情
        Route::post('/machine-info', [\app\api\controller\v1\MachineController::class, 'machineInfo']);
        // 钢珠操作
        Route::post('/jackpot-action', [\app\api\controller\v1\MachineController::class, 'jackPotAction']);
        // 斯洛机台操作
        Route::post('/slot-action', [\app\api\controller\v1\MachineController::class, 'slotAction']);
        // 保留机台
        Route::post('/machine-keep', [\app\api\controller\v1\MachineController::class, 'machineKeep']);
        // 取消机台保留
        Route::post('/machine-keep-cancel', [\app\api\controller\v1\MachineController::class, 'machineKeepCancel']);
        // 游戏记录
        Route::post('/game-record', [\app\api\controller\v1\PlayerController::class, 'gameRecord']);
        // 编辑玩家名称
        Route::post('/edit-player-name', [\app\api\controller\v1\PlayerController::class, 'editPlayerName']);
        // 编辑保存玩家头像
        Route::post('/edit-player-avatar', [\app\api\controller\v1\PlayerController::class, 'editPlayerAvatar']);
        // 玩家发送短信(修改玩家支付密码, 修改玩家绑定手机号)
        Route::post('/player-send-code', [\app\api\controller\v1\PlayerController::class, 'playerSendCode']);
        // 检查验证码(下一步)
        Route::post('/check-phone-code', [\app\api\controller\v1\PlayerController::class, 'checkPhoneCode']);
        // 绑定新手机号
        Route::post('/bind-new-phone', [\app\api\controller\v1\PlayerController::class, 'bindNewPhone']);
        // 修改支付密码
        Route::post('/change-play-password', [\app\api\controller\v1\PlayerController::class, 'changePlayPassword']);
        // 玩家转点
        Route::post('/present', [\app\api\controller\v1\PlayerController::class, 'present']);
        // 玩家转点不要密码
        Route::post('/present-not-password', [\app\api\controller\v1\PlayerController::class, 'presentNoPassword']);
        // 竖版玩家洗分
        Route::post('/present-auto', [\app\api\controller\v1\PlayerController::class, 'presentAuto']);
        // 玩家账单信息
        Route::post('/player-billing-record', [\app\api\controller\v1\PlayerController::class, 'playerBillingRecord']);
        // Q-talk充值回调
        Route::post('/talk-pay-notify', [\app\api\controller\v1\TalkPayController::class, 'talkPayNotify']);
        // Q-talk充值
        Route::post('/talk-recharge', [\app\api\controller\v1\PlayerController::class, 'talkRecharge']);
        // 充值单号信息
        Route::post('/get-talk-recharge-info', [\app\api\controller\v1\PlayerController::class, 'getTalkRechargeInfo']);
        // 公告列表
        Route::post('/announcement-list', [\app\api\controller\v1\IndexController::class, 'announcementList']);
        // 公告详情
        Route::post('/announcement-info', [\app\api\controller\v1\IndexController::class, 'announcementInfo']);
        // 默认头像列表
        Route::post('/get-avatar-list', [\app\api\controller\v1\PlayerController::class, 'getAvatarList']);
        // 修改玩家头像
        Route::post('/change-player-avatar', [\app\api\controller\v1\PlayerController::class, 'changePlayerAvatar']);
        // 玩家提现
        Route::post('/player-withdrawal', [\app\api\controller\v1\PlayerController::class, 'playerWithdrawal']);
        // 取消收藏机台
        Route::post('/cancel-favorite-machine', [\app\api\controller\v1\PlayerController::class, 'cancelFavoriteMachine']);
        // 收藏机台
        Route::post('/favorite-machine', [\app\api\controller\v1\PlayerController::class, 'favoriteMachine']);
        // 玩家收藏列表
        Route::post('/favorite-machine-list', [\app\api\controller\v1\PlayerController::class, 'favoriteMachineList']);
        // 玩家游戏中机台
        Route::post('/playing-machine', [\app\api\controller\v1\PlayerController::class, 'playingMachine']);
        // 添加银行卡
        Route::post('/add-bank-card', [\app\api\controller\v1\PlayerController::class, 'addBankCard']);
        // 银行卡列表
        Route::post('/bank-card-list', [\app\api\controller\v1\PlayerController::class, 'bankCardList']);
        // 提现方式列表
        Route::post('/get-withdrawal-way', [\app\api\controller\v1\PlayerController::class, 'getWithdrawalWay']);
        // 获得提现方式
        Route::post('/get-recharge-method', [\app\api\controller\v1\PlayerController::class, 'getRechargeMethod']);
        // 玩家充值
        Route::post('/player-recharge', [\app\api\controller\v1\PlayerController::class, 'playerRecharge']);
        // 检查是否绑定银行卡
        Route::post('/check-bind-bankcard', [\app\api\controller\v1\PlayerController::class, 'checkBindBankcard']);
        // 完成充值(提交充值凭证)
        Route::post('/complete-recharge', [\app\api\controller\v1\PlayerController::class, 'completeRecharge']);
        // 取消充值
        Route::post('/cancel-recharge', [\app\api\controller\v1\PlayerController::class, 'cancelRecharge']);
        // 获取充值订单
        Route::post('/get-recharge', [\app\api\controller\v1\PlayerController::class, 'getRecharge']);
        // 竖版机器投钞
        Route::post('/recharge-and-withdraw', [\app\api\controller\v1\MachineController::class, 'rechargeAndWithdraw']);
        // 账务查询
        Route::post('/accounting-list', [\app\api\controller\v1\MachineController::class, 'accountingList']);
        // 获取渠道信息
        Route::post('/get-channel', [\app\api\controller\v1\IndexController::class, 'getChannel']);
        // 获取客服链接
        Route::post('/get-chat', [\app\api\controller\v1\IndexController::class, 'getChat']);
        // 获取line讨论群
        Route::post('/get-line-group', [\app\api\controller\v1\IndexController::class, 'getLineGroup']);
        // 获取活动列表
        Route::post('/activity-list', [\app\api\controller\v1\ActivityController::class, 'activityList']);
        // 获取活动详情
        Route::post('/activity-info', [\app\api\controller\v1\ActivityController::class, 'activityInfo']);
        // 获取配置信息
        Route::post('/get-setting', [\app\api\controller\v1\IndexController::class, 'getSetting']);
        // 领取活动奖励
        Route::post('/receive-award', [\app\api\controller\v1\ActivityController::class, 'receiveAward']);
        // 推广数据
        Route::post('/promotion-data', [\app\api\controller\v1\PromoterController::class, 'promotionData']);
        // 推广数据竖版
        Route::post('/promotion-data-portrait', [\app\api\controller\v1\PromoterController::class, 'promotionDataPortrait']);
        // 推广玩家数据
        Route::post('/promotion-player', [\app\api\controller\v1\PromoterController::class, 'promotionPlayer']);
        // 推广玩家数据竖版(我的玩家 我的店铺, 我的机器)
        Route::post('/promotion-player-portrait', [\app\api\controller\v1\PromoterController::class, 'promotionPlayerPortrait']);
        // 设置推广员
        Route::post('/set-promoter', [\app\api\controller\v1\PromoterController::class, 'setPromoter']);
        // 设置推广员竖版
        Route::post('/set-promoter-portrait', [\app\api\controller\v1\PromoterController::class, 'setPromoterPortrait']);
        // 设置推广员备注名
        Route::post('/set-promoter-name', [\app\api\controller\v1\PromoterController::class, 'setPromoterName']);
        // 团队分润
        Route::post('/promotion-team', [\app\api\controller\v1\PromoterController::class, 'promotionTeam']);
        // 团队分润竖版
        Route::post('/promotion-team-portrait', [\app\api\controller\v1\PromoterController::class, 'promotionTeamPortrait']);
        // 鱼机台操作
        Route::post('/fish-action', [\app\api\controller\v1\MachineController::class, 'fishAction']);
        // 开增是否可以正常洗分
        Route::post('/if-key-out-condition', [\app\api\controller\v1\MachineController::class, 'ifKeyOutCondition']);
        // 开增：机台开增规则列表
        Route::post('/show_open_point_rule', [\app\api\controller\v1\MachineController::class, 'showOpenPointRule']);
        // 彩金列表
        Route::post('/lottery-list', [\app\api\controller\v1\LotteryController::class, 'lotteryList']);
        // 彩金中奖记录
        Route::post('/lottery-record-list', [\app\api\controller\v1\LotteryController::class, 'lotteryRecordList']);
        // 玩家消息列表
        Route::post('/notice-list', [\app\api\controller\v1\IndexController::class, 'noticeList']);
        // 领取彩金奖励
        Route::post('/receive-lottery', [\app\api\controller\v1\LotteryController::class, 'receiveLottery']);
        // 玩家账变记录
        Route::post('/player-delivery-record', [\app\api\controller\v1\PromoterController::class, 'playerDeliveryRecord']);
        // 玩家账变记录竖版
        Route::post('/player-delivery-record-portrait', [\app\api\controller\v1\PromoterController::class, 'playerDeliveryRecordPortrait']);
        // 一键领取
        Route::post('/receive-all-lottery', [\app\api\controller\v1\LotteryController::class, 'receiveAllLottery']);
        // line登录
        Route::post('/line-login', [\app\api\controller\v1\IndexController::class, 'lineLogin']);
        // line绑定
        Route::post('/line-bind', [\app\api\controller\v1\IndexController::class, 'lineBind']);
        // 更新玩家头像
        Route::post('/upload-avatar', [\app\api\controller\v1\PlayerController::class, 'uploadAvatar']);
        // 平台转出到电子游戏
        Route::post('/wallet-transfer-out',
            [\app\api\controller\v1\GamePlatformController::class, 'walletTransferOut']);
        // 电子游戏转入到平台
        Route::post('/wallet-transfer-in', [\app\api\controller\v1\GamePlatformController::class, 'walletTransferIn']);
        // 查询电子游戏平台余额
        Route::post('/get-balance', [\app\api\controller\v1\GamePlatformController::class, 'getBalance']);
        // 查询所有电子游戏平台余额
        Route::post('/get-wallet', [\app\api\controller\v1\GamePlatformController::class, 'getWallet']);
        // 转出全部
        Route::post('/withdrawAmountAll', [\app\api\controller\v1\GamePlatformController::class, 'withdrawAmountAll']);
        // 进入游戏大厅
        Route::post('/lobby-login', [\app\api\controller\v1\GamePlatformController::class, 'lobbyLogin']);
        // 游戏列表
        Route::post('/game-list', [\app\api\controller\v1\GamePlatformController::class, 'gamePlatformList']);
        // 游戏平台列表（不包含游戏）
        Route::post('/platform-list', [\app\api\controller\v1\GamePlatformController::class, 'getPlatformList']);
        // 电子游戏列表
        Route::post('/electron-game-list', [\app\api\controller\v1\GamePlatformController::class, 'getElectronGameList']);
        // 热门游戏列表
        Route::post('/hot-game-list', [\app\api\controller\v1\GamePlatformController::class, 'hotGameList']);
        // 快速转出电子游戏钱包余额
        Route::post('/fast-transfer', [\app\api\controller\v1\GamePlatformController::class, 'fastTransferAllIN']);
        // 富豪榜
        Route::post('/ranking-list', [\app\api\controller\v1\PlayerController::class, 'rankingList']);
        // 进入游戏
        Route::post('/enter-game', [\app\api\controller\v1\GamePlatformController::class, 'enterGame']);
        // 标签机器列表
        Route::post('/machine-data-list', [\app\api\controller\v1\MachineController::class, 'machineDataList']);
        // 编辑提现账户
        Route::post('/edit-bank-card', [\app\api\controller\v1\PlayerController::class, 'editBankCard']);
        // 删除提现账户
        Route::post('/delete-bank-card', [\app\api\controller\v1\PlayerController::class, 'deleteBankCard']);
        // 全民代理等级描述
        Route::post('/national-level', [\app\api\controller\v1\NationalPromoterController::class, 'nationalLevel']);
        //全民代理推广数据
        Route::post('/national-promoter-data', [\app\api\controller\v1\NationalPromoterController::class, 'promoterData']);
        //收益历史
        Route::post('/national-profit-record', [\app\api\controller\v1\NationalPromoterController::class, 'profitRecord']);
        //全民代理规则
        Route::post('/national-promoter-rules', [\app\api\controller\v1\NationalPromoterController::class, 'rulesList']);
        //全民代理我的用户
        Route::post('/national-sub-player', [\app\api\controller\v1\NationalPromoterController::class, 'subPlayersList']);
        //全民代理邀请规则
        Route::post('/national-invite-rules', [\app\api\controller\v1\NationalPromoterController::class, 'inviteRules']);
        Route::post('/set-player-remark', [\app\api\controller\v1\PromoterController::class, 'setPlayerRemark']);
        // 银行列表
        Route::post('/bank-list', [\app\api\controller\v1\PlayerController::class, 'bankList']);
        // 反水相关
        Route::post('/reverseWaterList', [\app\api\controller\v1\PlayerController::class, 'reverseWaterList']);
        // 获取反水详细
        Route::post('/getReverseWaterDetail', [\app\api\controller\v1\PlayerController::class, 'getReverseWaterDetail']);
        // 领取反水两级
        Route::post('/receiveReverseWater', [\app\api\controller\v1\PlayerController::class, 'receiveReverseWater']);
        // 平台反水列表
        Route::post('/reverseWaterSetting', [\app\api\controller\v1\GamePlatformController::class, 'reverseWaterSetting']);
        // 验证密码
        Route::post('/pass-check', [\app\api\controller\v1\PromoterController::class, 'passCheck']);
        // 玩家快速绑定购宝钱包
        Route::post('/fast-bind', [\app\api\controller\v1\PaymentController::class, 'fastBind']);
        // 玩家快速解绑定购宝钱包
        Route::post('/un-bind', [\app\api\controller\v1\PaymentController::class, 'unBind']);
        // 检查用户绑定情况
        Route::post('/check-binding', [\app\api\controller\v1\PaymentController::class, 'checkBinding']);
        // 检查用户授权情况
        Route::post('/check-verify', [\app\api\controller\v1\PaymentController::class, 'checkVerify']);
        // 发起授权
        Route::post('/verify-user', [\app\api\controller\v1\PaymentController::class, 'verifyUser']);
        // 发起授权-验证
        Route::post('/verify-code', [\app\api\controller\v1\PaymentController::class, 'verifyCode']);
        // 团队明细
        Route::post('/promotion-team-player', [\app\api\controller\v1\PromoterController::class, 'promotionTeamPlayer']);
        // 团队明细竖版
        Route::post('/promotion-team-player-portrait', [\app\api\controller\v1\PromoterController::class, 'promotionTeamPlayerPortrait']);
        // 获取腾讯云线路
        Route::post('/get-tencent-media', [\app\api\controller\v1\MachineController::class, 'getTencentMedia']);
        // 退出游戏
        Route::post('/exit-game', [\app\api\controller\v1\IndexController::class, 'exitGame']);
        // 获取开分配置
        Route::post('/get-open-score-setting', [\app\api\controller\v1\MachineController::class, 'getOpenScoreSetting']);
        // 开分业务
        Route::post('/open-score', [\app\api\controller\v1\PlayerController::class, 'openScore']);
        // 开分记录列表
        Route::post('/open-score-records', [\app\api\controller\v1\PlayerController::class, 'getOpenScoreRecords']);
        // 洗分记录列表
        Route::post('/present-auto-records', [\app\api\controller\v1\PlayerController::class, 'getPresentAutoRecords']);
        // 彩金中奖记录列表
        Route::post('/lottery-records', [\app\api\controller\v1\PlayerController::class, 'getLotteryRecords']);

        // ========== 充值满赠相关接口 ==========
        // 获取充值满赠活动列表
        Route::post('/deposit-bonus/activity-list', [\app\api\controller\v1\DepositBonusPlayerController::class, 'getActivityList']);
        // 核销充值满赠二维码
        Route::post('/deposit-bonus/verify-qrcode', [\app\api\controller\v1\DepositBonusPlayerController::class, 'verifyQrcode']);
        // 获取押码量进度
        Route::post('/deposit-bonus/bet-progress', [\app\api\controller\v1\DepositBonusPlayerController::class, 'getBetProgress']);
        // 获取押码量明细
        Route::post('/deposit-bonus/bet-details', [\app\api\controller\v1\DepositBonusPlayerController::class, 'getBetDetails']);
        // 我的充值满赠订单
        Route::post('/deposit-bonus/my-orders', [\app\api\controller\v1\DepositBonusPlayerController::class, 'myOrders']);
        // 检查是否可以提现
        Route::post('/deposit-bonus/check-withdrawable', [\app\api\controller\v1\DepositBonusPlayerController::class, 'checkWithdrawable']);
        // 获取可提现余额信息
        Route::post('/deposit-bonus/withdrawable-balance', [\app\api\controller\v1\DepositBonusPlayerController::class, 'getWithdrawableBalance']);
        // 获取首页押码量卡片
        Route::post('/deposit-bonus/home-bet-card', [\app\api\controller\v1\DepositBonusPlayerController::class, 'getHomeBetCard']);
    });
    Route::group('/auth', function () {
        // 绑定Q-talk账号
        Route::post('/bind-talk-profile', [\app\api\controller\Auth\TalkOAuthController::class, 'bindTalkProfile']);
        // 获取Q-talk账号信息
        Route::post('/get-talk-profile', [\app\api\controller\Auth\TalkOAuthController::class, 'getTalkProfile']);
    });
});

// 外部API
Route::group('/external', function () {
    Route::group('/app', function () {
        // 获取玩家游戏记录
        Route::post('/get-player-game', [\app\api\controller\external\ExternalApiController::class, 'getPlayerGame']);
        // 获取玩家信息
        Route::post('/get-player-info', [\app\api\controller\external\ExternalApiController::class, 'getPlayerInfo']);
    })->middleware([
        \app\middleware\ExternalAppMiddleware::class
    ]);
    // 获取AccessToken
    Route::post('/get-access-token', [\app\api\controller\external\ExternalApiController::class, 'getAccessToken']);
    // 攻略列表
    Route::post('/strategy-list', [\app\api\controller\external\StrategyApiController::class, 'strategyList']);
    // 攻略详情
    Route::post('/strategy-info', [\app\api\controller\external\StrategyApiController::class, 'strategyInfo']);
    // 获取渠道信息
    Route::post('/channel-info', [\app\api\controller\external\ExternalApiController::class, 'channelInfo']);
    Route::post('/get-password', [\app\api\controller\external\ExternalApiController::class, 'getPassword']);
    // 获取彩金池和最新中奖记录
    Route::post('/get-lottery-pool-and-records', [\app\api\controller\external\ExternalApiController::class, 'getLotteryPoolAndRecords']);

    // 测试接口
    Route::post('/test-check-lottery', [\app\api\controller\external\ExternalApiController::class, 'testCheckLottery']);
    Route::post('/test-trigger-burst', [\app\api\controller\external\ExternalApiController::class, 'testTriggerBurst']);
    Route::post('/test-get-burst-status', [\app\api\controller\external\ExternalApiController::class, 'testGetBurstStatus']);
    Route::post('/test-end-burst', [\app\api\controller\external\ExternalApiController::class, 'testEndBurst']);
    Route::post('/test-probability', [\app\api\controller\external\ExternalApiController::class, 'testProbability']);
    Route::post('/test-send-win-message', [\app\api\controller\external\ExternalApiController::class, 'testSendWinMessage']);
});

// 代理API
Route::group('/agent', function () {
    Route::group('/api', function () {
        // 创建玩家
        Route::post('/create-player', [\app\api\controller\agent\IndexController::class, 'createPlayer']);
        // 获取玩家信息
        Route::post('/get-player-info', [\app\api\controller\agent\IndexController::class, 'getPlayerInfo']);
        // 转出
        Route::post('/transfer-out', [\app\api\controller\agent\IndexController::class, 'transferOut']);
        // 转入
        Route::post('/transfer-in', [\app\api\controller\agent\IndexController::class, 'transferIn']);
        // 游戏入口
        Route::post('/enter-game', [\app\api\controller\agent\IndexController::class, 'enterGame']);
        // 登出玩家
        Route::post('/logout', [\app\api\controller\agent\IndexController::class, 'logout']);
        // 机台上下分
        Route::post('/machine-record', [\app\api\controller\agent\IndexController::class, 'machineRecord']);
        // 活动记录
        Route::post('/activity-record', [\app\api\controller\agent\IndexController::class, 'activityRecord']);
        // 电子游戏记录
        Route::post('/game-record', [\app\api\controller\agent\IndexController::class, 'gameRecord']);
        // 彩金记录
        Route::post('/lottery-record', [\app\api\controller\agent\IndexController::class, 'lotteryRecord']);
        // 变账记录
        Route::post('/delivery-record', [\app\api\controller\agent\IndexController::class, 'deliveryRecord']);
        // 钱包余额
        Route::post('/get-balance', [\app\api\controller\agent\IndexController::class, 'getBalance']);
        // 已结算机台游戏记录
        Route::post('/machine-finish-record',
            [\app\api\controller\agent\IndexController::class, 'machineFinishRecord']);
                    //通过account获取玩家信息
        Route::post('/get-player-info-by-account',
            [\app\api\controller\agent\IndexController::class, 'getPlayerInfoByAccount']);
    })->middleware([
        \app\middleware\ExternalAppMiddleware::class
    ]);
    Route::post('/get-access-token', [\app\api\controller\agent\IndexController::class, 'getAccessToken']);
});

Route::post('/test', [\app\api\controller\v1\IndexController::class, 'test']);
Route::post('/line-redirect', [\app\api\controller\external\LineApiController::class, 'lineRedirect']);
// 玩家绑定购宝钱包
Route::post('/callback-fast-bind', [\app\api\controller\external\ExternalApiController::class, 'callbackFastBind']);
Route::post('/callback-withdraw', [\app\api\controller\external\ExternalApiController::class, 'callbackWithdraw']);
//eh支付回调
Route::post('/eh-callback-deposit', [\app\api\controller\external\ExternalApiController::class, 'ehCallbackDeposit']);
Route::post('/eh-callback-withdraws', [\app\api\controller\external\ExternalApiController::class, 'ehCallbackWithdraws']);

Route::disableDefaultRoute();




