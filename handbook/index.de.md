---
title: keyring
routes:
  - civicrm/admin/keyring/tokens
---

# keyring

keyring gibt einem Kontakt mehrere API-Tokens statt des einen API-Schlüssels, den CiviCRM vorsieht. Jedes Token hat eine eigene Liste von Berechtigungen, ein optionales Ablaufdatum und einen Vermerk, wann es zuletzt benutzt wurde, und jedes lässt sich einzeln widerrufen. Ein Token meldet sich als sein Kontakt an, alles damit Erledigte wird also dieser Person zugeschrieben. Es gibt kein zusätzliches Benutzerkonto und keinen Schattenkontakt.

keyring ist für Programme gedacht, die im Auftrag einer Person mit CiviCRM sprechen, etwa ein Mail-Add-in auf einem Laptop. Das Programm bekommt nur die Berechtigungen, die es braucht, und ein verlorenes Gerät kostet ein Token, nicht den ganzen Zugang der Person.

Die Oberfläche von keyring ist englisch; die Beschriftungen unten sind so zitiert, wie sie auf dem Bildschirm stehen.

## Voraussetzungen

keyring braucht die Extensions authx, FormBuilder und SearchKit, die mit CiviCRM ausgeliefert werden. Die unterstützten CiviCRM- und PHP-Versionen stehen in der `info.xml` der Extension.

## Installation

Laden Sie das Release-Archiv von der [Release-Seite](https://github.com/civico-de/keyring/releases), entpacken Sie es in das Extension-Verzeichnis von CiviCRM und aktivieren Sie es:

```bash
cv ext:enable keyring
```

Geben Sie danach den Rollen, die mit Tokens arbeiten sollen, die Berechtigungen unten.

## Berechtigungen

| Berechtigung | Erlaubt |
|---|---|
| manage own keyring tokens | Eigene Tokens ausstellen, ansehen und widerrufen |
| administer keyring tokens | Dasselbe für alle Kontakte, dazu die Listen unter **Administer → keyring**, die Liste der Verweigerungen und das Bearbeiten eines Tokens |

## Ein Token ausstellen

1. Öffnen Sie den Kontakt und wechseln Sie in den Reiter **API tokens**.
2. Klicken Sie auf **Issue a token**.
3. Geben Sie eine Bezeichnung ein, die das Programm nennt, zum Beispiel „Outlook Laptop“.
4. Wählen Sie ein Scope-Profil und einen Modus, siehe [Scopes](scopes.md).
5. Geben Sie bei Bedarf ein Ablaufdatum ein.
6. Klicken Sie auf **Create token**.

Das Token erscheint ein einziges Mal, direkt nach dem Ausstellen. Kopieren Sie es dann in das Programm. CiviCRM speichert nur einen Fingerabdruck des Tokens; ein verlorenes Token lässt sich nicht noch einmal anzeigen. Widerrufen Sie es und stellen Sie ein neues aus.

## Was das Programm sendet

Das Programm schickt das Token in einem Request-Header:

```
X-Civi-Auth: Bearer kr_…
```

`Authorization: Bearer kr_…` funktioniert genauso. Ein Token in der Adresse oder in einem Formularfeld wird abgelehnt, weil Adressen in Browser-Verläufen und Server-Logs landen.

## Zustände

Der Reiter **API tokens** und die Liste unter **Administer → keyring → Tokens** zeigen den Zustand jedes Tokens:

| Zustand | Bedeutung |
|---|---|
| Active | Das Token funktioniert. |
| Expired | Das Ablaufdatum ist überschritten. |
| Revoked | Das Token wurde widerrufen. Es funktioniert nie wieder. |

Ein Ablaufdatum ist ein Tag, keine Uhrzeit: Ein Token, das am 31. Dezember abläuft, funktioniert bis zum Ende dieses Tages. Ein Datum in der Vergangenheit wird abgelehnt. Lassen Sie das Feld leer für ein Token, das nicht abläuft; als Ablauf steht dann „never“.

**Last used** zeigt, wann sich das Token zuletzt angemeldet hat, höchstens einmal pro Stunde aktualisiert. Bei einem Token, das nie benutzt wurde, steht „never“. So finden Sie Tokens, die niemand mehr braucht.

## Widerrufen

Klicken Sie im Reiter **API tokens** des Kontakts neben dem Token auf **Revoke** und bestätigen Sie. Um mehrere Tokens auf einmal zu widerrufen, markieren Sie sie unter **Administer → keyring → Tokens** und wählen Sie **Revoke tokens**.

Ein widerrufenes Token funktioniert ab der nächsten Anfrage des Programms nicht mehr. Tokens lassen sich nicht löschen: Ein Token bleibt als Bezug für seine Verweigerungen erhalten. Wird der Kontakt gelöscht, verschwinden seine Tokens mit.
