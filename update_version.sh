#!/bin/bash

# Function to increment version
increment_version() {
    local version=$1
    # Split version into array
    IFS='.' read -ra VERSION_PARTS <<< "$version"
    
    # Increment the last number
    VERSION_PARTS[2]=$((VERSION_PARTS[2] + 1))
    
    # Join back with dots
    echo "${VERSION_PARTS[0]}.${VERSION_PARTS[1]}.${VERSION_PARTS[2]}"
}

# Get current version from PHP file
CURRENT_VERSION=$(grep -o 'Version: [0-9]\+\.[0-9]\+\.[0-9]\+' wc-tracking-code-injector/wc-tracking-code-injector.php | cut -d' ' -f2)

# Calculate new version
NEW_VERSION=$(increment_version "$CURRENT_VERSION")

# Update version in PHP file
sed -i '' "s/Version: $CURRENT_VERSION/Version: $NEW_VERSION/" wc-tracking-code-injector/wc-tracking-code-injector.php

# Update version in README.md
sed -i '' "s/Current Version: $CURRENT_VERSION/Current Version: $NEW_VERSION/" README.md

echo "Version updated from $CURRENT_VERSION to $NEW_VERSION"
