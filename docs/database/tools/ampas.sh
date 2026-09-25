#!/bin/bash
# Academy Awards Database query tool (see ampas.js). Usage: bash ampas.sh search '{"Nominee":"RCA Sound"}' | bash ampas.sh page '/Search/Nominations?...'
export SPKI=$(openssl x509 -in /root/.ccr/agent-proxy-ca.crt -pubkey -noout | openssl pkey -pubin -outform der | openssl dgst -sha256 -binary | base64)
PATH=/opt/node22/bin:$PATH NODE_PATH=/opt/node22/lib/node_modules exec timeout 180 node /tmp/claude-0/-home-user/cffd3175-765f-5476-bc55-babd0243164e/scratchpad/ampas.js "$@"
