<?php
$content = file_get_contents('src/Effect/Aff.php');
$content = str_replace(
    'if (strpos($e->getMessage(), \'Object of class stdClass\') !== false) {',
    "if (\$e instanceof \\FiberError) { echo \"\\n\\n!!! FIBER ERROR INSIDE FIBER:\\n\" . \$e->getMessage() . \"\\n\" . \$e->getTraceAsString() . \"\\n\\n\"; }\n            if (strpos(\$e->getMessage(), 'Object of class stdClass') !== false) {",
    $content
);
file_put_contents('src/Effect/Aff.php', $content);
