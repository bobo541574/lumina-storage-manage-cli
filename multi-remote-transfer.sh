#!/bin/bash
# multi-remote-transfer.sh — DigitalOcean Spaces Multi-Remote Transfer
#
# Copy NYC → AMS:
#   ./multi-remote-transfer.sh \
#     --source-remote do-spaces-nyc --source-bucket my-data --source-path videos/2024 \
#     --dest-remote do-spaces-ams   --dest-bucket  backup   --dest-path   videos/2024 \
#     --operation copy
#
# Copy with public-read ACL:
#   ./multi-remote-transfer.sh \
#     --source-remote do-spaces-nyc --source-bucket my-data \
#     --dest-remote do-spaces-ams   --dest-bucket  backup \
#     --operation copy --acl public-read
#
# Download Space → Local with public-read ACL:
#   ./multi-remote-transfer.sh \
#     --source-remote do-spaces-nyc --source-bucket docs --source-path reports \
#     --dest-path /home/user/backups --operation download --acl public-read
#
# Upload Local → Space with public-read ACL:
#   ./multi-remote-transfer.sh \
#     --source-path /home/user/data \
#     --dest-remote do-spaces-sgp --dest-bucket uploads \
#     --operation upload --acl public-read
#
# Change ACL of existing files to public-read (top-level only):
#   ./multi-remote-transfer.sh \
#     --source-remote do-spaces-nyc --source-bucket my-data \
#     --operation set-acl --acl public-read
#
# Change ACL recursively (including nested directories):
#   ./multi-remote-transfer.sh \
#     --source-remote do-spaces-nyc --source-bucket my-data --source-path videos/2024 \
#     --operation set-acl --acl public-read --recursive
#
# Change ACL back to private recursively:
#   ./multi-remote-transfer.sh \
#     --source-remote do-spaces-nyc --source-bucket my-data \
#     --operation set-acl --acl private --recursive --dry-run
#
# Dry-run cross-region move with reduced parallelism (recommended for inter-region):
#   ./multi-remote-transfer.sh \
#     --source-remote do-spaces-nyc --source-bucket important-data \
#     --dest-remote do-spaces-ams   --dest-bucket  backup \
#     --operation move --transfers 4 --retries 5 --dry-run --verbose
#
# Remote bucket root ကို list လုပ်:
#   ./multi-remote-transfer.sh \
#     --source-remote do-spaces-nyc --source-bucket my-data \
#     --operation list
#
# Sub-path, files only, recursive:
#   ./multi-remote-transfer.sh \
#     --source-remote do-spaces-nyc --source-bucket my-data \
#     --source-path videos/2024 \
#     --operation list --ls-type files --ls-recursive
#
# Local directory:
#   ./multi-remote-transfer.sh \
#     --source-path /home/user/data \
#     --operation list --ls-recursive
#
# Use a saved configuration:
#   ./multi-remote-transfer.sh --use-config

# set -e  → command fail ဖြစ်ပါက script ချက်ချင်း ရပ်မည်
# set -o pipefail → pipe ထဲ command fail ဖြစ်ပါက pipe exit code ကို fail သတ်မည်
set -eo pipefail

# ── Colors ────────────────────────────────────────────────
# terminal ANSI color codes — log_* function တွေမှာ color output ဖြင့် ပြသရန်
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'    # reset — color ပိတ်မည်

# ── Directories ───────────────────────────────────────────
# config နဲ့ log files သိမ်းမည့် base directory
CONFIG_DIR="$HOME/.config/dospace-transfer"
LOG_DIR="$CONFIG_DIR/logs"
# script load ချိန်တွင်ပင် directory တွေ ဆောက်ထားသည် (function မဟုတ်)
mkdir -p "$CONFIG_DIR" "$LOG_DIR"

# ── Defaults ──────────────────────────────────────────────
# argument parsing မတိုင်မီ default value တွေ ကြေညာသည်
SOURCE_REMOTE=""
SOURCE_BUCKET=""
SOURCE_PATH=""
DEST_REMOTE=""
DEST_BUCKET=""
DEST_PATH=""
OPERATION=""
ACL="private"       # upload/copy တိုင်း object ရဲ့ default access permission
VERBOSE=false
DRY_RUN=false
TRANSFERS=8         # rclone parallel file transfer count (inter-region: 4-6 recommended)
RETRIES=3           # network error ဖြစ်ပါက retry မည့် အကြိမ်ရေ
USE_CONFIG=false
ARGS_PROVIDED=false # argument တစ်ခုမှ မပေးပါက interactive mode ဝင်မည်
LIST_RECURSIVE=false
LIST_TYPE="all"     # list operation: all | dirs | files
RECURSIVE=false     # set-acl operation: subdirectory ဆင်းမဆင်း

# set-acl တွင် validate လုပ်ရန် ACL whitelist
VALID_ACLS=("private" "public-read" "public-read-write" "authenticated-read")

# ── Logging ───────────────────────────────────────────────
# log level အလိုက် color ခွဲပြီး output ဖြင့် ပြသသည်
# log_error သည် >&2 (stderr) သို့ redirect လုပ်သောကြောင့် piping မှာ error မပါဘဲ data သာ ဆင်းမည်
log_info()    { echo -e "${GREEN}[INFO]${NC} $1"; }
log_error()   { echo -e "${RED}[ERROR]${NC} $1" >&2; }
log_warning() { echo -e "${YELLOW}[WARNING]${NC} $1"; }
log_step()    { echo -e "${BLUE}[STEP]${NC} $1"; }
log_acl()     { echo -e "${CYAN}[ACL]${NC} $1"; }

# ── Validation ────────────────────────────────────────────
# script မစတင်မီ rclone binary ရှိ/မရှိ စစ်ဆေးသည်
check_rclone() {
    if ! command -v rclone &>/dev/null; then
        log_error "rclone is not installed. See https://rclone.io/install/"
        exit 1
    fi
}

# rclone ထဲ configure လုပ်ထားသော remote name တစ်ခု ရှိ/မရှိ စစ်ဆေးသည်
# grep -q "^name:$" → exact full-line match (myremote စစ်ရင် myremote-backup မကျ)
check_remote() {
    local remote="$1"
    if ! rclone listremotes | grep -q "^${remote}:$"; then
        log_error "Remote '$remote' not found. Run 'rclone config' to set it up."
        log_info "Available remotes:"
        rclone listremotes
        return 1
    fi
    return 0
}

# bucket ရှိ/မရှိ စစ်ဆေးသည်; မရှိပါက user ကို create offer ပေးသည်
# awk '{print $NF}' → rclone lsd output မှ last column (bucket name) ကိုသာ ယူသည်
# grep -qx → exact full-line match (partial name match ဖြစ်မည် ကို ကာကွယ်သည်)
check_bucket() {
    local remote="$1"
    local bucket="$2"
    # Exact-name match prevents "my-bucket" matching "my-bucket-extra"
    if ! rclone lsd "${remote}:" 2>/dev/null | awk '{print $NF}' | grep -qx "$bucket"; then
        log_warning "Bucket '$bucket' not found in remote '$remote'"
        read -rp "Create it? (yes/no): " create
        if [ "$create" = "yes" ]; then
            rclone mkdir "${remote}:${bucket}"
            log_info "Bucket created: ${remote}:${bucket}"
        else
            return 1
        fi
    fi
    return 0
}

# ACL value တစ်ခု VALID_ACLS array ထဲ ရှိ/မရှိ စစ်ဆေးသည်
# caller မှာ validate_acl "$ACL" || return 1 ဖြင့် short-circuit terminate လုပ်နိုင်သည်
validate_acl() {
    local acl="$1"
    for valid in "${VALID_ACLS[@]}"; do
        [ "$acl" = "$valid" ] && return 0
    done
    log_error "Invalid ACL '$acl'. Valid values: ${VALID_ACLS[*]}"
    return 1
}

# ── Config ────────────────────────────────────────────────
# သိမ်းထားသော transfer config file ကို load လုပ်ပြီး global variable တွေ set သည်
# source "$file" မသုံး — config ထဲ arbitrary bash code ပါပါက execute ဖြစ်မည် (code injection risk)
# key-by-key grep+cut ဖြင့် safe parsing ကိုသာ သုံးသည်
load_saved_config() {
    local config_name="$1"
    local config_file="$CONFIG_DIR/${config_name}.conf"

    if [ ! -f "$config_file" ]; then
        log_error "Configuration not found: $config_name"
        log_info "Available configs:"
        ls -1 "$CONFIG_DIR"/*.conf 2>/dev/null | xargs -n1 basename | sed 's/.conf$//' || true
        return 1
    fi

    # Parse individual keys — avoids arbitrary code execution that `source` would allow
    # cut -d= -f2- → value ထဲ = sign ပါပါကလည်း (URL, base64) မဖြတ်ဘဲ f2 နောက်အားလုံး ယူသည်
    SOURCE_REMOTE=$(grep '^SOURCE_REMOTE=' "$config_file" | cut -d= -f2- | tr -d '"')
    SOURCE_BUCKET=$(grep '^SOURCE_BUCKET=' "$config_file" | cut -d= -f2- | tr -d '"')
    SOURCE_PATH=$(  grep '^SOURCE_PATH='   "$config_file" | cut -d= -f2- | tr -d '"')
    DEST_REMOTE=$(  grep '^DEST_REMOTE='   "$config_file" | cut -d= -f2- | tr -d '"')
    DEST_BUCKET=$(  grep '^DEST_BUCKET='   "$config_file" | cut -d= -f2- | tr -d '"')
    DEST_PATH=$(    grep '^DEST_PATH='     "$config_file" | cut -d= -f2- | tr -d '"')
    OPERATION=$(    grep '^OPERATION='     "$config_file" | cut -d= -f2- | tr -d '"')
    TRANSFERS=$(    grep '^TRANSFERS='     "$config_file" | cut -d= -f2- | tr -d '"')
    RETRIES=$(      grep '^RETRIES='       "$config_file" | cut -d= -f2- | tr -d '"')
    ACL=$(          grep '^ACL='           "$config_file" | cut -d= -f2- | tr -d '"')
    # ACL key မပါသော ဟောင်းသော config file ကို backward compatible ဖြစ်ရန် fallback
    ACL="${ACL:-private}"

    log_info "Loaded configuration: $config_name"
    return 0
}

# current global variable တွေကို config file ထဲ သိမ်းသည်
# heredoc (EOF without quote) → variable expansion လုပ်ကာ actual value တွေ ရေးသိမ်းသည်
save_config() {
    local config_name="$1"
    local config_file="$CONFIG_DIR/${config_name}.conf"

    cat > "$config_file" << EOF
# DigitalOcean Spaces Transfer Configuration
# Saved on: $(date)

SOURCE_REMOTE="$SOURCE_REMOTE"
SOURCE_BUCKET="$SOURCE_BUCKET"
SOURCE_PATH="$SOURCE_PATH"

DEST_REMOTE="$DEST_REMOTE"
DEST_BUCKET="$DEST_BUCKET"
DEST_PATH="$DEST_PATH"

OPERATION="$OPERATION"
TRANSFERS="$TRANSFERS"
RETRIES="$RETRIES"
ACL="$ACL"
EOF

    log_info "Configuration saved to: $config_file"
}

# CONFIG_DIR ထဲ *.conf file တွေကို loop ပတ်ပြီး source/dest/ACL info ပြသသည်
list_configs() {
    local found=false
    log_info "Saved configurations:"
    echo "----------------------------------------"
    for config in "$CONFIG_DIR"/*.conf; do
        # glob match မရပါက literal "*.conf" ဖြစ်မည် → file exist စစ်ဆေးသည်
        [ -f "$config" ] || continue
        found=true
        local name
        name=$(basename "$config" .conf)
        echo -e "${GREEN}$name${NC}"
        # SOURCE_, DEST_, ACL prefix lines တွေသာ filter ထုတ်ပြသည် (credentials မပါ)
        grep -E "^(SOURCE_|DEST_|ACL)" "$config" | sed 's/^/  /'
        echo "----------------------------------------"
    done
    if [ "$found" = false ]; then
        echo "No saved configurations found."
    fi
}

# ── Source Listing ────────────────────────────────────────
# remote bucket သို့မဟုတ် local directory ထဲ files/dirs ကို human-readable ဖြင့် ပြသသည်
list_source() {
    local target_path is_remote=false

    # source type ကို detect လုပ်သည်: remote (SOURCE_REMOTE+BUCKET) vs local (SOURCE_PATH only)
    if [ -n "$SOURCE_REMOTE" ] && [ -n "$SOURCE_BUCKET" ]; then
        is_remote=true
        target_path="${SOURCE_REMOTE}:${SOURCE_BUCKET}"
        [ -n "$SOURCE_PATH" ] && target_path="${target_path}/${SOURCE_PATH}"
        check_remote "$SOURCE_REMOTE" || return 1
    elif [ -n "$SOURCE_PATH" ]; then
        target_path="$SOURCE_PATH"
    else
        log_error "Specify --source-remote and --source-bucket, or --source-path for local listing"
        return 1
    fi

    log_step "Listing: $target_path"
    echo ""

    if [ "$is_remote" = true ]; then
        local lsf_opts=()
        # -R flag → subdirectory ဆင်းပြီး recursive list လုပ်မည်
        [ "$LIST_RECURSIVE" = true ] && lsf_opts+=("-R")

        # Directories
        if [ "$LIST_TYPE" != "files" ]; then
            echo -e "${BLUE}── Directories ──────────────────────────────${NC}"
            local dirs
            dirs=$(rclone lsf "${lsf_opts[@]}" --dirs-only "$target_path" 2>/dev/null | sort)
            if [ -n "$dirs" ]; then
                echo "$dirs" | sed 's/^/  /'
            else
                echo "  (none)"
            fi
            echo ""
        fi

        # Files — format: "size\tpath" then render with human-readable size column
        # --format "sp" → size နဲ့ path ကို tab-separated ဖြင့် ထုတ်သည်
        if [ "$LIST_TYPE" != "dirs" ]; then
            echo -e "${BLUE}── Files ────────────────────────────────────${NC}"
            local files
            files=$(rclone lsf "${lsf_opts[@]}" --files-only \
                        --format "sp" --separator $'\t' "$target_path" 2>/dev/null \
                    | sort -t$'\t' -k2)
            if [ -n "$files" ]; then
                # awk ဖြင့် bytes → GB/MB/KB/B human-readable format သို့ convert ပြသသည်
                echo "$files" | awk -F'\t' '{
                    s = $1 + 0
                    if      (s >= 1073741824) hr = sprintf("%.1fG", s/1073741824)
                    else if (s >= 1048576)    hr = sprintf("%.1fM", s/1048576)
                    else if (s >= 1024)       hr = sprintf("%.1fK", s/1024)
                    else                      hr = s "B"
                    printf "  %8s  %s\n", hr, $2
                }'
            else
                echo "  (none)"
            fi
            echo ""
        fi

        # rclone size → total file count နဲ့ total size summary ပြသသည်
        echo -e "${BLUE}── Summary ──────────────────────────────────${NC}"
        rclone size "$target_path" 2>/dev/null | sed 's/^/  /' \
            || echo "  (unable to calculate size)"
    else
        # Local path
        if [ ! -e "$target_path" ]; then
            log_error "Local path not found: $target_path"
            return 1
        fi
        if [ "$LIST_RECURSIVE" = true ]; then
            ls -laR "$target_path"
        else
            ls -la "$target_path"
        fi
    fi
}

# ── ACL Change ────────────────────────────────────────────
# rclone သည် "source == dest" copy ကို block လုပ်သောကြောင့် (rclone ၏ safety check)
# S3 PutObjectAcl API ကို aws CLI မှ တိုက်ရိုက်ခေါ်သည်
# ဒါဟာ metadata-only operation ဖြစ်ပြီး file bytes တစ်ခုမှ transfer မလုပ်ဘဲ
# object ၏ ACL metadata ကိုသာ server-side update လုပ်သည်
set_acl() {
    validate_acl "$ACL" || return 1

    if [ -z "$SOURCE_REMOTE" ] || [ -z "$SOURCE_BUCKET" ]; then
        log_error "set-acl requires --source-remote and --source-bucket"
        return 1
    fi

    check_remote "$SOURCE_REMOTE" || return 1

    # aws CLI ရှိ/မရှိ စစ်ဆေးသည် — PutObjectAcl API call အတွက် လိုအပ်သည်
    if ! command -v aws &>/dev/null; then
        log_error "set-acl requires the AWS CLI (S3-compatible)."
        log_info  "Install on macOS:  brew install awscli"
        log_info  "Install on Linux:  pip install awscli  OR  snap install aws-cli --classic"
        log_info  "More info: https://docs.aws.amazon.com/cli/latest/userguide/getting-started-install.html"
        return 1
    fi

    # rclone config ထဲမှ connection details extract လုပ်သည်
    # credentials ကို separate file မသိမ်းဘဲ rclone ၏ config ထဲမှ on-the-fly ဆွဲယူသောကြောင့်
    # credentials duplication မဖြစ်ပေ
    local config_block
    config_block=$(rclone config show "$SOURCE_REMOTE" 2>/dev/null)

    local endpoint access_key secret_key
    # sed 's/.*=\s*//' → "key = value" မှ "value" ကိုသာ ထုတ်ယူသည်
    # tr -d ' \r' → trailing space နဲ့ Windows line ending (\r) ဖြတ်ထုတ်သည်
    endpoint=$(  echo "$config_block" | grep '^\s*endpoint'          | sed 's/.*=\s*//' | tr -d ' \r')
    access_key=$(echo "$config_block" | grep '^\s*access_key_id'     | sed 's/.*=\s*//' | tr -d ' \r')
    secret_key=$(echo "$config_block" | grep '^\s*secret_access_key' | sed 's/.*=\s*//' | tr -d ' \r')

    if [ -z "$endpoint" ] || [ -z "$access_key" ] || [ -z "$secret_key" ]; then
        log_error "Could not extract endpoint/credentials from rclone config '$SOURCE_REMOTE'"
        log_info  "Ensure the remote has: endpoint, access_key_id, secret_access_key"
        return 1
    fi

    # rclone config ထဲ endpoint ကို https:// ပါ သိမ်းသောကြောင့် scheme prefix ဖြတ်ထုတ်သည်
    # sed -E → extended regex; -E မပါပါက macOS BSD sed မှာ ? quantifier မအလုပ်လုပ်
    # (macOS BSD sed သည် basic mode မှာ \? ကို support မလုပ်)
    endpoint=$(echo "$endpoint" | sed -E 's|^https?://||')

    # DO Spaces endpoint format: <region>.digitaloceanspaces.com
    # cut -d. -f1 → first dot segment = region name (e.g. sgp1.digitaloceanspaces.com → sgp1)
    local region
    region=$(echo "$endpoint" | cut -d. -f1)

    local list_target="${SOURCE_REMOTE}:${SOURCE_BUCKET}"
    [ -n "$SOURCE_PATH" ] && list_target="${list_target}/${SOURCE_PATH}"

    local log_file="$LOG_DIR/set_acl_$(date +%Y%m%d_%H%M%S).log"

    log_acl "Target:    $list_target"
    log_acl "Endpoint:  https://${endpoint}"
    log_acl "New ACL:   $ACL"
    log_acl "Recursive: $RECURSIVE"
    log_acl "Log:       $log_file"
    [ "$DRY_RUN" = true ] && log_warning "DRY-RUN — no changes will be made"
    echo ""

    # file list ကို enumerate လုပ်သည်
    # -R မပါပါက top-level files တွေသာ; -R ပါပါက nested directory ထဲ files အကုန်
    local lsf_opts=("--files-only")
    [ "$RECURSIVE" = true ] && lsf_opts+=("-R")

    local files
    files=$(rclone lsf "${lsf_opts[@]}" "$list_target" 2>/dev/null)

    if [ -z "$files" ]; then
        log_warning "No files found at $list_target"
        return 0
    fi

    local total changed failed
    # wc -l → line count = file count; tr -d ' ' → leading space ဖြတ်ထုတ်သည်
    total=$(echo "$files" | wc -l | tr -d ' ')
    changed=0
    failed=0

    log_acl "Found $total file(s) — applying ACL '$ACL'..."
    echo ""

    # SOURCE_PATH ဟာ file path (directory မဟုတ်) ဆိုသည်ကို detect လုပ်သည်
    # rclone lsf ကို file path ပေးပြီး run ပါက ထို file ၏ basename ကိုသာ ပြန်ထုတ်သည်
    # result == basename(SOURCE_PATH) ဖြစ်ပါက SOURCE_PATH ဟာ file ဖြစ်ကြောင်း သိနိုင်သည်
    # ဒါမှ object key ကို "prefix/basename" မဖြစ်ဘဲ SOURCE_PATH တိုက်ရိုက် သုံးနိုင်မည်
    local source_is_file=false
    if [ -n "$SOURCE_PATH" ] && [ "$total" = "1" ]; then
        if [ "$(echo "$files" | tr -d '[:space:]')" = "$(basename "$SOURCE_PATH")" ]; then
            source_is_file=true
        fi
    fi

    # directory ဖြစ်ပါက file list ရဲ့ filename တွေကို SOURCE_PATH prefix ဖြင့် ဖြည့်မည်
    local key_prefix="${SOURCE_PATH:+${SOURCE_PATH}/}"

    # here-string <<< ဖြင့် files variable ကို while loop ထဲ feed လုပ်သည်
    while IFS= read -r filename; do
        [ -z "$filename" ] && continue
        local object_key
        if [ "$source_is_file" = true ]; then
            # file ဖြစ်ပါက key = SOURCE_PATH (prefix + basename ထပ်မဖြစ်ရ)
            object_key="$SOURCE_PATH"
        else
            # directory ဖြစ်ပါက key = "prefix/filename"
            object_key="${key_prefix}${filename}"
        fi

        if [ "$DRY_RUN" = true ]; then
            log_info "[dry-run] $object_key"
            echo "$(date +%H:%M:%S) [dry-run] $object_key" >> "$log_file"
            changed=$(( changed + 1 ))
            continue
        fi

        # credentials ကို environment variable ဖြင့် pass လုပ်သည်
        # AWS_ACCESS_KEY_ID/SECRET ကို process scope တွင်သာ active ဖြစ်အောင် command prefix ဖြင့် သုံးသည်
        # global shell environment ကို ညစ်ညမ်းမည် မဟုတ်
        # aws s3api put-object-acl → S3 PutObjectAcl API call
        # metadata-only operation: bytes မ transfer ဘဲ ACL metadata ကိုသာ server-side update
        if AWS_ACCESS_KEY_ID="$access_key" \
           AWS_SECRET_ACCESS_KEY="$secret_key" \
           aws s3api put-object-acl \
               --endpoint-url "https://${endpoint}" \
               --region "$region" \
               --bucket "$SOURCE_BUCKET" \
               --key "$object_key" \
               --acl "$ACL" \
               --output text >> "$log_file" 2>&1; then
            log_info "✓ $object_key"
            echo "$(date +%H:%M:%S) OK  $object_key" >> "$log_file"
            changed=$(( changed + 1 ))
        else
            log_error "✗ $object_key"
            echo "$(date +%H:%M:%S) ERR $object_key" >> "$log_file"
            failed=$(( failed + 1 ))
        fi
    done <<< "$files"

    echo ""
    if [ "$DRY_RUN" = true ]; then
        log_acl "Dry-run complete — $changed file(s) would be updated"
    else
        log_acl "Complete — changed: $changed  failed: $failed  total: $total"
        log_acl "Log: $log_file"
        # failed > 0 ဖြစ်ပါက exit code 1 (failure) ပြန်ပေးသည်
        [ "$failed" -gt 0 ] && return 1
    fi

    return 0
}

# ── Transfer ──────────────────────────────────────────────

# rclone command ကို run ပြီး exit code ကို capture လုပ်သည်
# set -e ကြောင့် pipe ထဲ fail ပါက script ရပ်မည်ဆိုတာကို ကာကွယ်ရန်
# if block ထဲ ခေါ်ထားသောကြောင့် set -e suspend ဖြစ်နေပြီး PIPESTATUS[0] ဖြင့် rclone exit code capture
# tee -a → stdout ကို terminal နဲ့ log file နှစ်ခုလုံးသို့ တပြိုင်နက် output လုပ်သည်
run_rclone() {
    local log_file="$1"
    shift
    "$@" 2>&1 | tee -a "$log_file"
    return "${PIPESTATUS[0]}"    # rclone ၏ exit code; tee ၏ exit code ကို ignore
}

# rclone transfer (copy/move/sync/download/upload) ကို execute လုပ်သည်
execute_transfer() {
    validate_acl "$ACL" || return 1

    local log_file="$LOG_DIR/transfer_$(date +%Y%m%d_%H%M%S).log"
    # rclone options ကို array ဖြင့် build လုပ်သည် (word splitting bug ကို ကာကွယ်ရန်)
    local rclone_opts=(
        "--s3-acl=$ACL"         # destination files ၏ access permission
        "--progress"            # terminal ထဲ progress bar ပြသမည်
        "--stats=10s"           # 10 second တိုင်း transfer stats update မည်
        "--transfers=$TRANSFERS"
        "--retries=$RETRIES"
    )
    [ "$VERBOSE" = true ] && rclone_opts+=("--verbose")
    [ "$DRY_RUN"  = true ] && rclone_opts+=("--dry-run")

    # rclone path format: "remote:bucket/path"
    local src_full="${SOURCE_REMOTE}:${SOURCE_BUCKET}"
    local dst_full="${DEST_REMOTE}:${DEST_BUCKET}"
    [ -n "$SOURCE_PATH" ] && src_full="${src_full}/${SOURCE_PATH}"
    [ -n "$DEST_PATH"   ] && dst_full="${dst_full}/${DEST_PATH}"

    # --dest-path မပေးပါက source ၏ sub-path ကို destination ထဲ mirror လုပ်မည်
    if [ -z "$DEST_PATH" ] && [ -n "$SOURCE_PATH" ]; then
        dst_full="${dst_full}/${SOURCE_PATH}"
    fi

    local rclone_cmd=()
    case $OPERATION in
        copy|move|sync)
            # copy → source ဖျက်မည် မဟုတ်; move → source ဖျက်မည်; sync → dest ကို source နဲ့ mirror
            log_step "Transfer Configuration:"
            log_info "Source:    $src_full"
            log_info "Dest:      $dst_full"
            log_info "Operation: $OPERATION  ACL: $ACL  Transfers: $TRANSFERS  Retries: $RETRIES"
            log_info "Log:       $log_file"
            rclone_cmd=("rclone" "$OPERATION" "${rclone_opts[@]}" "$src_full" "$dst_full")
            ;;
        download)
            # source is Space, dest is local path
            local local_dest="${DEST_PATH:-$(pwd)/${SOURCE_BUCKET}}"
            # local filesystem မှာ S3 ACL concept မသိသောကြောင့် --s3-acl flag ဖြတ်ထုတ်သည်
            # array parameter expansion ${arr[@]/old/new} → matching element ကို replace
            rclone_opts=("${rclone_opts[@]/--s3-acl=$ACL/}")
            log_step "Transfer Configuration:"
            log_info "Source: $src_full"
            log_info "Dest:   $local_dest (local)"
            log_info "Log:    $log_file"
            rclone_cmd=("rclone" "copy" "${rclone_opts[@]}" "$src_full" "$local_dest")
            ;;
        upload)
            # source is local path, dest is Space — ACL ပါဆောင်မည်
            local local_source="$SOURCE_PATH"
            log_step "Transfer Configuration:"
            log_info "Source: $local_source (local)"
            log_info "Dest:   $dst_full  (ACL: $ACL)"
            log_info "Log:    $log_file"
            rclone_cmd=("rclone" "copy" "${rclone_opts[@]}" "$local_source" "$dst_full")
            ;;
        *)
            log_error "Unknown operation: $OPERATION"
            return 1
            ;;
    esac

    if run_rclone "$log_file" "${rclone_cmd[@]}"; then
        log_info "✓ Transfer completed successfully"
        return 0
    else
        log_error "✗ Transfer failed — see $log_file for details"
        return 1
    fi
}

# ── ACL prompt helper ─────────────────────────────────────
# user ဆီမှ ACL ရွေးချယ်ချက် ယူသည်
# return value mechanism: echo ဖြင့် output; caller မှ acl=$(prompt_acl) ဖြင့် capture
prompt_acl() {
    local default="${1:-private}"
    echo ""
    echo "Select ACL:"
    echo "  1) private            — owner only (default)"
    echo "  2) public-read        — anyone can read"
    echo "  3) public-read-write  — anyone can read and write"
    echo "  4) authenticated-read — authenticated users can read"
    read -rp "Choice [1-4, default 1]: " acl_choice
    case $acl_choice in
        2) echo "public-read" ;;
        3) echo "public-read-write" ;;
        4) echo "authenticated-read" ;;
        *) echo "$default" ;;
    esac
}

# ── Interactive Setup ──────────────────────────────────────
# argument မပေးပါက ဒီ function ကို ဝင်မည်; user ဆီမှ operation info အားလုံး prompt ဖြင့် ယူသည်
interactive_setup() {
    echo ""
    echo "========================================="
    echo "  Multi-Remote Transfer Setup"
    echo "========================================="
    echo ""
    echo "Available remotes:"
    # nl -w2 -s'. ' → line number ဖြင့် numbered list ပြသသည်
    rclone listremotes | nl -w2 -s'. '
    echo ""

    read -rp "Source remote name: " SOURCE_REMOTE
    check_remote "$SOURCE_REMOTE" || return 1
    read -rp "Source bucket name: " SOURCE_BUCKET
    read -rp "Source path (enter for root): " SOURCE_PATH

    echo ""
    echo "Select operation:"
    echo "1) Copy     (keep source)"
    echo "2) Move     (delete source after)"
    echo "3) Sync     (destination mirrors source)"
    echo "4) Download (Space → Local)"
    echo "5) Upload   (Local → Space)"
    echo "6) List     (browse source without transferring)"
    echo "7) Set ACL  (change ACL of existing files)"
    read -rp "Choice [1-7]: " op_choice

    case $op_choice in
        1) OPERATION="copy"     ;;
        2) OPERATION="move"     ;;
        3) OPERATION="sync"     ;;
        4) OPERATION="download" ;;
        5) OPERATION="upload"   ;;
        6)
            OPERATION="list"
            read -rp "Recursive? (yes/no, default no): " rec_choice
            [ "$rec_choice" = "yes" ] && LIST_RECURSIVE=true
            echo "Type: 1) all  2) dirs only  3) files only"
            read -rp "Choice [1-3, default 1]: " type_choice
            case $type_choice in
                2) LIST_TYPE="dirs"  ;;
                3) LIST_TYPE="files" ;;
                *) LIST_TYPE="all"   ;;
            esac
            ;;
        7)
            OPERATION="set-acl"
            ACL=$(prompt_acl "public-read")   # set-acl default suggestion: public-read
            read -rp "Apply recursively to sub-directories? (yes/no, default no): " rec_choice
            [ "$rec_choice" = "yes" ] && RECURSIVE=true
            # safety: default = dry-run ON; user က "no" ဆိုမှသာ actual change လုပ်မည်
            # accidental mass-ACL-change ကို ကာကွယ်ရန်
            read -rp "Dry-run first? (yes/no, default yes): " dr_choice
            [ "$dr_choice" = "no" ] || DRY_RUN=true
            ;;
        *) log_error "Invalid choice"; return 1 ;;
    esac

    # list/set-acl မဟုတ်သော transfer operations တွင်သာ ACL ရွေးချယ်ရမည်
    # (list/set-acl တွင် above case block ထဲ ACL ကို handle ပြီးသားဖြစ်သည်)
    if [[ "$OPERATION" =~ ^(copy|move|sync|upload)$ ]]; then
        ACL=$(prompt_acl "private")   # transfer default: private (safe default)
    fi

    # list/set-acl ဆိုပါက destination မလိုဘဲ source ကိုသာ စစ်ဆေးမည်
    if [[ ! "$OPERATION" =~ ^(list|set-acl)$ ]]; then
        echo ""
        read -rp "Destination remote name: " DEST_REMOTE
        check_remote "$DEST_REMOTE" || return 1
        read -rp "Destination bucket name: " DEST_BUCKET
        read -rp "Destination path (enter to mirror source): " DEST_PATH
    fi

    echo ""
    read -rp "Save this configuration? (yes/no): " save_it
    if [ "$save_it" = "yes" ]; then
        read -rp "Configuration name: " config_name
        save_config "$config_name"
    fi

    return 0
}

# ── Usage ──────────────────────────────────────────────────
show_usage() {
    cat << EOF
${GREEN}Usage:${NC} $0 [OPTIONS]

${GREEN}Transfer options:${NC}
    --source-remote REMOTE    Source remote name
    --source-bucket BUCKET    Source bucket name
    --source-path PATH        Source sub-path (optional)
    --dest-remote REMOTE      Destination remote name
    --dest-bucket BUCKET      Destination bucket name
    --dest-path PATH          Destination sub-path (optional; mirrors source by default)
    --operation OP            copy | move | sync | download | upload | list | set-acl
    --acl ACL                 ACL for uploaded/copied files (default: private)
                              Values: private | public-read | public-read-write | authenticated-read
    --recursive               For set-acl: descend into sub-directories
    --use-config              Load a saved configuration
    --list-configs            List all saved configurations
    --ls-recursive            List recursively (used with --operation list)
    --ls-type TYPE            dirs | files | all (default: all; used with --operation list)
    --verbose                 Verbose rclone output
    --dry-run                 Simulate without transferring / changing ACL
    --transfers NUM           Parallel transfers (default: 8; use 4-6 for cross-region)
    --retries NUM             Retry count on failure (default: 3; use 5 for cross-region)
    --help                    Show this help

${GREEN}ACL examples:${NC}
  Upload files as public-read:
    $0 --source-path /home/user/data \
       --dest-remote do-spaces-sgp --dest-bucket uploads \
       --operation upload --acl public-read

  Copy between Spaces with public-read:
    $0 --source-remote do-spaces-nyc --source-bucket my-data \
       --dest-remote do-spaces-ams   --dest-bucket  backup \
       --operation copy --acl public-read

  Change top-level files in a bucket to public-read (dry-run first):
    $0 --source-remote do-spaces-nyc --source-bucket my-data \
       --operation set-acl --acl public-read --dry-run

  Change all files recursively (including nested directories) to public-read:
    $0 --source-remote do-spaces-nyc --source-bucket my-data \
       --operation set-acl --acl public-read --recursive

  Change a specific sub-path back to private, recursively:
    $0 --source-remote do-spaces-nyc --source-bucket my-data \
       --source-path private/docs \
       --operation set-acl --acl private --recursive

${GREEN}List examples:${NC}
  List top-level of a bucket:
    $0 --source-remote do-spaces-nyc --source-bucket my-data --operation list

  List a sub-path, directories only, recursively:
    $0 --source-remote do-spaces-nyc --source-bucket my-data --source-path videos \
       --operation list --ls-type dirs --ls-recursive

  List a local directory:
    $0 --source-path /home/user/data --operation list
EOF
}

# ── Main ───────────────────────────────────────────────────
# script entry point: rclone စစ်ဆေးပြီး argument parsing စသည်
check_rclone

# argument parsing loop: argument တိုင်း case ဖြင့် match လုပ်ပြီး global variable set သည်
# shift 2 → current argument ($1) နဲ့ value ($2) နှစ်ခုလုံး consume ပြီး next argument သို့ ဆင်းသည်
while [[ $# -gt 0 ]]; do
    ARGS_PROVIDED=true
    case $1 in
        --source-remote) SOURCE_REMOTE="$2"; shift 2 ;;
        --source-bucket) SOURCE_BUCKET="$2"; shift 2 ;;
        --source-path)   SOURCE_PATH="$2";   shift 2 ;;
        --dest-remote)   DEST_REMOTE="$2";   shift 2 ;;
        --dest-bucket)   DEST_BUCKET="$2";   shift 2 ;;
        --dest-path)     DEST_PATH="$2";     shift 2 ;;
        --operation)     OPERATION="$2";     shift 2 ;;
        --acl)           ACL="$2";           shift 2 ;;
        --transfers)     TRANSFERS="$2";     shift 2 ;;
        --retries)       RETRIES="$2";       shift 2 ;;
        --recursive)     RECURSIVE=true;     shift ;;   # value မလိုသောကြောင့် shift 1 သာ
        --ls-recursive)  LIST_RECURSIVE=true; shift ;;
        --ls-type)       LIST_TYPE="$2";    shift 2 ;;
        --use-config)    USE_CONFIG=true;    shift ;;
        --list-configs)  list_configs; exit 0 ;;        # list ပြပြီး ချက်ချင်း exit
        --verbose)       VERBOSE=true;  shift ;;
        --dry-run)       DRY_RUN=true;  shift ;;
        --help)          show_usage; exit 0 ;;
        *) log_error "Unknown option: $1"; show_usage; exit 1 ;;
    esac
done

# argument တစ်ခုမှ မပေးပါက interactive mode ဝင်မည်
if [ "$ARGS_PROVIDED" = false ] && [ "$USE_CONFIG" = false ]; then
    if interactive_setup; then
        case "$OPERATION" in
            list)    list_source ;;
            set-acl) set_acl ;;
            *)       execute_transfer ;;
        esac
    fi
    exit 0
fi

# --use-config ပေးပါက config file မှ load လုပ်ပြီး global variable override လုပ်မည်
if [ "$USE_CONFIG" = true ]; then
    read -rp "Enter configuration name: " config_name
    load_saved_config "$config_name"
fi

# operation မပေးပါက error ထုတ်ပြီး usage ပြသမည်
if [ -z "$OPERATION" ]; then
    log_error "Operation not specified. Use --operation"
    show_usage
    exit 1
fi

# operation type အလိုက် route လုပ်သည် — validation နဲ့ required params စစ်ဆေးပြီးမှ function ခေါ်မည်

# List: browse source without transferring
if [ "$OPERATION" = "list" ]; then
    if [ -n "$SOURCE_REMOTE" ]; then
        check_remote "$SOURCE_REMOTE" || exit 1
    fi
    list_source
    exit 0
fi

# Set ACL: change ACL of existing remote objects
if [ "$OPERATION" = "set-acl" ]; then
    set_acl
    exit 0
fi

# Download: source is Space, destination is local path
if [ "$OPERATION" = "download" ]; then
    if [ -z "$SOURCE_REMOTE" ] || [ -z "$SOURCE_BUCKET" ]; then
        log_error "Download requires --source-remote and --source-bucket"
        exit 1
    fi
    check_remote "$SOURCE_REMOTE" || exit 1
    execute_transfer
    exit 0
fi

# Upload: source is local path, destination is Space
if [ "$OPERATION" = "upload" ]; then
    if [ -z "$DEST_REMOTE" ] || [ -z "$DEST_BUCKET" ]; then
        log_error "Upload requires --dest-remote and --dest-bucket"
        exit 1
    fi
    if [ -z "$SOURCE_PATH" ]; then
        log_error "Upload requires --source-path (local directory)"
        exit 1
    fi
    check_remote "$DEST_REMOTE" || exit 1
    execute_transfer
    exit 0
fi

# copy / move / sync — both sides are Spaces: source နဲ့ dest နှစ်ခုလုံး required
if [ -z "$SOURCE_REMOTE" ] || [ -z "$SOURCE_BUCKET" ] || \
   [ -z "$DEST_REMOTE"   ] || [ -z "$DEST_BUCKET"   ]; then
    log_error "Source and destination remotes/buckets are required for '$OPERATION'"
    show_usage
    exit 1
fi

# transfer မစတင်မီ remote နဲ့ bucket ရှိ/မရှိ စစ်ဆေးသည်
check_remote "$SOURCE_REMOTE" || exit 1
check_remote "$DEST_REMOTE"   || exit 1
check_bucket "$SOURCE_REMOTE" "$SOURCE_BUCKET" || exit 1
check_bucket "$DEST_REMOTE"   "$DEST_BUCKET"   || exit 1

execute_transfer
