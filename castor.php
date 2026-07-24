<?php

declare(strict_types=1);

use Castor\Attribute\AsTask;

use function Castor\import;
use function Castor\io;
use function Castor\run;

import(__DIR__ . '/.castor');

#[AsTask(name: 'aropixel:contrib:admin', description: 'Crée un environnement de contribution pour aropixel/admin-bundle')]
function aropixel_contrib_admin(string $name): void
{
    $root = getcwd();
    $contribDir = $root . '/' . $name;

    if (is_dir($contribDir)) {
        throw new RuntimeException(sprintf('Le dossier "%s" existe déjà.', $contribDir));
    }

    $bundleDir = $contribDir . '/admin-bundle';

    $domain = io()->ask('Nom de domaine local de dev', $name . '.local');
    $phpVersion = io()->choice('Version PHP', ['8.2', '8.3', '8.4', '8.5'], '8.5');
    $branchName = io()->ask('Nom de la branche de travail (laisser vide pour passer)', '');

    $adminSlug = generateAdminSlug();
    while (true) {
        $answer = io()->ask(
            'Slug de l\'admin (Entrée pour accepter, "r" pour régénérer)',
            $adminSlug,
        );
        if ($answer === 'r') {
            $adminSlug = generateAdminSlug();
            continue;
        }
        $adminSlug = $answer;
        break;
    }

    io()->title('Création de l\'environnement de contribution Aropixel Admin Bundle');
    io()->listing(array_filter([
        'Dossier : ' . $name,
        'Domaine local : ' . $domain,
        'Bundle : ' . $bundleDir,
        'Slug admin : ' . $adminSlug,
        $branchName ? 'Branche : ' . $branchName : null,
    ]));

    io()->section('1. Vérification de la CLI GitHub (gh)');
    checkGhCli();
    $username = getGithubUsername();
    io()->writeln(sprintf('Connecté en tant que : <info>%s</info>', $username));

    io()->section('2. Fork de aropixel/admin-bundle');
    forkBundle('admin-bundle');

    io()->section('3. Clonage de jolicode/docker-starter');
    run(sprintf('git clone https://github.com/jolicode/docker-starter.git %s', escapeshellarg($contribDir)));

    if (is_dir($contribDir . '/.git')) {
        run('rm -rf .git', context: \Castor\context()->withWorkingDirectory($contribDir));
    }

    io()->section('4. Clonage du fork');
    cloneBundleFork($bundleDir, $username, 'admin-bundle');

    if ('' !== $branchName) {
        io()->section('5. Création de la branche de travail');
        createWorkingBranch($bundleDir, $branchName);
    }

    io()->section('6. Personnalisation du docker-starter');
    copyInitialInfrastructureFiles($contribDir);
    customizeDockerStarterCastorFile($contribDir, $name, $domain, $phpVersion);
    customizeDockerfile($contribDir);
    customizeDockerPhp($contribDir);
    cleanNginxConfig($contribDir);

    io()->section('7. Installation de Symfony');
    run('castor symfony --web-app', context: \Castor\context()->withWorkingDirectory($contribDir));
    run('castor builder -- composer remove symfony/ux-turbo', context: \Castor\context()->withWorkingDirectory($contribDir));

    io()->section('8. Démarrage des conteneurs');
    run('castor start', context: \Castor\context()->withWorkingDirectory($contribDir));

    io()->section('9. Configuration de l\'environnement');
    configureEnvFile($contribDir);
    addPathRepository($contribDir, 'admin-bundle');

    io()->section('10. Création des dossiers et copie des fichiers de base');
    createProjectDirectories($contribDir);
    copyStarterFiles($contribDir, $adminSlug);
    copyContribCatalog($contribDir, $adminSlug);
    generateGitignore($contribDir);
    copyClaudeResources($contribDir);

    io()->section('11. Installation du bundle en path repository');
    addMissingBundles($contribDir);
    run(
        'castor builder -- composer require aropixel/admin-bundle:*@dev',
        context: \Castor\context()->withWorkingDirectory($contribDir),
    );
    run(
        'castor builder -- php bin/console assets:install --relative',
        context: \Castor\context()->withWorkingDirectory($contribDir),
    );
    addAdminBundleImportmap($contribDir);

    io()->section('12. Création de la base de données');
    run(
        'castor builder -- php bin/console doctrine:database:drop --force --if-exists',
        context: \Castor\context()->withWorkingDirectory($contribDir),
    );
    run(
        'castor builder -- php bin/console doctrine:database:create',
        context: \Castor\context()->withWorkingDirectory($contribDir),
    );
    run(
        'castor builder -- php bin/console doctrine:migration:diff -n',
        context: \Castor\context()->withWorkingDirectory($contribDir),
    );
    run(
        'castor builder -- php bin/console doctrine:migration:migrate -n --allow-no-migration --all-or-nothing',
        context: \Castor\context()->withWorkingDirectory($contribDir),
    );
    run(
        'castor builder -- php bin/console aropixel:admin:create-user --no-interaction',
        context: \Castor\context()->withWorkingDirectory($contribDir),
    );

    io()->success(array_filter([
        sprintf('L\'environnement de contribution "%s" est prêt.', $name),
        sprintf('Bundle : %s/admin-bundle/%s', $name, $branchName ? '(branche : ' . $branchName . ')' : ''),
        '',
        'Commandes utiles :',
        sprintf('  cd %s && castor builder -- composer ...', $name),
        sprintf('  cd %s/admin-bundle && git push origin %s', $name, $branchName ?: 'main'),
    ]));
}

#[AsTask(name: 'aropixel:contrib:blog', description: 'Crée un environnement de contribution pour aropixel/blog-bundle')]
function aropixel_contrib_blog(string $name): void
{
    $contribDir = getcwd() . '/' . $name;

    if (!is_dir($contribDir)) {
        io()->note('Environnement de base manquant — lancement de aropixel:contrib:admin...');
        aropixel_contrib_admin($name);
    }

    io()->title('Contribution Aropixel Blog Bundle');
    installContribBundle($name, 'blog-bundle', 'aropixel/blog-bundle');
}

#[AsTask(name: 'aropixel:contrib:page', description: 'Crée un environnement de contribution pour aropixel/page-bundle')]
function aropixel_contrib_page(string $name): void
{
    $contribDir = getcwd() . '/' . $name;

    if (!is_dir($contribDir)) {
        io()->note('Environnement de base manquant — lancement de aropixel:contrib:admin...');
        aropixel_contrib_admin($name);
    }

    io()->title('Contribution Aropixel Page Bundle');
    installContribBundle($name, 'page-bundle', 'aropixel/page-bundle');
}

#[AsTask(name: 'aropixel:contrib:menu', description: 'Crée un environnement de contribution pour aropixel/menu-bundle')]
function aropixel_contrib_menu(string $name): void
{
    $contribDir = getcwd() . '/' . $name;

    if (!is_dir($contribDir)) {
        io()->note('Environnement de base manquant — lancement de aropixel:contrib:admin...');
        aropixel_contrib_admin($name);
    }

    io()->title('Contribution Aropixel Menu Bundle');
    installContribBundle($name, 'menu-bundle', 'aropixel/menu-bundle');
}

#[AsTask(name: 'aropixel:contrib:all', description: 'Crée un environnement de contribution complet (admin + blog + page + menu)')]
function aropixel_contrib_all(string $name): void
{
    aropixel_contrib_admin($name);

    io()->title('Installation des bundles supplémentaires');

    installContribBundle($name, 'blog-bundle', 'aropixel/blog-bundle');
    installContribBundle($name, 'page-bundle', 'aropixel/page-bundle');
    installContribBundle($name, 'menu-bundle', 'aropixel/menu-bundle');

    $contribDir = getcwd() . '/' . $name;

    io()->section('Copie des fichiers sandbox (Project CRUD, fixtures, tests)');
    copyContribAllFiles($contribDir);

    io()->section('Installation des dépendances de test');
    configureAutoloadDevBundleTests($contribDir);
    requireTestDependencies($contribDir);
    addFixturesBundle($contribDir);

    io()->section('Migration pour l\'entité Project');
    run(
        'castor builder -- php bin/console doctrine:migration:diff -n',
        context: \Castor\context()->withWorkingDirectory($contribDir),
    );
    run(
        'castor builder -- php bin/console doctrine:migration:migrate -n --allow-no-migration --all-or-nothing',
        context: \Castor\context()->withWorkingDirectory($contribDir),
    );

    io()->section('Chargement des fixtures');
    run(
        'castor builder -- php bin/console doctrine:fixtures:load -n',
        context: \Castor\context()->withWorkingDirectory($contribDir),
    );

    io()->section('Configuration des suites de tests PHPUnit');
    configurePhpUnitTestSuites($contribDir);

    io()->section('Redémarrage des conteneurs');
    run('castor up', context: \Castor\context()->withWorkingDirectory($contribDir));

    io()->success([
        sprintf('L\'environnement de contribution complet "%s" est prêt.', $name),
        'Bundles installés : admin-bundle, blog-bundle, page-bundle, menu-bundle',
        '',
        'Comptes de test : admin@example.com / admin  —  superadmin@example.com / admin',
        'Lancer les tests : castor builder -- bin/phpunit',
    ]);
}

#[AsTask(name: 'aropixel:new:admin', description: 'Crée un projet Symfony admin Aropixel')]
function aropixel_new_admin(
    string $name,
    bool $withPage = false,
    bool $withBlog = false,
    bool $withMenu = false,
    bool $all = false,
): void {
    $root = getcwd();
    $projectDir = $root . '/' . $name;

    if (is_dir($projectDir)) {
        throw new RuntimeException(sprintf('Le dossier "%s" existe déjà.', $projectDir));
    }

    $domain = io()->ask('Nom de domaine local de dev', $name . '.local');
    $phpVersion = io()->choice('Version PHP', ['8.2', '8.3', '8.4'], '8.3');
    $adminSlug = generateAdminSlug();

    while (true) {
        $answer = io()->ask(
            'Slug de l\'admin (Entrée pour accepter, "r" pour régénérer)',
            $adminSlug,
        );

        if ($answer === 'r') {
            $adminSlug = generateAdminSlug();
            continue;
        }

        $adminSlug = $answer;
        break;
    }

    io()->title('Création du projet Aropixel Admin');
    io()->listing([
        'Projet : ' . $name,
        'Domaine local : ' . $domain,
        'Slug admin : ' . $adminSlug,
    ]);

    io()->section('1. Clonage de jolicode/docker-starter');
    run(sprintf(
        'git clone https://github.com/jolicode/docker-starter.git %s',
        escapeshellarg($name),
    ));

    // Optionnel: repartir sur un dépôt vierge
    if (is_dir($projectDir . '/.git')) {
        run('rm -rf .git', context: \Castor\context()->withWorkingDirectory($projectDir));
    }

    io()->section('2. Personnalisation du docker-starter');
    copyInitialInfrastructureFiles($projectDir);
    customizeDockerStarterCastorFile($projectDir, $name, $domain, $phpVersion);
    customizeDockerfile($projectDir);
    customizeDockerPhp($projectDir);
    cleanNginxConfig($projectDir);

    io()->section('3. Installation de Symfony');
    run('castor symfony --web-app', context: \Castor\context()->withWorkingDirectory($projectDir));
    run('castor builder -- composer remove symfony/ux-turbo', context: \Castor\context()->withWorkingDirectory($projectDir));

    io()->section('4. Initialisation du starter');
    run('castor start', context: \Castor\context()->withWorkingDirectory($projectDir));

    io()->section('5. Installation du bundle admin Aropixel');
    addMissingBundles($projectDir);
    run(
        'castor builder -- composer require aropixel/admin-bundle:dev-main',
        context: \Castor\context()
            ->withWorkingDirectory($projectDir),
    );
    run(
        'castor builder -- php bin/console assets:install --relative',
        context: \Castor\context()->withWorkingDirectory($projectDir),
    );
    addAdminBundleImportmap($projectDir);

    io()->section('6. Création des dossiers');
    createProjectDirectories($projectDir);

    io()->section('7. Copie des fichiers de base');
    configureEnvFile($projectDir);
    copyStarterFiles($projectDir, $adminSlug);
    generateGitignore($projectDir);

    io()->section('8. Copie des skills du bundle admin');
    copyAdminBundleSkills($projectDir);

    io()->section('9. Création de l\'administrateur');
    run(
        'castor builder -- php bin/console doctrine:database:drop --force --if-exists',
        context: \Castor\context()->withWorkingDirectory($projectDir),
    );
    run(
        'castor builder -- php bin/console doctrine:database:create',
        context: \Castor\context()->withWorkingDirectory($projectDir),
    );
    run(
        'castor builder -- php bin/console doctrine:migration:diff -n',
        context: \Castor\context()->withWorkingDirectory($projectDir),
    );
    run(
        'castor builder -- php bin/console doctrine:migration:migrate -n --allow-no-migration --all-or-nothing',
        context: \Castor\context()->withWorkingDirectory($projectDir),
    );
    run(
        'castor builder -- php bin/console aropixel:admin:create-user --no-interaction',
        context: \Castor\context()->withWorkingDirectory($projectDir),
    );

    $installPage = $all || $withPage;
    $installBlog = $all || $withBlog;
    $installMenu = $all || $withMenu;

    if ($installPage) {
        io()->section('10. Installation de aropixel/page-bundle');
        installNewBundle($projectDir, 'page-bundle', 'aropixel/page-bundle');
    }

    if ($installBlog) {
        io()->section('11. Installation de aropixel/blog-bundle');
        installNewBundle($projectDir, 'blog-bundle', 'aropixel/blog-bundle');
    }

    if ($installMenu) {
        io()->section('12. Installation de aropixel/menu-bundle');
        installNewBundle($projectDir, 'menu-bundle', 'aropixel/menu-bundle');
    }

    $bundlesInstalled = array_filter([
        $installPage ? 'page-bundle' : null,
        $installBlog ? 'blog-bundle' : null,
        $installMenu ? 'menu-bundle' : null,
    ]);

    io()->section('Redémarrage des conteneurs');
    run('castor stop && castor build && castor up', context: \Castor\context()->withWorkingDirectory($projectDir));

    io()->success(array_filter([
        sprintf('Le projet "%s" a été créé.', $name),
        sprintf('Lien vers l\'admin : https://%s/%s', $domain, $adminSlug),
        $bundlesInstalled ? 'Bundles supplémentaires installés : ' . implode(', ', $bundlesInstalled) : null,
    ]));
}
