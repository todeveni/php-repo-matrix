# PHP build matrix

**Live report: <https://todeveni.github.io/php-repo-matrix/>** (updated every 15 minutes)

A single, self-contained PHP script that builds an HTML overview of what is
published in [deb.sury.org/php](https://packages.sury.org/php) and
[ppa:ondrej/php](https://launchpad.net/~ondrej/+archive/ubuntu/php). It shows a
matrix of PHP branch × distro release × architecture, so missing or lagging
builds stand out right away (for example, PHP 7.4 missing for armhf on 24.04).

Background and discussion:
[deb.sury.org#157](https://codeberg.org/oerdnj/deb.sury.org/issues/157).

## What it shows

- **Main matrix**: the core and SAPI packages of each PHP branch (`phpX.Y`,
  `-common`, `-cli`, `-fpm`, `-cgi`, the Apache module, embed, litespeed).
  Clicking a cell shows the latest changelog entries of that source package.
- **All other packages** (extensions, libraries), one cell per distro.
- **Builds in progress**, so an upload that is still building isn't mistaken
  for a broken one:
  - PPA: queued or running builds, from the Launchpad API.
  - deb.sury.org: packaging branches whose latest commit is newer than the
    published build, or a newer version already building on the PPA. A commit
    still unbuilt after a few hours is flagged as a possibly failed build.
- **Upstream freshness**: PHP versions are compared with the latest releases
  on php.net; other source packages are checked against their `debian/watch`
  file, the way uscan does.
- **Todo list**: one row per thing to do for a source package, with filters.

The data comes from the public APT indexes (Release, Packages and Sources),
read the same way apt does. Everything is cached locally and revalidated with
conditional requests, so unchanged indexes aren't downloaded again.

The deb.sury.org build status is an educated guess from commits and versions,
as its Jenkins isn't public. A JSON export of the Jenkins build queue in a
public place (e.g. `packages.sury.org/php/jenkins-build-status.json`) would
let the report track actual build status.

## Usage

Requires PHP 8.5 with the curl and zlib extensions, and `tar` with xz support.

```sh
php php-repo-matrix.php [--output=matrix.html|-] [--config=file.php]
                        [--no-cache] [--no-publish] [--include-unsupported]
```

The report is written to `matrix.html` next to the script (`--output=-` for
stdout), then copied with scp to every `publish` target, if any. It can also
be served by a web server, in which case the HTML goes to the client.

## Configuration

The defaults at the top of the script can be overridden with a PHP file that
returns an array: `php-repo-matrix.config.php` next to the script, or
`--config=file.php`. See
[php-repo-matrix.config.php.example](php-repo-matrix.config.php.example).

## GitHub Pages

[`.github/workflows/publish.yml`](.github/workflows/publish.yml) builds the
report every 15 minutes, keeps the script's cache between runs and deploys
the page to GitHub Pages. To run it in a fork, set Settings → Pages → Source
to "GitHub Actions".

## Development

The code follows PSR-12, indented with tabs. PHP_CodeSniffer is the only
(development) dependency:

```sh
composer install
composer lint   # phpcs
composer fix    # phpcbf
```

Pull requests are linted by
[`.github/workflows/lint.yml`](.github/workflows/lint.yml).

## License

[MIT](LICENSE)
