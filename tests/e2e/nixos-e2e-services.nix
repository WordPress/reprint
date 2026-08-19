{ config, pkgs, lib, ... }:

let
  # PHP with required extensions
  phpPackage = pkgs.php82.withExtensions ({ enabled, all }: enabled ++ [
    all.pdo_mysql
    all.pdo_sqlite
    all.zlib
    all.curl
    all.mbstring
    all.openssl
    all.simplexml
    all.tokenizer
    all.filter
    all.ctype
  ]);

  # Read site definitions from registry (single source of truth)
  registry = builtins.fromJSON (builtins.readFile ./site-registry.json);
  siteRoot = registry.siteRoot;

  # All test sites with their nginx configurations
  # Each site gets a unique port on 127.0.0.1
  testSites = lib.mapAttrs (name: value: { port = value.port; }) registry.sites;

  # Filter sites by nginx type from registry
  standardSites = lib.filterAttrs (n: v: !(v ? nginx)) registry.sites;
  bufferedSites = lib.filterAttrs (n: v: (v.nginx or null) == "buffered") registry.sites;
  cursorHeaderLimitSites = lib.filterAttrs (n: v: (v.nginx or null) == "cursor-header-limit") registry.sites;
  redirectSites = lib.filterAttrs (n: v: (v.nginx or null) == "redirect") registry.sites;

  # Generate Nginx virtualHost config for standard sites
  makeVhost = name: cfg: let
    fpmSocket = if (cfg.openBasedir or false)
      then config.services.phpfpm.pools."e2e-open-basedir".socket
      else config.services.phpfpm.pools.e2e.socket;
  in {
    name = "e2e-${name}";
    value = {
      listen = [{ addr = "127.0.0.1"; port = cfg.port; }];
      root = "${siteRoot}/${name}";
      locations = {
        "/" = {
          tryFiles = "$uri $uri/ /index.php?$query_string";
        };
        "~ \\.php$" = {
          extraConfig = ''
            fastcgi_pass unix:${fpmSocket};
            fastcgi_index index.php;
            fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
            include ${pkgs.nginx}/conf/fastcgi_params;
            fastcgi_param SITE_EXPORT_TEST_MODE "1";
            fastcgi_read_timeout 120s;
            fastcgi_send_timeout 120s;
          '';
        };
      };
    };
  };

  # Generate a vhost with the 8191-byte X-Export-Cursor threshold used by this test
  makeCursorHeaderLimitVhost = name: cfg: {
    name = "e2e-${name}";
    value = {
      listen = [{ addr = "127.0.0.1"; port = cfg.port; }];
      root = "${siteRoot}/${name}";
      extraConfig = ''
        # Let nginx parse the request before applying the explicit cursor limit.
        large_client_header_buffers 4 64k;
        if ($http_x_export_cursor ~ "^.{8191,}$") {
          return 431;
        }
      '';
      locations = {
        "/" = {
          tryFiles = "$uri $uri/ /index.php?$query_string";
        };
        "~ \\.php$" = {
          extraConfig = ''
            fastcgi_pass unix:${config.services.phpfpm.pools.e2e.socket};
            fastcgi_index index.php;
            fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
            include ${pkgs.nginx}/conf/fastcgi_params;
            fastcgi_param SITE_EXPORT_TEST_MODE "1";
            fastcgi_read_timeout 120s;
            fastcgi_send_timeout 120s;
          '';
        };
      };
    };
  };

  # Generate buffered vhost
  makeBufferedVhost = name: cfg: {
    name = "e2e-${name}";
    value = {
      listen = [{ addr = "127.0.0.1"; port = cfg.port; }];
      root = "${siteRoot}/${name}";
      locations = {
        "/" = {
          tryFiles = "$uri $uri/ /index.php?$query_string";
        };
        "~ \\.php$" = {
          extraConfig = ''
            fastcgi_pass unix:${config.services.phpfpm.pools.e2e.socket};
            fastcgi_index index.php;
            fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
            include ${pkgs.nginx}/conf/fastcgi_params;
            fastcgi_param SITE_EXPORT_TEST_MODE "1";
            fastcgi_read_timeout 120s;
            # Enable proxy buffering to simulate Apache-like buffering
            fastcgi_buffering on;
            fastcgi_buffer_size 128k;
            fastcgi_buffers 8 128k;
          '';
        };
      };
    };
  };

  # Generate redirect vhost
  makeRedirectVhost = name: cfg: {
    name = "e2e-${name}";
    value = {
      listen = [{ addr = "127.0.0.1"; port = cfg.port; }];
      locations = {
        "/" = {
          return = "301 http://127.0.0.1:${toString cfg.redirectTo}$request_uri";
        };
      };
    };
  };

  allVhosts = builtins.listToAttrs (
    (lib.mapAttrsToList makeVhost standardSites)
    ++ (lib.mapAttrsToList makeBufferedVhost bufferedSites)
    ++ (lib.mapAttrsToList makeCursorHeaderLimitVhost cursorHeaderLimitSites)
    ++ (lib.mapAttrsToList makeRedirectVhost redirectSites)
  );

in {
  # MariaDB (MySQL-compatible)
  services.mysql = {
    enable = true;
    package = pkgs.mariadb;
    settings = {
      mysqld = {
        bind-address = "127.0.0.1";
        max_allowed_packet = "64M";
        innodb_buffer_pool_size = "256M";
      };
    };
    ensureDatabases = [];
    ensureUsers = [
      {
        name = "e2e_admin";
        ensurePermissions = {
          "*.*" = "ALL PRIVILEGES";
        };
      }
      {
        name = "e2e_restricted";
        ensurePermissions = {
          "e2e_mysql_restricted.*" = "SELECT";
        };
      }
    ];
  };

  # PHP-FPM pool for e2e tests
  services.phpfpm.pools.e2e = {
    user = "nginx";
    group = "nginx";
    phpPackage = phpPackage;
    settings = {
      "listen.owner" = "nginx";
      "listen.group" = "nginx";
      "pm" = "dynamic";
      "pm.max_children" = 20;
      "pm.start_servers" = 4;
      "pm.min_spare_servers" = 2;
      "pm.max_spare_servers" = 8;
      "php_admin_value[memory_limit]" = "512M";
      "php_admin_value[max_execution_time]" = "120";
      "php_admin_value[upload_max_filesize]" = "50M";
      "php_admin_value[post_max_size]" = "50M";
      "php_admin_value[error_reporting]" = "E_ALL";
      "php_admin_value[display_errors]" = "Off";
      "php_admin_value[log_errors]" = "On";
      "php_admin_value[error_log]" = "/tmp/php-e2e-errors.log";
      "php_admin_value[user_ini.cache_ttl]" = "0";
      "php_admin_value[realpath_cache_ttl]" = "0";
    };
    phpEnv = {
      SITE_EXPORT_TEST_MODE = "1";
    };
  };

  # Keep open_basedir in its own worker pool. A request-level value can remain
  # active when the same worker handles a request for another test site.
  services.phpfpm.pools."e2e-open-basedir" = {
    user = "nginx";
    group = "nginx";
    phpPackage = phpPackage;
    settings = {
      "listen.owner" = "nginx";
      "listen.group" = "nginx";
      "pm" = "ondemand";
      "pm.max_children" = 4;
      "php_admin_value[memory_limit]" = "512M";
      "php_admin_value[max_execution_time]" = "120";
      "php_admin_value[upload_max_filesize]" = "50M";
      "php_admin_value[post_max_size]" = "50M";
      "php_admin_value[error_reporting]" = "E_ALL";
      "php_admin_value[display_errors]" = "Off";
      "php_admin_value[log_errors]" = "On";
      "php_admin_value[error_log]" = "/tmp/php-e2e-errors.log";
      "php_admin_value[user_ini.cache_ttl]" = "0";
      "php_admin_value[realpath_cache_ttl]" = "0";
      "php_admin_value[open_basedir]" = "${siteRoot}/open-basedir:/tmp";
    };
    phpEnv = {
      SITE_EXPORT_TEST_MODE = "1";
    };
  };

  # Nginx
  services.nginx = {
    enable = true;
    recommendedProxySettings = true;
    clientMaxBodySize = "50m";
    virtualHosts = allVhosts;
  };

  # Open firewall ports for test sites
  networking.firewall.allowedTCPPorts =
    lib.mapAttrsToList (_: cfg: cfg.port) testSites;

  # Ensure site directory exists
  systemd.tmpfiles.rules = [
    "d ${siteRoot} 0755 nginx nginx -"
  ];

  # Make PHP CLI available
  environment.systemPackages = [
    phpPackage
    phpPackage.packages.composer
    pkgs.mariadb
  ];
}
