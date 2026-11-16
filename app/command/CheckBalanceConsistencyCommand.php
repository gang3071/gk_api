<?php

declare(strict_types=1);

namespace app\command;

use app\model\PlayerDeliveryRecord;
use app\model\PlayGameRecord;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 余额一致性检查指令
 *
 * 三项检查：
 * [1] 账变记录内部一致性：amount_before + amount ≈ amount_after
 * [2] 相邻账变连续性：records[n].amount_after ≈ records[n+1].amount_before（核心——检测隐性余额扣减）
 * [3] 电子游戏账变与游戏记录 diff 交叉比对
 */
class CheckBalanceConsistencyCommand extends Command
{
    protected static $defaultName        = 'check:balance-consistency';
    protected static $defaultDescription = '对比账变记录余额变动与电子游戏记录，检测异常余额扣减';

    /** 单一钱包游戏相关账变类型（下注/取消/打赏/结算/重结算） */
    private const GAME_DELIVERY_TYPES = [
        PlayerDeliveryRecord::TYPE_BET,
        PlayerDeliveryRecord::TYPE_CANCEL_BET,
        PlayerDeliveryRecord::TYPE_GIFT,
        PlayerDeliveryRecord::TYPE_SETTLEMENT,
        PlayerDeliveryRecord::TYPE_RE_SETTLEMENT,
    ];

    protected function configure(): void
    {
        $this
            ->addOption('player-id', 'p', InputOption::VALUE_OPTIONAL, '指定玩家 ID（不填则按日期全量扫描）')
            ->addOption('date',      'd', InputOption::VALUE_OPTIONAL, '检查日期 YYYY-MM-DD（默认昨天）')
            ->addOption('start',     's', InputOption::VALUE_OPTIONAL, '开始时间 YYYY-MM-DD HH:mm:ss（与 --end 配合使用）')
            ->addOption('end',       'e', InputOption::VALUE_OPTIONAL, '结束时间 YYYY-MM-DD HH:mm:ss')
            ->addOption('tolerance', 't', InputOption::VALUE_OPTIONAL, '金额误差容忍值（默认 0.0001）', '0.0001')
            ->addOption('limit',     'l', InputOption::VALUE_OPTIONAL, '连续性检查最多扫描多少名玩家（默认 500）', '500')
            ->addOption('skip-game', null, InputOption::VALUE_NONE,    '跳过电子游戏交叉比对（检查[3]）');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tolerance = (float) $input->getOption('tolerance');
        $limit     = (int)   $input->getOption('limit');
        $playerId  = $input->getOption('player-id');
        $skipGame  = (bool)  $input->getOption('skip-game');

        [$start, $end, $label] = $this->resolveTimeRange($input);

        $output->writeln("<info>===== 余额一致性检查 [{$label}] 容忍误差={$tolerance} =====</info>");

        $totalErrors  = 0;
        $totalErrors += $this->checkDeliveryInternal($output, $playerId, $start, $end, $tolerance);
        $totalErrors += $this->checkDeliverySequential($output, $playerId, $start, $end, $tolerance, $limit);

        if (!$skipGame) {
            $totalErrors += $this->checkGameVsDelivery($output, $playerId, $start, $end, $tolerance);
        }

        $output->writeln('');
        if ($totalErrors === 0) {
            $output->writeln('<info>===== 全部检查通过，未发现异常 =====</info>');
        } else {
            $output->writeln("<error>===== 共发现 {$totalErrors} 处异常，请人工核查 =====</error>");
        }

        return $totalErrors > 0 ? self::FAILURE : self::SUCCESS;
    }

    // ─────────────────────────────────────────────────────────────────
    // [1] 账变记录内部一致性：amount_before + amount ≈ amount_after
    // ─────────────────────────────────────────────────────────────────
    private function checkDeliveryInternal(
        OutputInterface $output,
        ?string $playerId,
        string $start,
        string $end,
        float $tolerance
    ): int {
        $output->writeln("\n<comment>[1] 账变记录内部一致性（变更前 + 变更额 ≈ 变更后）</comment>");

        $rows = [];

        PlayerDeliveryRecord::query()
            ->whereBetween('created_at', [$start, $end])
            ->when($playerId, fn ($q) => $q->where('player_id', $playerId))
            ->orderBy('id')
            ->chunk(2000, function ($records) use (&$rows, $tolerance) {
                foreach ($records as $r) {
                    $expected = round((float) $r->amount_before + (float) $r->amount, 6);
                    $actual   = round((float) $r->amount_after, 6);
                    $diff     = $actual - $expected;

                    if (abs($diff) > $tolerance) {
                        $rows[] = [
                            $r->id,
                            $r->player_id,
                            $r->type,
                            number_format((float) $r->amount_before, 4),
                            number_format((float) $r->amount,        4),
                            number_format((float) $r->amount_after,  4),
                            sprintf('%+.6f', $diff),
                            $r->created_at,
                        ];
                    }
                }
            });

        if (empty($rows)) {
            $output->writeln('  <info>✓ 全部通过</info>');
            return 0;
        }

        (new Table($output))
            ->setHeaders(['记录ID', '玩家ID', '类型', '变更前', '变更额', '变更后', '差异', '时间'])
            ->setRows($rows)
            ->render();

        $count = count($rows);
        $output->writeln("  <error>✗ 发现 {$count} 条内部不一致</error>");
        return $count;
    }

    // ─────────────────────────────────────────────────────────────────
    // [2] 相邻账变连续性（核心检查）
    // 同一玩家：records[n].amount_after ≈ records[n+1].amount_before
    // 差值存在 → 可能存在未记录的隐性余额扣减
    // ─────────────────────────────────────────────────────────────────
    private function checkDeliverySequential(
        OutputInterface $output,
        ?string $playerId,
        string $start,
        string $end,
        float $tolerance,
        int $limit
    ): int {
        $output->writeln("\n<comment>[2] 账变连续性（相邻记录：前条变更后 ≈ 后条变更前，差值疑为隐性扣减）</comment>");

        $pids = PlayerDeliveryRecord::query()
            ->whereBetween('created_at', [$start, $end])
            ->when($playerId, fn ($q) => $q->where('player_id', $playerId))
            ->groupBy('player_id')
            ->limit($limit)
            ->pluck('player_id');

        if ($pids->isEmpty()) {
            $output->writeln('  <info>该时间段无账变记录</info>');
            return 0;
        }

        $rows    = [];
        $checked = 0;

        foreach ($pids as $pid) {
            // 取截止 $end 最近 1000 条，含区间前的最后一条，以便跨段检查第一条是否连续
            $records = PlayerDeliveryRecord::query()
                ->where('player_id', $pid)
                ->where('created_at', '<=', $end)
                ->orderBy('id', 'desc')
                ->limit(1000)
                ->get(['id', 'type', 'amount_before', 'amount_after', 'created_at'])
                ->reverse()
                ->values();

            $checked++;
            $prev = null;

            foreach ($records as $r) {
                if ($prev !== null && $r->created_at >= $start) {
                    $gap = round((float) $r->amount_before - (float) $prev->amount_after, 6);

                    if (abs($gap) > $tolerance) {
                        $rows[] = [
                            "#{$prev->id}→#{$r->id}",
                            $pid,
                            "type={$prev->type}",
                            "type={$r->type}",
                            number_format((float) $prev->amount_after, 4),
                            number_format((float) $r->amount_before,  4),
                            sprintf('%+.4f', $gap),
                            $prev->created_at,
                            $r->created_at,
                        ];
                    }
                }
                $prev = $r;
            }
        }

        $output->writeln("  共检查 {$checked} 名玩家");

        if (empty($rows)) {
            $output->writeln('  <info>✓ 全部通过，未发现连续性断裂</info>');
            return 0;
        }

        (new Table($output))
            ->setHeaders(['记录区间', '玩家ID', '前条类型', '后条类型', '前条变更后', '后条变更前', '差额（疑似扣减）', '前条时间', '后条时间'])
            ->setRows($rows)
            ->render();

        $count = count($rows);
        $output->writeln("  <error>✗ 发现 {$count} 处连续性断裂（疑似存在未记录的余额扣减）</error>");
        return $count;
    }

    // ─────────────────────────────────────────────────────────────────
    // [3] 电子游戏账变与游戏记录 diff 交叉比对
    // 游戏账变合计（type 26/27/28/29/30）≈ PlayGameRecord.diff 合计
    // ─────────────────────────────────────────────────────────────────
    private function checkGameVsDelivery(
        OutputInterface $output,
        ?string $playerId,
        string $start,
        string $end,
        float $tolerance
    ): int {
        $output->writeln("\n<comment>[3] 电子游戏账变（下注/结算）与游戏记录 diff 交叉比对</comment>");

        // 按玩家聚合游戏账变金额
        $deliveryStats = PlayerDeliveryRecord::query()
            ->whereBetween('created_at', [$start, $end])
            ->whereIn('type', self::GAME_DELIVERY_TYPES)
            ->when($playerId, fn ($q) => $q->where('player_id', $playerId))
            ->selectRaw('player_id, SUM(amount) as delivery_total, COUNT(*) as delivery_count')
            ->groupBy('player_id')
            ->get()
            ->keyBy('player_id');

        // 按玩家聚合已结算游戏记录 diff
        $gameStats = PlayGameRecord::query()
            ->whereBetween('created_at', [$start, $end])
            ->where('settlement_status', PlayGameRecord::SETTLEMENT_STATUS_SETTLED)
            ->when($playerId, fn ($q) => $q->where('player_id', $playerId))
            ->selectRaw('player_id, SUM(diff) as game_total, COUNT(*) as game_count')
            ->groupBy('player_id')
            ->get()
            ->keyBy('player_id');

        $allPids = $deliveryStats->keys()->merge($gameStats->keys())->unique();

        if ($allPids->isEmpty()) {
            $output->writeln('  <info>该时间段无电子游戏记录</info>');
            return 0;
        }

        $rows = [];

        foreach ($allPids as $pid) {
            $deliveryTotal = round((float) ($deliveryStats->get($pid)?->delivery_total ?? 0), 4);
            $gameTotal     = round((float) ($gameStats->get($pid)?->game_total ?? 0), 4);
            $diff          = $deliveryTotal - $gameTotal;

            if (abs($diff) > $tolerance) {
                $rows[] = [
                    $pid,
                    $deliveryStats->get($pid)?->delivery_count ?? 0,
                    $gameStats->get($pid)?->game_count ?? 0,
                    number_format($deliveryTotal, 4),
                    number_format($gameTotal,     4),
                    sprintf('%+.4f', $diff),
                ];
            }
        }

        $output->writeln("  共对比 {$allPids->count()} 名玩家");

        if (empty($rows)) {
            $output->writeln('  <info>✓ 全部通过，账变与游戏记录一致</info>');
            return 0;
        }

        (new Table($output))
            ->setHeaders(['玩家ID', '账变条数', '游戏局数', '账变合计', '游戏diff合计', '差额'])
            ->setRows($rows)
            ->render();

        $count = count($rows);
        $output->writeln("  <error>✗ 发现 {$count} 名玩家账变与游戏记录不一致</error>");
        return $count;
    }

    // ─────────────────────────────────────────────────────────────────
    // 解析时间范围：优先 --start/--end，其次 --date，默认昨天
    // ─────────────────────────────────────────────────────────────────
    private function resolveTimeRange(InputInterface $input): array
    {
        $startOpt = $input->getOption('start');
        $endOpt   = $input->getOption('end');

        if ($startOpt && $endOpt) {
            return [$startOpt, $endOpt, "{$startOpt} ~ {$endOpt}"];
        }

        $date  = $input->getOption('date') ?: date('Y-m-d', strtotime('-1 day'));
        $start = $date . ' 00:00:00';
        $end   = $date . ' 23:59:59';

        return [$start, $end, $date];
    }
}
