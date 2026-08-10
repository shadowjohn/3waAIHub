mkdir() {
  local value
  for value in "$@"; do
    case "$value" in
      /DATA|/DATA/*)
        return 1
        ;;
    esac
  done
  command mkdir "$@"
}

chmod() {
  local value
  for value in "$@"; do
    case "$value" in
      /DATA|/DATA/*)
        return 1
        ;;
    esac
  done
  command chmod "$@"
}
