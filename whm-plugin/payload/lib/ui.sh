# shellcheck shell=bash
# shellcheck disable=SC2034  # colors, glyphs and SM_CHILD are used by smwhmplugin
#
# SynapseMail WHM plugin: terminal UI.
# Copyright (c) 2026 SynapseCyber.io. All rights reserved. Developed by Milad Ahmadipour (https://milad.io).
# Licensed under CC BY-NC-ND 4.0 (https://creativecommons.org/licenses/by-nc-nd/4.0/).
#
# One centered panel (title, gradient progress bar, step list, footer) redrawn on every tick and on resize.
# Pure bash 4+ with stty/tput. Falls back to plain lines when stdout is not a terminal, TERM=dumb,
# --plain or NO_COLOR (colors only).

UI_TTY=0 UI_COLOR=none UI_UTF8=0 UI_ROWS=24 UI_COLS=80 UI_RESIZED=1 UI_ACTIVE=0 UI_SAVED_STTY=''
UI_TITLE='SynapseMail WHM plugin' UI_SUB='' UI_PCT=0 UI_STATUS='' UI_FOOT='' UI_FOOT2='' UI_SPIN=0
UI_STEPS=() UI_STATE=()   # UI_STATE[i]: wait | run | ok | fail | skip
UI_LAST=()                # last drawn lines, so only changed lines are written

ui_init() {
  local cm
  cm=$(locale charmap 2>/dev/null || true)
  if [[ $cm == UTF-8 ]]; then
    UI_UTF8=1
  elif locale -a 2>/dev/null | grep -qiE '^c\.utf-?8$'; then
    export LC_ALL=C.UTF-8
    UI_UTF8=1
  fi
  if [[ -t 1 && -r /dev/tty && -n ${TERM:-} && $TERM != dumb && -z ${SM_PLAIN:-} ]]; then
    UI_TTY=1
  fi
  UI_COLOR=none
  if (( UI_TTY )) && [[ -z ${NO_COLOR:-} ]]; then
    case ${COLORTERM:-} in
      truecolor|24bit) UI_COLOR=truecolor ;;
      *)
        local n
        n=$(tput colors 2>/dev/null || echo 0)
        if (( n >= 256 )); then UI_COLOR=256; elif (( n >= 8 )); then UI_COLOR=16; fi
        ;;
    esac
  fi
  if (( UI_UTF8 )); then
    G_OK='✔' G_FAIL='✖' G_WAIT='○' G_SKIP='–' G_FULL='█' G_EMPTY='░' G_DOT='·' G_ARROW='→'
    G_TL='╭' G_TR='╮' G_BL='╰' G_BR='╯' G_H='─' G_V='│' G_SPIN='⠋⠙⠹⠸⠼⠴⠦⠧⠇⠏'
  else
    G_OK='v' G_FAIL='x' G_WAIT='o' G_SKIP='-' G_FULL='#' G_EMPTY='.' G_DOT='-' G_ARROW='->'
    G_TL='+' G_TR='+' G_BL='+' G_BR='+' G_H='-' G_V='|' G_SPIN="|/-\\"
  fi
}

# ---------- colors ----------

ui_rgb() { # ui_rgb r g b -> foreground escape
  case $UI_COLOR in
    truecolor) printf '\e[38;2;%d;%d;%dm' "$1" "$2" "$3" ;;
    256) printf '\e[38;5;%dm' $(( 16 + 36 * ($1 * 5 / 255) + 6 * ($2 * 5 / 255) + ($3 * 5 / 255) )) ;;
    16) printf '\e[36m' ;;
  esac
}
ui_sgr() { [[ $UI_COLOR == none ]] || printf '\e[%sm' "$1"; }

ui_palette() {
  C_RESET=$(ui_sgr 0) C_BOLD=$(ui_sgr 1)
  if [[ $UI_COLOR == 16 ]]; then
    C_TEXT=$(ui_sgr 37) C_MUTED=$(ui_sgr 90) C_OK=$(ui_sgr 32) C_FAIL=$(ui_sgr 31) C_RUN=$(ui_sgr 36) C_ACC=$(ui_sgr 36) C_FRAME=$(ui_sgr 90) C_WARN=$(ui_sgr 33)
  elif [[ $UI_COLOR == none ]]; then
    C_TEXT='' C_MUTED='' C_OK='' C_FAIL='' C_RUN='' C_ACC='' C_FRAME='' C_WARN=''
  else
    C_TEXT=$(ui_rgb 226 232 240) C_MUTED=$(ui_rgb 100 116 139) C_OK=$(ui_rgb 94 214 160) C_FAIL=$(ui_rgb 244 114 114)
    C_RUN=$(ui_rgb 125 211 252) C_ACC=$(ui_rgb 147 160 255) C_FRAME=$(ui_rgb 51 65 85) C_WARN=$(ui_rgb 250 204 21)
  fi
}

# ---------- text width ----------

ui_strip() { # visible text without SGR escapes -> REPLY
  local s=$1
  shopt -s extglob
  s=${s//$'\e['*([0-9;])m/}
  REPLY=$s
}
ui_width() { ui_strip "$1"; REPLY=${#REPLY}; }

ui_fit() { # ui_fit "plain text" width -> REPLY (shortened with … when too long)
  local s=$1 w=$2
  if (( ${#s} > w )); then
    if (( w > 1 )); then s=${s:0:w-1}; s+=$([[ $UI_UTF8 == 1 ]] && printf '…' || printf '.'); else s=${s:0:w}; fi
  fi
  REPLY=$s
}

# ---------- terminal ----------

ui_size() {
  local s
  s=$(stty size </dev/tty 2>/dev/null) || s='24 80'
  UI_ROWS=${s% *} UI_COLS=${s#* }
  [[ $UI_ROWS =~ ^[0-9]+$ && $UI_ROWS -gt 0 ]] || UI_ROWS=24
  [[ $UI_COLS =~ ^[0-9]+$ && $UI_COLS -gt 0 ]] || UI_COLS=80
}

ui_enter() {
  (( UI_TTY )) || return 0
  (( UI_ACTIVE )) && return 0
  UI_SAVED_STTY=$(stty -g </dev/tty 2>/dev/null || true)
  tput smcup 2>/dev/null || true
  tput civis 2>/dev/null || true
  stty -echo </dev/tty 2>/dev/null || true
  UI_ACTIVE=1 UI_RESIZED=1
  trap 'UI_RESIZED=1' WINCH
}

ui_leave() {
  (( UI_ACTIVE )) || return 0
  UI_ACTIVE=0
  trap - WINCH
  printf '\e[0m'
  tput cnorm 2>/dev/null || true
  tput rmcup 2>/dev/null || true
  if [[ -n $UI_SAVED_STTY ]]; then stty "$UI_SAVED_STTY" </dev/tty 2>/dev/null || true; fi
  return 0
}

# ---------- panel ----------

ui_bar() { # ui_bar width -> REPLY: gradient bar (teal -> blue -> violet) filled to UI_PCT
  local w=$1 filled i out='' r g b t d
  filled=$(( UI_PCT * w / 100 ))
  d=$(( w > 1 ? w - 1 : 1 ))
  for (( i = 0; i < w; i++ )); do
    if (( i < filled )); then
      if [[ $UI_COLOR == none ]]; then
        out+=$G_FULL
      else
        t=$(( i * 200 / d ))   # 0..200: two segments of the gradient
        if (( t <= 100 )); then
          r=$(( 110 + (96 - 110) * t / 100 )) g=$(( 231 + (165 - 231) * t / 100 )) b=$(( 183 + (250 - 183) * t / 100 ))
        else
          t=$(( t - 100 ))
          r=$(( 96 + (176 - 96) * t / 100 )) g=$(( 165 + (139 - 165) * t / 100 )) b=$(( 250 + (250 - 250) * t / 100 ))
        fi
        out+=$(ui_rgb "$r" "$g" "$b")$G_FULL
      fi
    else
      out+=$C_FRAME$G_EMPTY
    fi
  done
  REPLY=$out$C_RESET
}

ui_lines() { # build the panel content (without frame) into UI_BODY; arg: inner width
  local w=$1 i mark label line
  UI_BODY=()
  UI_BODY+=("${C_BOLD}${C_TEXT}${UI_TITLE}${C_RESET}${UI_SUB:+ ${C_MUTED}${G_DOT} ${UI_SUB}${C_RESET}}")
  UI_BODY+=('')
  ui_bar "$w"; UI_BODY+=("$REPLY")
  ui_fit "$UI_STATUS" $(( w - 6 ))
  UI_BODY+=("$(printf '%s%3d%%%s  %s' "$C_MUTED" "$UI_PCT" "$C_RESET" "${C_MUTED}${REPLY}${C_RESET}")")
  UI_BODY+=('')
  for i in "${!UI_STEPS[@]}"; do
    ui_fit "${UI_STEPS[i]}" $(( w - 2 ))
    label=$REPLY
    case ${UI_STATE[i]:-wait} in
      ok) mark="${C_OK}${G_OK}${C_RESET}" line="${C_TEXT}${label}${C_RESET}" ;;
      fail) mark="${C_FAIL}${G_FAIL}${C_RESET}" line="${C_FAIL}${label}${C_RESET}" ;;
      run) mark="${C_RUN}${G_SPIN:UI_SPIN % ${#G_SPIN}:1}${C_RESET}" line="${C_BOLD}${C_TEXT}${label}${C_RESET}" ;;
      skip) mark="${C_MUTED}${G_SKIP}${C_RESET}" line="${C_MUTED}${label}${C_RESET}" ;;
      *) mark="${C_MUTED}${G_WAIT}${C_RESET}" line="${C_MUTED}${label}${C_RESET}" ;;
    esac
    UI_BODY+=("$mark $line")
  done
  if [[ -n $UI_FOOT ]]; then
    UI_BODY+=('')
    ui_fit "$UI_FOOT" "$w"
    UI_BODY+=("${C_MUTED}${REPLY}${C_RESET}")
  fi
  if [[ -n $UI_FOOT2 ]]; then
    ui_fit "$UI_FOOT2" "$w"
    UI_BODY+=("${C_MUTED}${REPLY}${C_RESET}")
  fi
}

ui_draw() {
  (( UI_TTY && UI_ACTIVE )) || return 0
  local full=0 w inner top left i n pad line frame=1 out=''
  if (( UI_RESIZED )); then
    UI_RESIZED=0 full=1
    ui_size
    UI_LAST=()
  fi
  w=$(( UI_COLS - 4 )); (( w > 74 )) && w=74
  if (( w < 36 || UI_ROWS < 12 )); then frame=0 w=$(( UI_COLS - 2 )); (( w < 10 )) && w=10; fi
  inner=$(( frame ? w - 6 : w ))
  ui_lines "$inner"
  n=${#UI_BODY[@]}
  if (( frame && n + 4 > UI_ROWS )); then # too short for the frame: drop it
    frame=0 w=$(( UI_COLS - 2 )) inner=$w
    ui_lines "$inner"
    n=${#UI_BODY[@]}
  fi
  if (( n > UI_ROWS )); then # still too short: drop the blank lines
    local keep=() l
    for l in "${UI_BODY[@]}"; do [[ -n $l ]] && keep+=("$l"); done
    UI_BODY=("${keep[@]}")
    n=${#UI_BODY[@]}
  fi
  local h=$(( frame ? n + 4 : n ))
  top=$(( (UI_ROWS - h) / 2 )); (( top < 0 )) && top=0
  left=$(( (UI_COLS - w) / 2 )); (( left < 0 )) && left=0
  local rows=()
  if (( frame )); then
    local bar; printf -v bar '%*s' $(( w - 2 )) ''; bar=${bar// /$G_H}
    rows+=("${C_FRAME}${G_TL}${bar}${G_TR}${C_RESET}")
    rows+=("${C_FRAME}${G_V}${C_RESET}$(printf '%*s' $(( w - 2 )) '')${C_FRAME}${G_V}${C_RESET}")
    for (( i = 0; i < n; i++ )); do
      ui_width "${UI_BODY[i]}"; pad=$(( inner - REPLY )); (( pad < 0 )) && pad=0
      rows+=("${C_FRAME}${G_V}${C_RESET}  ${UI_BODY[i]}$(printf '%*s' $(( pad + 2 )) '')${C_FRAME}${G_V}${C_RESET}")
    done
    rows+=("${C_FRAME}${G_V}${C_RESET}$(printf '%*s' $(( w - 2 )) '')${C_FRAME}${G_V}${C_RESET}")
    rows+=("${C_FRAME}${G_BL}${bar}${G_BR}${C_RESET}")
  else
    for (( i = 0; i < n; i++ )); do
      ui_width "${UI_BODY[i]}"; pad=$(( inner - REPLY )); (( pad < 0 )) && pad=0
      rows+=("${UI_BODY[i]}$(printf '%*s' "$pad" '')")
    done
  fi
  (( full )) && out+=$'\e[0m\e[2J'
  for (( i = 0; i < ${#rows[@]}; i++ )); do
    if (( top + i >= UI_ROWS )); then break; fi
    line=${rows[i]}
    if (( full )) || [[ ${UI_LAST[i]:-} != "$line" ]]; then
      out+=$(printf '\e[%d;%dH' $(( top + i + 1 )) $(( left + 1 )))$line
      UI_LAST[i]=$line
    fi
  done
  [[ -n $out ]] && printf '%s' "$out"
  return 0
}

# ---------- steps ----------

ui_steps() { # ui_steps "label" ... : set the step list (all waiting)
  UI_STEPS=("$@") UI_STATE=() UI_PCT=0 UI_STATUS=''
  local i; for i in "${!UI_STEPS[@]}"; do UI_STATE[i]='wait'; done
}

ui_plain() { # plain-mode line output
  (( UI_TTY && UI_ACTIVE )) && return 0
  printf '%s\n' "$*"
}

# ui_run INDEX "status text" function [args]: runs the function in the background (output to the log),
# animates the panel, marks the step ok/fail. Returns the function's exit code.
ui_run() {
  local idx=$1 status=$2 rc=0 pid n=${#UI_STEPS[@]}
  shift 2
  UI_STATE[idx]=run
  UI_STATUS=$status
  UI_PCT=$(( idx * 100 / n ))
  if (( UI_TTY && UI_ACTIVE )); then
    ( "$@" ) </dev/null >>"${SM_LOG:-/dev/null}" 2>&1 &
    pid=$!
    SM_CHILD=$pid
    while kill -0 "$pid" 2>/dev/null; do
      UI_SPIN=$(( UI_SPIN + 1 ))
      ui_draw
      sleep 0.08
    done
    wait "$pid" || rc=$?
    SM_CHILD=''
  else
    printf '  [%d/%d] %s ... ' $(( idx + 1 )) "$n" "${UI_STEPS[idx]}"
    ( "$@" ) </dev/null >>"${SM_LOG:-/dev/null}" 2>&1 || rc=$?
    if (( rc == 0 )); then printf 'ok\n'; else printf 'FAILED\n'; fi
  fi
  if (( rc == 0 )); then UI_STATE[idx]=ok; else UI_STATE[idx]=fail; fi
  UI_PCT=$(( (idx + 1) * 100 / n ))
  ui_draw
  return "$rc"
}

ui_done() { # ui_done "status": fill the bar and show the final frame for a moment
  UI_PCT=100 UI_STATUS=$1
  ui_draw
  (( UI_TTY && UI_ACTIVE )) && sleep 0.6
  return 0
}

# ui_ask "question" "hint" "default" -> REPLY : a centered question with an input line
ui_ask() {
  local q=$1 hint=$2 def=$3 w left top
  if [[ ! -r /dev/tty ]]; then REPLY=$def; return 0; fi
  if ! (( UI_TTY && UI_ACTIVE )); then
    printf '%s\n%s\n' "$q" "$hint"
    read -r -p "> " REPLY </dev/tty || REPLY=''
    REPLY=${REPLY:-$def}
    return 0
  fi
  ui_size
  w=$(( UI_COLS - 4 )); (( w > 74 )) && w=74; (( w < 20 )) && w=$(( UI_COLS > 2 ? UI_COLS - 2 : 1 ))
  left=$(( (UI_COLS - w) / 2 )); top=$(( (UI_ROWS - 9) / 2 )); (( top < 0 )) && top=0
  printf '\e[0m\e[2J'
  ui_fit "$UI_TITLE" "$w"; printf '\e[%d;%dH%s%s%s%s' $(( top + 1 )) $(( left + 1 )) "$C_BOLD" "$C_TEXT" "$REPLY" "$C_RESET"
  ui_fit "$q" "$w"; printf '\e[%d;%dH%s%s%s' $(( top + 3 )) $(( left + 1 )) "$C_TEXT" "$REPLY" "$C_RESET"
  ui_fit "$hint" "$w"; printf '\e[%d;%dH%s%s%s' $(( top + 4 )) $(( left + 1 )) "$C_MUTED" "$REPLY" "$C_RESET"
  local line; printf -v line '%*s' "$w" ''; line=${line// /$G_H}
  printf '\e[%d;%dH%s%s%s' $(( top + 7 )) $(( left + 1 )) "$C_FRAME" "$line" "$C_RESET"
  printf '\e[%d;%dH%s%s %s' $(( top + 6 )) $(( left + 1 )) "$C_ACC" "$G_ARROW" "$C_RESET"
  stty echo </dev/tty 2>/dev/null || true
  tput cnorm 2>/dev/null || true
  REPLY=''
  IFS= read -r REPLY </dev/tty || REPLY=''
  stty -echo </dev/tty 2>/dev/null || true
  tput civis 2>/dev/null || true
  REPLY=${REPLY:-$def}
  UI_RESIZED=1
  return 0
}

# ui_card "title" "line" ... : a centered static card printed into the normal screen (status, summaries)
ui_card() {
  local title=$1 w left i pad line inner
  shift
  if ! (( UI_TTY )); then
    printf '%s\n' "$title"
    for line in "$@"; do ui_strip "$line"; printf '  %s\n' "$REPLY"; done
    return 0
  fi
  ui_size
  w=$(( UI_COLS - 4 )); (( w > 74 )) && w=74
  if (( w < 36 )); then
    printf '%s%s%s\n' "$C_BOLD" "$title" "$C_RESET"
    for line in "$@"; do printf '%s\n' "$line"; done
    return 0
  fi
  inner=$(( w - 6 ))
  left=$(( (UI_COLS - w) / 2 ))
  local bar sp; printf -v bar '%*s' $(( w - 2 )) ''; bar=${bar// /$G_H}; printf -v sp '%*s' "$left" ''
  printf '\n%s%s%s%s%s\n' "$sp" "$C_FRAME" "$G_TL" "$bar" "$G_TR$C_RESET"
  printf '%s%s%s%s%*s%s%s%s\n' "$sp" "$C_FRAME" "$G_V" "$C_RESET" $(( w - 2 )) '' "$C_FRAME" "$G_V" "$C_RESET"
  for line in "${C_BOLD}${C_TEXT}${title}${C_RESET}" '' "$@"; do
    ui_strip "$line"
    if (( ${#REPLY} > inner )); then ui_fit "$REPLY" "$inner"; line=$REPLY; fi
    ui_width "$line"; pad=$(( inner - REPLY )); (( pad < 0 )) && pad=0
    printf '%s%s%s%s  %s%*s  %s%s%s\n' "$sp" "$C_FRAME" "$G_V" "$C_RESET" "$line" "$pad" '' "$C_FRAME" "$G_V" "$C_RESET"
  done
  printf '%s%s%s%s%*s%s%s%s\n' "$sp" "$C_FRAME" "$G_V" "$C_RESET" $(( w - 2 )) '' "$C_FRAME" "$G_V" "$C_RESET"
  printf '%s%s%s%s%s\n\n' "$sp" "$C_FRAME" "$G_BL" "$bar" "$G_BR$C_RESET"
}
