# Single Sign-On (OIDC, z. B. Keycloak)

[Zur Hauptdokumentation](../README.md) · [Erweiterungsindex](README.md)

Erweiterung `extensions/Oidc/`. Anmeldung über einen externen Identity Provider mit OpenID Connect (Authorization Code Flow mit PKCE, `state`, `nonce`). Die Anmeldung per Link-Mail bleibt Standard und funktioniert ohne die Erweiterung unverändert.

## Inhalt

- [Aufbau](#aufbau)
- [Keycloak einrichten](#keycloak-einrichten)
- [In WehrPortal einrichten](#in-wehrportal-einrichten)
- [Zuordnung und Claims](#zuordnung-und-claims)
- [Lokaler System-Admin und Aussperr-Schutz](#lokaler-system-admin-und-aussperr-schutz)
- [Notfall-Wiederherstellung](#notfall-wiederherstellung-server-zugriff)
- [Weiterentwicklung und bestehende Clients](#weiterentwicklung-und-bestehende-clients)
- [Fehlersuche](#fehlersuche)

## Aufbau

| Teil | Ort |
|---|---|
| Core-Hook | `App\Services\AuthMethodRegistry` / `AuthMethod`, `User::isLocalAdmin()`, Login-Seite, `MagicLinkService` |
| Erweiterung | `extensions/Oidc/` (Provider, Routen, Migration, Filament-Seite, Befehle, Sprachdatei) |
| JWT-Prüfung | `web-token/jwt-library` (gepflegt, bereits Abhängigkeit von `minishlink/web-push`; nur asymmetrische Algorithmen, kein `none`, kein HMAC) |
| Tabellen | `x_oidc_identities` (user_id, issuer, subject, last_login_at); Spalte `users.ext_local_admin` |

Abschalten: `OIDC_ENABLED=false` lädt die Erweiterung gar nicht (keine Routen, keine Admin-Seite). Notfall: `OIDC_FORCE_DISABLED=true` schaltet nur den IdP-Login aus und gibt den Link-Login wieder für alle frei.

## Keycloak einrichten

1. Realm wählen (z. B. `musterstadt`), *Clients → Create client*: Client type `OpenID Connect`, Client ID z. B. `wehrportal`. Bestehende Clients bei einer Migration unverändert weiterverwenden.
2. *Client authentication* **On**, nur *Standard flow* aktiv.
3. *Valid redirect URIs*: die Redirect-URI aus der Admin-Seite, z. B. `https://app.ff-musterstadt.de/auth/oidc/callback` (exakt, kein Wildcard).
4. *Credentials*: Client-Secret kopieren.
5. Scopes: `openid email profile` (Standard). Benutzer brauchen eine **bestätigte** E-Mail-Adresse (`email_verified`).

Der IdP muss über öffentlich erreichbares https (Port 443) laufen: Discovery, Schlüssel und Token-Endpunkt werden über `App\Support\SafeUrl` nur an öffentliche Adressen gesendet (SSRF-Schutz).

## In WehrPortal einrichten

Admin → *Single Sign-On (OIDC)*: Issuer-URL (`https://sso.example.com/realms/musterstadt`), Client-ID, Client-Secret, Scopes, Button-Text. **Verbindung testen** ruft `/.well-known/openid-configuration` und die Schlüssel ab. Danach *Single Sign-On aktiv* einschalten und speichern.

Das Client-Secret wird verschlüsselt (APP_KEY) gespeichert und nie wieder angezeigt oder geloggt; leer lassen behält es.

## Zuordnung und Claims

| Claim | Verwendung |
|---|---|
| `iss`, `aud`, `exp`, `nonce`, Signatur | werden geprüft (Issuer = Discovery, Audience = Client-ID, Ablauf mit 60 s Toleranz) |
| `sub` | zusammen mit dem Issuer die Identität (`x_oidc_identities`) – maßgeblich bei jedem weiteren Login |
| `email`, `email_verified` | erster Login: Verknüpfung mit bestehendem aktivem Konto, nur bei `email_verified = true` |
| `name` / `preferred_username` | Name bei automatischer Anlage |

- Standard: nur bestehende Konten verknüpfen. Ein Konto, das schon mit einer anderen Identität desselben IdP verknüpft ist, wird nie per E-Mail neu verknüpft.
- Optional *automatisch anlegen (JIT)*: neues Konto ohne Admin-Rechte und ohne Modulzugänge.
- **Admin-Rechte kommen nie aus Claims.** Gruppen-/Rollen-Claims werden nicht ausgewertet.
- Inaktive Benutzer und Mitglieder ohne E-Mail-Adresse („ohne Login“) werden abgelehnt – auch mit bereits verknüpfter Identität. Fehlerseiten nennen keine Details; im Log steht nur ein Grund-Code (kein Token, kein Secret, keine Claims).
- Activity-Log: Eintrag `login` mit `method = oidc`.

## Lokaler System-Admin und Aussperr-Schutz

- Lokale System-Admins (`users.ext_local_admin`) können sich immer per Link-Mail anmelden. Neue Admins sind es automatisch; bestehende wurden bei der Migration markiert. Verwaltung: Admin-Seite (Feld *Lokale System-Admins*).
- *Nur Single Sign-On* schaltet den Link-Login für alle außer lokalen System-Admins ab (Seite `/anmelden` zeigt dann nur den SSO-Button). Lokale Admins melden sich unter `/anmelden/lokal` an.
- Speichern von *Nur Single Sign-On* ist nur mit mindestens einem aktiven lokalen System-Admin möglich. Solange es aktiv ist, kann der letzte lokale System-Admin nicht gelöscht, deaktiviert, herabgestuft oder ausgetragen werden.

## Notfall-Wiederherstellung (Server-Zugriff)

```bash
php artisan oidc:disable                         # IdP-Login aus, Link-Login wieder für alle
php artisan oidc:make-local-admin hanna@example.com [--grant-admin]
```

Oder `OIDC_FORCE_DISABLED=true` in der Umgebung setzen (Container neu starten) bzw. `OIDC_ENABLED=false`.

## Weiterentwicklung und bestehende Clients

Der Code liegt in `extensions/Oidc/`, `app/Services/AuthMethod*.php`, Tests unter `tests/Feature/Extensions/Oidc/`. Es gibt keine automatischen Upstream-Merges. Beim Identity Provider einen eigenen Client für WehrPortal einrichten, beispielsweise mit Client-ID `wehrportal`, dem gewünschten Issuer und der in den Einstellungen angezeigten Redirect-URI.

## Fehlersuche

- Button fehlt: Erweiterung aktiv? Konfiguration vollständig (Issuer, Client-ID, Secret)? `OIDC_FORCE_DISABLED` gesetzt?
- *nicht erreichbar*: Issuer muss https mit öffentlicher Adresse sein; *Verbindung testen* nennt den Grund-Code.
- *kein freigegebenes Konto*: E-Mail im IdP bestätigt? Konto in WehrPortal vorhanden und aktiv? Sonst JIT einschalten.
- Nicht umgesetzt: RP-initiated Logout und Gruppen-Mapping (der Core-Logout bleibt unverändert).
