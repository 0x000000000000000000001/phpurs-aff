<?php
$content = file_get_contents('src/Effect/Aff.php');
$content = str_replace(
    '$obj->finish($Left($e));',
    "echo \"\\nFATAL ERROR IN FIBER: \" . \$e->getMessage() . \"\\n\";\n                    \$obj->finish(\$Left(\$e));",
    $content
);
file_put_contents('src/Effect/Aff.php', $content);
