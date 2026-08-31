<?php

namespace app\api\controller\v1;

use app\exception\PlayerCheckException;
use app\model\Activity;
use app\model\ActivityContent;
use app\model\Notice;
use app\model\PlayerActivityPhaseRecord;
use app\model\SystemSetting;
use Illuminate\Support\Str;
use Respect\Validation\Exceptions\AllOfException;
use Respect\Validation\Validator as v;
use support\Db;
use support\Request;
use support\Response;
use think\Exception;
use Webman\Push\PushException;
use Webman\RateLimiter\Annotation\RateLimiter;

class ActivityController
{
    use \support\IdempotentTrait;

    /** 排除  */
    protected $noNeedSign = [];
    
    #[RateLimiter(limit: 5)]
    /**
     * 活动列表
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException
     */
    public function activityList(Request $request): Response
    {
        /** @var SystemSetting $setting */
        $setting = SystemSetting::query()->where('feature', 'activity_open')->first();
        if ($setting->status == 0) {
            return jsonFailResponse(trans('activity_off', [], 'message'));
        }
        $player = checkPlayer();
        $data = $request->all();
        $validator = v::key('page', v::intVal()->setName(trans('page', [], 'message')), false)
            ->key('size', v::intVal()->setName(trans('size', [], 'message')), false);

        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        $list = Activity::query()
            ->whereJsonContains('department_id', $player->department_id)
            ->where('status', 1)
            ->orderBy('sort', 'asc')
            ->whereNull('deleted_at')
            ->forPage($data['page'] ?? 1, $data['size'] ?? 20)
            ->get();
        $lang = locale() ?? 'zh_TW';
        $lang = Str::replace('_', '-', $lang);
        $activityList = [];
        /** @var Activity $activity */
        foreach ($list as $activity) {
            /** @var ActivityContent $activityContent */
            $activityContent = $activity->activity_content->where('lang', $lang)->first();
            $activityList[] = [
                'id' => $activity->id,
                'start_time' => $activity->start_time,
                'end_time' => $activity->end_time,
                'name' => $activityContent->name ?? '',
                'lang' => $activityContent->lang ?? '',
                'picture' => $activityContent->picture ?? '',
            ];
        }

        return jsonSuccessResponse('success', $activityList);
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 活动详情
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public function activityInfo(Request $request): Response
    {
        /** @var SystemSetting $setting */
        $setting = SystemSetting::query()->where('feature', 'activity_open')->first();
        if ($setting->status == 0) {
            return jsonFailResponse(trans('activity_off', [], 'message'));
        }
        $player = checkPlayer();
        $data = $request->all();
        $validator = v::key('activity_id', v::intVal()->setName(trans('activity_id', [], 'message')));

        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        /** @var Activity $activity */
        $activity = Activity::query()
            ->whereJsonContains('department_id', $player->department_id)
            ->where('status', 1)
            ->whereNull('deleted_at')
            ->where('id', $data['activity_id'])
            ->first();
        if (empty($activity)) {
            return jsonFailResponse(trans('activity_not_found', [], 'message'));
        }
        $lang = locale() ?? 'zh_TW';
        $lang = Str::replace('_', '-', $lang);
        /** @var ActivityContent $activityContent */
        $activityContent = $activity->activity_content->where('lang', $lang)->first();
        $activityInfo = [
            'id' => $activity->id,
            'start_time' => $activity->start_time,
            'end_time' => $activity->end_time,
            'name' => $activityContent->name,
            'lang' => $activityContent->lang,
            'picture' => $activityContent->picture,
            'description' => $activityContent->description,
            'join_condition' => $activityContent->join_condition,
            'get_way' => $activityContent->get_way,
            'activity_phase' => $activity->activity_phase->makeHidden(['notice', 'sort', 'created_at', 'updated_at']),
        ];

        return jsonSuccessResponse('success', $activityInfo);
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 领取奖励
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|PushException|Exception
     */
    public function receiveAward(Request $request): Response
    {
        $player = checkPlayer();
        $data = $request->all();

        // ==================== 阶段1：占位前检查（参数验证） ====================
        $validator = v::key('id', v::intVal()->setName(trans('player_activity_phase_record_id', [], 'message')));

        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }

        // ==================== 阶段2：幂等性处理 ====================
        $requestId = $data['request_id'] ?? null;
        $idempotentResponse = $this->checkIdempotent($requestId, 'receive-activity-award:' . ($data['id'] ?? ''), $player->id);
        if ($idempotentResponse !== null) {
            return $idempotentResponse;
        }

        if (!$this->reserveIdempotent($requestId, 'receive-activity-award:' . ($data['id'] ?? ''), $player->id)) {
            $response = $this->checkIdempotent($requestId, 'receive-activity-award:' . ($data['id'] ?? ''), $player->id);
            return $response ?? jsonFailResponse(trans('request_processing', [], 'message'));
        }

        // ==================== 阶段3：占位后检查（需要查库，失败需释放占位） ====================
        /** @var SystemSetting $setting */
        $setting = SystemSetting::where('feature', 'activity_open')->first();
        if ($setting->status == 0) {
            $this->releaseIdempotent($requestId);
            return jsonFailResponse(trans('activity_off', [], 'message'));
        }

        /** @var PlayerActivityPhaseRecord $playerActivityPhaseRecord */
        $playerActivityPhaseRecord = PlayerActivityPhaseRecord::query()->where('player_id',
            $player->id)->find($data['id']);

        if (empty($playerActivityPhaseRecord)) {
            $this->releaseIdempotent($requestId);
            return jsonFailResponse(trans('player_activity_phase_record_not_found', [], 'message'));
        }

        if ($playerActivityPhaseRecord->status == PlayerActivityPhaseRecord::STATUS_RECEIVED) {
            $this->releaseIdempotent($requestId);
            return jsonFailResponse(trans('player_activity_phase_record_has_received', [], 'message'));
        }
        if ($playerActivityPhaseRecord->status == PlayerActivityPhaseRecord::STATUS_COMPLETE) {
            $this->releaseIdempotent($requestId);
            return jsonFailResponse(trans('player_activity_phase_record_has_complete', [], 'message'));
        }

        Db::beginTransaction();
        try {
            $playerActivityPhaseRecord->status = PlayerActivityPhaseRecord::STATUS_RECEIVED;
            $playerActivityPhaseRecord->save();
            /** @var ActivityContent $activityContent */
            $activityContent = $playerActivityPhaseRecord->activity->activity_content()
                ->where('lang', $playerActivityPhaseRecord->player->channel->lang)
                ->first();
            $content = '活動獎勵待稽核，玩家' . (empty($playerActivityPhaseRecord->player->name) ? $playerActivityPhaseRecord->player->name : $playerActivityPhaseRecord->player->phone);
            $content .= ', 在機台: ' . $playerActivityPhaseRecord->machine->code;
            $content .= ' 達成活動: ' . ($activityContent->name ? $activityContent->name : '') . '的獎勵要求';
            $content .= ' 獎勵遊戲點: ' . $playerActivityPhaseRecord->bonus . '.';
            $notice = new Notice();
            $notice->department_id = $playerActivityPhaseRecord->player->department_id;
            $notice->player_id = $playerActivityPhaseRecord->player_id;
            $notice->source_id = $playerActivityPhaseRecord->id;
            $notice->type = Notice::TYPE_EXAMINE_ACTIVITY;
            $notice->receiver = Notice::RECEIVER_ADMIN;
            $notice->is_private = 0;
            $notice->title = '活動獎勵待稽核';
            $notice->content = $content;
            $notice->save();

            Db::commit();

            // 事务提交后发送消息（避免事务回滚时消息已发送）
            // 发送总站领取消息
            sendSocketMessage('private-admin_group-admin-1', [
                'msg_type' => 'player_examine_activity_bonus',
                'id' => $playerActivityPhaseRecord->id,
                'player_id' => $player->id,
            ]);
            // 发送子站领取消息
            sendSocketMessage('private-admin_group-channel-' . $player->department_id, [
                'msg_type' => 'player_examine_activity_bonus',
                'id' => $playerActivityPhaseRecord->id,
                'player_id' => $player->id,
            ]);

            // 保存幂等性记录（覆盖占位）
            $response = jsonSuccessResponse('success', [
                'bonus' => $playerActivityPhaseRecord->bonus,
                'condition' => $playerActivityPhaseRecord->condition,
            ]);
            $this->saveIdempotent($requestId, $response, 'receive-activity-award:' . ($data['id'] ?? ''), $player->id);

            return $response;
        } catch (\Exception $e) {
            Db::rollBack();
            // 释放占位（允许重试）
            $this->releaseIdempotent($requestId);
            return jsonFailResponse(trans('system_error', [], 'message'));
        }
    }
}
