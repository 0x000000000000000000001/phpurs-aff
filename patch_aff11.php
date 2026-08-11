<?php
$content = file_get_contents('src/Effect/Aff.php');
$content = str_replace(
    'phpursRunAffTrampoline(($cond->killed)($err->error)($resource));',
    'echo "\n--- KILLED HANDLER RUNNING ---\n";
                            phpursRunAffTrampoline(($cond->killed)($err->error)($resource));
                            echo "\n--- KILLED HANDLER FINISHED ---\n";',
    $content
);
file_put_contents('src/Effect/Aff.php', $content);
