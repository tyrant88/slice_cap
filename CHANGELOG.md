# Changelog

## 1.1.6 – 2026-10-28

- Nach dem Einfügen eines ausgeschnittenen Blocks wird der Quellartikel jetzt so behandelt wie beim Löschen über die Content-Seite: Update-Felder setzen, Artikel-Cache löschen, `STRUCTURE_CONTENT_ARTICLE_UPDATED`, `SLICE_DELETED` und `ART_CONTENT_UPDATED` auslösen. Bisher zeigte das Frontend des Quellartikels den Block weiter an, wenn er in einen anderen Artikel oder eine andere Sprache verschoben wurde.

## 1.1.5 – 2026-09-02

- Ersetzt ein anderes Addon die Blockauswahl (z. B. module_preview), steht der Einfügen-Button jetzt neben der Auswahl statt darunter. Lange Blocknamen werden im Button gekürzt.
- Neu in der `package.yml`: `clear_clipboard_after_paste` (Standard `true`). Mit `false` bleibt ein kopierter Block nach dem Einfügen in der Zwischenablage und lässt sich mehrfach einfügen. Ausgeschnittene Blöcke verlassen sie immer.

## 1.1.4 – 2026-09-01

- Doppeltes Escaping im Einfüge-Label behoben (`&quot;` im Backend sichtbar, nur in Sprachen mit geraden Anführungszeichen).

## 1.1.3 – 2026-09-01

- Der Einfüge-Eintrag erscheint auch, wenn ein anderes Addon die Blockauswahl ersetzt oder das Fragment `module_select.php` überschrieben ist. Er steht dann als eigener Button hinter der Blockauswahl.

## 1.1.2 – 2026-08-11

- README überarbeitet.
- Repo nach `tyrant88/slice_cap` umgezogen, Autor und Copyright angepasst.

## 1.1.1 – 2026-08-11

- `btn-default` an die Block-Buttons.
- MIT-Lizenz, Supportpage auf die Repo-Seite.

## 1.1.0 – 2026-08-11

- Meldungen erscheinen nur noch einmal: Sie laufen über einen eigenen Flash in der Session statt über die Message des `rex_api_result`, die der Core auf `content/edit` doppelt ausgibt. Die Meldung bleibt außerdem über den Redirect nach dem Einfügen erhalten.

## 1.0.0 – 2026-08-10

- Blöcke kopieren, ausschneiden und einfügen. Ersatz für `bloecks/cutncopy`.
- Anlegen über einen eigenen `rex_api_function`-Endpunkt und `rex_content_service::addSlice()`, ohne nachgebauten Core-Request.
- Die Zwischenablage hält nur Slice-ID und Aktion, Inhalte werden beim Einfügen frisch gelesen. Rechte werden im Endpunkt geprüft.
