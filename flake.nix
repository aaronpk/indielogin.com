{
  description = "flake for indielogin";

  inputs = {
    nixpkgs.url = "nixpkgs/nixpkgs-unstable";
    parts.url = "github:hercules-ci/flake-parts";
    systems.url = "github:nix-systems/x86_64-linux";
    process-compose.url = "github:Platonic-Systems/process-compose-flake";
  };

  outputs =
    inp:
    inp.parts.lib.mkFlake { inputs = inp; } {
      systems = import inp.systems;
      imports = [
        inp.process-compose.flakeModule
      ];
      perSystem =
        {
          self',
          pkgs,
          lib,
          ...
        }:
        let
          phpPkg = pkgs.php82;
        in
        {
          formatter = pkgs.nixfmt-rfc-style;
          packages = {
            indielogin = phpPkg.buildComposerProject {
              pname = "indielogin";
              version = "0.0.1";

              composerStrictValidation = false;
              src = builtins.path {
                name = "indielogin-src";
                path = ./.;
                # dont add nix stuff to source
                filter = let
                  pathsFilter = [
                    "README.md"
                    "flake.nix"
                    "flake.lock"
                    "pgp"
                  ];
                in
                  p: _: ! (lib.any (op: baseNameOf p == op) pathsFilter);
              };

              # NOTE: should be updated when composer.lock changes
              vendorHash = "sha256-PrqC3RitzEhiul5VXYlvqN/rNb6KizAYVstQzfXRXTo=";
            };
            pgp = let
              gems = pkgs.bundlerEnv {
                name = "pgp";
                gemdir = ./pgp;
              };
            in
              pkgs.stdenv.mkDerivation {
                pname = "pgp";
                version = "0.0.1";

                src = ./pgp;

                installPhase = ''
                  # install src
                  mkdir -p $out/share/pgp
                  cp -r $src $out/share/pgp/src
                  # add serve bin
                  mkdir -p $out/bin
                  echo "#!${pkgs.stdenv.shell}" > $out/bin/pgp
                  echo "export PATH=\$PATH:${gems}/bin:${gems.wrappedRuby}/bin" >> $out/bin/pgp
                  #echo "export GEM_HOME=${gems}" >> $out/bin/pgp
                  echo "cd $out/share/pgp/src/" >> $out/bin/pgp
                  echo "rackup \$@" >> $out/bin/pgp
                  chmod +x $out/bin/pgp
                '';
              };
            default = self'.packages.indielogin-run;
          };
          process-compose."indielogin-run" =
            let
              phpIni = pkgs.writers.writeText "php.ini" ''
                date.timezone = "UTC"
                mbstring.internal_encoding = "UTF-8"
                memory_limit = 128M
              '';
              caddyfile = pkgs.writers.writeText "caddyfile" ''
                {
                  auto_https off
                }

                %baseurl%

                root * %rootpath%/public
                php_fastcgi 127.0.0.1:9000
                file_server
              '';
            in
            {
              settings.environment = {
                DOTENV_PATH = ".env";
                MYSQL_DATA_PATH = "mysql-data";
              };
              settings.processes = {
                php-fpm.command = pkgs.writeShellApplication {
                  name = "php-fpm-dev";
                  runtimeInputs = with pkgs; [
                    coreutils
                    gnused
                    phpPkg
                  ];
                  text = ''
                    set -x
                    # setup all the paths we will use as variables
                    phpfpmConfigDir="$(mktemp -d)"
                    phpfpmConfig="$phpfpmConfigDir/php-fpm.conf"
                    phpfpmConfigD="$phpfpmConfigDir/php-fpm.d"
                    # copy all the default configurations
                    cp --no-preserve=ownership,mode ${phpPkg}/etc/php-fpm.conf.default "$phpfpmConfig"
                    cp -r --no-preserve=ownership,mode ${phpPkg}/etc/php-fpm.d "$phpfpmConfigD"
                    mv "$phpfpmConfigD/www.conf.default" "$phpfpmConfigD/www.conf"
                    # fix include php-fpm.d in config
                    sed -i '/^include=/d' "$phpfpmConfig"
                    echo "include=$phpfpmConfigD/*.conf" >> "$phpfpmConfig"
                    # create log dir cause php-fpm doesnt do it for us
                    mkdir "$phpfpmConfigDir/log"
                    # finally, run php-fpm (and force it to be in the foreground)
                    exec php-fpm -p "$phpfpmConfigDir" -c ${phpIni} --fpm-config "$phpfpmConfig" -F
                  '';
                };

                caddy.command = pkgs.writeShellApplication {
                  name = "caddy-dev";
                  runtimeInputs = with pkgs; [
                    coreutils
                    findutils
                    caddy
                  ];
                  text = ''
                    set -x
                    # copy caddyfile so we can modify it
                    caddyfile="$(mktemp -u)"
                    cp --no-preserve=ownership,mode ${caddyfile} "$caddyfile"
                    # create a new rootpath
                    rootpath="$(mktemp -d)"
                    # copy all the contents we want to serve to new root path
                    cp -r --no-preserve=ownership,mode ${self'.packages.indielogin}/share/php/indielogin/* "$rootpath"
                    # make sure dotenv is present in the rootpath we are serving
                    # and also source the dotenv while we are at it (also dont log this cause it will have secrets in it)
                    set +x
                    [[ -f "$DOTENV_PATH" ]] && (cp "$DOTENV_PATH" "$rootpath" && export "$(xargs < "$DOTENV_PATH")")
                    set -x
                    # set root path and base url in caddyfile to our newly created one
                    sed -i "s|%rootpath%|$rootpath|g" "$caddyfile"
                    sed -i "s|%baseurl%|$BASE_URL|g"  "$caddyfile"
                    # finally run caddy with the caddyfile we modified
                    exec caddy run --adapter caddyfile --config "$caddyfile"
                  '';
                };

                pgp-verify.command = pkgs.writeShellApplication {
                  name = "pgp-verify-dev";
                  runtimeInputs = with pkgs; [coreutils self'.packages.pgp trurl];
                  text = ''
                    # source dot env
                    [[ -f "$DOTENV_PATH" ]] && export "$(xargs < "$DOTENV_PATH")"
                    set -x
                    # get host and port from dotenv to use
                    host="$(trurl "$PGP_VERIFICATION_API" -g '[host]')"
                    port="$(trurl "$PGP_VERIFICATION_API" -g '[port]')"
                    exec pgp --host "$host" -p "$port"
                  '';
                };

                redis.command = pkgs.writeShellApplication {
                  name = "redis-dev";
                  runtimeInputs = with pkgs; [
                    coreutils
                    redis
                    util-linux
                    trurl
                  ];
                  text = ''
                    # source dot env
                    [[ -f "$DOTENV_PATH" ]] && export "$(xargs < "$DOTENV_PATH")"
                    set -x
                    [[ -z "$REDIS_URL" ]] && echo "redis url not set, aborting" && exit 1
                    # split on colon delimeter and choose the latest item which should be our port
                    port="$(trurl "$REDIS_URL" -g '[port]')"
                    # run redis with the port we got
                    exec redis-server --port "$port" --loglevel notice
                  '';
                };

                mysql.command = pkgs.writeShellApplication {
                  name = "mysql-dev";
                  runtimeInputs = with pkgs; [
                    coreutils
                    mysql
                  ];
                  text = ''
                    [[ -f "$DOTENV_PATH" ]] && export "$(xargs < "$DOTENV_PATH")"
                    set -x
                    mysqlData="$MYSQL_DATA_PATH"
                    [[ -z "$mysqlData" ]] && echo "mysql data path not set, aborting" && exit 1
                    # make mysqlData an absolute path if its not already
                    if [[ "$mysqlData" != /* ]]; then
                      mysqlData="$(pwd)/$mysqlData"
                    fi
                    # only create db if the path requested does not exist
                    [[ -d "$mysqlData" ]] || (mkdir "$mysqlData" && mysql_install_db --ldata="$mysqlData")
                    # we configure mysql so that it uses less memory
                    exec mysqld --console --datadir "$mysqlData" \
                      --performance-schema=off \
                      --innodb-buffer-pool-size=5M \
                      --innodb-log-buffer-size=2M \
                      --key-buffer-size=16M \
                      --tmp-table-size=1M \
                      --max-connections=25 \
                      --sort-buffer-size=512K \
                      --read-buffer-size=256K \
                      --read-rnd-buffer-size=512K \
                      --join-buffer-size=128K \
                      --thread-stack=196K \
                      --bind-address="$DB_HOST" \
                      --unix-socket=off \
                      --socket=""
                  '';
                };
              };
            };
        };
    };
}
