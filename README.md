# slice_cap

Blöcke im REDAXO-Backend kopieren, ausschneiden und an anderer Stelle wieder einfügen — artikel-, sprach- und ctype-übergreifend.

Ersatz für `bloecks/cutncopy`. Der Nachbau war nötig, weil bloecks 4 den Block anlegt, indem es ein `save=1` samt `REX_INPUT_*` in `$_REQUEST` fälscht und damit den Core-Formularpfad simuliert. Seit structure 2.20.3 (REDAXO 5.21.3) prüft der Core dort einen CSRF-Token, und der Trick fällt durch. `slice_cap` benutzt stattdessen einen eigenen `rex_api_function`-Endpunkt und `rex_content_service::addSlice()`.

## Bedienung

- An jedem Block liegen im Menü zwei zusätzliche Buttons: **Kopieren** und **Ausschneiden**. Der gemerkte Block wird farbig markiert; derselbe Button nochmal geklickt leert die Zwischenablage.
- Liegt etwas in der Zwischenablage, erscheint in jedem **Block hinzufügen**-Dropdown ganz oben ein Einfügen-Eintrag. Er wird nur dort angezeigt, wo der Block auch landen darf.
- Beim Einfügen eines ausgeschnittenen Blocks wird die Quelle entfernt und die Zwischenablage geleert; eine Kopie bleibt liegen und kann mehrfach eingefügt werden.

## Berechtigungen

`slice_cap[]` — allgemeines Recht, ohne das die Buttons nicht erscheinen. Administratoren haben es implizit.

Zusätzlich gilt bei jeder Aktion, was auch für das normale Bearbeiten gilt: Sprach- und Kategorie-Recht am betroffenen Artikel, Modul-Recht am Modul des Blocks, und beim Einfügen muss das Template des Zielartikels das Modul im gewählten ctype zulassen. Geprüft wird das im API-Endpunkt, nicht nur beim Rendern der Buttons.

## Aufbau

| Datei | Inhalt |
| --- | --- |
| `lib/SliceCap.php` | Zwischenablage (Session) und die Rechte-Guards |
| `lib/SliceCapBackend.php` | Buttons am Block, Einfügen-Eintrag im Dropdown |
| `lib/SliceCapApi.php` | `rex_api_slice_cap` — führt Kopieren/Ausschneiden/Einfügen aus |

Ein paar bewusste Entscheidungen:

- **Die Zwischenablage hält nur Slice-ID und Aktion**, kein Cookie und keine Inhalte. Artikel, Sprache, Modul und Werte werden beim Einfügen frisch aus der Datenbank gelesen. Ein zwischenzeitlich geänderter Block wird damit in seinem aktuellen Stand eingefügt, ein gelöschter erzeugt eine Meldung statt eines halben Blocks.
- **Der Einfügen-Eintrag wird nicht per Regex aus dem gerenderten Dropdown gefischt.** Die URL wird aus den Parametern des Extension Points gebaut; im Markup wird nur hinter dem öffnenden `<ul class="dropdown-menu">` eingehängt. Passt das Muster nicht (überschriebenes Fragment), bleibt das Markup unverändert, statt kaputtes HTML zu erzeugen.
- **`$published = false`** am API-Endpunkt. Der Core erzwingt damit Backend-Kontext *und* eine gültige Session; `rex::isBackend()` allein wäre kein Login-Gate.
- **`setRequiresReboot(true)`** nach dem Einfügen. Der Core leitet danach auf die Seite ohne API-Parameter um, sodass ein Reload den Block nicht ein zweites Mal anlegt.
- **Die Spalten des Quell-Blocks werden per `array_diff_key` übernommen**, nicht einzeln aufgezählt. Zusätzliche Spalten in `rex_article_slice` kommen dadurch automatisch mit; ausgenommen sind nur Zuordnung, Position und die Global-Fields.

## Kompatibilität

REDAXO ab 5.18, PHP ab 8.1, `structure/content` ab 2.11. Das `structure/version`-PlugIn wird berücksichtigt: eingefügt wird in die Revision, in der die Content-Seite den Artikel gerade zeigt.

## Zwei Eigenheiten des Cores

Beide betreffen nicht nur dieses AddOn, fallen hier aber auf:

- **Die Message eines `rex_api_result` erscheint auf `content/edit` zweimal.** Einmal global über der Blockliste (`content.php`, `rex_api_function::getMessage()`) und ein zweites Mal am Block mit der `slice_id` aus der URL: `content.edit.php` übernimmt dieselbe Message nach `$info`, und `rex_article_content_editor` rendert sie „at current slice". Den Core-eigenen API-Funktionen fällt das nicht auf, weil deren Results keine Message tragen. Deshalb laufen die Meldungen hier über `SliceCap::flash()` statt über das Result — siehe `STRUCTURE_CONTENT_BEFORE_SLICES` in `SliceCapBackend`.
- **`rex_content_service::addSlice()` liefert eine unauflösbare Meldung.** Der Rückgabewert ist `rex_i18n::msg('slice_added')`, und `slice_added` ist in keiner Sprachdatei von `structure` definiert — heraus kommt `[translate:slice_added]`. Deshalb wird der Rückgabewert hier verworfen und die Meldung selbst formuliert.

## Lizenz

MIT — siehe [LICENSE.md](LICENSE.md).

## Migration von bloecks

`slice_cap` und `bloecks` können parallel installiert sein; die Buttons erscheinen dann doppelt. Nach dem Umstieg `bloecks` deinstallieren und aus `src/addons/` entfernen.
