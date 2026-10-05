<?php

declare(strict_types=1);

use function Castor\io;
use function Castor\run;

function checkGhCli(): void
{
    try {
        \Castor\capture('gh auth status 2>&1');
    } catch (\Throwable) {
        throw new RuntimeException(
            'La CLI GitHub (gh) n\'est pas installée ou non authentifiée. ' .
            'Installez-la (https://cli.github.com) puis lancez "gh auth login".'
        );
    }
}

function getGithubUsername(): string
{
    $username = trim(\Castor\capture('gh api user --jq .login'));

    if ('' === $username) {
        throw new RuntimeException('Impossible de récupérer le nom d\'utilisateur GitHub.');
    }

    return $username;
}

function forkBundle(string $repoName): void
{
    run(sprintf(
        'gh repo fork %s --default-branch-only --clone=false',
        escapeshellarg('aropixel/' . $repoName)
    ));
}

function cloneBundleFork(string $targetDir, string $username, string $repoName): void
{
    run(sprintf(
        'gh repo clone %s/%s %s',
        escapeshellarg($username),
        escapeshellarg($repoName),
        escapeshellarg($targetDir)
    ));
}

function createWorkingBranch(string $bundleDir, string $branchName): void
{
    run(
        sprintf('git checkout -b %s', escapeshellarg($branchName)),
        context: \Castor\context()->withWorkingDirectory($bundleDir)
    );
}

function configureEnvFile(string $contribDir): void
{
    $envFile = $contribDir . '/application/.env';

    if (!file_exists($envFile)) {
        throw new RuntimeException(sprintf('Fichier introuvable : %s', $envFile));
    }

    $content = (string) file_get_contents($envFile);

    $content = preg_replace(
        '/^DATABASE_URL=.*/m',
        'DATABASE_URL="mysql://root:@db:3306/app?serverVersion=8.4.0&charset=utf8mb4"',
        $content
    );

    $content = preg_replace(
        '/^MAILER_DSN=.*/m',
        'MAILER_DSN=smtp://mail:25',
        $content
    );

    file_put_contents($envFile, $content);
}

function addPathRepository(string $contribDir, string $bundleName): void
{
    $composerJson = $contribDir . '/application/composer.json';

    if (!file_exists($composerJson)) {
        throw new RuntimeException(sprintf('Fichier introuvable : %s', $composerJson));
    }

    $json = json_decode((string) file_get_contents($composerJson), true);

    if (!\is_array($json)) {
        throw new RuntimeException('Le fichier composer.json est invalide.');
    }

    $url = '../' . $bundleName;
    $pathRepo = [
        'type' => 'path',
        'url' => $url,
        'options' => ['symlink' => true],
    ];

    // Évite les doublons si déjà configuré
    $existing = $json['repositories'] ?? [];
    foreach ($existing as $repo) {
        if (($repo['type'] ?? '') === 'path' && ($repo['url'] ?? '') === $url) {
            return;
        }
    }

    $json['repositories'] = array_merge([$pathRepo], $existing);

    file_put_contents(
        $composerJson,
        json_encode($json, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . "\n"
    );
}

function copyClaudeResources(string $contribDir): void
{
    $root = dirname(__DIR__);

    $claudeMd = $root . '/resources/CLAUDE.md';
    if (file_exists($claudeMd)) {
        copy($claudeMd, $contribDir . '/CLAUDE.md');
    }

    $claudeDir = $root . '/resources/.claude';
    if (is_dir($claudeDir)) {
        \Castor\run(sprintf(
            'cp -r %s %s',
            escapeshellarg($claudeDir),
            escapeshellarg($contribDir)
        ));
    }
}

function readAdminSlug(string $contribDir): string
{
    $adminRouteFile = $contribDir . '/application/config/routes/aropixel_admin.yaml';

    if (!file_exists($adminRouteFile)) {
        return '';
    }

    $content = file_get_contents($adminRouteFile);

    if (preg_match('#prefix:\s*/([^\s]+)#', $content, $matches)) {
        return $matches[1];
    }

    return '';
}

function copyBundleRouteFile(string $contribDir, string $bundleName): void
{
    $root = dirname(__DIR__);
    $routeFile = sprintf('aropixel_%s.yaml', explode('-', $bundleName)[0] ?? '');
    $source = $root . '/resources/application/config/routes/' . $routeFile;

    if (!file_exists($source)) {
        return;
    }

    $targetDir = $contribDir . '/application/config/routes';
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0777, true);
    }

    $content = file_get_contents($source);
    $adminSlug = readAdminSlug($contribDir);
    if ('' !== $adminSlug) {
        $content = str_replace('__ADMIN_SLUG__', $adminSlug, $content);
    }

    file_put_contents($targetDir . '/' . $routeFile, $content);
}

/**
 * Installe un bundle Aropixel supplémentaire dans un environnement de contribution existant.
 * Utilisé par contrib:blog, :page, :menu.
 */
function installContribBundle(string $name, string $bundleName, string $packageName): void
{
    $contribDir = getcwd() . '/' . $name;
    $bundleDir = $contribDir . '/' . $bundleName;

    if (is_dir($bundleDir)) {
        throw new RuntimeException(sprintf('Le dossier "%s" existe déjà.', $bundleDir));
    }

    checkGhCli();
    $username = getGithubUsername();
    io()->writeln(sprintf('Connecté en tant que : <info>%s</info>', $username));

    $branchName = io()->ask('Nom de la branche de travail (laisser vide pour passer)', '');

    io()->section(sprintf('Fork de aropixel/%s', $bundleName));
    forkBundle($bundleName);

    io()->section('Clonage du fork');
    cloneBundleFork($bundleDir, $username, $bundleName);

    if ('' !== $branchName) {
        io()->section('Création de la branche de travail');
        createWorkingBranch($bundleDir, $branchName);
    }

    io()->section('Ajout du path repository');
    addPathRepository($contribDir, $bundleName);

    io()->section('Copie du fichier de routes');
    copyBundleRouteFile($contribDir, $bundleName);

    io()->section(sprintf('Installation de %s', $packageName));
    run(
        sprintf('castor builder -- composer require %s:*@dev', escapeshellarg($packageName)),
        context: \Castor\context()->withWorkingDirectory($contribDir)
    );

    io()->section(sprintf('Migration de la base de données pour %s', $packageName));
    run(
        'castor builder -- php bin/console doctrine:migration:diff -n',
        context: \Castor\context()->withWorkingDirectory($contribDir)
    );
    run(
        'castor builder -- php bin/console doctrine:migration:migrate -n --allow-no-migration',
        context: \Castor\context()->withWorkingDirectory($contribDir)
    );

    io()->success(array_filter([
        sprintf('Le bundle "%s" est installé et prêt pour la contribution.', $bundleName),
        $branchName ? 'Branche : ' . $branchName : null,
        '',
        'Commandes utiles :',
        sprintf('  cd %s/%s && git push origin %s', $name, $bundleName, $branchName ?: 'main'),
    ]));
}
