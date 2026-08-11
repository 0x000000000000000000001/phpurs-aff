#!/bin/bash
set -e
if true; then
    php -r "exit(255);"
fi
echo "SHOULD NOT PRINT"
