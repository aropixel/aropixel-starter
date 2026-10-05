# Aropixel Starter

A [Castor](https://github.com/jolicode/castor) task runner that automates two workflows for Aropixel Symfony projects:

1. **Scaffold a new Symfony admin project** — clones [jolicode/docker-starter](https://github.com/jolicode/docker-starter), installs Symfony, and sets up [aropixel/admin-bundle](https://github.com/aropixel/admin-bundle) with Docker infrastructure.
2. **Bootstrap a contribution environment** for Aropixel bundles — forks the repo on GitHub, clones the fork, and creates a Symfony sandbox with the bundle installed as a path repository (symlink) for live development.

## Prerequisites

- [Castor](https://github.com/jolicode/castor) installed globally
- Docker + Docker Compose
- PHP + Composer
- `gh` CLI (GitHub CLI) — required for all `contrib:*` commands; must be authenticated (`gh auth login`)

## Installation

### 1. Clone the project

```bash
git clone git@github.com:aropixel/aropixel-starter.git
```

### 2. Install dependencies

```bash
cd aropixel-starter
composer install
```

### 3. Configure the alias

To use `aropixel-starter` from anywhere on your machine, run this command **from the cloned project folder**:

```bash
echo "alias aropixel-starter='\"$(pwd)/vendor/bin/castor\" --castor-file=\"$(pwd)/castor.php\"'" >> ~/.$(basename $SHELL)rc && source ~/.$(basename $SHELL)rc
```

> Works with zsh and bash (macOS and Linux). For fish or another shell, add the alias manually in the corresponding configuration file.

| OS | File to edit | Reload command |
|----|-------------|----------------|
| macOS | `~/.zshrc` | `source ~/.zshrc` |
| Linux | `~/.bashrc` | `source ~/.bashrc` |

## Usage

### Scaffold a new admin project

```bash
aropixel-starter new:admin <project-name> [--with-page] [--with-blog] [--with-menu] [--all]
```

Creates a complete Symfony admin project with Docker infrastructure, `aropixel/admin-bundle`, and Clever Cloud deployment config.

**Options:**
- `--with-page`: Install `aropixel/page-bundle`
- `--with-blog`: Install `aropixel/blog-bundle`
- `--with-menu`: Install `aropixel/menu-bundle`
- `--all`: Install all additional bundles (Page, Blog, Menu)

**Includes:**
- Ready-to-use administration via `aropixel/admin-bundle` with a default administrator account
- Optimized Docker infrastructure based on `jolicode/docker-starter` (PHP 8.2+, Nginx, MySQL, Varnish, Mailpit, phpMyAdmin)
- Clever Cloud deployment config (Varnish, post-build scripts)
- Image management with `LiipImagineBundle` and Doctrine extensions with `StofDoctrineExtensionsBundle`
- Security and routing configured with a randomized administration slug
- Claude Code skills for AI-assisted development copied into `.claude/skills/`

### Bootstrap a contribution environment

These commands set up a local development environment for contributing to an Aropixel bundle. The bundle fork is installed as a path repository (symlink), so changes are immediately visible without any `composer update`.

```bash
# Base environment — always required first
aropixel-starter contrib:admin <dir>

# Add a bundle to an existing contrib environment (auto-runs contrib:admin if <dir> is missing)
aropixel-starter contrib:blog <dir>
aropixel-starter contrib:page <dir>
aropixel-starter contrib:menu <dir>

# Full environment with all bundles at once
aropixel-starter contrib:all <dir>
```

The generated structure places the bundle fork alongside the Symfony app so both are inside the Docker volume mount:

```
<dir>/
  admin-bundle/     ← fork clone (symlinked via Composer path repository)
  application/      ← Symfony app
  infrastructure/
```

### List all available tasks

```bash
aropixel-starter
```
