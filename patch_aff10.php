<?php
$content = file_get_contents('src/Effect/Aff.php');
$content = str_replace(
    '                if (isset(PhpursFiberObj::$pendingKills[$fiberId])) {
                    $killErr = PhpursFiberObj::$pendingKills[$fiberId];
                    unset(PhpursFiberObj::$pendingKills[$fiberId]);
                    throw new PhpursAffKillException($killErr);
                }
                
                try {
                    $useResult = phpursRunAffTrampoline($use($resource));',
    '                try {
                    if (isset(PhpursFiberObj::$pendingKills[$fiberId])) {
                        $killErr = PhpursFiberObj::$pendingKills[$fiberId];
                        unset(PhpursFiberObj::$pendingKills[$fiberId]);
                        throw new PhpursAffKillException($killErr);
                    }
                    $useResult = phpursRunAffTrampoline($use($resource));',
    $content
);
file_put_contents('src/Effect/Aff.php', $content);
