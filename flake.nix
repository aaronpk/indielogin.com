{
  description = "flake for indielogin";

  inputs = {
    dream2nix.url = "github:nix-community/dream2nix";
    nixpkgs.follows = "dream2nix/nixpkgs";
    parts.url = "github:hercules-ci/flake-parts";
    systems.url = "github:nix-systems/x86_64-linux";
    process-compose.url = "github:Platonic-Systems/process-compose-flake";
    phps.url = "github:fossar/nix-phps";
    phps.inputs.nixpkgs.follows = "nixpkgs";
  };

  outputs = inp:
    inp.parts.lib.mkFlake {inputs = inp;} {
      systems = import inp.systems;
      imports = [
        inp.process-compose.flakeModule
      ];
      perSystem = {self', system, pkgs, ...}: {
        apps = {
          dev = {
            type = "app";
            program = "${self'.packages.indielogin-start-dev}/bin/indielogin-start-dev";
          };
          default = self'.apps.dev;
        };
        packages = {
          indielogin = 
            inp.dream2nix.lib.evalModules {
              packageSets.nixpkgs = inp.nixpkgs.legacyPackages.${system};
              modules = [
                ./default.nix
                {
                  paths.projectRoot = ./.;
                  paths.projectRootFile = "composer.json";
                  paths.package = ./.;
                }
              ];
            };
          default = self'.packages.indielogin;
        };
        process-compose."indielogin-start-dev" = let
          phpPkg = inp.phps.packages.${system}.php82;
          phpIni = pkgs.writers.writeText "php.ini" ''
            date.timezone = "Europe/Istanbul"
            mbstring.internal_encoding = "UTF-8"
            memory_limit = 128M
          '';
          caddyfile = pkgs.writers.writeText "caddyfile" ''
            {
              auto_https off
            }

            http://localhost:8080

            root * public
            php_fastcgi 127.0.0.1:9000
          '';
        in {
          settings.processes = {
            php-fpm.command = pkgs.writeShellApplication {
              name = "php-fpm-dev";
              runtimeInputs = with pkgs; [coreutils gnused phpPkg phpPkg.packages.composer];
              text = ''
                set -x
                # first install composer
                composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
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
              runtimeInputs = with pkgs; [coreutils caddy];
              text = ''
                caddyfile="$(mktemp -u)"
                cp -f --no-preserve=ownership,mode ${caddyfile} "$caddyfile"
                sed -i "s|public|$(pwd)/public|g" "$caddyfile"
                exec caddy run --adapter caddyfile --config "$caddyfile"
              '';
            };

            redis.command = pkgs.writeShellApplication {
              name = "redis-dev";
              runtimeInputs = with pkgs; [coreutils redis];
              text = ''
                set -x
                exec redis-server --port 6379 --loglevel notice
              '';
            };

            mysql.command = pkgs.writeShellApplication {
              name = "mysql-dev";
              runtimeInputs = with pkgs; [coreutils mysql];
              text = ''
                set -x
                mysqlData="$(pwd)/mysql-data"
                # only create db if we already didnt create it
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
                  --bind-address=127.0.0.1 \
                  --unix-socket=off \
                  --socket=""
              '';
            };
          };
        };
      };
    };
}
