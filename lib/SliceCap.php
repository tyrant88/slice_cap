<?php

namespace slice_cap;

use rex;
use rex_article;
use rex_article_revision;
use rex_plugin;
use rex_sql;
use rex_template;
use rex_user;

/**
 * Zwischenablage für Slices und die Rechte-Guards drumherum.
 *
 * In der Session stehen nur Slice-ID und Aktion. Artikel, Sprache, Modul und
 * Inhalte werden beim Einfügen frisch aus der Datenbank gelesen — ein
 * zwischenzeitlich geänderter oder gelöschter Block kann so keine veralteten
 * Inhalte einfügen.
 */
final class SliceCap
{
    public const ACTION_COPY = 'copy';
    public const ACTION_CUT = 'cut';

    private const SESSION_KEY = 'slice_cap_clipboard';
    private const FLASH_KEY = 'slice_cap_flash';

    /**
     * Spalten, die beim Einfügen nicht vom Quell-Block übernommen werden:
     * Position im Zielartikel und die Global-Fields setzt der Core selbst.
     */
    private const STRUCTURAL_COLUMNS = [
        'id', 'article_id', 'clang_id', 'ctype_id', 'module_id', 'revision',
        'priority', 'createdate', 'createuser', 'updatedate', 'updateuser',
    ];

    public static function put(int $sliceId, string $action): void
    {
        \rex_login::startSession();
        \rex_set_session(self::SESSION_KEY, ['slice_id' => $sliceId, 'action' => $action]);
    }

    public static function clear(): void
    {
        \rex_login::startSession();
        \rex_unset_session(self::SESSION_KEY);
    }

    public static function getSliceId(): ?int
    {
        $clipboard = self::getClipboard();

        return isset($clipboard['slice_id']) ? (int) $clipboard['slice_id'] : null;
    }

    public static function getAction(): ?string
    {
        $clipboard = self::getClipboard();
        $action = $clipboard['action'] ?? null;

        return in_array($action, [self::ACTION_COPY, self::ACTION_CUT], true) ? $action : null;
    }

    /**
     * Der gemerkte Block, frisch aus der Datenbank. Null, wenn die
     * Zwischenablage leer ist oder der Block nicht mehr existiert.
     *
     * @return array<string, mixed>|null
     */
    public static function getSlice(): ?array
    {
        $sliceId = self::getSliceId();

        if (null === $sliceId || null === self::getAction()) {
            return null;
        }

        return self::findSlice($sliceId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findSlice(int $sliceId): ?array
    {
        if ($sliceId <= 0) {
            return null;
        }

        $sql = rex_sql::factory();
        $sql->setQuery('SELECT * FROM ' . rex::getTable('article_slice') . ' WHERE id = ?', [$sliceId]);

        if (1 !== $sql->getRows()) {
            return null;
        }

        return $sql->getArray()[0];
    }

    /**
     * Die kopierbaren Werte des Blocks — alles außer Position, Zuordnung und
     * Global-Fields. Bewusst spaltenweise aus der Zeile abgeleitet, damit
     * zusätzliche Spalten (z. B. aus Addons) automatisch mitkommen.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function buildSliceData(array $row): array
    {
        return array_diff_key($row, array_flip(self::STRUCTURAL_COLUMNS));
    }

    public static function mayUse(rex_user $user): bool
    {
        return $user->hasPerm('slice_cap[]');
    }

    /**
     * Sprach- und Kategorie-Berechtigung für einen Artikel — dieselbe Prüfung,
     * die auch die Content-Seite des Cores vornimmt.
     */
    public static function mayEditArticle(rex_user $user, int $articleId, int $clangId): bool
    {
        $article = rex_article::get($articleId, $clangId);

        if (!$article instanceof rex_article) {
            return false;
        }

        return $user->getComplexPerm('clang')->hasPerm($clangId)
            && $user->getComplexPerm('structure')->hasCategoryPerm($article->getCategoryId());
    }

    /**
     * Erlaubt das Template des Zielartikels dieses Modul in diesem ctype?
     */
    public static function templateAllowsModule(int $articleId, int $clangId, int $ctypeId, int $moduleId): bool
    {
        $sql = rex_sql::factory();
        $sql->setQuery(
            'SELECT template.attributes
                FROM ' . rex::getTable('article') . ' AS article
                LEFT JOIN ' . rex::getTable('template') . ' AS template ON template.id = article.template_id
                WHERE article.id = ? AND article.clang_id = ?',
            [$articleId, $clangId],
        );

        if (1 !== $sql->getRows()) {
            return false;
        }

        $attributes = $sql->getArrayValue('attributes');

        if (!is_array($attributes)) {
            $attributes = [];
        }

        return rex_template::hasModule($attributes, $ctypeId, $moduleId);
    }

    /**
     * Die Revision, in der die Content-Seite den Artikel gerade zeigt. Ohne
     * das version-PlugIn gibt es nur die Live-Revision 0.
     */
    public static function getRevision(int $articleId): int
    {
        if (!rex_plugin::get('structure', 'version')->isAvailable()) {
            return 0;
        }

        return (int) rex_article_revision::getSessionArticleRevision($articleId);
    }

    /**
     * Meldung für die nächste Ausgabe der Content-Seite vormerken.
     *
     * Bewusst nicht über die Message des rex_api_result: der Core gibt die
     * zweimal aus — einmal global (content.php, rex_api_function::getMessage)
     * und einmal am Block der aktuellen slice_id (content.edit.php füllt damit
     * $info, article_content_editor rendert es "at current slice"). Über die
     * Session bleibt die Meldung ausserdem über den Redirect nach dem Einfügen
     * erhalten.
     */
    public static function flash(string $message, bool $success = true): void
    {
        \rex_login::startSession();
        \rex_set_session(self::FLASH_KEY, ['message' => $message, 'success' => $success]);
    }

    /**
     * Die vorgemerkte Meldung, fertig formatiert — und danach verbraucht.
     */
    public static function takeFlash(): ?string
    {
        \rex_login::startSession();

        /** @var array{message?: string, success?: bool} $flash */
        $flash = \rex_session(self::FLASH_KEY, 'array', []);

        if (!isset($flash['message']) || '' === $flash['message']) {
            return null;
        }

        \rex_unset_session(self::FLASH_KEY);

        return ($flash['success'] ?? true)
            ? \rex_view::success($flash['message'])
            : \rex_view::warning($flash['message']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function getClipboard(): array
    {
        \rex_login::startSession();

        return \rex_session(self::SESSION_KEY, 'array', []);
    }
}
