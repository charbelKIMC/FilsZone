# Wi-Fi Zone : plateforme de vente de tickets Wi-Fi

Prototype de plateforme de « Wi-Fi zone » : l'administrateur crée des forfaits (volume, durée ou les deux), génère des tickets (identifiant + mot de passe) qu'il vend au comptoir, et suit les connexions. Le client se connecte avec son ticket et suit sa consommation en direct.

> **État actuel : prototype.** La plateforme gère toute la logique métier (tickets, forfaits, sessions, statistiques) mais **ne contrôle pas encore un vrai réseau** : l'adresse MAC est simulée par le navigateur et la consommation est simulée par le bouton « Simuler une consommation ». La section [Mettre la plateforme sur un vrai réseau](#mettre-la-plateforme-sur-un-vrai-réseau) explique comment passer en production avec un routeur.

---

## Sommaire

1. [Structure du projet](#structure-du-projet)
2. [Installation locale (XAMPP)](#installation-locale-xampp)
3. [Règles métier](#règles-métier)
4. [Comment fonctionne une vraie Wi-Fi zone](#comment-fonctionne-une-vraie-wi-fi-zone)
5. [Mettre la plateforme sur un vrai réseau](#mettre-la-plateforme-sur-un-vrai-réseau)
6. [Avant de passer derrière un proxy ou un NAT](#avant-de-passer-derrière-un-proxy-ou-un-nat)
7. [Checklist de sécurité avant la mise en ligne](#checklist-de-sécurité-avant-la-mise-en-ligne)

---

## Structure du projet

```
wifizone/
├── public/            API (seul point d'entrée PHP) : index.php déclare toutes les routes
├── src/
│   ├── Core/          Routeur, requête, réponse JSON, session, lecture du .env
│   ├── Controllers/   Lisent la requête, appellent un service, renvoient du JSON
│   ├── Services/      Règles métier (tickets, connexion client, anti force brute…)
│   ├── Repositories/  Requêtes SQL (PDO, requêtes préparées)
│   ├── Middleware/    Contrôle d'accès admin / client
│   └── Config/        Connexion à la base + scripts SQL
├── frontend/
│   ├── admin/         Interface de l'administrateur
│   ├── user/          Portail du client (page de connexion + tableau de bord)
│   ├── js/            Modules JavaScript (sans framework)
│   └── config.js      Personnalisation : nom de la zone, logos, devise, mode démo
├── storage/cache/     Cache des forfaits (généré automatiquement, non versionné)
├── .env.example       Modèle de configuration (copier en .env)
└── LISMOI.md
```

---

## Installation locale (XAMPP)

**Prérequis :** PHP 8.0 ou plus, MySQL 8.0.16+ ou MariaDB 10.2+ (fournis par XAMPP), Apache avec `mod_rewrite`.

1. **Copier le projet** dans `C:\xampp\htdocs\wifizone`.

2. **Créer la base** : dans phpMyAdmin, importer `src/Config/wifi zone.sql`.
   > ⚠️ Ce script commence par `DROP DATABASE IF EXISTS wifi_zone` : il **efface** la base existante. Ne jamais l'exécuter sur une base qui contient de vraies données.
   > Si la base a été créée avec une ancienne version du script, exécuter plutôt `src/Config/migration_2026_10_28.sql` (une seule fois).

3. **Créer le mot de passe administrateur** : le script insère un compte `admin` avec un faux hash. Générer un vrai hash :
   ```bash
   C:/xampp/php/php.exe -r "echo password_hash('VotreMotDePasse', PASSWORD_DEFAULT);"
   ```
   puis dans phpMyAdmin :
   ```sql
   UPDATE administrateur SET mot_de_passe = 'LE_HASH_OBTENU' WHERE login = 'admin';
   ```

4. **Configurer** : copier `.env.example` en `.env` et adapter les accès à la base. Mettre `APP_DEBUG=true` en développement pour voir le détail des erreurs.

5. **Ouvrir** :
   - Administration : http://localhost/wifizone/frontend/admin/
   - Portail client : http://localhost/wifizone/frontend/user/
   - Test de l'API : http://localhost/wifizone/public/ping

---

## Règles métier

| Règle | Où |
|---|---|
| Le décompte du temps démarre à la **première connexion**, pas à la génération du ticket | `TicketRepository::startFirstUse` |
| Un ticket reste utilisable tant qu'il reste **du temps ET du volume** | `TicketService::assertUsable` |
| **Un seul appareil à la fois** par ticket. Un second appareil est refusé (409) tant que le premier n'est pas déconnecté. Une session inactive depuis `SESSION_INACTIVITE_MINUTES` est fermée automatiquement, ce qui libère le ticket. | `ClientService::login` |
| Deux connexions **simultanées** avec le même ticket passent l'une après l'autre (transaction + verrou `SELECT … FOR UPDATE` sur le ticket) : il est impossible que les deux réussissent | `ClientService::login`, `TicketRepository::lock` |
| Le prix est **figé** sur le ticket à sa génération : modifier un forfait ne change pas les statistiques passées | colonne `ticket.prix_vente` |

### Protection contre la force brute

Les échecs de connexion sont comptés sur une fenêtre de **15 minutes** (table `tentative_connexion`) :

| Clé | Limite | Rôle |
|---|---|---|
| `admin:<ip>` | 5 échecs | Connexion administrateur |
| `client:<ip>` | 10 échecs | Empêche un appareil d'essayer des tickets au hasard |
| `ticket:<identifiant>` | 5 échecs | Empêche de deviner le mot de passe d'un ticket précis, même en changeant d'appareil ou d'adresse IP |

- Une connexion réussie remet à zéro le compteur du **ticket**, pas celui de l'**IP** : sinon un fraudeur possédant un ticket valide pourrait effacer ses échecs en se connectant entre deux séries d'essais.
- Contrepartie à connaître : quelqu'un qui connaît l'identifiant d'un ticket (par exemple en le voyant par-dessus une épaule) peut le bloquer 15 minutes en tapant 5 faux mots de passe. Le propriétaire du ticket devra attendre.

---

## Comment fonctionne une vraie Wi-Fi zone

Dans une vraie Wi-Fi zone, ce n'est pas le site web qui ouvre ou coupe Internet : c'est le **routeur**. Le site web (le « portail captif ») ne sert qu'à saisir le ticket. Le routeur demande à un serveur **RADIUS** si le ticket est valable, puis applique lui-même les limites.

```
 Téléphone ──Wi-Fi──▶ Point d'accès ──▶ Routeur hotspot ──▶ Box / fibre ──▶ Internet
                                              │
                                              │ RADIUS (UDP 1812 / 1813 / 3799)
                                              ▼
                                   Serveur : FreeRADIUS + cette plateforme (PHP + MySQL)
```

1. Le téléphone se connecte au Wi-Fi. Le routeur bloque tout son trafic et redirige ses pages web vers le **portail** (notre page `frontend/user/`).
2. Le client saisit son ticket. Le formulaire est envoyé **au routeur**.
3. Le routeur envoie une demande d'accès RADIUS avec l'identifiant, le mot de passe et la **vraie adresse MAC** de l'appareil.
4. FreeRADIUS transmet la demande à notre API, qui applique les règles métier (ticket valable ? déjà utilisé ailleurs ?) et répond **accepté** (avec le temps et le volume restants) ou **refusé**.
5. Le routeur ouvre Internet à l'appareil, **compte lui-même les octets** et envoie régulièrement la consommation (« accounting ») à RADIUS, qui la transmet à notre API.
6. Quand le temps ou le volume est épuisé, le routeur coupe l'appareil. Si l'administrateur désactive un ticket, l'API demande au routeur de couper l'appareil (« Disconnect »).

### Ce qui change entre le prototype et la production

| Prototype (aujourd'hui) | Production |
|---|---|
| MAC simulée par le navigateur, envoyée dans `POST /login` | MAC fournie par le routeur (attribut RADIUS `Calling-Station-Id`), impossible à falsifier depuis le navigateur |
| Consommation simulée par `POST /usage` | Accounting RADIUS (`Acct-Input-Octets`, `Acct-Output-Octets`, toutes les minutes) |
| Le portail appelle `POST /login` de l'API | Le portail envoie le formulaire au routeur, qui interroge RADIUS, qui interroge l'API |
| Fermer une session = mettre `date_fin` en base | En plus : envoyer un `Disconnect-Message` au routeur, sinon l'appareil garde Internet |
| Inactivité gérée par l'API (`SESSION_INACTIVITE_MINUTES`) | Gérée par le routeur (`Idle-Timeout`) |
| Temps restant affiché | Envoyé au routeur : `Session-Timeout` (en secondes) |
| Volume restant affiché | Envoyé au routeur : `Mikrotik-Total-Limit` (en octets) |

---

## Mettre la plateforme sur un vrai réseau

> Ce guide décrit la marche à suivre avec un routeur **MikroTik** (le matériel le plus répandu pour les Wi-Fi zones) et **FreeRADIUS**. Il n'a **pas encore été testé** sur du vrai matériel : les commandes et fichiers de configuration sont un point de départ, à valider étape par étape. Pour déboguer FreeRADIUS, toujours le lancer en mode verbeux : `freeradius -X`.

### Étape 1 : le matériel

| Élément | Exemples | Rôle |
|---|---|---|
| Routeur hotspot | MikroTik hAP ac², hAP ax², RB951 (RouterOS **v7**) | Portail captif, RADIUS, comptage des octets |
| Points d'accès (si grande zone) | MikroTik cAP, ou tout point d'accès en mode « bridge » | Étendre la couverture Wi-Fi |
| Serveur | Mini-PC, vieux PC ou Raspberry Pi 4 sous **Debian 12** | Héberge FreeRADIUS, Apache, PHP, MySQL |
| Accès Internet | Box, fibre, 4G | Sortie Internet |

**Alternatives :** OpenWrt + openNDS, ou pfSense (portail captif intégré). Le principe RADIUS reste le même.

**Où placer le serveur ?** Sur un port dédié du MikroTik (par exemple `ether5`, réseau `192.168.50.0/24`), **hors** du réseau hotspot et **sans masquerade** entre les clients et lui. Ainsi le serveur voit la vraie IP de chaque client (voir [la section NAT](#avant-de-passer-derrière-un-proxy-ou-un-nat)). Un serveur sur Internet (VPS) est possible, mais il faut alors relier le routeur et le VPS par un tunnel **WireGuard** (disponible dans RouterOS v7), car RADIUS ne doit jamais passer en clair sur Internet.

### Étape 2 : préparer le serveur (Debian 12)

```bash
sudo apt update
sudo apt install apache2 php php-mysql mariadb-server freeradius freeradius-rest
```

1. Copier le projet dans `/var/www/wifizone`.
2. Créer la base comme en local (étape 2 et 3 de l'installation), avec un **utilisateur MySQL dédié** (pas `root`) :
   ```sql
   CREATE USER 'wifizone'@'localhost' IDENTIFIED BY 'UN_MOT_DE_PASSE_LONG';
   GRANT SELECT, INSERT, UPDATE, DELETE ON wifi_zone.* TO 'wifizone'@'localhost';
   ```
3. Configurer Apache pour que **seuls** `frontend/` et `public/` soient accessibles (le `.env` et `src/` restent hors d'atteinte, même si un `.htaccess` est oublié) :
   ```apache
   <VirtualHost *:80>
       ServerName portail.mazone.lan
       DocumentRoot /var/www/wifizone/frontend
       Alias /api /var/www/wifizone/public

       <Directory /var/www/wifizone/frontend>
           Require all granted
       </Directory>
       <Directory /var/www/wifizone/public>
           AllowOverride All
           Require all granted
       </Directory>
   </VirtualHost>
   ```
   ```bash
   sudo a2enmod rewrite && sudo systemctl reload apache2
   ```
4. Dans `frontend/config.js` : `apiBase: '/api'` et `demo: false`.
5. Dans `.env` : `APP_DEBUG=false`, accès MySQL de l'utilisateur dédié.

### Étape 3 : configurer le hotspot MikroTik

Avec Winbox (ou en ligne de commande, menu *New Terminal*) :

1. **Assistant hotspot** : *IP > Hotspot > Hotspot Setup*. Choisir l'interface Wi-Fi (ou le bridge des points d'accès), garder le réseau proposé (ex. `10.5.50.0/24`), et donner un nom DNS au portail (ex. `login.mazone.lan`).

2. **Déclarer le serveur RADIUS** (adresse du serveur et un secret long, le même que dans FreeRADIUS) :
   ```
   /radius add service=hotspot address=192.168.50.10 secret=UN_SECRET_LONG_ET_ALEATOIRE
   /radius incoming set accept=yes port=3799
   ```
   La deuxième ligne permet à la plateforme de **couper** un client (désactivation d'un ticket).

3. **Profil du hotspot** (`hsprof1` est le nom créé par l'assistant ; vérifier dans *IP > Hotspot > Server Profiles*) :
   ```
   /ip hotspot profile set hsprof1 use-radius=yes login-by=http-pap radius-interim-update=1m
   /ip hotspot user profile set default shared-users=1 idle-timeout=15m
   ```
   - `login-by=http-pap` : obligatoire ici, car nos mots de passe sont **hachés** en base. La méthode CHAP exige que le serveur connaisse le mot de passe en clair, ce qui n'est pas notre cas. Contrepartie : entre le téléphone et le routeur, le mot de passe circule en clair sur le Wi-Fi. Pour l'éviter, activer plus tard `login-by=https` avec un certificat.
   - `radius-interim-update=1m` : le routeur envoie la consommation chaque minute.
   - `shared-users=1` : un seul appareil par ticket, appliqué aussi par le routeur.

4. **Walled garden** : autoriser l'accès au serveur **avant** la connexion, sinon le portail ne s'affiche pas :
   ```
   /ip hotspot walled-garden ip add dst-address=192.168.50.10 action=accept
   ```

5. **Page de connexion** : dans *Files*, dossier `hotspot/`, remplacer `login.html` par une simple redirection vers notre portail. Le routeur remplace lui-même les variables `$(…)` :
   ```html
   <!doctype html>
   <html><head>
   <meta http-equiv="refresh" content="0; url=http://192.168.50.10/user/?mac=$(mac-esc)&login=$(link-login-only-esc)&dst=$(link-orig-esc)">
   </head><body></body></html>
   ```

### Étape 4 : relier FreeRADIUS à la plateforme

Deux possibilités :

- **`rlm_sql`** : FreeRADIUS lit directement la base. Simple, mais il faudrait réécrire en SQL toutes les règles métier (un seul appareil, expiration, volume…) et stocker les mots de passe en clair.
- **`rlm_rest` (recommandé)** : FreeRADIUS appelle notre API en HTTP. **Toutes les règles restent dans le PHP existant**, et les mots de passe restent hachés.

Configuration de `rlm_rest` (fichier `/etc/freeradius/3.0/mods-available/rest`, puis l'activer avec `sudo ln -s ../mods-available/rest /etc/freeradius/3.0/mods-enabled/rest`) :

```
rest {
    connect_uri = "http://127.0.0.1/api/radius"

    authenticate {
        uri = "${..connect_uri}/auth"
        method = "post"
        body = "json"
    }
    accounting {
        uri = "${..connect_uri}/accounting"
        method = "post"
        body = "json"
    }
}
```

Dans `/etc/freeradius/3.0/sites-enabled/default`, faire passer l'authentification et l'accounting par ce module :

```
authorize {
    # … garder le reste …
    update control {
        Auth-Type := rest
    }
}
authenticate {
    Auth-Type rest {
        rest
    }
}
accounting {
    rest
}
```

Déclarer le routeur comme client autorisé dans `/etc/freeradius/3.0/clients.conf` :

```
client mikrotik {
    ipaddr = 192.168.50.1
    secret = UN_SECRET_LONG_ET_ALEATOIRE
}
```

### Étape 5 : adapter la plateforme (code à écrire)

Les éléments suivants **n'existent pas encore** dans le code :

1. **`POST /radius/auth`** : reçoit `User-Name`, `User-Password` et `Calling-Station-Id` (MAC). Il réutilise la logique de `ClientService::login`. Réponse :
   - **200** avec les limites, par exemple
     ```json
     { "reply:Session-Timeout": 3600, "reply:Mikrotik-Total-Limit": 1073741824, "reply:Mikrotik-Total-Limit-Gigawords": 0 }
     ```
   - **401** pour refuser (FreeRADIUS envoie alors un `Access-Reject`).
   - `Session-Timeout` = secondes restantes (à omettre pour un forfait sans durée).
   - Volume restant en octets : `Mikrotik-Total-Limit` = octets modulo 2³², `Mikrotik-Total-Limit-Gigawords` = octets ÷ 2³² (le premier attribut est limité à 4 Go). Choisir une convention et s'y tenir (par exemple 1 Go = 1 073 741 824 octets).

2. **`POST /radius/accounting`** : reçoit `Acct-Status-Type` (`Start`, `Interim-Update`, `Stop`), `Acct-Session-Id`, `Acct-Input-Octets`, `Acct-Output-Octets` (et leurs `-Gigawords`), `Framed-IP-Address`. Les compteurs envoyés par le routeur sont **cumulés depuis le début de la session** : enregistrer la différence avec la valeur précédente. Ajouter une colonne `acct_session_id` à `session_wifi` pour retrouver la session. `Stop` ferme la session. Ce point remplace `POST /usage`.

3. **Protéger `/radius/*`** : n'accepter que les requêtes venant de `127.0.0.1` (FreeRADIUS est sur le même serveur), plus un en-tête secret partagé.

4. **Anti force brute en mode RADIUS** : toutes les requêtes viennent de FreeRADIUS, donc de la même IP (`127.0.0.1`). La clé `client:<ip>` doit être remplacée par `client:<mac>` (le `Calling-Station-Id`).

5. **Couper un client** (désactivation d'un ticket, fermeture d'une session par l'admin) : envoyer un `Disconnect-Message` au routeur, par exemple avec `radclient` :
   ```bash
   echo "User-Name=IDENTIFIANT" | radclient -x 192.168.50.1:3799 disconnect UN_SECRET_LONG_ET_ALEATOIRE
   ```

6. **Portail client** (`frontend/js/user/main.js`) :
   - Lire `mac`, `login` et `dst` dans l'adresse de la page.
   - Envoyer le formulaire (un vrai `<form method="post">`, pas `fetch`) vers l'adresse `login` du routeur avec les champs `username`, `password` et `dst`.
   - Supprimer la MAC simulée.

7. **Tableau de bord client** : la connexion passe désormais par le routeur, donc l'API ne crée plus de session PHP. L'API peut identifier le client par son adresse IP (`REMOTE_ADDR`), qui correspond à la `Framed-IP-Address` de sa session ouverte. Cela suppose que le serveur voie les vraies IP des clients (voir la section suivante).

### Étape 6 : tester

1. `freeradius -X` dans un terminal pour voir chaque requête en direct.
2. Tester l'authentification sans routeur :
   ```bash
   echo "User-Name=IDENTIFIANT,User-Password=MOTDEPASSE,Calling-Station-Id=AA:BB:CC:DD:EE:FF" | radclient -x 127.0.0.1 auth testing123
   ```
   (`testing123` est le secret du client `localhost` par défaut dans `clients.conf`.)
3. Se connecter au Wi-Fi avec un téléphone, vérifier la redirection vers le portail, puis la connexion.
4. Vérifier dans *IP > Hotspot > Active* du MikroTik que l'appareil apparaît, avec ses limites.
5. Consommer du volume et vérifier que la plateforme le reçoit (sessions de l'admin).
6. Désactiver le ticket depuis l'admin : l'appareil doit perdre Internet.

---

## Avant de passer derrière un proxy ou un NAT

> **À traiter le jour où la plateforme passe derrière un proxy inverse (Nginx, Cloudflare…) ou un NAT.** Aujourd'hui, ce n'est pas le cas : rien à faire pour l'instant.

**Le problème :** l'anti force brute et l'enregistrement des sessions utilisent l'adresse IP du client (`Request::ip()`, qui lit `REMOTE_ADDR`). Derrière un proxy ou un NAT, `REMOTE_ADDR` contient l'adresse du proxy pour **tous** les clients. Conséquences :
- 10 échecs de n'importe quel client bloquent **tout le monde** pendant 15 minutes ;
- toutes les sessions affichent la même IP.

**Cas du hotspot MikroTik :** si le serveur est placé derrière la règle `masquerade` du routeur, il voit l'IP du routeur pour tous les clients. D'où le conseil de l'étape 1 : serveur sur un port dédié, sans masquerade.

**La correction**, à faire à ce moment-là, dans `src/Core/Request.php` : lire l'en-tête `X-Forwarded-For`, **uniquement** si la requête vient d'un proxy de confiance. Sinon n'importe quel client pourrait inventer cet en-tête et changer d'IP à volonté pour contourner l'anti force brute.

```php
// .env : TRUSTED_PROXIES=127.0.0.1,10.0.0.5
public function ip(): string {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $trusted = array_map('trim', explode(',', Env::get('TRUSTED_PROXIES', '')));

    if (in_array($remote, $trusted, true) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // Dernière adresse de la liste : celle ajoutée par NOTRE proxy (les précédentes peuvent être inventées)
        $chain = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
        $ip = end($chain);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    return $remote;
}
```

En mode RADIUS, la question ne se pose plus pour la connexion : la clé anti force brute devient l'adresse MAC (voir l'étape 5, point 4).

---

## Checklist de sécurité avant la mise en ligne

- [ ] `APP_DEBUG=false` dans le `.env`
- [ ] Utilisateur MySQL dédié avec un mot de passe (jamais `root` sans mot de passe)
- [ ] Mot de passe administrateur long et unique
- [ ] `demo: false` dans `frontend/config.js`, et route `POST /usage` supprimée
- [ ] Apache : `DocumentRoot` sur `frontend/`, alias vers `public/` ; `.env` et `src/` inaccessibles depuis le navigateur (vérifier avec un navigateur que `/.env` renvoie une erreur 404)
- [ ] Fichiers de brouillon retirés du serveur (ex. `frontend/admin/dashboard.txt`)
- [ ] Ne **jamais** exécuter `wifi zone.sql` sur la base de production (il contient `DROP DATABASE`)
- [ ] `CORS_ORIGIN` = adresse exacte du front s'il est sur un autre domaine
- [ ] HTTPS sur l'interface d'administration
- [ ] Secret RADIUS long et aléatoire ; ports 1812, 1813 et 3799 accessibles **uniquement** depuis le routeur
- [ ] Sauvegarde quotidienne de la base (`mysqldump wifi_zone > sauvegarde.sql`)
