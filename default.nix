{
  lib,
  config,
  dream2nix,
  ...
}: let
  isNixFile = path: lib.any (name: baseNameOf path == name) ["flake.nix" "flake.lock" "default.nix"];
in {
  imports = [
    dream2nix.modules.dream2nix.php-composer-lock
    dream2nix.modules.dream2nix.php-granular
  ];

  deps = {nixpkgs, ...}: {
    inherit
      (nixpkgs)
      fetchFromGitHub
      stdenv
      ;
  };

  name = "indielogin";
  version = "main";

  php-composer-lock = {
    source = builtins.filterSource (path: type: ! isNixFile path) ./.;
  };

  mkDerivation = {
    src = config.php-composer-lock.source;
  };
}
