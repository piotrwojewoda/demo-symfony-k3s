#!/usr/bin/env bash
# Test suite declaration required by the malina CI/CD platform.
# The pipeline fails the release when this script is missing or exits non-zero,
# and expects the JUnit report at build/junit.xml afterwards.
# Contract: infr-as-a-code/specs/002-tests-before-deploy/contracts/test-suite.md
set -euo pipefail

mkdir -p build
vendor/bin/phpunit --log-junit build/junit.xml
