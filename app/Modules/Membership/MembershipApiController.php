<?php

declare(strict_types=1);

namespace Mofu\Modules\Membership;

use Mofu\Core\Http\ApiController;
use Mofu\Core\Http\HttpException;
use Mofu\Core\Security\RateLimiter;
use Mofu\Modules\Growth\LevelService;
use Mofu\Modules\Payment\PaymentService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class MembershipApiController extends ApiController
{
    /** 允许客户端自行登记的任务；其余（登录 / 观看视频 / 投币）由对应服务端行为触发 */
    private const CLIENT_TASKS = ['share'];

    public function __construct(
        private readonly LevelService $levels,
        private readonly TaskService $tasks,
        private readonly QuizService $quiz,
        private readonly RateLimiter $limiter,
        private readonly MembershipService $memberships,
        private readonly PaymentService $payments,
    ) {
    }

    /** 下单开通 / 续期大会员，返回跳转支付地址 */
    public function purchase(Request $request, Response $response): Response
    {
        $user = $this->requireUser($request);
        $userId = (int) $user['id'];
        $plan = (string) ($this->input($request)['plan'] ?? '');
        $plans = $this->memberships->plans();
        $selected = null;

        foreach ($plans as $item) {
            if ($item['key'] === $plan) {
                $selected = $item;
            }
        }

        if ($selected === null) {
            throw HttpException::badRequest('请选择会员套餐');
        }

        $order = $this->payments->create(
            $userId,
            PaymentService::KIND_MEMBERSHIP,
            0,
            (string) $selected['label'],
            (float) $selected['price'],
            ['plan' => $plan],
        );

        return $this->ok($response, [
            'order_no' => (string) $order['order_no'],
            'pay_url' => '/pay/' . (string) $order['order_no'],
        ]);
    }

    public function status(Request $request, Response $response): Response
    {
        $user = $this->requireUser($request);
        $userId = (int) $user['id'];

        return $this->ok($response, [
            'level' => $this->levels->stats($userId),
            'tasks' => $this->tasks->status($userId),
            'quiz' => $this->quiz->status($userId),
        ]);
    }

    /** @param array<string, string> $args */
    public function task(Request $request, Response $response, array $args): Response
    {
        $user = $this->requireUser($request);
        $userId = (int) $user['id'];
        $source = (string) ($args['source'] ?? '');
        $units = (int) (((array) $request->getParsedBody())['units'] ?? 1);

        // 只开放「分享」这类没有更强服务端凭证、但价值有限的任务；
        // 其余任务一律由服务端行为触发，避免登录用户直接 POST 刷取。
        if (!in_array($source, self::CLIENT_TASKS, true)) {
            throw HttpException::badRequest('该任务不支持手动登记');
        }

        if (!$this->limiter->attempt('task:' . $userId . ':' . $source, 10, 300)) {
            throw HttpException::tooManyRequests('操作太频繁，请稍后再试');
        }

        $result = $this->tasks->award($userId, $source, $units);

        return $this->ok($response, $result + [
            'tasks' => $this->tasks->status($userId),
            'level' => $this->levels->stats($userId),
        ]);
    }

    /** 大会员每日加速包：手动领取（+10 XP/天） */
    public function premiumBonus(Request $request, Response $response): Response
    {
        $user = $this->requireUser($request);
        $userId = (int) $user['id'];

        $result = $this->tasks->claimPremiumBonus($userId);

        return $this->ok($response, $result + [
            'tasks' => $this->tasks->status($userId),
            'level' => $this->levels->stats($userId),
        ]);
    }

    public function quizPaper(Request $request, Response $response): Response    {
        $this->requireUser($request);

        $tier = (int) ($request->getQueryParams()['tier'] ?? QuizService::TIER_FORMAL);

        return $this->ok($response, [
            'tier' => $tier,
            'questions' => $this->quiz->paper($tier),
            'pass_score' => $tier === QuizService::TIER_FLASH ? QuizService::FLASH_PASS_SCORE : QuizService::PASS_SCORE,
        ]);
    }

    public function quizSubmit(Request $request, Response $response): Response
    {
        $user = $this->requireUser($request);
        $input = $this->input($request);
        $answers = is_array($input['answers'] ?? null) ? (array) $input['answers'] : [];
        $tier = (int) ($input['tier'] ?? QuizService::TIER_FORMAL);

        $result = $this->quiz->grade((int) $user['id'], $answers, $tier);

        return $this->ok($response, $result);
    }
}
