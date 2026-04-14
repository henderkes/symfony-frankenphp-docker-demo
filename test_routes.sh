#!/usr/bin/env bash
#
# Test script for Symfony Demo routes via FrankenPHP
# Hits ALL public routes. Parallel/fork routes are tested sequentially only
# (concurrent forking crashes FrankenPHP). All other routes are tested at
# concurrency 1 and 20.
#
# Usage:
#   ./test_routes.sh
#
# Environment variables:
#   BASE_URL        Server URL         (default: http://localhost:80)
#   LOCALE          URL locale prefix  (default: en)
#   TOTAL_REQUESTS  Requests per route (default: 20)
#

set -uo pipefail

BASE_URL="${BASE_URL:-http://localhost:80}"
LOCALE="${LOCALE:-en}"
TOTAL_REQUESTS="${TOTAL_REQUESTS:-20}"
TIMEOUT=5
TIMEOUT_HTTP=30  # at-fork-http makes external HTTP calls (~13s per request)

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

# Standard routes — safe to hit concurrently
ROUTES=(
    "/${LOCALE}/"
    "/${LOCALE}/blog/"
    "/${LOCALE}/blog/rss.xml"
    "/${LOCALE}/blog/page/1"
    "/${LOCALE}/blog/page/2"
    "/${LOCALE}/blog/search?q=lorem"
    "/${LOCALE}/blog/posts/ut-suscipit-posuere-justo-at-vulputate"
    "/${LOCALE}/blog/posts/eros-diam-egestas-libero-eu-vulputate-risus"
    "/${LOCALE}/blog/posts/mauris-dapibus-risus-quis-suscipit-vulputate"
    "/${LOCALE}/login"
)

# Parallel/fork routes — only tested sequentially (concurrent forking segfaults FrankenPHP)
FORK_ROUTES=(
    "/${LOCALE}/blog/all"
    "/${LOCALE}/blog/parallel"
    "/${LOCALE}/blog/parallel/2"
    "/${LOCALE}/blog/parallel/4"
    "/${LOCALE}/parallel/test"
    "/${LOCALE}/parallel/runtime"
    "/${LOCALE}/parallel/futures"
    "/${LOCALE}/parallel/fan-out"
    "/${LOCALE}/parallel/at-fork"
    "/${LOCALE}/parallel/at-fork-custom"
    "/${LOCALE}/parallel/at-fork-multi"
    "/${LOCALE}/parallel/at-fork-override"
    "/${LOCALE}/parallel/at-fork-remove"
    "/${LOCALE}/parallel/at-fork-http"
    "/${LOCALE}/parallel/stats-fork"
)

ALL_ROUTES=("${ROUTES[@]}" "${FORK_ROUTES[@]}")

route_timeout() {
    if [[ "$1" == *"at-fork-http"* ]]; then
        echo "$TIMEOUT_HTTP"
    else
        echo "$TIMEOUT"
    fi
}
CONCURRENCY_LEVELS=(1 20)

# Run $TOTAL_REQUESTS against a single route at given concurrency
run_route_load() {
    local route="$1"
    local concurrency="$2"
    local t
    t=$(route_timeout "$route")
    local url="${BASE_URL}${route}"

    local tmpfile
    tmpfile=$(mktemp)
    for ((i = 0; i < TOTAL_REQUESTS; i++)); do
        echo "url = \"${url}\"" >> "$tmpfile"
        echo "-o /dev/null" >> "$tmpfile"
        echo "-s" >> "$tmpfile"
        echo "--max-time ${t}" >> "$tmpfile"
        echo "-w \"%{http_code}\n\"" >> "$tmpfile"
    done

    local start_ts end_ts elapsed_ms results ok_count err_count
    start_ts=$(date +%s%N)
    results=$(curl --parallel --parallel-max "$concurrency" --config "$tmpfile" 2>/dev/null)
    end_ts=$(date +%s%N)
    rm -f "$tmpfile"

    elapsed_ms=$(( (end_ts - start_ts) / 1000000 ))
    ok_count=$(echo "$results" | grep -cE '^[23][0-9]{2}$' || true)
    err_count=$(echo "$results" | grep -cEv '^[23][0-9]{2}$' || true)

    if [[ "$err_count" -eq 0 ]]; then
        echo -e "  ${GREEN}✓${NC} ${route}  ${ok_count}/${TOTAL_REQUESTS} ok  ${elapsed_ms}ms"
    else
        echo -e "  ${RED}✗${NC} ${route}  ${ok_count}/${TOTAL_REQUESTS} ok, ${err_count} errors  ${elapsed_ms}ms"
    fi
}

passed=0
failed=0
errors=()

total_routes=$(( ${#ROUTES[@]} + ${#FORK_ROUTES[@]} ))
echo -e "${CYAN}Testing ${total_routes} routes at ${BASE_URL} (timeout=${TIMEOUT}s)${NC}"
echo -e "${CYAN}  ${#ROUTES[@]} standard routes (concurrency 1 & 20)${NC}"
echo -e "${CYAN}  ${#FORK_ROUTES[@]} fork routes (sequential only)${NC}"
echo ""

# ── Phase 1: Smoke test ALL routes sequentially ─────────────────────────────
echo -e "${CYAN}═══════════════════════════════════════════════════════${NC}"
echo -e "${CYAN}  Phase 1: Smoke Test (sequential, one request each)${NC}"
echo -e "${CYAN}═══════════════════════════════════════════════════════${NC}"

for route in "${ALL_ROUTES[@]}"; do
    t=$(route_timeout "$route")
    start_ts=$(date +%s%N)
    http_code=$(curl -s -o /dev/null -w "%{http_code}" --max-time "$t" --retry 2 --retry-delay 1 "${BASE_URL}${route}")
    end_ts=$(date +%s%N)
    elapsed_ms=$(( (end_ts - start_ts) / 1000000 ))
    if [[ "$http_code" -ge 200 && "$http_code" -lt 400 ]]; then
        echo -e "  ${GREEN}✓${NC} ${http_code}  ${route}  ${elapsed_ms}ms"
        ((passed++))
    else
        echo -e "  ${RED}✗${NC} ${http_code}  ${route}  ${elapsed_ms}ms"
        ((failed++))
        errors+=("${route} → HTTP ${http_code}")
    fi
done

echo ""
echo -e "  Results: ${GREEN}${passed} passed${NC}, ${RED}${failed} failed${NC}"
echo ""

# ── Phase 2: Concurrent load — standard routes only ─────────────────────────
echo -e "${CYAN}═══════════════════════════════════════════════════════${NC}"
echo -e "${CYAN}  Phase 2: Concurrent requests (standard routes)${NC}"
echo -e "${CYAN}═══════════════════════════════════════════════════════${NC}"

for concurrency in "${CONCURRENCY_LEVELS[@]}"; do
    echo ""
    echo -e "${YELLOW}── Concurrency: ${concurrency} (${TOTAL_REQUESTS} requests per route) ──${NC}"

    for route in "${ROUTES[@]}"; do
        run_route_load "$route" "$concurrency"
    done
done

# ── Phase 3: Sequential load — fork routes ──────────────────────────────────
echo ""
echo -e "${CYAN}═══════════════════════════════════════════════════════${NC}"
echo -e "${CYAN}  Phase 3: Sequential requests (fork routes)${NC}"
echo -e "${CYAN}═══════════════════════════════════════════════════════${NC}"
echo ""
echo -e "${YELLOW}── Concurrency: 1 (${TOTAL_REQUESTS} requests per route) ──${NC}"

for route in "${FORK_ROUTES[@]}"; do
    run_route_load "$route" 1
done

# ── Phase 4: Mixed-route concurrent blast (standard routes only) ────────────
echo ""
echo -e "${CYAN}═══════════════════════════════════════════════════════${NC}"
echo -e "${CYAN}  Phase 4: Mixed-route concurrent blast (standard)${NC}"
echo -e "${CYAN}═══════════════════════════════════════════════════════${NC}"

for concurrency in "${CONCURRENCY_LEVELS[@]}"; do
    tmpfile=$(mktemp)
    total=0

    for ((round = 0; round < 3; round++)); do
        for route in "${ROUTES[@]}"; do
            t=$(route_timeout "$route")
            echo "url = \"${BASE_URL}${route}\"" >> "$tmpfile"
            echo "-o /dev/null" >> "$tmpfile"
            echo "-s" >> "$tmpfile"
            echo "--max-time ${t}" >> "$tmpfile"
            echo "-w \"%{http_code}\n\"" >> "$tmpfile"
            ((total++))
        done
    done

    start_ts=$(date +%s%N)
    results=$(curl --parallel --parallel-max "$concurrency" --config "$tmpfile" 2>/dev/null)
    end_ts=$(date +%s%N)
    rm -f "$tmpfile"

    elapsed_ms=$(( (end_ts - start_ts) / 1000000 ))
    ok_count=$(echo "$results" | grep -cE '^[23][0-9]{2}$' || true)
    err_count=$(echo "$results" | grep -cEv '^[23][0-9]{2}$' || true)
    rps=$(( total * 1000 / (elapsed_ms + 1) ))

    echo -e "  Concurrency ${YELLOW}${concurrency}${NC}: ${total} reqs, ${GREEN}${ok_count} ok${NC}, ${RED}${err_count} err${NC}, ${elapsed_ms}ms total, ~${rps} req/s"
done

# ── Summary ──────────────────────────────────────────────────────────────────
echo ""
echo -e "${CYAN}═══════════════════════════════════════════════════════${NC}"
echo -e "${CYAN}  Summary${NC}"
echo -e "${CYAN}═══════════════════════════════════════════════════════${NC}"

if [[ ${#errors[@]} -eq 0 ]]; then
    echo -e "  ${GREEN}All smoke tests passed!${NC}"
else
    echo -e "  ${RED}Smoke test failures:${NC}"
    for err in "${errors[@]}"; do
        echo -e "    ${RED}•${NC} ${err}"
    done
fi

echo ""
