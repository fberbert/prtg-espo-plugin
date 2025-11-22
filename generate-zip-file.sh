#!/bin/bash

# which dir the script is in
DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd $DIR
rm prtg-espo-plugin.zip
zip -r prtg-espo-plugin.zip files manifest.json scripts
cd -
