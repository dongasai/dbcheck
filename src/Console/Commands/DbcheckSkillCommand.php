<?php

namespace Dongasai\Dbcheck\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * 把 dbcheck 的 agent 引导 skill 注册到项目已有的 agent skill 目录（交互式）。
 *
 * 用法：
 *   php artisan dbcheck:skill                  # 交互：检测已有 skill 目录 → 选择 → 注册
 *   php artisan dbcheck:skill --dir=.codebuddy/skills --dir=.claude/skills
 *   php artisan dbcheck:skill --list           # 只检测并列出，不注册
 *   php artisan dbcheck:skill --force          # 覆盖已存在的 dbcheck skill
 */
class DbcheckSkillCommand extends Command
{
    /**
     * 命令签名。
     *
     * @var string
     */
    protected $signature = 'dbcheck:skill
                            {--dir=* : 目标 agent skill 目录（可多次）}
                            {--list : 只检测并列出已有 skill 目录，不注册}
                            {--force : 覆盖已存在的 dbcheck skill}';

    /**
     * 命令描述。
     *
     * @var string
     */
    protected $description = '把 dbcheck 的 agent 引导 skill 交互式注册到项目已有 agent skill 目录';

    /**
     * skill 目录名（用于探测）。
     *
     * @var array<int, string>
     */
    protected $dirNames = ['skills', 'skill'];

    /**
     * 内置默认候选目录（始终作为候选项，且交互时默认选中第一个）。
     *
     * @var array<int, string>
     */
    protected $defaultDirs = ['.agent/skills'];

    /**
     * 排除的目录。
     *
     * @var array<int, string>
     */
    protected $excluded = ['vendor', 'node_modules', '.git', 'public', 'storage'];

    /**
     * 执行命令。
     */
    public function handle(): int
    {
        $source = realpath(__DIR__ . '/../../../resources/skill/dbcheck');

        if ($source === false || !File::exists($source . '/SKILL.md')) {
            $this->error('未找到 dbcheck skill 源文件: ' . __DIR__ . '/../../../resources/skill/dbcheck');

            return self::FAILURE;
        }

        $given = array_values(array_filter(array_map('strval', (array) $this->option('dir'))));
        $dirs = count($given) > 0 ? $given : $this->candidateDirs();

        if ($this->option('list')) {
            return $this->showList($dirs);
        }

        if (empty($dirs)) {
            $this->warn('未检测到项目已有的 agent skill 目录');
            $answer = (string) $this->ask('请输入要注册到的 skill 目录路径', $this->defaultDirs[0]);
            if ($answer === '') {
                $this->line('已取消');

                return self::SUCCESS;
            }
            $dirs = [$answer];
        } elseif (count($given) === 0) {
            // 未显式指定 --dir 时才交互选择
            $dirs = $this->chooseDirs($dirs);
        }

        $force = (bool) $this->option('force');
        $registered = 0;
        $skipped = 0;

        foreach ($dirs as $dir) {
            $target = $this->register($source, $dir, $force);
            if ($target === null) {
                $skipped++;
                continue;
            }
            $registered++;
            $this->info('已注册: ' . $target);
        }

        if ($registered === 0) {
            $this->line($skipped > 0 ? '全部跳过（如需覆盖请加 --force）' : '未注册任何目录');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('agent 后续编写检查类时，可直接引用该 skill 的命名规则与列定义格式。');

        return self::SUCCESS;
    }

    /**
     * 探测项目已有的 agent skill 目录。
     *
     * 规则：在项目根下查找名为 skills / skill 的目录（排除 vendor、node_modules、.git、public）。
     *
     * @return array<int, string> 相对项目根的路径
     */
    /**
     * 最终候选目录：内置默认目录在前，其后为探测到的目录（去重后排序）。
     *
     * @return array<int, string> 相对项目根的路径
     */
    protected function candidateDirs(): array
    {
        $dirs = array_merge($this->defaultDirs, $this->detectDirs());
        $dirs = array_values(array_unique($dirs));

        return $dirs;
    }

    protected function detectDirs(): array
    {
        $root = rtrim(base_path(), '/\\');
        $source = $this->sourceDir();
        $found = [];
        $queue = [''];
        $depth = 0;

        while (!empty($queue) && $depth < 6) {
            $next = [];
            foreach ($queue as $rel) {
                $abs = $rel === '' ? $root : $root . DIRECTORY_SEPARATOR . $rel;
                if (!is_dir($abs)) {
                    continue;
                }
                foreach (scandir($abs) ?: [] as $entry) {
                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }
                    $childAbs = $abs . DIRECTORY_SEPARATOR . $entry;
                    $childRel = $rel === '' ? $entry : $rel . DIRECTORY_SEPARATOR . $entry;
                    if (!is_dir($childAbs)) {
                        continue;
                    }
                    if (in_array($entry, $this->excluded, true)) {
                        continue;
                    }
                    // 排除包自身的 skill 源目录
                    if ($source !== null && $this->isInside($childAbs, $source)) {
                        continue;
                    }
                    if (in_array($entry, $this->dirNames, true)) {
                        $found[] = $childRel;
                        continue;
                    }
                    $next[] = $childRel;
                }
            }
            $queue = $next;
            $depth++;
        }

        sort($found);

        return $found;
    }

    /**
     * 包内置 skill 源目录（不作为注册目标）。
     */
    protected function sourceDir(): ?string
    {
        $dir = realpath(__DIR__ . '/../../../resources/skill');

        return $dir === false ? null : $dir;
    }

    /**
     * 判断 $path 是否位于 $root 之内。
     */
    protected function isInside(string $path, string $root): bool
    {
        $path = rtrim($path, '/\\');
        $root = rtrim($root, '/\\');

        return $path === $root || strpos($path, $root . DIRECTORY_SEPARATOR) === 0;
    }

    /**
     * 列出检测到的目录。
     *
     * @param array<int, string> $dirs
     */
    protected function showList(array $dirs): int
    {
        if (empty($dirs)) {
            $this->warn('未检测到已有的 agent skill 目录');

            return self::SUCCESS;
        }

        $this->line('agent skill 目录候选:');
        foreach ($dirs as $i => $d) {
            $mark = in_array($d, $this->defaultDirs, true) ? ' [默认]' : '';
            $this->line('  ' . ($i + 1) . '. ' . $d . $mark);
        }

        return self::SUCCESS;
    }

    /**
     * 交互式选择目标目录。
     *
     * @param array<int, string> $dirs
     * @return array<int, string>
     */
    protected function chooseDirs(array $dirs): array
    {
        if (count($dirs) === 1) {
            if ($this->confirm('注册到 ' . $dirs[0] . '？', true)) {
                return [$dirs[0]];
            }

            return [];
        }

        $options = array_merge($dirs, ['全部目录', '自定义路径']);
        $defaultIndex = (int) array_search($this->defaultDirs[0], $options, true);
        if ($defaultIndex === false || $defaultIndex < 0) {
            $defaultIndex = 0;
        }
        $picked = (string) $this->choice('请选择要注册到的 agent skill 目录', $options, $defaultIndex);

        if ($picked === '自定义路径') {
            $answer = (string) $this->ask('请输入 skill 目录路径');

            return $answer === '' ? [] : [$answer];
        }

        if ($picked === '全部目录') {
            return $dirs;
        }

        return [$picked];
    }

    /**
     * 复制 skill 到目标目录，返回目标文件路径（跳过时返回 null）。
     */
    protected function register(string $source, string $dir, bool $force): ?string
    {
        $abs = $this->absolute($dir);
        $targetDir = $abs . DIRECTORY_SEPARATOR . 'dbcheck';
        $target = $targetDir . DIRECTORY_SEPARATOR . 'SKILL.md';

        if (File::exists($target) && !$force) {
            $this->warn('已存在，跳过: ' . $this->display($target));

            return null;
        }

        if (!File::isDirectory($targetDir)) {
            if (!$this->confirm('目录 ' . $this->display($targetDir) . ' 不存在，是否创建？', true)) {
                return null;
            }
            File::makeDirectory($targetDir, 0755, true);
        }

        File::copy($source . DIRECTORY_SEPARATOR . 'SKILL.md', $target);

        return $this->display($target);
    }

    /**
     * 相对路径转绝对路径。
     */
    protected function absolute(string $dir): string
    {
        if ($dir === '') {
            return base_path();
        }

        return preg_match('#^(/|[A-Za-z]:[\\\\/])#', $dir) ? rtrim($dir, '/\\') : base_path($dir);
    }

    /**
     * 展示路径（项目内用相对路径）。
     */
    protected function display(string $abs): string
    {
        $root = rtrim(base_path(), '/\\');
        if (strpos($abs, $root) === 0) {
            return ltrim(substr($abs, strlen($root)), '/\\');
        }

        return $abs;
    }
}
