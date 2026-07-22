#!/usr/bin/env bash
#
# Tier-2 integration verification for the API inventory slice.
#
# Runs the project's locked gate checks and writes a Markdown report to
# .minimax-flow/verification-report.md. Exits non-zero if any check fails.
#
# This script is read-only with respect to the rest of the repository:
# it only writes the verification report. It orchestrates every check via
# subprocess invocation of the canonical tools (make, npm, python3, bash)
# rather than delegating to other shell scripts.
#
# Compatible with bash 3.2+ (macOS default).

set -uo pipefail

readonly SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
readonly REPORT_DIR="${ROOT}/.minimax-flow"
readonly REPORT="${REPORT_DIR}/verification-report.md"

if [[ -t 1 ]]; then
    readonly C_OK=$'\033[32m'
    readonly C_FAIL=$'\033[31m'
    readonly C_RESET=$'\033[0m'
else
    readonly C_OK=""
    readonly C_FAIL=""
    readonly C_RESET=""
fi

mkdir -p "${REPORT_DIR}"

# Truncate previous report so re-runs do not append stale rows.
: > "${REPORT}"

declare -i PASS_COUNT=0
declare -i FAIL_COUNT=0
declare -a ROWS=()

# CAPTURE_RC holds the exit code returned by run_command_capture.
declare -i CAPTURE_RC=0
# CAPTURE_OUT holds the combined stdout/stderr of the last run_command_capture.
CAPTURE_OUT=""

log_row() {
    local check="$1"
    local expected="$2"
    local actual="$3"
    local status="$4"
    local evidence="$5"

    if [[ "${status}" == "PASS" ]]; then
        PASS_COUNT+=1
    else
        FAIL_COUNT+=1
    fi

    local sanitized_evidence="${evidence//|/\\|}"
    local sanitized_actual="${actual//|/\\|}"
    local sanitized_expected="${expected//|/\\|}"

    ROWS+=("| ${check} | ${sanitized_expected} | ${sanitized_actual} | ${status} | \`${sanitized_evidence}\` |")
}

# Run a command capturing stdout+stderr to a file and the exit code in
# CAPTURE_RC / CAPTURE_OUT. Always returns 0; failures are reported through
# CAPTURE_RC.
run_command_capture() {
    local cmd="$1"
    local tmp_out tmp_err
    tmp_out="$(mktemp)"
    tmp_err="$(mktemp)"

    eval "${cmd}" >"${tmp_out}" 2>"${tmp_err}"
    CAPTURE_RC=$?

    CAPTURE_OUT="$(cat "${tmp_out}" "${tmp_err}")"
    CAPTURE_OUT="${CAPTURE_OUT%$'\n'}"

    rm -f "${tmp_out}" "${tmp_err}"
}

check_verify_boundaries() {
    local label="make verify-boundaries"
    local expected="exit=0"
    run_command_capture "cd '${ROOT}' && make verify-boundaries"

    local status actual evidence
    if [[ ${CAPTURE_RC} -eq 0 ]]; then
        status="PASS"
    else
        status="FAIL"
    fi
    actual="exit=${CAPTURE_RC}"
    evidence="${CAPTURE_OUT:0:240}"
    log_row "${label}" "${expected}" "${actual}" "${status}" "${evidence}"
}

check_git_diff_clean() {
    local label="git diff --stat apps/api apps/web"
    local expected="0 changed lines under apps/api apps/web"
    run_command_capture "cd '${ROOT}' && git diff --stat -- apps/api apps/web"

    local status actual evidence diff_output line_count
    diff_output="${CAPTURE_OUT}"
    if [[ -z "${diff_output// /}" ]]; then
        status="PASS"
        actual="0 lines"
    else
        status="FAIL"
        line_count="$(printf '%s\n' "${diff_output}" | wc -l | tr -d ' ')"
        actual="${line_count} line(s) of diff"
    fi
    evidence="${diff_output:0:240}"
    log_row "${label}" "${expected}" "${actual}" "${status}" "${evidence}"
}

check_inventory_initial() {
    local label="inventory-routes.py --check (initial)"
    local expected="exit=0"
    run_command_capture "cd '${ROOT}' && python3 scripts/inventory-routes.py --check"

    local status actual evidence
    if [[ ${CAPTURE_RC} -eq 0 ]]; then
        status="PASS"
    else
        status="FAIL"
    fi
    actual="exit=${CAPTURE_RC}"
    evidence="${CAPTURE_OUT:0:240}"
    log_row "${label}" "${expected}" "${actual}" "${status}" "${evidence}"
}

check_api_lint() {
    local label="npm api:lint"
    local expected="exit=0 (Redocly lint on 4 openapi files)"
    run_command_capture "cd '${ROOT}' && npm --prefix apps/web run api:lint"

    local status actual evidence
    if [[ ${CAPTURE_RC} -eq 0 ]]; then
        status="PASS"
    else
        status="FAIL"
    fi
    actual="exit=${CAPTURE_RC}"
    evidence="${CAPTURE_OUT:0:240}"
    log_row "${label}" "${expected}" "${actual}" "${status}" "${evidence}"
}

check_rbac_idempotency() {
    local label="rbac idempotency"
    local expected="diff /tmp/v1 /tmp/v2 == 0"
    local dir_a dir_b
    dir_a="$(mktemp -d)"
    dir_b="$(mktemp -d)"

    run_command_capture "cd '${ROOT}' && python3 scripts/inventory-routes.py --mode rbac --json '${dir_a}' && python3 scripts/inventory-routes.py --mode rbac --json '${dir_b}' && diff -r '${dir_a}' '${dir_b}'"

    local status actual evidence
    if [[ ${CAPTURE_RC} -eq 0 ]]; then
        status="PASS"
    else
        status="FAIL"
    fi
    actual="diff_exit=${CAPTURE_RC}"
    evidence="${CAPTURE_OUT:0:240}"

    rm -rf "${dir_a}" "${dir_b}"
    log_row "${label}" "${expected}" "${actual}" "${status}" "${evidence}"
}

check_api_bundle() {
    local label="npm api:bundle"
    local expected="exit=0 (Redocly bundle)"
    run_command_capture "cd '${ROOT}' && npm --prefix apps/web run api:bundle"

    local status actual evidence
    if [[ ${CAPTURE_RC} -eq 0 ]]; then
        status="PASS"
    else
        status="FAIL"
    fi
    actual="exit=${CAPTURE_RC}"
    evidence="${CAPTURE_OUT:0:240}"
    log_row "${label}" "${expected}" "${actual}" "${status}" "${evidence}"
}

check_validate_docs() {
    local label="validate-docs.sh"
    local expected="exit=0"
    run_command_capture "cd '${ROOT}' && ./scripts/validate-docs.sh"

    local status actual evidence
    if [[ ${CAPTURE_RC} -eq 0 ]]; then
        status="PASS"
    else
        status="FAIL"
    fi
    actual="exit=${CAPTURE_RC}"
    evidence="${CAPTURE_OUT:0:240}"
    log_row "${label}" "${expected}" "${actual}" "${status}" "${evidence}"
}

check_inventory_post_md_write() {
    local label="inventory-routes.py --check (post md-write)"
    local expected="exit=0 after --mode md --write"
    local md_dir
    md_dir="$(mktemp -d)"

    run_command_capture "cd '${ROOT}' && python3 scripts/inventory-routes.py --mode md --write --json '${md_dir}'"

    run_command_capture "cd '${ROOT}' && python3 scripts/inventory-routes.py --check"

    local status actual evidence
    if [[ ${CAPTURE_RC} -eq 0 ]]; then
        status="PASS"
    else
        status="FAIL"
    fi
    actual="exit=${CAPTURE_RC}"
    evidence="${CAPTURE_OUT:0:240}"

    rm -rf "${md_dir}"
    log_row "${label}" "${expected}" "${actual}" "${status}" "${evidence}"
}

check_endpoints_line_count() {
    local label="endpoints.md line count"
    local expected="wc -l docs/api/endpoints.md > 600"
    local file="${ROOT}/docs/api/endpoints.md"
    local status actual evidence line_count
    if [[ ! -f "${file}" ]]; then
        status="FAIL"
        actual="file missing"
        evidence="${file} not found"
    else
        line_count="$(wc -l < "${file}" | tr -d ' ')"
        if [[ "${line_count}" -gt 600 ]]; then
            status="PASS"
        else
            status="FAIL"
        fi
        actual="${line_count} lines"
        evidence="threshold=600, observed=${line_count}"
    fi
    log_row "${label}" "${expected}" "${actual}" "${status}" "${evidence}"
}

check_endpoints_section_count() {
    local label="endpoints.md section headings"
    local expected="grep -c '^## ' docs/api/endpoints.md >= 6"
    local file="${ROOT}/docs/api/endpoints.md"
    local status actual evidence heading_count
    if [[ ! -f "${file}" ]]; then
        status="FAIL"
        actual="file missing"
        evidence="${file} not found"
    else
        heading_count="$(grep -c '^## ' "${file}" || true)"
        if [[ "${heading_count}" -ge 6 ]]; then
            status="PASS"
        else
            status="FAIL"
        fi
        actual="${heading_count} headings"
        evidence="threshold>=6, observed=${heading_count}"
    fi
    log_row "${label}" "${expected}" "${actual}" "${status}" "${evidence}"
}

check_endpoints_placeholder_remainder() {
    local label="endpoints.md AR placeholder count"
    local expected="grep -c '{{AR:' docs/api/endpoints.md <= 5"
    local file="${ROOT}/docs/api/endpoints.md"
    local status actual evidence placeholder_count
    if [[ ! -f "${file}" ]]; then
        status="FAIL"
        actual="file missing"
        evidence="${file} not found"
    else
        placeholder_count="$(grep -c '{{AR:' "${file}" || true)"
        if [[ "${placeholder_count}" -le 5 ]]; then
            status="PASS"
        else
            status="FAIL"
        fi
        actual="${placeholder_count} placeholders"
        evidence="threshold<=5, observed=${placeholder_count}"
    fi
    log_row "${label}" "${expected}" "${actual}" "${status}" "${evidence}"
}

write_report() {
    local timestamp overall
    timestamp="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    if [[ ${FAIL_COUNT} -eq 0 ]]; then
        overall="PASS"
    else
        overall="FAIL"
    fi

    {
        printf '# Tier-2 Integration Verification Report\n\n'
        printf 'Generated: %s\n\n' "${timestamp}"
        printf 'Overall: %s (pass=%d, fail=%d)\n\n' "${overall}" "${PASS_COUNT}" "${FAIL_COUNT}"
        printf '| Check | Expected | Actual | Pass/Fail | Evidence |\n'
        printf '|-------|----------|--------|-----------|----------|\n'
        local row
        for row in "${ROWS[@]}"; do
            printf '%s\n' "${row}"
        done
        printf '\n'
    } > "${REPORT}"
}

print_summary() {
    local overall
    if [[ ${FAIL_COUNT} -eq 0 ]]; then
        overall="${C_OK}PASS${C_RESET}"
    else
        overall="${C_FAIL}FAIL${C_RESET}"
    fi

    printf 'Tier-2 verification: %s (pass=%d fail=%d)\n' "${overall}" "${PASS_COUNT}" "${FAIL_COUNT}"
    printf 'Report written to: %s\n' "${REPORT}"
}

main() {
    check_verify_boundaries
    check_git_diff_clean
    check_inventory_initial
    check_api_lint
    check_rbac_idempotency
    check_api_bundle
    check_validate_docs
    check_inventory_post_md_write
    check_endpoints_line_count
    check_endpoints_section_count
    check_endpoints_placeholder_remainder

    write_report
    print_summary

    if [[ ${FAIL_COUNT} -gt 0 ]]; then
        exit 1
    fi
    exit 0
}

main "$@"
