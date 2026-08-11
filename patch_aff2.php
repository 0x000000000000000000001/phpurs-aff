<?php
$content = file_get_contents('src/Effect/Aff.php');
$content = str_replace(
    '$resource = phpursRunAffTrampoline($acq);',
    "echo \"\\nBracket: Running acq\\n\";\n                    \$resource = phpursRunAffTrampoline(\$acq);\n                    echo \"Bracket: acq done\\n\";",
    $content
);
$content = str_replace(
    '$useResult = phpursRunAffTrampoline($use($resource));',
    "echo \"Bracket: Running use\\n\";\n                    \$useResult = phpursRunAffTrampoline(\$use(\$resource));\n                    echo \"Bracket: use done\\n\";",
    $content
);
$content = str_replace(
    'phpursRunAffTrampoline(($cond->completed)($useResult)($resource));',
    "echo \"Bracket: Running completed\\n\";\n                        phpursRunAffTrampoline((\$cond->completed)(\$useResult)(\$resource));\n                        echo \"Bracket: completed done\\n\";",
    $content
);
file_put_contents('src/Effect/Aff.php', $content);
