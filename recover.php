<?php
$lines = file('/Users/0x1/.gemini/antigravity-ide/brain/533c36f5-fc3d-48d8-bc8d-b12f30308219/.system_generated/logs/transcript.jsonl');
for ($i = count($lines) - 1; $i >= 0; $i--) {
    if (strpos($lines[$i], '_fork = ') !== false && strpos($lines[$i], 'multi_replace_file_content') !== false) {
        echo $lines[$i] . "\n";
    }
}
