<?php
$content = file_get_contents('src/Effect/Aff.php');
$content = str_replace(
    'echo "\n--- CAUGHT EXCEPTION: " . get_class($err) . " ---\n";',
    'echo "\n--- CAUGHT EXCEPTION: " . get_class($err) . " ---\n";
                    echo "Message: " . $err->getMessage() . "\n";',
    $content
);
file_put_contents('src/Effect/Aff.php', $content);
