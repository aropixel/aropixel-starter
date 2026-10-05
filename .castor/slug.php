<?php

declare(strict_types=1);

use function Castor\io;

/**
 * Demande à l'utilisateur un slug admin.
 *
 * Comportement :
 * - propose un slug généré automatiquement
 * - Entrée = accepte la proposition
 * - "r" = régénère
 * - toute autre valeur = slug personnalisé
 */
function askAdminSlug(int $parts = 3): string
{
    $slug = generateAdminSlug($parts);

    while (true) {
        io()->writeln('');
        io()->comment(sprintf('Slug proposé : %s', $slug));

        $answer = trim((string) io()->ask(
            'Slug admin ([Entrée] accepter / [r] régénérer / saisir une valeur personnalisée)',
            ''
        ));

        if ($answer === '') {
            return $slug;
        }

        if (strtolower($answer) === 'r') {
            $slug = generateAdminSlug($parts);
            continue;
        }

        $customSlug = normalizeSlug($answer);

        if ($customSlug === '') {
            io()->error('Le slug saisi est vide ou invalide.');
            continue;
        }

        return $customSlug;
    }
}

/**
 * Génère un slug admin lisible.
 *
 * Exemple :
 * - velvet-harbor-signal
 * - acidic-mirror-lantern-vault
 */
function generateAdminSlug(int $parts = 3): string
{
    if ($parts < 3) {
        $parts = 3;
    }

    if ($parts > 4) {
        $parts = 4;
    }

    [$words, $source] = loadAdminSlugWords();

    io()->note(sprintf('Utilisation des mots du %s pour la génération du slug.', $source));

    $used = [];
    $segments = [];

    $groups = [
        'adjectives',
        'nouns',
        'extras',
        'tails',
    ];

    for ($i = 0; $i < $parts; ++$i) {
        $groupName = $groups[$i] ?? 'extras';
        $pool = $words[$groupName] ?? [];

        if ($pool === []) {
            throw new RuntimeException(sprintf('La liste "%s" est vide.', $groupName));
        }

        $picked = pickUniqueWord($pool, $used);
        $used[] = $picked;
        $segments[] = $picked;
    }

    return normalizeSlug(implode('-', $segments));
}

/**
 * Charge les mots depuis un fichier local privé.
 *
 * Fichier attendu :
 * ~/.config/aropixel/aropixel-starter/words.php
 * (l'ancien chemin ~/.config/aropixel/castor-starter/words.php est encore lu)
 */
function loadAdminSlugWords(): array
{
    $file = getAdminSlugWordsConfigPath();

    if (is_file($file)) {
        $data = require $file;

        if (isValidWordsConfiguration($data)) {
            return [normalizeWordsConfiguration($data), 'fichier de configuration local'];
        }

        io()->warning(sprintf(
            'Le fichier "%s" existe mais son format est invalide. Utilisation du fallback interne.',
            $file
        ));
    }

    return [getFallbackWordsConfiguration(), 'fallback interne'];
}

/**
 * Retourne le chemin du fichier privé local.
 */
function getAdminSlugWordsConfigPath(): string
{
    $home = getenv('HOME');

    if (!is_string($home) || trim($home) === '') {
        throw new RuntimeException('Impossible de déterminer le répertoire HOME de l\'utilisateur.');
    }

    $file = rtrim($home, '/').'/'.'.config/aropixel/aropixel-starter/words.php';
    $legacyFile = rtrim($home, '/').'/'.'.config/aropixel/castor-starter/words.php';

    // Chemin d'avant le renommage en aropixel-starter
    if (!is_file($file) && is_file($legacyFile)) {
        return $legacyFile;
    }

    return $file;
}

/**
 * Vérifie la structure minimale de la configuration.
 */
function isValidWordsConfiguration(mixed $data): bool
{
    if (!is_array($data)) {
        return false;
    }

    $requiredKeys = ['adjectives', 'nouns', 'extras'];

    foreach ($requiredKeys as $key) {
        if (!array_key_exists($key, $data) || !is_array($data[$key]) || $data[$key] === []) {
            return false;
        }
    }

    if (array_key_exists('tails', $data) && !is_array($data['tails'])) {
        return false;
    }

    return true;
}

/**
 * Nettoie et normalise les listes de mots.
 */
function normalizeWordsConfiguration(array $data): array
{
    $normalized = [
        'adjectives' => normalizeWordList($data['adjectives'] ?? []),
        'nouns' => normalizeWordList($data['nouns'] ?? []),
        'extras' => normalizeWordList($data['extras'] ?? []),
        'tails' => normalizeWordList($data['tails'] ?? []),
    ];

    if ($normalized['tails'] === []) {
        $normalized['tails'] = ['vault', 'signal', 'stone', 'ember'];
    }

    foreach (['adjectives', 'nouns', 'extras'] as $required) {
        if ($normalized[$required] === []) {
            throw new RuntimeException(sprintf('La liste "%s" est vide après normalisation.', $required));
        }
    }

    return $normalized;
}

/**
 * Fallback minimal si aucun fichier local privé n'existe.
 */
function getFallbackWordsConfiguration(): array
{
    return [
        'adjectives' => [
            'silent',
            'hidden',
            'velvet',
            'amber',
            'acidic',
            'crimson',
            'wild',
            'lunar',
        ],
        'nouns' => [
            'harbor',
            'mirror',
            'orchid',
            'radar',
            'lantern',
            'anchor',
            'forest',
            'bridge',
        ],
        'extras' => [
            'signal',
            'vault',
            'stone',
            'ember',
            'shadow',
            'wave',
            'crown',
            'socket',
        ],
        'tails' => [
            'delta',
            'meadow',
            'summit',
            'rocket',
            'copper',
            'thunder',
            'pepper',
            'fossil',
        ],
    ];
}

/**
 * Normalise une liste de mots :
 * - trim
 * - minuscules
 * - slugification basique
 * - suppression des doublons
 * - suppression des vides
 */
function normalizeWordList(array $words): array
{
    $normalized = [];

    foreach ($words as $word) {
        if (!is_scalar($word)) {
            continue;
        }

        $slug = normalizeSlug((string) $word);

        if ($slug !== '') {
            $normalized[] = $slug;
        }
    }

    $normalized = array_values(array_unique($normalized));

    return $normalized;
}

/**
 * Choisit un mot en évitant autant que possible ceux déjà utilisés.
 */
function pickUniqueWord(array $pool, array $used = []): string
{
    $available = array_values(array_diff($pool, $used));

    if ($available === []) {
        $available = array_values($pool);
    }

    $index = random_int(0, count($available) - 1);

    return $available[$index];
}

/**
 * Transforme une chaîne libre en slug.
 */
function normalizeSlug(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    if (function_exists('iconv')) {
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($transliterated) && $transliterated !== '') {
            $value = $transliterated;
        }
    }

    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    $value = preg_replace('/-+/', '-', $value) ?? '';
    $value = trim($value, '-');

    return $value;
}
