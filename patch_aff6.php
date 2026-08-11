<?php
$content = file_get_contents('src/Effect/Aff.php');
$content = str_replace(
    '$obj->finish($Left($e));',
    'if ($e instanceof PhpursAffKillException) { $obj->finish($Left($e->error)); } else { $obj->finish($Left($e)); }',
    $content
);
file_put_contents('src/Effect/Aff.php', $content);
