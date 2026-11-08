---
title: Scopes
order: 10
routes:
  - civicrm/admin/keyring/denials
  - civicrm/admin/setting/keyring
---

# Scopes

Der Scope eines Tokens ist die Liste der Berechtigungen, die es benutzen darf. Ein Token bekommt nie mehr als sein Kontakt: Es kann eine Berechtigung nur nutzen, wenn sie in seinem Scope steht und der Kontakt sie hat.

Fehlt eine Berechtigung im Scope, gibt es keine Fehlermeldung. Eine Suche findet einfach nichts, und eine Seite bleibt leer. Ein geschätzter Scope liefert deshalb Ergebnisse, die plausibel aussehen und falsch sind. Mit dem Report-Modus messen Sie den Scope, statt ihn zu schätzen.

## Scope-Profile

Ein Scope-Profil ist eine benannte Liste von Berechtigungen. Die Profile stehen unter **Administer → keyring → Settings** als JSON-Objekt, das jedem Profilnamen seine Berechtigungen zuordnet:

```json
{
  "empty": [],
  "addin": ["access CiviCRM", "access AJAX API", "view all contacts", "edit all contacts", "add contacts", "view all activities"]
}
```

Ungültiges JSON wird beim Speichern mit Begründung abgelehnt.

Zwei Berechtigungen regelt keyring selbst. „authenticate with api key“ gehört immer zum Scope, weil sich ein Token ohne sie nicht anmelden kann. „all CiviCRM permissions and ACLs“ wird immer entfernt, weil sie alles erlauben würde.

## Modi

| Modus | Wirkung |
|---|---|
| Enforce | Das Token kann nur die Berechtigungen aus seinem Scope nutzen. |
| Report only | Das Token kann alles, was sein Kontakt darf. Jede Berechtigung außerhalb des Scopes wird als Verweigerung festgehalten, statt abgelehnt zu werden. |

## Den passenden Scope finden

1. Stellen Sie ein Token im Modus „Report only“ mit dem kleinsten Profil aus, das Ihnen ausreichend erscheint.
2. Lassen Sie das Programm einen repräsentativen Zeitraum arbeiten: eine Woche bei einem Mail-Add-in, einen ganzen Zyklus bei einem monatlichen Job.
3. Öffnen Sie **Administer → keyring → Denials**. Jede Zeile ist ein Token, eine Berechtigung und eine Adresse, mit der Zahl der Anfragen. Eine Berechtigung, die nur ein paar Mal von einer einzigen Adresse angefragt wurde, ist so echt wie eine mit Tausenden Anfragen.
4. Tragen Sie die benötigten Berechtigungen in das Profil ein.
5. Stellen Sie mit diesem Profil ein neues Token im Modus „Enforce“ aus und widerrufen Sie das Report-Token.

FormBuilder-Seiten sind zusätzlich durch eine Berechtigung geschützt, die `@afform:` gefolgt vom Formularnamen heißt. Sie erscheint in der Liste der Verweigerungen wie jede andere Berechtigung. Tragen Sie sie genau so in das Profil ein.
