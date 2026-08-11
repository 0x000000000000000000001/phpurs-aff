<?php
$content = file_get_contents('src/Effect/Aff.php');
$content = str_replace(
    '                } catch (\Throwable $err) {',
    '                } catch (\Throwable $err) {
                    echo "\n--- CAUGHT EXCEPTION: " . get_class($err) . " ---\n";',
    $content
);
file_put_contents('src/Effect/Aff.php', $content);
