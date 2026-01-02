#!/bin/bash

# Configuration
API_URL="http://your-server-ip/gateway_monitor/api" # URL to your central server's api folder
API_KEY="key_wan1"                                  # Unique API Key from the "Manage Gateways" dashboard
TARGET="8.8.8.8"                                    # The destination to monitor latency towards
INTERVAL="0.2"                                      # Interval between mtr packets (0.2s = 5 packets/sec)
CYCLES=250                                          # Number of packets per run (250 = 50 seconds out of 60 per run)

# Check if mtr is installed
if ! command -v mtr &> /dev/null; then
    echo "mtr could not be found. Please install it."
    exit 1
fi

# Create a temporary file for the payload
PAYLOAD_FILE=$(mktemp /tmp/mtr_payload_XXXXXX.json)
trap 'rm -f "$PAYLOAD_FILE"' EXIT

# Run mtr and capture JSON output
# Note: Requires mtr 0.93+ for JSON support.
# We use ICMP (default) for better compatibility without root.
MTR_TEMP_OUT=$(mktemp /tmp/mtr_out_XXXXXX.json)
mtr -i $INTERVAL --report --report-wide --report-cycles $CYCLES --json $TARGET > "$MTR_TEMP_OUT" 2>&1
MTR_EXIT_CODE=$?
MTR_OUTPUT=$(cat "$MTR_TEMP_OUT")
rm -f "$MTR_TEMP_OUT"

if [ $MTR_EXIT_CODE -ne 0 ] || [ -z "$MTR_OUTPUT" ]; then
    echo "Error: mtr failed or returned no data."
    echo "Output: $MTR_OUTPUT"
    echo "Ensure you have mtr 0.93+ installed for JSON support."
    exit 1
fi

# Prepare payload using a temporary file to avoid shell escaping/size issues
cat <<EOF > "$PAYLOAD_FILE"
{
    "api_key": "$API_KEY",
    "target": "$TARGET",
    "mtr_data": $MTR_OUTPUT
}
EOF

# Send to API
RESPONSE=$(curl -s -X POST -H "Content-Type: application/json" --data-binary @"$PAYLOAD_FILE" "$API_URL/ingest.php")

if [[ "$RESPONSE" == *"\"success\":true"* ]]; then
    echo "Data ingested successfully."
else
    echo "Error ingesting data: $RESPONSE"
    exit 1
fi
