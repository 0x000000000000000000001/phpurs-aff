<?php
$content = file_get_contents('src/Effect/Aff.php');
$content = str_replace(
    'class PhpursFiberObj {',
    'class PhpursFiberObj {
    public static $masked = [];
    public static $pendingKills = [];',
    $content
);
$content = preg_replace(
    '/if \(\$this->fiber && \$this->fiber->isSuspended\(\)\) \{\s*\\\\Revolt\\\\EventLoop::queue\(function\(\) use\(\$error\) \{\s*if \(\$this->fiber && \$this->fiber->isSuspended\(\)\) \{\s*\$this->fiber->throw\(new PhpursAffKillException\(\$error\)\);\s*\}\s*\}\);\s*\}/',
    'if ($this->fiber && $this->fiber->isSuspended()) {
                    if (!empty(self::$masked[spl_object_id($this->fiber)])) {
                        self::$pendingKills[spl_object_id($this->fiber)] = $error;
                    } else {
                        \Revolt\EventLoop::queue(function() use($error) {
                            if ($this->fiber && $this->fiber->isSuspended()) {
                                $this->fiber->throw(new PhpursAffKillException($error));
                            }
                        });
                    }
                }',
    $content
);

$bracket_replace = '
                $fiberId = spl_object_id(\Fiber::getCurrent());
                if (!isset(PhpursFiberObj::$masked[$fiberId])) PhpursFiberObj::$masked[$fiberId] = 0;
                PhpursFiberObj::$masked[$fiberId]++;
                try {
                    $resource = phpursRunAffTrampoline($acq);
                } catch (\Throwable $e) {
                    PhpursFiberObj::$masked[$fiberId]--;
                    throw $e;
                }
                PhpursFiberObj::$masked[$fiberId]--;
                
                if (isset(PhpursFiberObj::$pendingKills[$fiberId])) {
                    $killErr = PhpursFiberObj::$pendingKills[$fiberId];
                    unset(PhpursFiberObj::$pendingKills[$fiberId]);
                    throw new PhpursAffKillException($killErr);
                }
';

$content = str_replace(
    '                try {
                    $resource = phpursRunAffTrampoline($acq);
                } catch (\Throwable $e) {
                    throw $e;
                }',
    $bracket_replace,
    $content
);

file_put_contents('src/Effect/Aff.php', $content);
