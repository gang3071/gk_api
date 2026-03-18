<?php

namespace app\api\controller\external;

use Exception;
use support\Request;
use support\Response;
use Webman\RateLimiter\Annotation\RateLimiter;

class LineApiController
{
    #[RateLimiter(limit: 5)]
    /**
     * LINE回调地址
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function lineRedirect(Request $request): Response
    {
        return jsonSuccessResponse('success');
    }
}
