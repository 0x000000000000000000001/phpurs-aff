<?php
$content = file_get_contents('src/Effect/Aff.php');
$content = str_replace(
    'return (object)[
                "fiber" => $obj->fiber,
                "supervisor" => $supervisor,
            ];',
    'return (object)[
                "fiber" => $obj,
                "supervisor" => $supervisor,
            ];',
    $content
);
file_put_contents('src/Effect/Aff.php', $content);
