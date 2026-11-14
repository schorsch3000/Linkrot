#!/usr/bin/env bash
# Installs the production dependencies and checks the setup. Run after unpacking a release, and again after
# every upgrade.
# usage: install.sh [--add-host <hostname>]... [--no-network-check]
#   --add-host          enroll a host (a tenant): lists it in app_data/global-config.php and creates its data folder
#   --no-network-check  skip the round trip through base_url (offline installs, a host that cannot reach itself)
set -euo pipefail

network_check=1
new_hosts=()
while [ $# -gt 0 ]; do
	case "$1" in
	--no-network-check) network_check=0 ;;
	--add-host)
		[ $# -ge 2 ] || {
			echo "install.sh: --add-host needs a hostname" >&2
			exit 2
		}
		new_hosts+=("$2")
		shift
		;;
	*)
		echo "usage: install.sh [--add-host <hostname>]... [--no-network-check]" >&2
		exit 2
		;;
	esac
	shift
done

cd "$(dirname "$0")"

command -v composer >/dev/null 2>&1 || {
	echo "install.sh: composer is required: https://getcomposer.org" >&2
	exit 1
}

composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader --classmap-authoritative

# app_data lives next to app/ and is never touched by a deployment (LINKROT_DATA overrides it, as for the server).
# It holds global-config.php (the hosts) and one folder per host.
data_dir="${LINKROT_DATA:-$(cd .. && pwd)/app_data}"
export LINKROT_DATA="$data_dir"

if [ ${#new_hosts[@]} -gt 0 ]; then
	[ -d "$data_dir" ] || mkdir -p "$data_dir"
fi
for new_host in "${new_hosts[@]}"; do
	host_status="$(php bin/hosts add "$new_host")" || exit 1
	host="${host_status##* }"
	# 711: nginx opens the files itself (X-Accel-Redirect), it needs search permission on the way down but no listing
	[ -d "$data_dir/$host" ] || mkdir -m 711 "$data_dir/$host"
	[ -d "$data_dir/$host/files" ] || mkdir -m 711 "$data_dir/$host/files"
	echo "install.sh: $host_status"
done

if [ ! -f "$data_dir/global-config.php" ]; then
	cat >&2 <<MSG

================================================================================
 ACTION REQUIRED: no host is set up yet.
 Until $data_dir/global-config.php exists every request is answered with
 "server misconfigured". Enroll the first host (one per hostname the server answers to):

     install.sh --add-host your.server.example

 Then follow the instructions it prints for the configuration of that host.
================================================================================

MSG
	exit 0
fi

hosts=()
while IFS= read -r line; do hosts+=("$line"); done < <(php bin/hosts) || exit 1

# Creates the databases or migrates them to the schema of this release. The server only checks the version.
for host in "${hosts[@]}"; do
	if [ -d "$data_dir/$host" ]; then
		LINKROT_HOST="$host" php bin/migrate
	else
		echo "install.sh: note: $data_dir/$host does not exist yet, run install.sh --add-host $host (or create it, owned by the php-fpm user) and run install.sh again to set up its database." >&2
	fi
done

# One host: the configuration, the files folder, the end-to-end check. Returns 1 if the check failed.
check_host() {
	local host="$1"
	local dir="$data_dir/$host"
	local config="$dir/config.php"

	[ -d "$dir" ] || return 0

	if [ ! -d "$dir/files" ]; then
		echo "install.sh: note: $dir/files does not exist yet, create it (writable by the php-fpm user)." >&2
	elif ! find "$dir/files" -maxdepth 0 -perm -001 | grep -q .; then
		# nginx opens the files itself (X-Accel-Redirect). If it runs as another user than php-fpm it needs search permission.
		echo "install.sh: note: $dir/files is not searchable by others. If nginx runs as another user than php-fpm, downloads fail with 403: chmod o+x $dir/files" >&2
	fi

	if [ ! -f "$config" ]; then
		cat >&2 <<MSG

================================================================================
 ACTION REQUIRED: there is no configuration for $host yet.
 Until it exists every request for this host is answered with "server misconfigured".

 Create $config, readable by the user php-fpm runs as:

     <?php
     return [
         'base_url' => 'https://$host',   // public URL, used for the share links
     ];

 The folder $dir/files must exist and be writable by that user too.
 All options: docs/SERVER.md (Configuration)
================================================================================

MSG
		return 0
	fi

	# The config exists: load it the way the server does, so that mistakes show up now and not as a 500 later.
	local error
	if ! error="$(bin/check-config "$config" 2>&1)"; then
		cat >&2 <<MSG

================================================================================
 WARNING: $config is not valid:

 $error

 (Checked as $(id -un). If that user may not read the file but php-fpm's user can, this is a false alarm.)
================================================================================

MSG
		return 0
	fi

	echo "install.sh: $config is valid."

	if [ "$network_check" = 0 ]; then
		echo "install.sh: $host: the end-to-end check was skipped (--no-network-check)."
		return 0
	fi

	# End to end: a temporary token (test-install-check, removed again) does health, upload, page, download and checks
	# the headers through the public base_url. A failure makes install.sh fail.
	if ! LINKROT_HOST="$host" bin/check-install; then
		cat >&2 <<MSG

================================================================================
 FAILED: the end-to-end check for $host did not pass, see the lines above.
 The installation itself is done. Fix the cause and run install.sh again; if this host cannot reach its own
 base_url (hairpin NAT, no DNS), run install.sh --no-network-check.
================================================================================

MSG
		return 1
	fi
	echo "install.sh: $host: end-to-end check passed."
}

failed=0
for host in "${hosts[@]}"; do
	check_host "$host" || failed=1
done
exit "$failed"
