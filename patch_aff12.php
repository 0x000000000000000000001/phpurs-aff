<?php
$content = file_get_contents('src/Effect/Aff.php');
$content = str_replace(
    'if (!empty(self::$masked[spl_object_id($this->fiber)])) {
                        self::$pendingKills[spl_object_id($this->fiber)] = $error;',
    'if (!empty(self::$masked[spl_object_id($this->fiber)])) {
                        echo "\n--- RECORDING PENDING KILL ---\n";
                        self::$pendingKills[spl_object_id($this->fiber)] = $error;',
    $content
);
$content = str_replace(
    'throw new PhpursAffKillException($killErr);',
    'echo "\n--- THROWING PENDING KILL ---\n";
                        throw new PhpursAffKillException($killErr);',
    $content
);
file_put_contents('src/Effect/Aff.php', $content);
