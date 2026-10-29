<?php

declare(strict_types=1);

namespace Mofu\Modules\Membership;

use Mofu\Core\Database\Query;
use Mofu\Core\Http\HttpException;
use Mofu\Core\Security\RateLimiter;
use Mofu\Modules\Growth\LevelService;

/**
 * 答题：转正（tier 1，100 题 / 60 分）与满级后的进阶答题（tier 2，20 题 / 80 分）。
 * 题目从对应题库随机抽取，答案只留在服务端，得分由服务端计算。
 */
final class QuizService
{
    public const TIER_FORMAL = 1;
    public const TIER_FLASH = 2;

    public const QUESTION_COUNT = 100;
    public const PASS_SCORE = 60;

    /** 进阶答题（闪电 Lv.6）：题目更少、难度更高、及格线更高 */
    public const FLASH_QUESTION_COUNT = 20;
    public const FLASH_PASS_SCORE = 80;

    /** 题目分类（题库内用于抽题时错开同类题，避免同主题扎堆） */
    public const CATEGORIES = ['地理水文', '历史沿革', '乡镇区划', '名山胜景', '物产特产', '人文典故', '红色记忆', '经济社会'];

    public function __construct(
        private readonly Query $query,
        private readonly LevelService $levels,
        private readonly RateLimiter $limiter,
        private readonly string $bankPath,
        private readonly string $flashBankPath = '',
    ) {
    }

    /** 题库为空时从内置题库文件导入（管理员可再增删改） */
    public function ensureBank(int $tier = self::TIER_FORMAL): void
    {
        $count = (int) $this->query->value('SELECT COUNT(*) FROM quiz_questions WHERE tier = :tier', ['tier' => $tier]);
        $path = $this->bankPathFor($tier);

        if ($count > 0 || !is_file($path)) {
            return;
        }

        $raw = (string) file_get_contents($path);
        $items = json_decode($raw, true);

        if (!is_array($items)) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        $this->query->transaction(function (Query $q) use ($items, $now, $tier): void {
            foreach ($items as $index => $item) {
                $options = $item['options'] ?? [];

                if (!is_array($options) || count($options) < 2) {
                    continue;
                }

                $q->insert('quiz_questions', [
                    'question' => mb_substr((string) ($item['question'] ?? ''), 0, 255),
                    'category' => mb_substr((string) ($item['category'] ?? ''), 0, 32),
                    'options' => (string) json_encode(array_values(array_map('strval', $options)), JSON_UNESCAPED_UNICODE),
                    'answer' => max(0, (int) ($item['answer'] ?? 0)),
                    'active' => 1,
                    'tier' => $tier,
                    'sort' => (int) ($item['sort'] ?? $index + 1),
                    'created_at' => $now,
                ]);
            }
        });
    }

    /** @return array<string, mixed> */
    public function status(int $userId): array
    {
        $this->ensureBank(self::TIER_FORMAL);
        $this->ensureBank(self::TIER_FLASH);

        $stats = $this->levels->stats($userId);
        $isFlash = $this->levels->isFlash($userId);

        return [
            'is_formal' => (bool) $stats['is_formal'],
            'is_flash' => $isFlash,
            'flash_available' => (int) $stats['level'] >= LevelService::MAX_LEVEL,
            'question_count' => self::QUESTION_COUNT,
            'pass_score' => self::PASS_SCORE,
            'attempts' => $this->attemptCount($userId, self::TIER_FORMAL),
            'best_score' => $this->bestScore($userId, self::TIER_FORMAL),
            'questions_available' => $this->availableCount(self::TIER_FORMAL),
            'flash_question_count' => self::FLASH_QUESTION_COUNT,
            'flash_pass_score' => self::FLASH_PASS_SCORE,
            'flash_attempts' => $this->attemptCount($userId, self::TIER_FLASH),
            'flash_best_score' => $this->bestScore($userId, self::TIER_FLASH),
            'flash_questions_available' => $this->availableCount(self::TIER_FLASH),
        ];
    }

    /**
     * 抽一份试卷（不含答案）。
     *
     * 按分类分组后轮流取：分类顺序与组内顺序都随机，但相邻题目尽量不同主题。
     *
     * @return list<array{id: int, question: string, options: list<string>}>
     */
    public function paper(int $tier = self::TIER_FORMAL): array
    {
        $this->ensureBank($tier);

        $limit = $this->questionCount($tier);
        $rows = $this->query->all(
            'SELECT id, question, options, category FROM quiz_questions WHERE active = 1 AND tier = :tier',
            ['tier' => $tier],
        );

        if ($rows === []) {
            throw HttpException::badRequest('题库还没有题目，请联系管理员');
        }

        $groups = [];

        foreach ($rows as $row) {
            $category = trim((string) ($row['category'] ?? ''));
            $groups[$category === '' ? '其他' : $category][] = $row;
        }

        foreach ($groups as &$group) {
            shuffle($group);
        }
        unset($group);

        $keys = array_keys($groups);
        shuffle($keys);

        $picked = [];

        while (count($picked) < $limit) {
            $advanced = false;

            foreach ($keys as $key) {
                if ($groups[$key] === []) {
                    continue;
                }

                $picked[] = array_shift($groups[$key]);
                $advanced = true;

                if (count($picked) >= $limit) {
                    break;
                }
            }

            if (!$advanced) {
                break;
            }
        }

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'question' => (string) $row['question'],
                'options' => array_values((array) (json_decode((string) $row['options'], true) ?: [])),
            ],
            $picked,
        );
    }

    /**
     * 评分。$answers 形如 [questionId => optionIndex]。
     *
     * @param array<int|string, mixed> $answers
     * @return array<string, mixed>
     */
    public function grade(int $userId, array $answers, int $tier = self::TIER_FORMAL): array
    {
        if ($tier === self::TIER_FLASH && (int) $this->levels->stats($userId)['level'] < LevelService::MAX_LEVEL) {
            throw HttpException::badRequest('满级（Lv.' . LevelService::MAX_LEVEL . '）后才能参加进阶答题');
        }

        if ($answers === []) {
            throw HttpException::badRequest('请先作答');
        }

        if (!$this->limiter->attempt('quiz:' . $tier . ':' . $userId, 3, 3600)) {
            throw HttpException::tooManyRequests('提交太频繁，请稍后再试');
        }

        $ids = [];

        foreach (array_keys($answers) as $key) {
            $id = (int) $key;

            if ($id > 0) {
                $ids[] = $id;
            }
        }

        $ids = array_slice(array_values(array_unique($ids)), 0, $this->questionCount($tier));

        if ($ids === []) {
            throw HttpException::badRequest('请先作答');
        }

        // 分母固定为「整份试卷应有的题数」，而不是提交里能查到的题数：
        // 否则只提交 1 道答对的题就能得 100 分（转正绕过）。
        $bank = $this->query->all('SELECT id, answer FROM quiz_questions WHERE active = 1 AND tier = :tier', ['tier' => $tier]);

        if ($bank === []) {
            throw HttpException::badRequest('题库还没有题目，请联系管理员');
        }

        $correctAnswers = [];

        foreach ($bank as $row) {
            $correctAnswers[(int) $row['id']] = (int) $row['answer'];
        }

        $total = min($this->questionCount($tier), count($bank));
        $correct = 0;
        $wrong = [];

        foreach ($ids as $id) {
            if (!isset($correctAnswers[$id])) {
                continue;
            }

            $given = (int) ($answers[$id] ?? $answers[(string) $id] ?? -1);

            if ($given === $correctAnswers[$id]) {
                $correct++;
            } else {
                $wrong[] = $id;
            }
        }

        $passScore = $this->passScore($tier);
        $score = (int) round($correct / $total * 100);
        $passed = $score >= $passScore;

        $this->query->insert('quiz_attempts', [
            'user_id' => $userId,
            'tier' => $tier,
            'score' => $score,
            'total' => $total,
            'passed' => $passed ? 1 : 0,
            'wrong_ids' => (string) json_encode($wrong),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        if ($passed) {
            if ($tier === self::TIER_FLASH) {
                if (!$this->levels->isFlash($userId)) {
                    $this->levels->markFlash($userId);
                }
            } elseif (!$this->levels->isFormal($userId)) {
                $this->levels->markFormal($userId);
            }
        }

        return [
            'tier' => $tier,
            'score' => $score,
            'correct' => $correct,
            'total' => $total,
            'passed' => $passed,
            'pass_score' => $passScore,
            'wrong_ids' => $wrong,
            'is_formal' => $this->levels->isFormal($userId),
            'is_flash' => $this->levels->isFlash($userId),
            'level' => $this->levels->stats($userId),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function adminList(int $tier = self::TIER_FORMAL): array
    {
        $this->ensureBank($tier);

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'question' => (string) $row['question'],
                'category' => (string) ($row['category'] ?? ''),
                'options' => array_values((array) (json_decode((string) $row['options'], true) ?: [])),
                'answer' => (int) $row['answer'],
                'active' => (int) $row['active'] === 1,
                'sort' => (int) $row['sort'],
            ],
            $this->query->all('SELECT * FROM quiz_questions WHERE tier = :tier ORDER BY sort ASC, id ASC LIMIT 500', ['tier' => $tier]),
        );
    }

    /** 题库文件：转正与进阶各自一份 */
    private function bankPathFor(int $tier): string
    {
        return $tier === self::TIER_FLASH ? $this->flashBankPath : $this->bankPath;
    }

    private function questionCount(int $tier): int
    {
        return $tier === self::TIER_FLASH ? self::FLASH_QUESTION_COUNT : self::QUESTION_COUNT;
    }

    private function passScore(int $tier): int
    {
        return $tier === self::TIER_FLASH ? self::FLASH_PASS_SCORE : self::PASS_SCORE;
    }

    private function attemptCount(int $userId, int $tier): int
    {
        return (int) $this->query->value(
            'SELECT COUNT(*) FROM quiz_attempts WHERE user_id = :user_id AND tier = :tier',
            ['user_id' => $userId, 'tier' => $tier],
        );
    }

    private function bestScore(int $userId, int $tier): int
    {
        return (int) $this->query->value(
            'SELECT COALESCE(MAX(score), 0) FROM quiz_attempts WHERE user_id = :user_id AND tier = :tier',
            ['user_id' => $userId, 'tier' => $tier],
        );
    }

    private function availableCount(int $tier): int
    {
        return (int) $this->query->value(
            'SELECT COUNT(*) FROM quiz_questions WHERE active = 1 AND tier = :tier',
            ['tier' => $tier],
        );
    }

    /** @param array<string, mixed> $data */
    public function saveQuestion(?int $id, array $data, int $tier = self::TIER_FORMAL): int
    {
        $tier = $tier === self::TIER_FLASH ? self::TIER_FLASH : self::TIER_FORMAL;
        $question = trim((string) ($data['question'] ?? ''));
        $options = array_values(array_filter(array_map(
            static fn (mixed $option): string => trim((string) $option),
            (array) ($data['options'] ?? []),
        ), static fn (string $option): bool => $option !== ''));

        if ($question === '' || count($options) < 2) {
            throw HttpException::badRequest('请填写题干与至少两个选项');
        }

        $payload = [
            'question' => mb_substr($question, 0, 255),
            'category' => mb_substr(trim((string) ($data['category'] ?? '')), 0, 32),
            'options' => (string) json_encode($options, JSON_UNESCAPED_UNICODE),
            'answer' => max(0, min(count($options) - 1, (int) ($data['answer'] ?? 0))),
            'active' => array_key_exists('active', $data) && !$data['active'] ? 0 : 1,
            'sort' => (int) ($data['sort'] ?? 0),
        ];

        if ($id !== null && $id > 0) {
            $this->query->update('quiz_questions', $payload, 'id = :id', ['id' => $id]);

            return $id;
        }

        return $this->query->insert('quiz_questions', $payload + [
            'tier' => $tier,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function deleteQuestion(int $id): void
    {
        $this->query->delete('quiz_questions', 'id = :id', ['id' => $id]);
    }
}
