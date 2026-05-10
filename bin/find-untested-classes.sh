#!/usr/bin/env bash
#
# find-untested-classes.sh
#
# Usage:
#   bin/find-untested-classes.sh [--check] [subsystem]
#
# Lists source classes that have no dedicated PHPUnit test (i.e. no file under
# tests/ or addons/*/tests/ that references the class name). Used by
# contributors locally and by .github/workflows/phpunit.yml to gate the
# per-subsystem coverage floors recorded in tests/.coverage-baseline.json.
#
# Subsystems: base-tools, pro-tools, rest, slash, services, harness, providers, all.
# When --check is passed and a baseline file is present, the script exits non-zero
# if the count of *covered* classes for the requested subsystem falls below the
# floor recorded in tests/.coverage-baseline.json.
#
set -e

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

CHECK_MODE=0
SUBSYSTEM="all"
for arg in "$@"; do
    case "$arg" in
        --check) CHECK_MODE=1 ;;
        base-tools|pro-tools|rest|slash|services|harness|providers|all) SUBSYSTEM="$arg" ;;
        -h|--help)
            sed -n '2,20p' "$0"
            exit 0
            ;;
        *)
            echo "Unknown argument: $arg" >&2
            exit 2
            ;;
    esac
done

# Glob lists per subsystem.
declare -a BASE_TOOLS
mapfile -t BASE_TOOLS < <(find includes/tools -type f -name 'class-*.php' 2>/dev/null)
declare -a PRO_TOOLS
mapfile -t PRO_TOOLS < <(find addons/pro/includes/tools -name 'class-*.php' 2>/dev/null)
declare -a REST
mapfile -t REST < <(find includes addons -name 'class-*-rest*.php' -o -name 'class-*-controller.php' 2>/dev/null | grep -v vendor || true)
declare -a SLASH
mapfile -t SLASH < <(find includes/slash-commands/commands -type f -name 'class-*.php' 2>/dev/null)
declare -a SERVICES
mapfile -t SERVICES < <(find includes/services addons/pro/includes/services -name 'class-*.php' 2>/dev/null)
declare -a HARNESS
mapfile -t HARNESS < <(find includes/harness addons/pro/includes/harness -name 'class-*.php' 2>/dev/null)
declare -a PROVIDERS
mapfile -t PROVIDERS < <(find includes -maxdepth 1 -type f -name 'class-wp-mcp-ai-*-client.php' | sort -u)

# Search both base + pro test directories.
TEST_DIRS=( tests addons/pro/tests )
for d in addons/*/tests; do
    [ -d "$d" ] && [ "$d" != "addons/pro/tests" ] && TEST_DIRS+=( "$d" )
done

is_covered() {
    local file="$1"
    local kebab class
    kebab="$(basename "$file" .php | sed 's/^class-//')"

    # Match against either:
    # - the kebab-case file basename (e.g. via require_once or coverage manifests), or
    # - the actual PHP class symbol declared inside the file (parsed once, no
    #   guesswork about acronym casing like LM/OpenAI/RabbitMQ).
    class="$(grep -oE 'class[[:space:]]+[A-Z][A-Za-z0-9_]+' "$file" 2>/dev/null \
        | head -n1 \
        | awk '{print $NF}')"

    if [ -n "$class" ]; then
        grep -rqE -- "(${kebab}|${class})" "${TEST_DIRS[@]}" 2>/dev/null
    else
        grep -rq -- "$kebab" "${TEST_DIRS[@]}" 2>/dev/null
    fi
}

report_subsystem() {
    local label="$1"
    shift
    local files=( "$@" )
    local total=0
    local covered=0
    local -a missing=()

    for file in "${files[@]}"; do
        [ -e "$file" ] || continue
        total=$((total + 1))
        if is_covered "$file"; then
            covered=$((covered + 1))
        else
            missing+=( "$(basename "$file" .php | sed 's/^class-//')" )
        fi
    done

    printf '\n=== %s ===\n' "$label"
    printf 'source classes:      %d\n' "$total"
    printf 'covered (referenced): %d\n' "$covered"
    printf 'untested:             %d\n' "${#missing[@]}"

    if [ "${#missing[@]}" -gt 0 ] && [ "$CHECK_MODE" -eq 0 ]; then
        printf '\nUntested classes:\n'
        printf '  - %s\n' "${missing[@]}" | sort
    fi

    LAST_COVERED="$covered"
    # shellcheck disable=SC2034  # Reserved for future ratio reporting.
    LAST_TOTAL="$total"
}

run_for() {
    case "$1" in
        base-tools) report_subsystem "Base tools (includes/tools/)" "${BASE_TOOLS[@]}" ;;
        pro-tools)  report_subsystem "Pro tools (addons/pro/includes/tools/)" "${PRO_TOOLS[@]}" ;;
        rest)       report_subsystem "REST controllers" "${REST[@]}" ;;
        slash)      report_subsystem "Slash commands" "${SLASH[@]}" ;;
        services)   report_subsystem "Services" "${SERVICES[@]}" ;;
        harness)    report_subsystem "Harness layers" "${HARNESS[@]}" ;;
        providers)  report_subsystem "Provider clients" "${PROVIDERS[@]}" ;;
    esac
}

declare -A BASELINE_KEYS=(
    [base-tools]=base_tools
    [pro-tools]=pro_tools
    [rest]=rest_controllers
    [slash]=slash_commands
    [services]=services
    [harness]=harness
    [providers]=provider_clients
)

check_against_baseline() {
    local sub="$1"
    local key="${BASELINE_KEYS[$sub]}"
    local baseline_file="tests/.coverage-baseline.json"
    [ -f "$baseline_file" ] || return 0
    local floor
    floor=$(php -r "
        \$j = json_decode(file_get_contents('$baseline_file'), true);
        echo isset(\$j['subsystem_floors']['$key']['covered_classes_min'])
            ? (int) \$j['subsystem_floors']['$key']['covered_classes_min']
            : 0;
    ")
    if [ "$LAST_COVERED" -lt "$floor" ]; then
        printf '\n❌ %s coverage regressed: %d covered < baseline floor %d\n' "$sub" "$LAST_COVERED" "$floor" >&2
        return 1
    else
        printf '✅ %s meets baseline floor (%d ≥ %d)\n' "$sub" "$LAST_COVERED" "$floor"
    fi
}

if [ "$SUBSYSTEM" = "all" ]; then
    SUBSYSTEMS=(base-tools pro-tools rest slash services harness providers)
else
    SUBSYSTEMS=("$SUBSYSTEM")
fi

EXIT_CODE=0
for sub in "${SUBSYSTEMS[@]}"; do
    run_for "$sub"
    if [ "$CHECK_MODE" -eq 1 ]; then
        check_against_baseline "$sub" || EXIT_CODE=1
    fi
done

if [ "$CHECK_MODE" -eq 1 ]; then
    # Test-file floor.
    if [ -f tests/.coverage-baseline.json ]; then
        floor=$(php -r "
            \$j = json_decode(file_get_contents('tests/.coverage-baseline.json'), true);
            echo isset(\$j['test_file_floor']['min_count']) ? (int) \$j['test_file_floor']['min_count'] : 0;
        ")
        current=$(find tests addons/*/tests -name 'test-*.php' 2>/dev/null | wc -l | tr -d ' ')
        if [ "$current" -lt "$floor" ]; then
            printf '\n❌ Total test file count regressed: %d < baseline %d\n' "$current" "$floor" >&2
            EXIT_CODE=1
        else
            printf '✅ Total test files: %d ≥ baseline %d\n' "$current" "$floor"
        fi
    fi
fi

exit "$EXIT_CODE"
