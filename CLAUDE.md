# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this project is

A [Castor](https://github.com/jolicode/castor) task runner (`castor.php`) that automates two workflows:

1. **Scaffold new Symfony admin projects** — clones [jolicode/docker-starter](https://github.com/jolicode/docker-starter), installs Symfony, and sets up [aropixel/admin-bundle](https://github.com/aropixel/admin-bundle) with Docker infrastructure.
2. **Bootstrap a contribution environment** for `aropixel/admin-bundle` — forks the repo on GitHub, clones the fork, creates a Symfony sandbox with the bundle installed as a path repository (symlink) so changes are reflected immediately without republishing.

The `vendor/` directory contains Castor itself, not the generated project.

## Prerequisites

- [Castor](https://github.com/jolicode/castor) installed globally
- Docker + Docker Compose
- `gh` CLI (GitHub CLI) — required for all `contrib:*` commands; must be authenticated (`gh auth login`)

## Available tasks

```bash
# Scaffold a new Symfony admin project
castor new:admin <project-name>

# Bootstrap a contribution environment (admin-bundle is always the base)
castor contrib:admin <dir>

# Add a bundle to an existing contrib environment (auto-runs contrib:admin if dir missing)
castor contrib:blog <dir>
castor contrib:page <dir>
castor contrib:menu <dir>

# List all tasks
castor
```

Both commands take the target directory name as a CLI argument and fail immediately if it already exists. Interactive prompts follow for domain, PHP version, etc.

## File structure

```
castor.php                  # Task entry points (two #[AsTask] functions)
.castor/
  scaffold.php              # Helpers for new-admin (file ops, Docker customizations)
  slug.php                  # Admin slug generation and word list management
  contrib.php               # Helpers for contrib:admin (gh CLI, path repository)
resources/
  application/
    config/packages/security.yaml   # Template — contains __ADMIN_SLUG__ placeholder
    config/routes/aropixel_admin.yaml
    .env.local
    private/pixel.png
  infrastructure/docker/
    docker-compose.yml              # MySQL, PHP, Varnish, phpMyAdmin, Mailpit, Messenger
    services/varnish/default.vcl
  clevercloud/
    post_build.sh
    varnish.vcl
```

## Architecture

### `castor.php` — tasks

| Task                     | Function | Description |
|--------------------------|---|---|
| `new:admin` | `aropixel_new_admin(string $name)` | Full project scaffold |
| `contrib:admin` | `aropixel_contrib_admin(string $name)` | Contribution environment (base — always required) |
| `contrib:blog` | `aropixel_contrib_blog(string $name)` | Adds blog-bundle (auto-runs admin if dir missing) |
| `contrib:page` | `aropixel_contrib_page(string $name)` | Adds page-bundle (auto-runs admin if dir missing) |
| `contrib:menu` | `aropixel_contrib_menu(string $name)` | Adds menu-bundle (auto-runs admin if dir missing) |

### `.castor/scaffold.php` — helpers for `new-admin`

| Function | What it does |
|---|---|
| `customizeDockerStarterCastorFile($dir, $name, $domain, $phpVersion)` | Patches `create_default_variables()` in the cloned docker-starter's `castor.php` with project name, TLD, domains, PHP version |
| `copyInitialInfrastructureFiles($dir)` | Copies `docker-compose.yml` and Varnish VCL from `resources/` into the new project |
| `customizeDockerfile($dir)` | Adds PHP extensions (gd, imagick, mysql, redis) to the docker-starter Dockerfile |
| `customizeDockerPhp($dir)` | Injects `MAIL_DOMAIN` env var into `.castor/docker.php` |
| `cleanNginxConfig($dir)` | Removes the static-file cache location block from the Nginx config |
| `createProjectDirectories($dir)` | Creates `.claude/`, `application/private/`, `application/public/media/`, `application/src/Controller/Admin/` |
| `copyStarterFiles($dir, $adminSlug)` | Copies template files from `resources/` and substitutes `__ADMIN_SLUG__` in `security.yaml` and route config |
| `addMissingBundles($dir)` | Adds `LiipImagineBundle` and `StofDoctrineExtensionsBundle` to `config/bundles.php` if absent |
| `copyAdminBundleSkills($dir)` | Copies the bundle's `skills/` directory into `.claude/skills/` of the new project |
| `generateGitignore($dir)` | Writes `.gitignore` files in `application/private/` and `application/public/media/` |

### `.castor/slug.php` — admin slug generation

| Function | What it does |
|---|---|
| `askAdminSlug(int $parts = 3)` | Interactive prompt with regeneration loop ("r" to regenerate) |
| `generateAdminSlug(int $parts = 3)` | Builds a random hyphenated slug, e.g. `velvet-harbor-signal` |
| `loadAdminSlugWords()` | Loads words from `~/.config/aropixel/aropixel-starter/words.php`; falls back to hardcoded list if absent or invalid |

Word categories: `adjectives`, `nouns`, `extras`, `tails` (optional, used for 4-part slugs).

### `.castor/contrib.php` — helpers for `contrib:admin`

| Function | What it does |
|---|---|
| `checkGhCli()` | Verifies `gh` is installed and authenticated; throws `RuntimeException` otherwise |
| `getGithubUsername()` | Returns the authenticated GitHub login via `gh api user --jq .login` |
| `forkBundle($repoName)` | Forks `aropixel/<repoName>` via `gh repo fork` (idempotent) |
| `cloneBundleFork($targetDir, $username, $repoName)` | Clones `git@github.com:<user>/<repoName>.git` into `$targetDir` |
| `createWorkingBranch($bundleDir, $branchName)` | Runs `git checkout -b <branch>` inside the fork clone |
| `configureEnvFile($contribDir)` | Patches `application/.env`: sets MySQL `DATABASE_URL` and `MAILER_DSN=smtp://mail:25` |
| `addPathRepository($contribDir, $bundleName)` | Injects `{"type":"path","url":"../<bundleName>","options":{"symlink":true}}` into `application/composer.json` (no-op if already present) |
| `installContribBundle($name, $bundleName, $packageName)` | Shared logic for blog/page/menu: fork, clone, optional branch, path repo, composer require |

## Generated structure for `contrib:admin`

```
<name>/                       ← docker-starter root (mounted as /var/www/ in Docker)
  admin-bundle/               ← fork clone → /var/www/admin-bundle/ in container
  application/                ← Symfony app → /var/www/application/ in container
    composer.json             ← path repo url: ../admin-bundle
    vendor/aropixel/admin-bundle → symlink to ../admin-bundle
```

`admin-bundle/` sits at the docker-starter root alongside `application/`, so both fall within the Docker volume mount (`../..:/var/www` relative to `infrastructure/docker/`). The path repo URL `../admin-bundle` resolves correctly both on the host and inside the container (`/var/www/admin-bundle`). Changes in `admin-bundle/` are immediately visible without any `composer update`.

## Code patterns

```php
// Run a command in the current working directory
run('git clone https://github.com/...');

// Run in a specific directory
run('castor start', context: \Castor\context()->withWorkingDirectory($dir));

// Run and capture output
$value = trim(\Castor\capture('gh api user --jq .login'));

// Run and allow failure
$output = \Castor\capture('gh auth status 2>&1', allowFailure: true);

// Interactive prompts
$domain   = io()->ask('Label', 'default');
$version  = io()->choice('Label', ['8.2', '8.3', '8.4'], '8.3');

// Output
io()->title('...');
io()->section('1. Step name');
io()->listing([...]);
io()->success([...]);
io()->writeln('<info>text</info>');
```

## Custom word list format

Place at `~/.config/aropixel/aropixel-starter/words.php`. Must return an array with keys `adjectives`, `nouns`, `extras` (and optionally `tails` for 4-part slugs):

```php
<?php
return [
    'adjectives' => ['silent', 'velvet', ...],
    'nouns'      => ['harbor', 'mirror', ...],
    'extras'     => ['signal', 'vault', ...],
    'tails'      => ['delta', 'summit', ...], // optional
];
```

If the file is absent or invalid, a hardcoded fallback list is used (8 words per category).

## Key conventions

- **All helper functions are plain functions** (not methods), auto-loaded via `import(__DIR__ . '/.castor')` in `castor.php`.
- **Tasks validate early**: check directory existence before doing any network/disk work.
- **`__ADMIN_SLUG__`** is the only template placeholder; it appears in `security.yaml` and `aropixel_admin.yaml`.
- **`escapeshellarg()`** is used on all user-supplied values passed to `run()`.
- **JSON manipulation** (e.g. `addPathRepository`) uses `json_decode` / `json_encode` with `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES`.
