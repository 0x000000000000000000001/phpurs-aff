<?php
$content = file_get_contents('src/Effect/Aff.php');
$content = str_replace(
    '                    // Completed!
                    try {
                        phpursRunAffTrampoline(($cond->completed)($useResult)($resource));
                    } catch (\Throwable $e) {
                        throw $e;
                    }',
    '                    // Completed!
                    PhpursFiberObj::$masked[$fiberId]++;
                    try {
                        phpursRunAffTrampoline(($cond->completed)($useResult)($resource));
                    } catch (\Throwable $e) {
                        PhpursFiberObj::$masked[$fiberId]--;
                        throw $e;
                    }
                    PhpursFiberObj::$masked[$fiberId]--;',
    $content
);
$content = str_replace(
    '                        // Killed!
                        try {
                            phpursRunAffTrampoline(($cond->killed)($err->error)($resource));
                        } catch (\Throwable $e) {
                            throw $e;
                        }',
    '                        // Killed!
                        PhpursFiberObj::$masked[$fiberId]++;
                        try {
                            phpursRunAffTrampoline(($cond->killed)($err->error)($resource));
                        } catch (\Throwable $e) {
                            PhpursFiberObj::$masked[$fiberId]--;
                            throw $e;
                        }
                        PhpursFiberObj::$masked[$fiberId]--;',
    $content
);
$content = str_replace(
    '                        // Failed!
                        try {
                            phpursRunAffTrampoline(($cond->failed)($err)($resource));
                        } catch (\Throwable $e) {
                            throw $e;
                        }',
    '                        // Failed!
                        PhpursFiberObj::$masked[$fiberId]++;
                        try {
                            phpursRunAffTrampoline(($cond->failed)($err)($resource));
                        } catch (\Throwable $e) {
                            PhpursFiberObj::$masked[$fiberId]--;
                            throw $e;
                        }
                        PhpursFiberObj::$masked[$fiberId]--;',
    $content
);

file_put_contents('src/Effect/Aff.php', $content);
