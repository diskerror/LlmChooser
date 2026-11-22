#!/bin/bash
# LlmChooser installer for Debian/Ubuntu. Installs the service files from
# deploy/ with your user, directory, port and worker count filled in, and
# sets the permissions on storage/.
#
#   sudo ./install.sh [--mode php-s|nginx] [--user llama] [OPTIONS]
#        ./install.sh  --mode user [OPTIONS]          (no sudo)
#
# --mode php-s   (default) Option A: PHP's built-in server as a system
#                service, /etc/systemd/system/llmchooser.service, running
#                as --user (created if missing).
# --mode user    Option A as a per-user service for the user running this
#                script: ~/.config/systemd/user/llmchooser.service. No sudo;
#                suits an install in your home directory.
# --mode nginx   Option B: nginx + a dedicated php-fpm pool running as --user.
# --user NAME    Service user for php-s and nginx (default: llama).
# --port N       Port to listen on (default: 1234).
# --workers N    Requests served at once (default: 8).
# --dry-run      Print what would be done; change nothing.
# --root DIR     Write the service files under DIR instead (for testing);
#                skips systemctl, nginx and user/ownership changes.
#
# It never installs packages or changes package sources: it checks for what
# it needs and prints the apt command if something is missing. Existing files
# are not overwritten: a differing file is left alone and the new version is
# written next to it as *.new for you to compare.
set -euo pipefail

MODE=php-s
SVC_USER=llama
PORT=1234
WORKERS=8
DRY=0
ROOT=
APP_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)

die()  { echo "install.sh: $*" >&2; exit 1; }
say()  { echo "==> $*"; }
run()  { if [ "$DRY" = 1 ] || [ -n "$ROOT" ]; then echo "    (skip) $*"; else "$@"; fi; }

while [ $# -gt 0 ]; do
	case "$1" in
		--mode)    MODE=$2; shift ;;
		--user)    SVC_USER=$2; shift ;;
		--port)    PORT=$2; shift ;;
		--workers) WORKERS=$2; shift ;;
		--dry-run) DRY=1 ;;
		--root)    ROOT=$2; shift ;;
		-h|--help) sed -n '2,26p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
		*) die "unknown option $1 (see --help)" ;;
	esac
	shift
done

case "$MODE" in php-s|user|nginx) ;; *) die "--mode must be php-s, user or nginx" ;; esac
[[ "$PORT" =~ ^[0-9]+$ ]] || die "--port must be a number"
[[ "$WORKERS" =~ ^[0-9]+$ ]] && [ "$WORKERS" -ge 2 ] || die "--workers must be 2 or more"
if [ "$MODE" = user ]; then
	[ "$(id -u)" != 0 ] || die "--mode user installs for the user running it: run it without sudo"
	SVC_USER=$(id -un)
else
	[[ "$SVC_USER" =~ ^[a-z_][a-z0-9_-]*$ ]] || die "--user is not a valid user name"
	if [ "$DRY" = 0 ] && [ -z "$ROOT" ] && [ "$(id -u)" != 0 ]; then
		die "run with sudo (or use --dry-run, or --mode user)"
	fi
fi

# ---------------------------------------------------------------- checks
say "Checking requirements"
missing=()
command -v php >/dev/null || missing+=(php-cli)
if command -v php >/dev/null; then
	PHPV=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
	php -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);' || die "PHP $PHPV found; 8.1 or newer is required"
	# pcntl and posix come with php-cli on Debian/Ubuntu (no separate package).
	for ext in curl intl pcntl posix; do
		php -m | grep -qix "$ext" || missing+=("php-$ext")
	done
	echo "    PHP $PHPV"
fi

if [ "$MODE" = nginx ]; then
	{ command -v nginx || [ -x /usr/sbin/nginx ]; } >/dev/null || missing+=(nginx-light)
	[ -n "${PHPV:-}" ] && { [ -d "/etc/php/$PHPV/fpm" ] || missing+=("php$PHPV-fpm"); }
fi

if [ ${#missing[@]} -gt 0 ]; then
	echo "    Missing: ${missing[*]}"
	echo "    Install with: sudo apt install ${missing[*]}"
	die "install the missing packages, then run this again"
fi

[ -f "$APP_DIR/vendor/autoload.php" ] \
	|| die "vendor/ is missing: run 'composer install --no-dev' in $APP_DIR first (as your own user, not root)"
[ -f "$APP_DIR/public/index.php" ] || die "$APP_DIR does not look like LlmChooser"

# ---------------------------------------------------------------- user
say "Service user: $SVC_USER"
if [ "$MODE" = user ]; then
	for g in video render; do   # GPU access for the models it starts
		if getent group "$g" >/dev/null && ! id -nG | grep -qw "$g"; then
			echo "    NOTE: you are not in group $g; models may not see the GPU."
			echo "          Fix with: sudo usermod -aG $g $SVC_USER   (then log in again)"
		fi
	done
else
	if ! getent passwd "$SVC_USER" >/dev/null; then
		echo "    creating system user $SVC_USER (home /var/lib/$SVC_USER)"
		run useradd --system --create-home --home-dir "/var/lib/$SVC_USER" --shell /usr/sbin/nologin "$SVC_USER"
	fi
	for g in video render; do
		if getent group "$g" >/dev/null && ! id -nG "$SVC_USER" 2>/dev/null | grep -qw "$g"; then
			echo "    adding $SVC_USER to group $g"
			run usermod -aG "$g" "$SVC_USER"
		fi
	done
fi

# Vulkan/Mesa keeps its shader cache in ~/.cache; without it every model
# start logs a permission error and runs without the cache.
HOME_DIR=$(getent passwd "$SVC_USER" | cut -d: -f6 || true)
if [ -n "$HOME_DIR" ] && [ ! -d "$HOME_DIR/.cache" ]; then
	echo "    creating $HOME_DIR/.cache"
	if [ "$MODE" = user ]; then
		[ "$DRY" = 1 ] || mkdir -p "$HOME_DIR/.cache"
	else
		run install -d -o "$SVC_USER" -g "$SVC_USER" "$HOME_DIR/.cache"
	fi
fi

# ---------------------------------------------------------------- storage
# Only the service user needs storage/ (presets, logs with prompts and
# paths, runtime state): owner read/write, group read, others nothing.
# The app itself creates new files with umask 027 to keep it that way.
say "storage/: owner $SVC_USER, directories 750, files 640"
STORAGE="$APP_DIR/storage"
if [ "$MODE" = user ]; then
	if [ -n "$(find "$STORAGE" ! -user "$SVC_USER" -print -quit)" ]; then
		die "some files in $STORAGE are not owned by $SVC_USER; fix with: sudo chown -R $SVC_USER: $STORAGE"
	fi
else
	run chown -R "$SVC_USER:" "$STORAGE"
fi

if [ "$DRY" = 1 ] || { [ -n "$ROOT" ] && [ "$MODE" != user ]; }; then
	echo "    (skip) chmod"
else
	find "$STORAGE" -type d -exec chmod 750 {} +
	find "$STORAGE" -type f -exec chmod 640 {} +
fi

# ---------------------------------------------------------------- files
# Fill in a deploy/ template: the defaults in the templates are replaced
# token by token, so the templates stay copy-and-paste ready.
render() {
	sed -e "s#/opt/llmchooser#$APP_DIR#g" \
	    -e "s#^User=llama#User=$SVC_USER#; s#^Group=llama#Group=$SVC_USER#" \
	    -e "s#^user = llama#user = $SVC_USER#; s#^group = llama#group = $SVC_USER#" \
	    -e "s#0\.0\.0\.0:1234#0.0.0.0:$PORT#; s#listen 1234;#listen $PORT;#" \
	    -e "s#PHP_CLI_SERVER_WORKERS=8#PHP_CLI_SERVER_WORKERS=$WORKERS#; s#^pm.max_children = 8#pm.max_children = $WORKERS#" \
	    -e "s#/usr/bin/php #$(command -v php) #" \
	    -e "s#/etc/php/8\.4/#/etc/php/$PHPV/#g; s#php8\.4-fpm#php$PHPV-fpm#g" \
	    "$APP_DIR/deploy/$1"
}
changed=0

place() {   # place TEMPLATE DEST
	local dest="$ROOT$2" new
	new=$(render "$1")
	if [ -f "$dest" ] && [ "$(cat "$dest")" = "$new" ]; then
		echo "    unchanged: $dest"
	elif [ -f "$dest" ]; then
		echo "    EXISTS and differs, left alone: $dest"
		echo "    new version written to:         $dest.new  (compare, then move it into place)"
		[ "$DRY" = 1 ] || printf '%s\n' "$new" > "$dest.new"
	else
		echo "    writing $dest"
		changed=1
		if [ "$DRY" = 0 ]; then
			mkdir -p "$(dirname "$dest")"
			printf '%s\n' "$new" > "$dest"
		fi
	fi
}

case "$MODE" in
php-s)
	say "Option A: php -S with $WORKERS workers on port $PORT"
	place llmchooser.service /etc/systemd/system/llmchooser.service
	if [ "$changed" = 1 ]; then
		run systemctl daemon-reload
		run systemctl enable --now llmchooser
	else
		echo "    not starting: run 'sudo systemctl enable --now llmchooser' once the unit is as you want it"
	fi
	;;
user)
	say "Option A, per-user service: php -S with $WORKERS workers on port $PORT"
	place llmchooser-user.service "${XDG_CONFIG_HOME:-$HOME/.config}/systemd/user/llmchooser.service"
	if [ "$changed" = 1 ]; then
		run systemctl --user daemon-reload
		run systemctl --user enable --now llmchooser
	else
		echo "    not starting: run 'systemctl --user enable --now llmchooser' once the unit is as you want it"
	fi
	# Without lingering, user services stop at logout and don't start at boot.
	if [ "$(loginctl show-user "$SVC_USER" -P Linger 2>/dev/null || echo no)" != yes ]; then
		echo "    enabling linger (keeps it running after logout, starts it at boot)"
		if [ "$DRY" = 1 ] || [ -n "$ROOT" ]; then
			echo "    (skip) loginctl enable-linger $SVC_USER"
		elif ! loginctl enable-linger "$SVC_USER" 2>/dev/null; then
			echo "    could not enable linger; run: sudo loginctl enable-linger $SVC_USER"
		fi
	fi
	;;
nginx)
	say "Option B: nginx + php$PHPV-fpm pool 'llmchooser' on port $PORT"
	place php-fpm-llmchooser.conf "/etc/php/$PHPV/fpm/pool.d/llmchooser.conf"
	place php-fpm-killmode.conf   "/etc/systemd/system/php$PHPV-fpm.service.d/llmchooser.conf"
	place nginx-llmchooser.conf   /etc/nginx/sites-available/llmchooser
	if [ ! -e "$ROOT/etc/nginx/sites-enabled/llmchooser" ]; then
		echo "    linking sites-enabled/llmchooser"
		[ "$DRY" = 1 ] || { mkdir -p "$ROOT/etc/nginx/sites-enabled"; ln -s /etc/nginx/sites-available/llmchooser "$ROOT/etc/nginx/sites-enabled/llmchooser"; }
	fi
	# nginx (www-data) serves public/ itself, so it must be able to reach it.
	if [ "$DRY" = 0 ] && [ -z "$ROOT" ] && ! sudo -u www-data test -r "$APP_DIR/public/index.php"; then
		echo "    WARNING: www-data cannot read $APP_DIR/public (a private home directory?)."
		echo "             Install under /opt, or use --mode php-s / --mode user."
	fi
	if [ "$changed" = 1 ]; then
		run systemctl daemon-reload
		run systemctl restart "php$PHPV-fpm"
		run nginx -t
		run systemctl reload nginx
	else
		echo "    not restarting anything: no new files were installed"
	fi
	;;
esac

say "Done. Open http://$(hostname -f 2>/dev/null || hostname):$PORT/"
