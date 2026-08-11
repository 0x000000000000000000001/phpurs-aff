<?php
$content = file_get_contents('src/Effect/Aff.php');
$content = preg_replace(
    '/(echo "\\\\nFATAL ERROR IN FIBER: " \. \$e->getMessage\(\) \. "\\\\n";)/',
    "// $1",
    $content
);
file_put_contents('src/Effect/Aff.php', $content);
