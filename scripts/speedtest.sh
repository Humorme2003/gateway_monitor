#!/bin/bash

# Configuration
API_URL="http://your-server-ip/gateway_monitor/api" # URL to your central server's api folder (without trailing slash)
API_KEY="key_wan1"                                  # Unique API Key from the "Manage Gateways" dashboard
TARGET="8.8.8.8"

# Check if speedtest is installed
if ! command -v speedtest &> /dev/null; then
    echo "Ookla Speedtest CLI could not be found. Please install it."
    echo "Follow instructions at: https://www.speedtest.net/apps/cli"
    exit 1
fi

# Verify it's the official Ookla version
if ! speedtest --version 2>&1 | grep -q "Ookla"; then
    echo "The 'speedtest' command found is not the official Ookla CLI."
    echo "This script requires the official binary from speedtest.net."
    echo "Please follow instructions at: https://www.speedtest.net/apps/cli"
    exit 1
fi

# Check for --force flag
FORCE=0
FORCE_PARAM=""
if [[ "$1" == "--force" ]]; then
    FORCE=1
    FORCE_PARAM="&force=1"
    echo "Force mode enabled. Bypassing interval check."
fi

# Create a unique temporary file for this run to avoid permission conflicts in /tmp
RESULT_FILE=$(mktemp /tmp/speedtest_result_XXXXXX.json)
PAYLOAD_FILE=$(mktemp /tmp/speedtest_payload_XXXXXX.json)

# Safety net: Reset testing flag and cleanup temp files on exit
trap 'rm -f "$RESULT_FILE" "$PAYLOAD_FILE"; curl -s -X POST "$API_URL/speedtest_control.php?action=stop&api_key=$API_KEY"; echo' EXIT

# 1. Signal Start
echo "Signaling start of speed test..."
START_RESPONSE=$(curl -s -X POST "$API_URL/speedtest_control.php?action=start&api_key=$API_KEY$FORCE_PARAM")

# Check if we are allowed to proceed
if [[ "$START_RESPONSE" != *"\"success\":true"* ]]; then
    echo "Speedtest not due or not authorized. API Response: $START_RESPONSE"
    # Exit without error to avoid cron mail spam, unless it's a real error
    if [[ "$START_RESPONSE" == *"Too soon"* ]]; then
        exit 0
    fi
    exit 1
fi

echo "Response: $START_RESPONSE"

# Extract server ID if provided (using more robust extraction)
SERVER_ID=$(echo "$START_RESPONSE" | sed -n 's/.*"server_id":"\([^"]*\)".*/\1/p')
SERVER_PARAM=""
if [[ -n "$SERVER_ID" ]]; then
    SERVER_PARAM="-s $SERVER_ID"
    echo "Using preferred server: $SERVER_ID"
fi

# Add a small random jitter (0-30s) to avoid synchronized spikes if multiple nodes run at once
if [ $FORCE -eq 0 ]; then
    JITTER=$(( RANDOM % 31 ))
    if [ $JITTER -gt 0 ]; then
        echo "Sleeping for ${JITTER}s jitter..."
        sleep $JITTER
    fi
fi

# 2. Run Speedtest
echo "Running Ookla Speedtest..."
# Using timeout to prevent hanging
speedtest $SERVER_PARAM -f json --accept-license --accept-gdpr > "$RESULT_FILE"

if [ $? -eq 0 ]; then
    echo "Speedtest completed. Ingesting data..."
    
    # 3. Ingest Data
    JSON_DATA=$(cat "$RESULT_FILE")
    
    # Prepare ingestion payload using a temporary file to avoid shell expansion issues
    if [ $FORCE -eq 1 ]; then
        cat <<EOF > "$PAYLOAD_FILE"
{
    "api_key": "$API_KEY",
    "force": true,
    "speedtest_data": $JSON_DATA
}
EOF
    else
        cat <<EOF > "$PAYLOAD_FILE"
{
    "api_key": "$API_KEY",
    "speedtest_data": $JSON_DATA
}
EOF
    fi

    RESPONSE=$(curl -s -H "Content-Type: application/json" -X POST --data-binary @"$PAYLOAD_FILE" "$API_URL/ingest_speedtest.php")
    
    if [[ "$RESPONSE" == *"\"success\":true"* ]]; then
        echo "Ingestion complete."
    else
        echo "Error ingesting data: $RESPONSE"
        exit 1
    fi
else
    echo "Speedtest failed."
    exit 1
fi

# 4. Signal End (handled by trap)
echo "Resetting testing flag..."
